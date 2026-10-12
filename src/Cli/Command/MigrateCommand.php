<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Installer\Cli\Command;

use CitOmni\Installer\Cli\InstallerCli;
use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Enum\ExitCode;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Operation\ApplyEnvironmentMaterialization;
use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\AuditAppSkeletonMigration;
use CitOmni\Installer\Operation\BuildMigrationDiagnostics;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\AppSkeletonReferenceFetcher;
use CitOmni\Installer\Support\InstallerLock;
use CitOmni\Installer\Support\MigrationReportWriter;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\PhpLintRunner;
use CitOmni\Installer\Support\ScaffoldManifestLocator;

/**
 * `migrate` — repeatable scaffold migration and reconciliation against current sources.
 *
 * Legacy/no-state applications rebuild from current manifests. Current v2 applications
 * keep their trusted managed baselines so local edits remain protected, while every
 * target is still re-evaluated against today's installed package sources. Create-only
 * code differences become freshly generated `.new` sidecars on every run. Template
 * targets remain authoritative. The installer block in .gitignore is refreshed, and a
 * missing .gitignore is created in a Git checkout; malformed block markers stop
 * migration before writing. App-skeleton references and recursive PHP lint are
 * included in the human-review report.
 */
final class MigrateCommand extends AbstractWriteCommand {

	public function __construct(
		ScaffoldManifestLocator $locator,
		BuildScaffoldPlan $builder,
		ApplyScaffoldPlan $applier,
		PlaceholderResolver $placeholders,
		ScaffoldState $state,
		InstallerLock $lock,
		private readonly ApplyEnvironmentMaterialization $materializer,
		private readonly BuildMigrationDiagnostics $diagnostics,
		private readonly AppSkeletonReferenceFetcher $appSkeletonFetcher,
		private readonly AuditAppSkeletonMigration $appSkeletonAudit,
		private readonly MigrationReportWriter $reportWriter,
		private readonly PhpLintRunner $phpLint
	) {
		parent::__construct($locator, $builder, $applier, $placeholders, $state, $lock);
	}


	protected function commandName(): string {
		return 'migrate';
	}


	/**
	 * Rebuild the current scaffold and establish current installer state.
	 *
	 * @param array<int,string> $args Arguments after the command name.
	 * @return int Installer exit code.
	 */
	public function run(array $args): int {
		$parsed = InstallerCli::parseCommonOptions($args);
		if ($parsed['ok'] !== true) {
			\fwrite(\STDERR, $parsed['error'] . "\n");
			return ExitCode::USAGE_ERROR->value;
		}

		$opt = $parsed['options'];
		$format = $opt['format'];

		$environment = $this->parseEnvironmentOption($opt['environment'] ?? null, $format);
		if (!$environment instanceof Environment) {
			return ExitCode::USAGE_ERROR->value;
		}

		if ($opt['package'] !== null || $opt['force']) {
			return $this->fail(
				$format,
				'migrate applies to the complete application and does not accept --package or --force.',
				ExitCode::USAGE_ERROR->value
			);
		}

		return $this->withWriteLock($opt, fn(): int => $this->runMigration($opt, $environment));
	}


