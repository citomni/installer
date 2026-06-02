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
use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\Support\ComposerPackageLocator;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Exception\InstallerException;

/**
 * Shared transport layer for the write commands (install, repair, sync).
 *
 * The three write commands are identical at the CLI boundary: parse the common
 * option grammar, resolve manifests + placeholders, ask BuildScaffoldPlan for a
 * plan, hand that plan to ApplyScaffoldPlan, then map the apply result to an exit
 * code and render it. The only per-command differences live in the engine
 * (the command verb passed to BuildScaffoldPlan) and in whether a positional
 * [target] is accepted (sync only). Those two seams are the abstract/overridable
 * hooks below; everything else is inherited so the verbs cannot drift apart.
 *
 * Boundaries:
 * - This class never writes app files or state. ApplyScaffoldPlan is the only
 *   collaborator that may mutate disk; this class only shapes input and output.
 * - --dry-run is forwarded to the applier (which still computes the same plan and
 *   therefore the same exit code, just without touching disk).
 * - --force is forwarded to the plan builder. install honours it (forced
 *   overwrite + backup); sync honours it; repair's decision graph ignores it.
 *
 * Exit codes (per the installer contract, derived from the apply result):
 * - 0  every actionable file was created/updated/registered, or nothing to do.
 * - 4  at least one file requires manual action: install refused an existing file
 *      (conflict), or sync wrote a sibling <target>.new instead of overwriting.
 * - 1  at least one file failed to apply (stale plan, internal plan error, or a
 *      per-file IO failure). Per-file error detail is in the rendered output.
 * - 6  the apply step itself raised (e.g. the state file could not be written).
 * - 2  invalid usage / arguments (from the shared option parser).
 */
abstract class AbstractWriteCommand {

	public function __construct(
		private readonly ComposerPackageLocator $locator,
		private readonly BuildScaffoldPlan $builder,
		private readonly ApplyScaffoldPlan $applier,
		private readonly PlaceholderResolver $placeholders
	) {}


	// ----------------------------------------------------------------
	// Per-command hooks
	// ----------------------------------------------------------------

	/** The verb handed to BuildScaffoldPlan ('install', 'repair', 'sync'). */
	abstract protected function commandName(): string;

	/** Whether a single positional [target] is accepted (sync only). */
	protected function acceptsPositionalTarget(): bool {
		return false;
	}


	// ----------------------------------------------------------------
	// Run
	// ----------------------------------------------------------------

	/**
	 * Build and apply the scaffold plan, then return the process exit code.
	 *
	 * @param  array<int,string>  $args  Arguments after the command name.
	 * @return int  Exit code per the installer contract.
	 */
	public function run(array $args): int {
		// -- 1. Positional target (sync only) -------------------------
		$target = null;
		if ($this->acceptsPositionalTarget()) {
			[$target, $args] = $this->extractPositionalTarget($args);
		}

		// -- 2. Shared option grammar ---------------------------------
		$parsed = InstallerCli::parseCommonOptions($args);
		if ($parsed['ok'] !== true) {
			\fwrite(\STDERR, $parsed['error'] . "\n");
			return ExitCode::USAGE_ERROR->value;
		}
		$opt    = $parsed['options'];
		$format = $opt['format'];

		// -- 3. Manifests ---------------------------------------------
		try {
			$manifests = $this->resolveManifests($opt['package']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), ExitCode::GENERAL_ERROR->value);
		}
		if ($manifests === []) {
			return $this->emptyResult($format, $opt['package']);
		}

