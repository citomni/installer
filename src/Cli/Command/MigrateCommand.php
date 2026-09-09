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
use CitOmni\Installer\Support\InstallerLock;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldManifestLocator;

/**
 * `migrate` — rebuild legacy installer materialization from current manifests.
 *
 * Legacy state is never converted into the new baseline. Current installed package
 * manifests and current placeholder resolution are authoritative. Existing create-only
 * files are preserved, while managed files are adopted when current or backed up and
 * replaced when they differ. Composer posture and v2 state are committed last.
 */
final class MigrateCommand extends AbstractWriteCommand {

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

		$environment = $this->parseEnvironmentOption($opt['environment'], $format);
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
			// Validate migration eligibility before manifest planning. The returned legacy
			// data is intentionally ignored; current manifests are authoritative.
			$this->state->legacyVersionForMigration();

			$manifests = $this->locator->discover();
			if ($manifests === []) {
				return $this->emptyResult($format, null);
			}

			$manifests = $this->locator->selectEnvironment($manifests, $environment);
			$placeholders = $this->resolvePlaceholders($manifests, $opt['placeholders']);
			$plan = $this->builder->build('migrate', $manifests, $placeholders, [
				'environment_materialization' => true,
			]);
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
	 * Parse the mandatory migration environment option.
	 *
	 * @param mixed $value Parsed option value.
	 * @param string $format Output format.
	 * @return Environment|null Null after emitting a usage error.
	 */
	private function parseEnvironmentOption(mixed $value, string $format): ?Environment {
		if (!\is_string($value) || $value === '') {
			$this->fail(
				$format,
				'migrate requires --environment=<dev|stage|prod>.',
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
}
