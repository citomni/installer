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
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldManifestLocator;

/**
 * `status` — read-only scaffold and Composer environment status.
 *
 * An explicit --environment wins over recorded state and acts as a preview. When
 * environment-aware manifests exist and neither source provides an environment,
 * status fails rather than guessing. Composer posture is inspected directly from
 * composer.json and never requires the Composer executable.
 */
final class StatusCommand {

	public function __construct(
		private readonly string $appRoot,
		private readonly ScaffoldManifestLocator $locator,
		private readonly BuildScaffoldPlan $builder,
		private readonly PlaceholderResolver $placeholders,
		private readonly ScaffoldState $state
	) {}


	/**
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

		try {
			$manifests = $this->resolveManifests($opt['package']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), ExitCode::GENERAL_ERROR->value);
		}

		if ($opt['package'] !== null && $manifests === []) {
			return $this->fail($format, "Package '{$opt['package']}' is not installed or has no CitOmni scaffold manifest.", ExitCode::GENERAL_ERROR->value);
		}

		$environment = null;
		if ($opt['environment'] !== null) {
			$environment = Environment::tryFrom($opt['environment']);
			if ($environment === null) {
				return $this->fail(
					$format,
					\sprintf('Invalid environment %s. Expected one of: %s.', \var_export($opt['environment'], true), \implode(', ', Environment::values())),
					ExitCode::USAGE_ERROR->value
				);
			}
		} else {
			try {
				$environment = $this->state->environment();
			} catch (InstallerException $e) {
				return $this->fail($format, $e->getMessage(), ExitCode::UNSAFE_STATE->value);
			}
		}

		if (!$environment instanceof Environment && $this->locator->hasEnvironmentAwareEntries($manifests)) {
			return $this->fail(
				$format,
				'Environment-aware scaffold is installed, but no environment is recorded. Pass --environment=<dev|stage|prod> for a read-only preview.',
				ExitCode::GENERAL_ERROR->value
			);
		}

		$manifests = $environment instanceof Environment
			? $this->locator->selectEnvironment($manifests, $environment)
			: $this->locator->selectEnvironmentAgnostic($manifests);

		try {
			$resolved = $this->placeholders->resolve($opt['placeholders']);
			$placeholders = [];
			foreach (\array_keys($manifests) as $pkgName) {
				$placeholders[$pkgName] = $resolved;
			}
			$plan = $this->builder->build('status', $manifests, $placeholders);
		} catch (InstallerException $e) {
			return $this->fail(
				$format,
				$e->getMessage()
					. "\nHint: status renders managed stubs. Provide missing values via config/citomni_installer.php or --placeholder=KEY=VALUE.",
				ExitCode::GENERAL_ERROR->value
			);
		}

		$composer = $environment instanceof Environment
			? $this->composerStatus($environment)
			: null;

		if (\is_array($composer) && $composer['status'] === 'error') {
			return $this->fail($format, $composer['reason'], ExitCode::GENERAL_ERROR->value);
		}

		$exit = $this->exitCodeFor($plan);
		if (\is_array($composer) && $composer['status'] === 'drift' && $exit !== ExitCode::CONFLICT->value) {
			$exit = ExitCode::DRIFT->value;
		}

		if ($format === 'json') {
			$this->emitJson($plan, $environment, $composer, $exit);
		} else {
			$this->emitText($plan, $environment, $composer, $exit);
		}

		return $exit;
	}


	/**
	 * @return array<string,array<string,mixed>> Manifests keyed by package name.
	 */
	private function resolveManifests(?string $package): array {
		if ($package === null) {
			return $this->locator->discover();
		}

		$one = $this->locator->discoverPackage($package);
		return $one === null ? [] : [$package => $one];
	}


	/**
	 * Inspect explicit config.classmap-authoritative in composer.json.
	 *
	 * @param Environment $environment Selected environment.
	 * @return array{status:string,expected:bool,actual:mixed,reason:string}
	 */
	private function composerStatus(Environment $environment): array {
		$path = $this->appRoot . '/composer.json';
		$json = @\file_get_contents($path);
		if ($json === false) {
			return ['status' => 'error', 'expected' => $environment->classmapAuthoritative(), 'actual' => null, 'reason' => 'composer.json is missing or unreadable: ' . $path];
		}

		$data = \json_decode($json, true);
		if (!\is_array($data)) {
			return ['status' => 'error', 'expected' => $environment->classmapAuthoritative(), 'actual' => null, 'reason' => 'composer.json is not valid JSON: ' . $path];
		}

		$expected = $environment->classmapAuthoritative();
		$config = $data['config'] ?? null;
		if (!\is_array($config) || !\array_key_exists('classmap-authoritative', $config)) {
			return ['status' => 'drift', 'expected' => $expected, 'actual' => null, 'reason' => 'classmap_authoritative_missing'];
		}

		$actual = $config['classmap-authoritative'];
		if (!\is_bool($actual) || $actual !== $expected) {
			return ['status' => 'drift', 'expected' => $expected, 'actual' => $actual, 'reason' => 'classmap_authoritative_mismatch'];
		}

		return ['status' => 'up_to_date', 'expected' => $expected, 'actual' => $actual, 'reason' => 'matches_environment'];
	}


