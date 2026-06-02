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

namespace CitOmni\Installer\Cli;

use CitOmni\Installer\Cli\Command\DoctorCommand;
use CitOmni\Installer\Cli\Command\StatusCommand;
use CitOmni\Installer\Cli\Command\InstallCommand;
use CitOmni\Installer\Cli\Command\RepairCommand;
use CitOmni\Installer\Cli\Command\SyncCommand;
use CitOmni\Installer\Enum\ExitCode;
use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\ComposerPackageLocator;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Exception\InstallerException;

/**
 * Minimal dispatcher for the installer commands (doctor, status, install, repair, sync).
 *
 * Responsibilities:
 * - Route argv to a command, or print usage/help.
 * - Build the collaborators (PathGuard, locator, state, resolver, and for the
 *   write commands a plan builder + applier) once and inject them into the
 *   selected command.
 * - Provide a single shared option parser and JSON encoder so the commands stay
 *   "arg parsing + output ONLY".
 *
 * Notes:
 * - This class is intentionally not framework-aware: it does not boot CitOmni and
 *   has no service map. Collaborators are created with `new`/factory helpers.
 * - The command classes never write app files themselves; only ApplyScaffoldPlan
 *   (injected into the write commands) may mutate files or state.
 */
final class InstallerCli {

	/** Commands handled by this layer. */
	private const COMMANDS = ['doctor', 'status', 'install', 'repair', 'sync'];

	public function __construct(private readonly string $appRoot) {}


	// ----------------------------------------------------------------
	// Dispatch
	// ----------------------------------------------------------------

	/**
	 * Parse argv, route to a command, and return the process exit code.
	 *
	 * @param  array<int,string>  $argv  Raw argv (index 0 is the script name).
	 * @return int  Exit code per the installer contract (0,1,2,3,4,5,6).
	 */
	public function run(array $argv): int {
		$args = \array_values(\array_slice($argv, 1));

		// -- 1. No command, or top-level help -------------------------
		if ($args === [] || $args[0] === '--help' || $args[0] === '-h' || $args[0] === 'help') {
			\fwrite(\STDOUT, $this->topUsage());
			return ExitCode::OK->value;
		}

		$name = $args[0];
		if (\str_starts_with($name, '-')) {
			\fwrite(\STDERR, "Expected a command before options. Try 'citomni-installer --help'.\n");
			return ExitCode::USAGE_ERROR->value;
		}

		if (!\in_array($name, self::COMMANDS, true)) {
			\fwrite(\STDERR, "Unknown command '{$name}'. Expected one of: " . \implode(', ', self::COMMANDS) . ".\n");
			\fwrite(\STDERR, $this->topUsage());
			return ExitCode::USAGE_ERROR->value;
		}

		$cmdArgs = \array_values(\array_slice($args, 1));

		// -- 2. Command help needs no app environment -----------------
		if (\in_array('--help', $cmdArgs, true) || \in_array('-h', $cmdArgs, true)) {
			\fwrite(\STDOUT, $this->commandUsage($name));
			return ExitCode::OK->value;
		}

		// -- 3. Build read-only collaborators -------------------------
		try {
			$pathGuard   = new PathGuard($this->appRoot);
			$appRoot     = $pathGuard->appRoot();
			$resolver    = new PlaceholderResolver($appRoot);
		} catch (InstallerException $e) {
			\fwrite(\STDERR, $e->getMessage() . "\n");
			return ExitCode::IO_ERROR->value;
		}

		$locator = ComposerPackageLocator::forAppRoot($appRoot);
		$state   = ScaffoldState::forAppRoot($appRoot);

		// -- 4. Dispatch ----------------------------------------------
		try {
			switch ($name) {
				case 'doctor':
					return (new DoctorCommand($appRoot, $pathGuard, $locator, $state, $resolver))->run($cmdArgs);
				case 'status':
					$builder = new BuildScaffoldPlan($pathGuard, new ScaffoldRenderer(), $state);
					return (new StatusCommand($locator, $builder, $resolver))->run($cmdArgs);
				case 'install':
				case 'repair':
				case 'sync':
					// Write commands share one renderer between the plan builder and the
					// applier. The applier is the ONLY collaborator allowed to touch disk.
					$renderer = new ScaffoldRenderer();
					$builder  = new BuildScaffoldPlan($pathGuard, $renderer, $state);
					$applier  = new ApplyScaffoldPlan($pathGuard, $renderer, $state);
					$command  = match ($name) {
						'install' => new InstallCommand($locator, $builder, $applier, $resolver),
						'repair'  => new RepairCommand($locator, $builder, $applier, $resolver),
						default   => new SyncCommand($locator, $builder, $applier, $resolver),
					};
					return $command->run($cmdArgs);
			}
		} catch (\Throwable $e) {
			// Boundary catch: known domain errors are handled inside the commands
			// and mapped to specific exit codes; anything else surfaces as a
			// generic failure here rather than a stack trace.
			\fwrite(\STDERR, 'Unexpected error: ' . $e->getMessage() . "\n");
			return ExitCode::GENERAL_ERROR->value;
		}

		return ExitCode::GENERAL_ERROR->value; // Unreachable: COMMANDS guard above is exhaustive.
	}


