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
 * Install is intentionally not an environment switch interface. Environment-aware
 * targets that already exist must match the requested environment exactly before any
 * write can occur. Existing content from another environment is routed to the
 * dedicated `environment <env>` command instead of being overwritten by --force.
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
			$manifests = $this->resolveManifests($opt['package']);
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
			]);
		} catch (InstallerException $e) {
			return $this->fail(
				$format,
				$e->getMessage(),
				$this->exceptionExitCode($e)
			);
		}

		if ($this->hasDifferentEnvironmentConflict($plan)) {
			return $this->fail(
				$format,
				\sprintf(
					'An existing environment-aware target does not match %s. No scaffold files were written. Use `citomni-installer environment %s` to switch it authoritatively.',
					$environment->value,
					$environment->value
				),
				ExitCode::CONFLICT->value
			);
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
	 * @return bool
	 */
	private function hasDifferentEnvironmentConflict(array $plan): bool {
		foreach ((array)($plan['packages'] ?? []) as $package) {
			foreach ((array)($package['files'] ?? []) as $file) {
				if (($file['action'] ?? null) === 'conflict' && ($file['reason'] ?? null) === 'different_environment') {
					return true;
				}
			}
		}

		return false;
	}
}
