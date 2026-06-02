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
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\ComposerPackageLocator;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Exception\InstallerException;

/**
 * `doctor` — read-only environment validation.
 *
 * Validates, without creating or modifying anything:
 * - app-root and vendor/ presence,
 * - Composer metadata (installed.json),
 * - scaffold manifests (discovery also validates each manifest),
 * - the installer config (config/citomni_installer.php) via PlaceholderResolver,
 * - write access to the app-root and the var/ tree,
 * - the installer state file's format_version, if a state file exists.
 *
 * The exit code summarises the worst finding; the report lists every check.
 */
final class DoctorCommand {

	public function __construct(
		private readonly string $appRoot,
		private readonly PathGuard $pathGuard,
		private readonly ComposerPackageLocator $locator,
		private readonly ScaffoldState $state,
		private readonly PlaceholderResolver $placeholders
	) {}

	/**
	 * @param  array<int,string>  $args  Arguments after the command name.
	 * @return int  Exit code (0 ok; 1 manifest/config; 5 unsafe state; 6 IO; 2 usage).
	 */
	public function run(array $args): int {
		$parsed = InstallerCli::parseCommonOptions($args);
		if ($parsed['ok'] !== true) {
			\fwrite(\STDERR, $parsed['error'] . "\n");
			return ExitCode::USAGE_ERROR->value;
		}

		$format  = $parsed['options']['format'];
		$package = $parsed['options']['package'];

		/** @var list<array{name:string,status:string,detail:string}> $checks */
		$checks    = [];
		/** @var list<int> $failCodes */
		$failCodes = [];

		// -- 1. app-root (already validated by PathGuard) -------------
		$checks[] = ['name' => 'app_root', 'status' => 'ok', 'detail' => $this->appRoot];

		// -- 2. vendor/ ----------------------------------------------
		$vendor   = $this->appRoot . '/vendor';
		$vendorOk = \is_dir($vendor);
		$checks[] = $vendorOk
			? ['name' => 'vendor_dir', 'status' => 'ok', 'detail' => $vendor]
			: ['name' => 'vendor_dir', 'status' => 'fail', 'detail' => "Not found: {$vendor}"];
		if (!$vendorOk) {
			$failCodes[] = 6;
		}

		// -- 3. Composer metadata + scaffold manifests ----------------
		if (!$vendorOk) {
			$checks[] = ['name' => 'composer_metadata', 'status' => 'skip', 'detail' => 'Skipped (vendor/ missing).'];
			$checks[] = ['name' => 'scaffold_manifests', 'status' => 'skip', 'detail' => 'Skipped (vendor/ missing).'];
		} else {
			$installed = $vendor . '/composer/installed.json';
			if (!\is_file($installed) || !\is_readable($installed)) {
				$checks[]    = ['name' => 'composer_metadata', 'status' => 'fail', 'detail' => "Missing or unreadable: {$installed}"];
				$failCodes[] = ExitCode::IO_ERROR->value;
				$checks[]    = ['name' => 'scaffold_manifests', 'status' => 'skip', 'detail' => 'Skipped (composer metadata unavailable).'];
			} else {
				$checks[] = ['name' => 'composer_metadata', 'status' => 'ok', 'detail' => $installed];
				try {
					$manifests = $package !== null
						? (($one = $this->locator->discoverPackage($package)) === null ? [] : [$package => $one])
						: $this->locator->discover();

					if ($manifests === []) {
						$detail = $package !== null
							? "No scaffold manifest for package '{$package}'."
							: 'No CitOmni scaffold manifests found among installed packages.';
						$checks[] = ['name' => 'scaffold_manifests', 'status' => 'warn', 'detail' => $detail];
					} else {
						$parts = [];
						foreach ($manifests as $pkgName => $manifest) {
							$count   = \count((array)($manifest['files'] ?? []));
							$parts[] = "{$pkgName} ({$count} file" . ($count === 1 ? '' : 's') . ')';
						}
						$checks[] = ['name' => 'scaffold_manifests', 'status' => 'ok', 'detail' => \implode(', ', $parts)];
					}
				} catch (InstallerException $e) {
					$checks[]    = ['name' => 'scaffold_manifests', 'status' => 'fail', 'detail' => $e->getMessage()];
					$failCodes[] = ExitCode::GENERAL_ERROR->value;
				}
			}
		}

		// -- 4. Installer config (config/citomni_installer.php) -------
		$configPath = $this->appRoot . '/config/citomni_installer.php';
		if (!\is_file($configPath)) {
			$checks[] = ['name' => 'installer_config', 'status' => 'ok', 'detail' => "No installer config present (config + CLI only): {$configPath}"];
		} else {
			try {
				$resolved = $this->placeholders->resolve([]);
				$checks[] = ['name' => 'installer_config', 'status' => 'ok', 'detail' => \sprintf('Loaded %d placeholder(s) from %s', \count($resolved), $configPath)];
			} catch (InstallerException $e) {
				$checks[]    = ['name' => 'installer_config', 'status' => 'fail', 'detail' => $e->getMessage()];
				$failCodes[] = 1;
			}
		}

		// -- 5. Write access -----------------------------------------
		[$writeStatus, $writeDetail, $writeCode] = $this->checkWriteAccess();
		$checks[] = ['name' => 'write_access', 'status' => $writeStatus, 'detail' => $writeDetail];
		if ($writeCode !== ExitCode::OK->value) {
			$failCodes[] = $writeCode;
		}

		// -- 6. State file (read-only; never created) -----------------
		if ($this->state->exists()) {
			try {
				$this->state->read();
				$checks[] = ['name' => 'state_file', 'status' => 'ok', 'detail' => 'Readable, format_version supported: ' . $this->state->path()];
			} catch (InstallerException $e) {
				$checks[]    = ['name' => 'state_file', 'status' => 'fail', 'detail' => $e->getMessage()];
				$failCodes[] = 5;
			}
		} else {
			$checks[] = ['name' => 'state_file', 'status' => 'ok', 'detail' => 'No state file yet (created on first install): ' . $this->state->path()];
		}

		$exit = $this->exitCode($failCodes);
		if ($format === 'json') {
			$this->emitJson($checks, $exit);
		} else {
			$this->emitText($checks, $exit);
		}
		return $exit;
	}