	/** Execute migration while the caller holds the application lock. */
	private function runMigration(array $opt, Environment $environment): int {
		$format = $opt['format'];

		try {
			$migrationVersion = $this->state->migrationVersion();
			$reconcileCurrent = $migrationVersion === ScaffoldState::FORMAT_VERSION;
			$currentEnvironment = $reconcileCurrent ? $this->state->environment() : null;
			$environmentChanged = $currentEnvironment instanceof Environment && $currentEnvironment !== $environment;

			$discovered = $this->locator->discover();
			if ($discovered === []) {
				return $this->emptyResult($format, null);
			}

			$sourceWarnings = $this->diagnostics->inspectPercentStyleSourceTokens($discovered);
			$configAudit = $this->diagnostics->inspectInstallerConfig($discovered, $environment, $opt['placeholders']);
			$missing = (array)($configAudit['missing_effective_selected'] ?? []);
			if ($missing !== []) {
				$message = \sprintf(
					'Missing installer placeholders for %s: %s. Add them to config/citomni_installer.php or pass --placeholder=KEY=VALUE.',
					$environment->value,
					\implode(', ', $missing)
				);
				$warningFiles = (int)($sourceWarnings['file_count'] ?? 0);
				if ($warningFiles > 0) {
					$message .= \sprintf(
						"\n\nAdvisory: %d current scaffold source file%s contain percent-style uppercase tokens:\n",
						$warningFiles,
						$warningFiles === 1 ? '' : 's'
					);

					foreach ((array)($sourceWarnings['files'] ?? []) as $warning) {
						$package = (string)($warning['package'] ?? '');
						$source = (string)($warning['source'] ?? '');
						$targets = \array_values(\array_filter(
							(array)($warning['targets'] ?? []),
							static fn(mixed $target): bool => \is_string($target) && $target !== ''
						));

						$message .= '- ' . ($package !== '' ? $package . ' — ' : '') . $source;
						if ($targets !== []) {
							$message .= ' → ' . \implode(', ', $targets);
						}
						$message .= "\n";
					}

					$message .= "\nResolve the missing placeholders and rerun migrate for the full source-warning report.";
				}
				return $this->fail($format, $message, ExitCode::GENERAL_ERROR->value);
			}

			$manifests = $this->locator->selectEnvironment($discovered, $environment);
			$placeholders = $this->resolvePlaceholders($manifests, $opt['placeholders']);
			$plan = $this->builder->build('migrate', $manifests, $placeholders, [
				'environment_materialization' => true,
				'migration_reconcile' => $reconcileCurrent,
				'migration_environment_changed' => $environmentChanged,
				'gitignore' => $this->locator->gitignoreRules($discovered),
			]);
			$gitignoreExit = $this->failOnGitignoreConflict($plan, $format);
			if ($gitignoreExit !== null) {
				return $gitignoreExit;
			}
			$planDiagnostics = $this->diagnostics->inspectPlan($plan);

			$result = $this->materializer->apply($plan, $environment, ['dry_run' => $opt['dry_run']]);

			try {
				// Compare composer.json only after environment materialization so the diff
				// reflects the final classmap-authoritative posture of this run.
				$reference = $this->appSkeletonFetcher->fetchCurrent();
				$appSkeleton = $this->appSkeletonAudit->apply($reference, (bool)$opt['dry_run']);
			} catch (InstallerException $e) {
				// App-skeleton comparison is advisory. Network/filesystem trouble here must
				// not invalidate scaffold and Composer materialization that already succeeded.
				$appSkeleton = [
					'available' => false,
					'source' => 'citomni/app-skeleton',
					'error' => $e->getMessage(),
					'files' => [],
					'manual_review' => [],
				];
			}

			try {
				$phpLint = $this->phpLint->run();
			} catch (InstallerException $e) {
				$phpLint = [
					'available' => false,
					'php_binary' => \PHP_BINARY,
					'checked' => 0,
					'passed' => 0,
					'failed' => 0,
					'files' => [],
					'error' => $e->getMessage(),
				];
			}

			$result['migration'] = [
				'mode' => $reconcileCurrent ? 'reconcile_v2' : 'rebuild_legacy',
				'previous_environment' => $currentEnvironment?->value,
				'installer_config' => $configAudit,
				'scaffold_source_warnings' => $sourceWarnings,
				'scaffold_diffs' => $planDiagnostics['scaffold_diffs'],
				'blocking_manual_review' => $this->diagnostics->blockingManualReview($result),
				'advisory_manual_review' => $this->diagnostics->advisoryManualReview($result),
				'app_skeleton' => $appSkeleton,
				'php_lint' => $phpLint,
			];
			$result['migration_report'] = null;
			$result['migration_report_error'] = null;
			if (!$opt['dry_run']) {
				try {
					$result['migration_report'] = $this->reportWriter->write($result);
				} catch (InstallerException $e) {
					// Reporting is post-commit audit output. Never turn an already completed
					// migration into a false failure merely because var/logs is unavailable.
					$result['migration_report_error'] = $e->getMessage();
				}
			}
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), $this->exceptionExitCode($e));
		}

		$exit = $this->exitCodeFor($result);
		if ($format === 'json') {
			$this->emitJson($result, $exit);
		} else {
			$this->emitText($result, $exit);
		}

		return $exit;
	}


	/**
	 * Parse the mandatory migration environment option.
	 *
	 * @param mixed $value Parsed option value.
	 * @param string $format Output format.
	 * @return Environment|null Null after emitting a usage error.
	 */
	private function parseEnvironmentOption(mixed $value, string $format): ?Environment {
		if ($value === null || $value === '') {
			return Environment::DEV;
		}
		if (!\is_string($value)) {
			$this->fail($format, 'Invalid migrate environment option.', ExitCode::USAGE_ERROR->value);
			return null;
		}

		$environment = Environment::tryFrom($value);
		if ($environment === null) {
			$this->fail(
				$format,
				\sprintf('Invalid environment %s. Expected one of: %s.', \var_export($value, true), \implode(', ', Environment::values())),
				ExitCode::USAGE_ERROR->value
			);
		}

		return $environment;
	}
}
