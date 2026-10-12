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
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldManifestLocator;
use CitOmni\Installer\Support\InstallerLock;

/**
 * `install` — initial scaffold and environment materialization.
 *
 * Install is intentionally not an environment switch interface. An existing
 * environment-aware target must match the requested rendering, or keep a clean recorded
 * baseline of the same environment that sync updates later, before any write can occur.
 * Otherwise install stops without writing and names every conflicting target. Content
 * from another environment is routed to the dedicated `environment <env>` command
 * instead of being overwritten by --force.
 *
 * Install also maintains the installer block in an existing .gitignore, planned from
 * every installed package even when --package narrows the scaffold files. Malformed
 * block markers stop install before writing, like an environment conflict.
 */
final class InstallCommand extends AbstractWriteCommand {

	public function __construct(
		ScaffoldManifestLocator $locator,
		BuildScaffoldPlan $builder,
		ApplyScaffoldPlan $applier,
		PlaceholderResolver $placeholders,
		ScaffoldState $state,
		InstallerLock $lock,
		private readonly ApplyEnvironmentMaterialization $materializer
	) {
		parent::__construct($locator, $builder, $applier, $placeholders, $state, $lock);
	}


	protected function commandName(): string {
		return 'install';
	}


	/**
	 * Materialize the complete scaffold for one explicitly selected environment.
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

		$environment = $this->parseEnvironmentOption($opt['environment'], $format);
		if (!$environment instanceof Environment) {
			return ExitCode::USAGE_ERROR->value;
		}

		return $this->withWriteLock($opt, fn(): int => $this->runMaterialization($opt, $environment));
	}

	/** Execute materialization while the caller holds the application lock. */
	private function runMaterialization(array $opt, Environment $environment): int {
		$format = $opt['format'];
		try {
			$recorded = $this->state->environment();
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), ExitCode::UNSAFE_STATE->value);
		}

		if ($recorded === null && $opt['package'] !== null) {
			return $this->fail(
				$format,
				'Initial environment materialization requires all packages. Omit --package; package-scoped install is available after initial materialization.',
				ExitCode::USAGE_ERROR->value
			);
		}

		if ($recorded instanceof Environment && $recorded !== $environment) {
			return $this->fail(
				$format,
				\sprintf(
					'Application is already materialized as %s. Use `citomni-installer environment %s` to switch environments.',
					$recorded->value,
					$environment->value
				),
				ExitCode::CONFLICT->value
			);
		}

		try {
			[$discovered, $manifests] = $this->resolveManifests($opt['package']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), $this->exceptionExitCode($e));
		}

		if ($manifests === []) {
			return $this->emptyResult($format, $opt['package']);
		}

		$manifests = $this->locator->selectEnvironment($manifests, $environment);

		try {
			$placeholders = $this->resolvePlaceholders($manifests, $opt['placeholders']);
			$plan = $this->builder->build('install', $manifests, $placeholders, [
				'force' => $opt['force'],
				'environment_materialization' => true,
				'gitignore' => $this->locator->gitignoreRules($discovered),
			]);
		} catch (InstallerException $e) {
			return $this->fail(
				$format,
				$e->getMessage(),
				$this->exceptionExitCode($e)
			);
		}

		if ($this->hasEnvironmentConflict($plan)) {
			return $this->fail(
				$format,
				$this->environmentConflictMessage($plan, $environment),
				ExitCode::CONFLICT->value,
				$this->conflictPackages($plan)
			);
		}

		$gitignoreExit = $this->failOnGitignoreConflict($plan, $format);
		if ($gitignoreExit !== null) {
			return $gitignoreExit;
		}

		$confirmExit = $this->confirmForcedPlanIfNeeded($plan, $format, $opt);
		if ($confirmExit !== null) {
			return $confirmExit;
		}

		try {
			$result = $this->materializer->apply($plan, $environment, ['dry_run' => $opt['dry_run']]);
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
	 * Parse the mandatory install environment option.
	 *
	 * @param mixed $value Parsed option value.
	 * @param string $format Output format.
	 * @return Environment|null Null after emitting a usage error.
	 */
	private function parseEnvironmentOption(mixed $value, string $format): ?Environment {
		if (!\is_string($value) || $value === '') {
			$this->fail(
				$format,
				'install requires --environment=<dev|stage|prod>.',
				ExitCode::USAGE_ERROR->value
			);
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


	/**
	 * Detect the install-only pre-write environment compatibility conflict.
	 *
	 * @param array<string,mixed> $plan Install plan.
	 * @return bool True when an environment-aware target conflicts.
	 */
	private function hasEnvironmentConflict(array $plan): bool {
		foreach ((array)($plan['packages'] ?? []) as $package) {
			foreach ((array)($package['files'] ?? []) as $file) {
				if (($file['action'] ?? null) === 'conflict' && !empty($file['_environment_aware'])) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Explain a pre-write environment conflict with every blocking target.
	 *
	 * Behavior:
	 * - Lists each conflicting target as "package target: reason", including ordinary
	 *   managed targets that would also block this install.
	 * - Adds one explanation per reason that occurs.
	 *
	 * @param array<string,mixed> $plan Install plan.
	 * @param Environment $environment Requested environment.
	 * @return string Message for text and JSON output.
	 */
	private function environmentConflictMessage(array $plan, Environment $environment): string {
		$lines = [\sprintf(
			'Install stopped before writing: existing environment-aware scaffold targets do not match the %s rendering.',
			$environment->value
		)];
		$reasons = [];
		foreach ($this->conflictPackages($plan) as $package) {
			foreach ($package['files'] as $file) {
				$lines[] = \sprintf('- %s %s: %s', $package['name'], $file['target'], $file['reason']);
				$reasons[$file['reason']] = true;
			}
		}

		if (isset($reasons['unknown_existing'])) {
			$lines[] = \sprintf(
				'unknown_existing: no recorded baseline exists, so another environment, an older package revision and a local edit cannot be told apart. Delete a stale package-managed file so install can recreate it; to adopt files from another environment, install that environment first and then run `citomni-installer environment %s`.',
				$environment->value
			);
		}
		if (isset($reasons['local_modified'])) {
			$lines[] = \sprintf(
				'local_modified: the file differs from its recorded baseline. `citomni-installer sync` writes the current rendering as .new for review; `citomni-installer environment %s` replaces environment-aware targets after a backup.',
				$environment->value
			);
		}

		return \implode("\n", $lines);
	}
}
