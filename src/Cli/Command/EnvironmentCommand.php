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
 * `environment` — authoritative environment switch for materialized applications.
 *
 * Only environment-aware scaffold targets participate. Environment-agnostic files
 * remain untouched, while the requested environment also determines Composer's
 * classmap-authoritative posture through ApplyEnvironmentMaterialization.
 */
final class EnvironmentCommand extends AbstractWriteCommand {

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
		return 'environment';
	}


	/**
	 * Switch the already materialized application to one environment.
	 *
	 * @param array<int,string> $args Arguments after the command name.
	 * @return int Installer exit code.
	 */
	public function run(array $args): int {
		[$environmentName, $args, $positionalError] = $this->extractEnvironment($args);
		if ($positionalError !== null) {
			\fwrite(\STDERR, $positionalError . "\n");
			return ExitCode::USAGE_ERROR->value;
		}

		$parsed = InstallerCli::parseCommonOptions($args);
		if ($parsed['ok'] !== true) {
			\fwrite(\STDERR, $parsed['error'] . "\n");
			return ExitCode::USAGE_ERROR->value;
		}

		$opt = $parsed['options'];
		$format = $opt['format'];

		$environment = Environment::tryFrom((string)$environmentName);
		if ($environment === null) {
			return $this->fail(
				$format,
				\sprintf('Invalid environment %s. Expected one of: %s.', \var_export($environmentName, true), \implode(', ', Environment::values())),
				ExitCode::USAGE_ERROR->value
			);
		}

		if ($opt['environment'] !== null || $opt['package'] !== null || $opt['force']) {
			return $this->fail(
				$format,
				'environment accepts the positional environment plus --format, --placeholder and --dry-run. It does not accept --environment, --package or --force.',
				ExitCode::USAGE_ERROR->value
			);
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

		if (!$recorded instanceof Environment) {
			return $this->fail(
				$format,
				'No materialized environment is recorded. Use install --environment=<dev|stage|prod> for initial materialization.',
				ExitCode::GENERAL_ERROR->value
			);
		}

		try {
			$manifests = $this->locator->discover();
			$manifests = $this->locator->selectEnvironment($manifests, $environment, true);
			$placeholders = $this->resolvePlaceholders($manifests, $opt['placeholders']);
			$plan = $this->builder->build('environment', $manifests, $placeholders);
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
	 * Extract exactly one positional environment without consuming options.
	 *
	 * @param array<int,string> $args Raw arguments after `environment`.
	 * @return array{0:?string,1:array<int,string>,2:?string}
	 */
	private function extractEnvironment(array $args): array {
		$environment = null;
		$rest = [];

		foreach ($args as $arg) {
			if (!\str_starts_with($arg, '-')) {
				if ($environment !== null) {
					return [null, [], 'environment expects exactly one positional value: dev, stage or prod.'];
				}
				$environment = $arg;
				continue;
			}
			$rest[] = $arg;
		}

		if ($environment === null) {
			return [null, [], 'environment requires one positional value: dev, stage or prod.'];
		}

		return [$environment, $rest, null];
	}
}
