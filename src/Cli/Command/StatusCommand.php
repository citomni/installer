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
use CitOmni\Installer\Enum\ExitCode;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\Support\ComposerPackageLocator;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Exception\InstallerException;

/**
 * `status` — read-only scaffold status.
 *
 * Builds a `status` plan via BuildScaffoldPlan and reports each file's status and
 * human-readable reason. No files or state are written.
 *
 * Behavior:
 * - Placeholders are resolved once via PlaceholderResolver (config + CLI overrides)
 *   and applied to every discovered package, because drift detection renders the
 *   managed stubs. A stub token with no resolved value makes the plan fail; that is
 *   reported as an error with a hint, not a stack trace.
 * - The exit code is derived from the resulting statuses, not from any single file.
 *
 * Notes:
 * - `--force` / `--dry-run` are accepted by the shared grammar but have no effect
 *   on a read-only command.
 */
final class StatusCommand {

	public function __construct(
		private readonly ComposerPackageLocator $locator,
		private readonly BuildScaffoldPlan $builder,
		private readonly PlaceholderResolver $placeholders
	) {}

	/**
	 * @param  array<int,string>  $args  Arguments after the command name.
	 * @return int  Exit code (0 up-to-date; 3 updates; 4 conflicts; 1 error; 2 usage).
	 */
	public function run(array $args): int {
		$parsed = InstallerCli::parseCommonOptions($args);
		if ($parsed['ok'] !== true) {
			\fwrite(\STDERR, $parsed['error'] . "\n");
			return ExitCode::USAGE_ERROR->value;
		}

		$opt    = $parsed['options'];
		$format = $opt['format'];

		// -- 1. Discover manifests ------------------------------------
		try {
			$manifests = $this->resolveManifests($opt['package']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage());
		}

		if ($manifests === []) {
			return $this->emptyResult($format, $opt['package']);
		}

		// -- 2. Resolve placeholders (config + CLI overrides) ---------
		try {
			$resolved = $this->placeholders->resolve($opt['placeholders']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage());
		}

		$placeholders = [];
		foreach (\array_keys($manifests) as $pkgName) {
			$placeholders[$pkgName] = $resolved;
		}

		// -- 3. Build the status plan ---------------------------------
		try {
			$plan = $this->builder->build('status', $manifests, $placeholders, []);
		} catch (InstallerException $e) {
			return $this->fail(
				$format,
				$e->getMessage()
				. "\nHint: status renders managed stubs to detect drift. Provide the missing "
				. 'value(s) via config/citomni_installer.php or --placeholder=KEY=VALUE.'
			);
		}

		// -- 4. Report ------------------------------------------------
		$exit = $this->exitCodeFor($plan);
		if ($format === 'json') {
			$this->emitJson($plan, $exit);
		} else {
			$this->emitText($plan, $exit);
		}
		return $exit;
	}


	// ----------------------------------------------------------------
	// Discovery & exit code
	// ----------------------------------------------------------------

	/**
	 * @return array<string,array<string,mixed>>  Manifests keyed by package name.
	 */
	private function resolveManifests(?string $package): array {
		if ($package === null) {
			return $this->locator->discover();
		}
		$one = $this->locator->discoverPackage($package);
		return $one === null ? [] : [$package => $one];
	}

	/**
	 * Derive the exit code from the resulting file statuses.
	 *
	 * 4 (conflict): local_modified, unknown_existing.
	 * 3 (drift):    missing, update_available, placeholder_drift.
	 * 0 (clean):    up_to_date, create_only_present.
	 * Any other status is treated as a general error (1).
	 *
	 * @param  array<string,mixed>  $plan
	 */
	private function exitCodeFor(array $plan): int {
		$worst    = ExitCode::OK->value;
		$anyError = false;

		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			foreach ((array)($pkg['files'] ?? []) as $file) {
				$code = match ((string)($file['status'] ?? '')) {
					'local_modified', 'unknown_existing'               => ExitCode::CONFLICT->value,
					'missing', 'update_available', 'placeholder_drift' => ExitCode::DRIFT->value,
					'up_to_date', 'create_only_present'                => ExitCode::OK->value,
					default                                            => -1,
				};
				if ($code === -1) {
					$anyError = true;
					continue;
				}
				if ($code > $worst) {
					$worst = $code;
				}
			}
		}

		return $anyError ? ExitCode::GENERAL_ERROR->value : $worst;
	}


	// ----------------------------------------------------------------
	// Output
	// ----------------------------------------------------------------

	/** @param array<string,mixed> $plan */
	private function emitText(array $plan, int $exit): void {
		$out = '';
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
		if ($out === '') {
			$out = "No CitOmni scaffold manifests found.\n";
		}
		$out .= \sprintf("\nResult: %s (exit %d)\n", $this->summaryLabel($exit), $exit);
		\fwrite(\STDOUT, $out);
	}

	/** @param array<string,mixed> $plan */
	private function emitJson(array $plan, int $exit): void {
		$packages = [];
		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			$files = [];
			foreach ((array)($pkg['files'] ?? []) as $file) {
				$files[] = [
					'target' => (string)($file['target'] ?? ''),
					'type'   => (string)($file['type'] ?? ''),
					'policy' => (string)($file['policy'] ?? ''),
					'status' => (string)($file['status'] ?? ''),
					'reason' => (string)($file['reason'] ?? ''),
					'action' => (string)($file['action'] ?? 'none'),
				];
			}
			$packages[] = ['name' => (string)($pkg['name'] ?? ''), 'files' => $files];
		}

		$payload = ['ok' => $exit === ExitCode::OK->value, 'exit_code' => $exit, 'packages' => $packages];
		\fwrite(\STDOUT, InstallerCli::encodeJson($payload) . "\n");
	}

	private function summaryLabel(int $exit): string {
		return match ($exit) {
			ExitCode::OK->value       => 'up to date',
			ExitCode::DRIFT->value    => 'updates available',
			ExitCode::CONFLICT->value => 'conflicts / local changes',
			default => 'error',
		};
	}


	// ----------------------------------------------------------------
	// Terminal results
	// ----------------------------------------------------------------

	private function emptyResult(string $format, ?string $package): int {
		if ($package !== null) {
			return $this->fail($format, "Package '{$package}' is not installed or has no CitOmni scaffold manifest.");
		}
		if ($format === 'json') {
			\fwrite(\STDOUT, InstallerCli::encodeJson(['ok' => true, 'exit_code' => ExitCode::OK->value, 'packages' => []]) . "\n");
		} else {
			\fwrite(\STDOUT, "No CitOmni scaffold manifests found.\n");
		}
		return ExitCode::OK->value;
	}

	private function fail(string $format, string $message): int {
		if ($format === 'json') {
			\fwrite(\STDOUT, InstallerCli::encodeJson([
				'ok'        => false,
				'exit_code' => ExitCode::GENERAL_ERROR->value,
				'error'     => $message,
				'packages'  => [],
			]) . "\n");
		} else {
			\fwrite(\STDERR, $message . "\n");
		}
		return ExitCode::GENERAL_ERROR->value;
	}
}