	// ----------------------------------------------------------------
	// Shared helpers (used by the commands)
	// ----------------------------------------------------------------

	/**
	 * Parse the common CLI grammar shared by all installer commands.
	 *
	 * Recognised: --package=<vendor/name>, --format=text|json,
	 * --placeholder=KEY=VALUE (repeatable), --force, --dry-run, --help/-h.
	 * Positional arguments are rejected here (only sync/diff accept them).
	 *
	 * @param  array<int,string>  $args  Arguments after the command name.
	 * @return array{ok:true,options:array{package:?string,format:string,placeholders:array<string,string>,force:bool,dry_run:bool,help:bool}}|array{ok:false,error:string}
	 */
	public static function parseCommonOptions(array $args): array {
		$options = [
			'package'      => null,
			'format'       => 'text',
			'placeholders' => [],
			'force'        => false,
			'dry_run'      => false,
			'help'         => false,
		];

		foreach ($args as $arg) {
			if ($arg === '--help' || $arg === '-h') {
				$options['help'] = true;
				continue;
			}
			if ($arg === '--force') {
				$options['force'] = true;
				continue;
			}
			if ($arg === '--dry-run') {
				$options['dry_run'] = true;
				continue;
			}
			if (\str_starts_with($arg, '--format=')) {
				$value = \substr($arg, 9);
				if ($value !== 'text' && $value !== 'json') {
					return self::usageError("Invalid value for --format: '{$value}' (expected 'text' or 'json').");
				}
				$options['format'] = $value;
				continue;
			}
			if (\str_starts_with($arg, '--package=')) {
				$value = \substr($arg, 10);
				if ($value === '' || \preg_match('#^[^/\s]+/[^/\s]+$#', $value) !== 1) {
					return self::usageError("Invalid value for --package: '{$value}' (expected 'vendor/name').");
				}
				$options['package'] = $value;
				continue;
			}
			if (\str_starts_with($arg, '--placeholder=')) {
				$pair = \substr($arg, 14);
				$eq   = \strpos($pair, '=');
				if ($eq === false || $eq === 0) {
					return self::usageError("Malformed --placeholder (expected KEY=VALUE): '{$pair}'.");
				}
				$key = \substr($pair, 0, $eq);
				$val = \substr($pair, $eq + 1);
				if (\preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
					return self::usageError("Invalid placeholder key '{$key}' (expected [A-Z][A-Z0-9_]*).");
				}
				$options['placeholders'][$key] = $val;
				continue;
			}

			return self::usageError("Unknown or unexpected argument: '{$arg}'.");
		}

		return ['ok' => true, 'options' => $options];
	}