	/**
	 * Derive scaffold exit code from plan statuses.
	 *
	 * @param array<string,mixed> $plan Status plan.
	 * @return int
	 */
	private function exitCodeFor(array $plan): int {
		$worst = ExitCode::OK->value;
		$anyError = false;

		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			foreach ((array)($pkg['files'] ?? []) as $file) {
				$code = match ((string)($file['status'] ?? '')) {
					'local_modified', 'unknown_existing' => ExitCode::CONFLICT->value,
					'missing', 'update_available', 'placeholder_drift' => ExitCode::DRIFT->value,
					'up_to_date', 'create_only_present' => ExitCode::OK->value,
					default => -1,
				};

				if ($code === -1) {
					$anyError = true;
				} elseif ($code > $worst) {
					$worst = $code;
				}
			}
		}

		return $anyError ? ExitCode::GENERAL_ERROR->value : $worst;
	}


	/**
	 * @param array<string,mixed> $plan Status plan.
	 * @param array<string,mixed>|null $composer Composer posture report.
	 */
	private function emitText(array $plan, ?Environment $environment, ?array $composer, int $exit): void {
		$out = "citomni-installer status\n";
		if ($environment instanceof Environment) {
			$out .= 'Environment: ' . $environment->value . "\n";
		}

		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			$out .= (string)($pkg['name'] ?? '') . "\n";
			foreach ((array)($pkg['files'] ?? []) as $file) {
				$out .= \sprintf(
					"  %-32s %-20s %s\n",
					(string)($file['target'] ?? ''),
					(string)($file['status'] ?? ''),
					(string)($file['reason'] ?? '')
				);
			}
		}

		if (\is_array($composer)) {
			$out .= \sprintf(
				"Composer classmap-authoritative: %s (expected %s, actual %s; %s)\n",
				$composer['status'],
				$composer['expected'] ? 'true' : 'false',
				$composer['actual'] === null ? 'missing' : \var_export($composer['actual'], true),
				$composer['reason']
			);
		}

		$out .= \sprintf("\nResult: %s (exit %d)\n", $this->summaryLabel($exit), $exit);
		\fwrite(\STDOUT, $out);
	}


	/**
	 * @param array<string,mixed> $plan Status plan.
	 * @param array<string,mixed>|null $composer Composer posture report.
	 */
	private function emitJson(array $plan, ?Environment $environment, ?array $composer, int $exit): void {
		$packages = [];
		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			$files = [];
			foreach ((array)($pkg['files'] ?? []) as $file) {
				$files[] = [
					'target' => (string)($file['target'] ?? ''),
					'type' => (string)($file['type'] ?? ''),
					'policy' => (string)($file['policy'] ?? ''),
					'status' => (string)($file['status'] ?? ''),
					'reason' => (string)($file['reason'] ?? ''),
					'action' => (string)($file['action'] ?? 'none'),
				];
			}
			$packages[] = ['name' => (string)($pkg['name'] ?? ''), 'files' => $files];
		}

		\fwrite(\STDOUT, InstallerCli::encodeJson([
			'ok' => $exit === ExitCode::OK->value,
			'exit_code' => $exit,
			'environment' => $environment?->value,
			'composer' => $composer,
			'packages' => $packages,
		]) . "\n");
	}


	private function summaryLabel(int $exit): string {
		return match ($exit) {
			ExitCode::OK->value => 'up to date',
			ExitCode::DRIFT->value => 'updates available',
			ExitCode::CONFLICT->value => 'conflicts / local changes',
			default => 'error',
		};
	}


	private function fail(string $format, string $message, int $exit): int {
		if ($format === 'json') {
			\fwrite(\STDOUT, InstallerCli::encodeJson([
				'ok' => false,
				'exit_code' => $exit,
				'error' => $message,
				'packages' => [],
			]) . "\n");
		} else {
			\fwrite(\STDERR, $message . "\n");
		}

		return $exit;
	}
}