	// ----------------------------------------------------------------
	// Checks
	// ----------------------------------------------------------------

	/**
	 * Verify the app-root and the var/ write targets are writable.
	 *
	 * Read-only: nothing is created; the nearest existing ancestor of each target
	 * directory is tested with is_writable().
	 *
	 * @return array{0:string,1:string,2:int}  [status, detail, exitCodeContribution]
	 */
	private function checkWriteAccess(): array {
		$targets = [
			$this->appRoot,
			$this->safeResolve('var/state/citomni'),
			$this->safeResolve('var/backups/citomni-installer'),
		];

		$bad = [];
		foreach ($targets as $abs) {
			if ($abs === null) {
				continue;
			}
			$dir = $this->nearestExistingDir($abs);
			if ($dir === null || !\is_writable($dir)) {
				$bad[] = $abs;
			}
		}

		return $bad === []
			? ['ok', 'App-root and var/ tree are writable.', ExitCode::OK->value]
			: ['fail', 'Not writable: ' . \implode(', ', $bad), ExitCode::IO_ERROR->value];
	}

	private function safeResolve(string $relative): ?string {
		try {
			return $this->pathGuard->resolveTarget($relative);
		} catch (InstallerException) {
			return null;
		}
	}

	private function nearestExistingDir(string $abs): ?string {
		$dir = $abs;
		while (!\is_dir($dir)) {
			$parent = \dirname($dir);
			if ($parent === $dir) {
				return null;
			}
			$dir = $parent;
		}
		return $dir;
	}

	/**
	 * Reduce the collected fail codes to a single exit code.
	 *
	 * Precedence (most blocking first): 6 IO/permission, 5 unsafe state,
	 * 1 manifest/config error. No failures -> 0.
	 *
	 * @param  list<int>  $failCodes
	 */
	private function exitCode(array $failCodes): int {
		foreach ([ExitCode::IO_ERROR->value, ExitCode::UNSAFE_STATE->value, ExitCode::GENERAL_ERROR->value] as $code) {
			if (\in_array($code, $failCodes, true)) {
				return $code;
			}
		}
		return ExitCode::OK->value;
	}


	// ----------------------------------------------------------------
	// Output
	// ----------------------------------------------------------------

	/** @param list<array{name:string,status:string,detail:string}> $checks */
	private function emitText(array $checks, int $exit): void {
		$out = "citomni-installer doctor\n";
		foreach ($checks as $c) {
			$tag = match ($c['status']) {
				'ok'    => '[ OK ]',
				'warn'  => '[WARN]',
				'fail'  => '[FAIL]',
				'skip'  => '[SKIP]',
				default => '[ ?? ]',
			};
			$out .= \sprintf("  %s %-20s %s\n", $tag, $c['name'], $c['detail']);
		}
		$out .= \sprintf("\nResult: %s (exit %d)\n", $exit === ExitCode::OK->value ? 'OK' : 'problems found', $exit);
		\fwrite(\STDOUT, $out);
	}

	/** @param list<array{name:string,status:string,detail:string}> $checks */
	private function emitJson(array $checks, int $exit): void {
		$payload = [
			'ok'        => $exit === ExitCode::OK->value,
			'exit_code' => $exit,
			'checks'    => \array_map(
				static fn(array $c): array => ['name' => $c['name'], 'status' => $c['status'], 'detail' => $c['detail']],
				$checks
			),
		];
		\fwrite(\STDOUT, InstallerCli::encodeJson($payload) . "\n");
	}
}