		// -- 4. Placeholders (config + CLI overrides) -----------------
		try {
			$resolved = $this->placeholders->resolve($opt['placeholders']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), ExitCode::GENERAL_ERROR->value);
		}
		$placeholders = [];
		foreach (\array_keys($manifests) as $pkgName) {
			$placeholders[$pkgName] = $resolved;
		}

		// -- 5. Build the plan ----------------------------------------
		$buildOptions = ['force' => $opt['force']];
		if ($target !== null) {
			$buildOptions['target'] = $target;
		}
		try {
			$plan = $this->builder->build($this->commandName(), $manifests, $placeholders, $buildOptions);
		} catch (InstallerException $e) {
			return $this->fail(
				$format,
				$e->getMessage()
				. "\nHint: rendering managed stubs requires every token to resolve. Provide the "
				. 'missing value(s) via config/citomni_installer.php or --placeholder=KEY=VALUE.',
				1
			);
		}

		// -- 6. Apply the plan (the only writer) ----------------------
		try {
			$result = $this->applier->apply($plan, ['dry_run' => $opt['dry_run']]);
		} catch (InstallerException $e) {
			// The applier swallows per-file failures; reaching here means the apply
			// step itself failed (e.g. the state file could not be persisted).
			return $this->fail($format, $e->getMessage(), ExitCode::IO_ERROR->value);
		}

		// -- 7. Emit + exit -------------------------------------------
		$exit = $this->exitCodeFor($result);
		if ($format === 'json') {
			$this->emitJson($result, $exit);
		} else {
			$this->emitText($result, $exit);
		}
		return $exit;
	}


	// ----------------------------------------------------------------
	// Input shaping
	// ----------------------------------------------------------------

	/**
	 * Split the first non-option token off as the positional target.
	 *
	 * Any further positional tokens are left in place so the shared option parser
	 * rejects them as unexpected arguments (exit 2).
	 *
	 * @param  array<int,string>  $args
	 * @return array{0:?string,1:array<int,string>}
	 */
	private function extractPositionalTarget(array $args): array {
		$target = null;
		$rest   = [];
		foreach ($args as $arg) {
			if ($target === null && !\str_starts_with($arg, '-')) {
				$target = $arg;
				continue;
			}
			$rest[] = $arg;
		}
		return [$target, $rest];
	}

	/**
	 * @return array<string,array<string,mixed>>
	 * @throws InstallerException
	 */
	private function resolveManifests(?string $package): array {
		if ($package === null) {
			return $this->locator->discover();
		}
		$one = $this->locator->discoverPackage($package);
		return $one === null ? [] : [$package => $one];
	}


	// ----------------------------------------------------------------
	// Exit code
	// ----------------------------------------------------------------

	/**
	 * Map the apply result to an exit code.
	 *
	 * Precedence: a hard failure (1) outranks a manual-action outcome (4), which
	 * outranks success (0). 'failed' is intentionally mapped to the generic error
	 * code rather than 6, because it conflates IO, stale-plan, and internal-plan
	 * causes; an IO failure that is unambiguously IO (the apply throw) is mapped
	 * to 6 in run().
	 */
	private function exitCodeFor(array $result): int {
		$hasFailure  = false;
		$hasConflict = false;
		foreach ((array)($result['packages'] ?? []) as $pkg) {
			foreach ((array)($pkg['files'] ?? []) as $file) {
				switch ((string)($file['applied'] ?? '')) {
					case 'failed':
						$hasFailure = true;
						break;
					case 'conflict':
					case 'wrote_new':
						$hasConflict = true;
						break;
				}
			}
		}
		if ($hasFailure) {
			return ExitCode::GENERAL_ERROR->value;
		}
		if ($hasConflict) {
			return ExitCode::CONFLICT->value;
		}
		return ExitCode::OK->value;
	}


	// ----------------------------------------------------------------
	// Output
	// ----------------------------------------------------------------

	private function emitText(array $result, int $exit): void {
		$command = (string)($result['command'] ?? $this->commandName());
		$dryRun  = (bool)($result['dry_run'] ?? false);

		$out = 'citomni-installer ' . $command . ($dryRun ? ' (dry-run)' : '') . "\n";
		$any = false;
		foreach ((array)($result['packages'] ?? []) as $pkg) {
			$any  = true;
			$out .= (string)($pkg['name'] ?? '') . "\n";
			foreach ((array)($pkg['files'] ?? []) as $file) {
				$out .= \rtrim(\sprintf(
					"  %-32s %-12s %s",
					(string)($file['target'] ?? ''),
					(string)($file['applied'] ?? ''),
					(string)($file['reason'] ?? '')
				)) . "\n";
				if (isset($file['new_path'])) {
					$out .= '      wrote:  ' . (string)$file['new_path'] . "\n";
				}
				if (isset($file['backup_path'])) {
					$out .= '      backup: ' . (string)$file['backup_path'] . "\n";
				}
				if (isset($file['error'])) {
					$out .= '      error:  ' . (string)$file['error'] . "\n";
				}
			}
		}
		if (!$any) {
			$out .= "No scaffold files matched.\n";
		}
		if (!empty($result['backup_dir'])) {
			$out .= "\nBackups: " . (string)$result['backup_dir'] . "\n";
		}
		$out .= \sprintf("\nResult: %s (exit %d)\n", $this->summaryLabel($exit, $dryRun), $exit);

		\fwrite(\STDOUT, $out);
	}

	private function emitJson(array $result, int $exit): void {
		$packages = [];
		foreach ((array)($result['packages'] ?? []) as $pkg) {
			$files = [];
			foreach ((array)($pkg['files'] ?? []) as $file) {
				$files[] = [
					'target'      => (string)($file['target'] ?? ''),
					'type'        => (string)($file['type'] ?? ''),
					'policy'      => (string)($file['policy'] ?? ''),
					'status'      => (string)($file['status'] ?? ''),
					'reason'      => (string)($file['reason'] ?? ''),
					'action'      => (string)($file['action'] ?? 'none'),
					'applied'     => (string)($file['applied'] ?? ''),
					'backup_path' => isset($file['backup_path']) ? (string)$file['backup_path'] : null,
					'new_path'    => isset($file['new_path']) ? (string)$file['new_path'] : null,
					'error'       => isset($file['error']) ? (string)$file['error'] : null,
				];
			}
			$packages[] = [
				'name'              => (string)($pkg['name'] ?? ''),
				'installed_version' => (string)($pkg['installed_version'] ?? 'unknown'),
				'files'             => $files,
			];
		}

		$payload = [
			'ok'            => $exit === ExitCode::OK->value,
			'exit_code'     => $exit,
			'command'       => (string)($result['command'] ?? $this->commandName()),
			'dry_run'       => (bool)($result['dry_run'] ?? false),
			'backup_dir'    => $result['backup_dir'] ?? null,
			'state_written' => (bool)($result['state_written'] ?? false),
			'packages'      => $packages,
		];

		\fwrite(\STDOUT, InstallerCli::encodeJson($payload) . "\n");
	}

	private function summaryLabel(int $exit, bool $dryRun): string {
		$label = match ($exit) {
			0       => 'ok',
			4       => 'conflicts / manual action required',
			default => 'error',
		};
		return $dryRun ? $label . ' (no changes written)' : $label;
	}


	// ----------------------------------------------------------------
	// Early returns (no plan was applied)
	// ----------------------------------------------------------------

	private function emptyResult(string $format, ?string $package): int {
		if ($package !== null) {
			return $this->fail($format, "Package '{$package}' is not installed or has no CitOmni scaffold manifest.", ExitCode::GENERAL_ERROR->value);
		}
		if ($format === 'json') {
			\fwrite(\STDOUT, InstallerCli::encodeJson([
				'ok'        => true,
				'exit_code' => ExitCode::OK->value,
				'command'   => $this->commandName(),
				'packages'  => [],
			]) . "\n");
		} else {
			\fwrite(\STDOUT, "No CitOmni scaffold manifests found.\n");
		}
		return ExitCode::OK->value;
	}

	private function fail(string $format, string $message, int $exit): int {
		if ($format === 'json') {
			\fwrite(\STDOUT, InstallerCli::encodeJson([
				'ok'        => false,
				'exit_code' => $exit,
				'command'   => $this->commandName(),
				'error'     => $message,
				'packages'  => [],
			]) . "\n");
		} else {
			\fwrite(\STDERR, $message . "\n");
		}
		return $exit;
	}
}