	/**
	 * Encode a payload as stable, human-diffable JSON for machine consumers.
	 *
	 * @param  array<string,mixed>  $data
	 * @return string  Pretty JSON without a trailing newline.
	 */
	public static function encodeJson(array $data): string {
		$json = \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
		return $json === false
			? '{"ok":false,"exit_code":' . ExitCode::GENERAL_ERROR->value . ',"error":"json_encode_failed"}'
			: $json;
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/** @return array{ok:false,error:string} */
	private static function usageError(string $message): array {
		return ['ok' => false, 'error' => $message];
	}

	private function topUsage(): string {
		return <<<TXT
citomni-installer — CitOmni scaffold installer (read-only commands)

Usage:
  citomni-installer <command> [options]

Commands:
  doctor    Validate environment, manifests, installer config and write access.
  status    Report scaffold state per package.
  install   Create missing scaffold files and record their baseline.
  repair    Recreate missing files from recorded state.
  sync      Update managed files forward to the current baseline.

Global options:
  --package=<vendor/name>   Limit to a single package.
  --format=text|json        Output format (default: text).
  --placeholder=KEY=VALUE   Provide a placeholder value (repeatable; overrides config).
  --force                   Overwrite existing files (write commands; backs up first).
  --dry-run                 Preview without writing (write commands).
  --help, -h                Show help.

Run 'citomni-installer <command> --help' for command-specific help.

TXT;
	}

	private function commandUsage(string $name): string {
		return match ($name) {
			'doctor' => <<<TXT
citomni-installer doctor — read-only environment validation

Usage:
  citomni-installer doctor [--package=<vendor/name>] [--format=text|json]

Checks app-root, vendor/, Composer metadata, scaffold manifests, the installer
config (config/citomni_installer.php) and write access. If a state file exists it
is read to confirm its format_version is supported. Nothing is created or written.

Exit codes:
  0  ok            1  manifest/config error
  5  unsafe state  6  IO/permission error      2  invalid usage

TXT,
			'status' => <<<TXT
citomni-installer status — read-only scaffold status

Usage:
  citomni-installer status [--package=<vendor/name>] [--format=text|json]
                           [--placeholder=KEY=VALUE ...]

Reports per file: missing, up_to_date, update_available (stub drift),
placeholder_drift, local_modified, unknown_existing, create_only_present.

Placeholder values are resolved from config/citomni_installer.php and then
overridden by any --placeholder options. Drift detection renders managed stubs,
so a stub that contains a token with no resolved value is an error — provide it
via config or --placeholder.

Exit codes:
  0  up to date           3  updates available
  4  conflicts/local mod   1  error                  2  invalid usage

TXT,
			'install' => <<<TXT
citomni-installer install — first materialization for a package

Usage:
  citomni-installer install [--package=<vendor/name>] [--format=text|json]
                            [--placeholder=KEY=VALUE ...] [--force] [--dry-run]

Creates missing managed and create-only files from the current stubs and records
their baseline (stub + rendered checksums, policy, placeholder snapshot) in the
state file. Existing files are NOT overwritten unless --force is given, in which
case the previous file is backed up first. Use --dry-run to preview.

Exit codes:
  0  applied / nothing to do   4  conflicts (existing files in the way)
  6  IO/permission error       1  error                  2  invalid usage

TXT,
			'repair' => <<<TXT
citomni-installer repair — recreate missing files from recorded state

Usage:
  citomni-installer repair [--package=<vendor/name>] [--format=text|json]
                           [--placeholder=KEY=VALUE ...] [--dry-run]

Recreates only files that are MISSING on disk, using the placeholders recorded in
the state file, then refreshes their baseline. Existing files are never touched and
--force has no effect. Warns when the recorded stub differs from the current stub.
This is not a restore: it does not bring back historical bytes.

Exit codes:
  0  applied / nothing to do   6  IO/permission error
  1  error                     2  invalid usage

TXT,
			'sync' => <<<TXT
citomni-installer sync — controlled update of managed files

Usage:
  citomni-installer sync [target] [--package=<vendor/name>] [--format=text|json]
                         [--placeholder=KEY=VALUE ...] [--force] [--dry-run]

Moves managed files forward to the current stub/placeholders, but only while the
file on disk still matches its recorded baseline. Locally modified files are never
overwritten: a sibling <target>.new is written instead, unless --force is given
(which backs up the existing file first). An optional positional [target] limits
the run to a single app-relative file. Use --dry-run to preview.

Exit codes:
  0  up to date / applied      4  conflicts / .new written (manual action)
  6  IO/permission error       1  error                  2  invalid usage

TXT,
			default => $this->topUsage(),
		};
	}
}
