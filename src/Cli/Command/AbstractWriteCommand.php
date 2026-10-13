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
use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldManifestLocator;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Exception\FilesystemException;
use CitOmni\Installer\Exception\ConflictException;
use CitOmni\Installer\Support\InstallerLock;

/**
 * Shared transport, locking, reporting, and lifecycle flow for write commands.
 *
 * Repair and sync use the inherited scaffold flow. Install and environment use
 * ApplyEnvironmentMaterialization and share the lock and output helpers here.
 * Every real write command holds the application lock from before discovery/state
 * reads through scaffold writes, Composer when applicable, and state persistence.
 * Dry runs do not acquire the lock or write any application files.
 *
 * Commands that maintain the installer block in .gitignore plan it from every
 * discovered manifest, also when --package narrows the scaffold files. Create-only
 * targets the application omits in config/citomni_installer.php are resolved against
 * every discovered manifest as well, and are planned as `omitted`.
 *
 * Exit codes:
 * - 0: Every actionable file succeeded, or nothing needs changing.
 * - 1: General validation, planning, Composer, or internal apply failure.
 * - 2: Invalid usage or arguments.
 * - 4: A conflict, stale plan, competing writer, or generated .new needs attention.
 * - 5: The command cannot safely read recorded scaffold state.
 * - 6: A filesystem operation failed, including a per-file apply failure.
 */
abstract class AbstractWriteCommand {

	public function __construct(
		protected readonly ScaffoldManifestLocator $locator,
		protected readonly BuildScaffoldPlan $builder,
		protected readonly ApplyScaffoldPlan $applier,
		protected readonly PlaceholderResolver $placeholders,
		protected readonly ScaffoldState $state,
		protected readonly InstallerLock $lock
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

	/** Whether the inherited flow maintains the .gitignore block when no positional target narrows it (sync only). */
	protected function maintainsGitignore(): bool {
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
		$opt = $parsed['options'];
		if ($opt['environment'] !== null) {
			return $this->fail($opt['format'], '--environment is not accepted by ' . $this->commandName() . '.', ExitCode::USAGE_ERROR->value);
		}
		return $this->withWriteLock($opt, fn(): int => $this->runScaffold($opt, $target));
	}

	/** Run the parsed lifecycle command while the caller holds the write lock. */
	private function runScaffold(array $opt, ?string $target): int {
		$format = $opt['format'];

		// -- 3. Manifests ---------------------------------------------
		try {
			[$discovered, $manifests] = $this->resolveManifests($opt['package']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), $this->exceptionExitCode($e));
		}
		if ($manifests === []) {
			return $this->emptyResult($format, $opt['package']);
		}

		try {
			$environment = $this->state->environment();
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), ExitCode::UNSAFE_STATE->value);
		}

		if (!$environment instanceof Environment) {
			return $this->fail(
				$format,
				'No materialized environment is recorded. Run install --environment=<dev|stage|prod> first.',
				ExitCode::GENERAL_ERROR->value
			);
		}

		$manifests = $this->locator->selectEnvironment($manifests, $environment);

		// -- 4. Placeholders (config + CLI overrides) -----------------
		try {
			$placeholders = $this->resolvePlaceholders($manifests, $opt['placeholders']);
		} catch (InstallerException $e) {
			return $this->fail($format, $e->getMessage(), $this->exceptionExitCode($e));
		}

		// -- 5. Build the plan ----------------------------------------
		$buildOptions = ['force' => $opt['force']];
		if ($target !== null) {
			$buildOptions['target'] = $target;
		} elseif ($this->maintainsGitignore()) {
			$buildOptions['gitignore'] = $this->locator->gitignoreRules($discovered);
		}
		try {
			$buildOptions['omit'] = $this->locator->omissions($discovered)['omitted'];
			$plan = $this->builder->build($this->commandName(), $manifests, $placeholders, $buildOptions);
		} catch (InstallerException $e) {
			return $this->fail(
				$format,
				$e->getMessage(),
				$this->exceptionExitCode($e)
			);
		}

		$confirmExit = $this->confirmForcedPlanIfNeeded($plan, $format, $opt);
		if ($confirmExit !== null) {
			return $confirmExit;
		}

		// -- 6. Apply scaffold files and state ----------------------
		try {
			$result = $this->applier->apply($plan, ['dry_run' => $opt['dry_run']]);
		} catch (InstallerException $e) {
			// The applier swallows per-file failures; reaching here means the apply
			// step itself failed (e.g. the state file could not be persisted).
			return $this->fail($format, $e->getMessage(), $this->exceptionExitCode($e));
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


	/**
	 * Hold the shared application lock across all reads, writes, and finalization.
	 *
	 * @param array<string,mixed> $opt Parsed options.
	 * @param callable():int $run Command body, after transport validation.
	 * @return int Command result or a mapped lock error.
	 */
	protected function withWriteLock(array $opt, callable $run): int {
		if ($opt['dry_run']) {
			return $run();
		}
		try {
			$this->lock->acquire();
		} catch (InstallerException $e) {
			return $this->fail($opt['format'], $e->getMessage(), $this->exceptionExitCode($e));
		}
		try {
			return $run();
		} finally {
			$this->lock->release();
		}
	}

	/** Map transport-independent failures to the documented CLI exit codes. */
	protected function exceptionExitCode(InstallerException $e): int {
		return match (true) {
			$e instanceof FilesystemException => ExitCode::IO_ERROR->value,
			$e instanceof ConflictException => ExitCode::CONFLICT->value,
			default => ExitCode::GENERAL_ERROR->value,
		};
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
	protected function extractPositionalTarget(array $args): array {
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
	 * Discover every scaffold manifest once and select the requested package.
	 *
	 * Discovery validates global target ownership before the package filter applies.
	 *
	 * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>}
	 *         [all discovered manifests, manifests selected by --package].
	 * @throws InstallerException
	 */
	protected function resolveManifests(?string $package): array {
		$discovered = $this->locator->discover();
		if ($package === null) {
			return [$discovered, $discovered];
		}
		return [$discovered, isset($discovered[$package]) ? [$package => $discovered[$package]] : []];
	}


	/**
	 * Resolve one placeholder map and apply it to every selected package.
	 *
	 * @param array<string,array<string,mixed>> $manifests Planner-ready manifests.
	 * @param array<string,string> $overrides CLI placeholder overrides.
	 * @return array<string,array<string,string>> Placeholders keyed by package name.
	 * @throws InstallerException When placeholder resolution fails.
	 */
	protected function resolvePlaceholders(array $manifests, array $overrides): array {
		$resolved = $this->placeholders->resolve($overrides);
		$out = [];

		foreach (\array_keys($manifests) as $pkgName) {
			$out[$pkgName] = $resolved;
		}

		return $out;
	}


	/**
	 * Ask for confirmation before applying destructive forced overwrites.
	 *
	 * @param  array<string,mixed>  $plan
	 * @param  array<string,mixed>  $opt
	 * @return ?int  Null when execution may continue; otherwise an exit code.
	 */
	protected function confirmForcedPlanIfNeeded(array $plan, string $format, array $opt): ?int {
		if (empty($opt['force']) || !empty($opt['force_confirmed']) || !empty($opt['dry_run'])) {
			return null;
		}

		$count = $this->countForcedOverwrites($plan);
		if ($count === 0) {
			return null;
		}

		if ($format === 'json') {
			return $this->fail(
				$format,
				'Interactive confirmation is not available with --format=json. Use --force=yes to confirm forced overwrites.',
				ExitCode::USAGE_ERROR->value
			);
		}

		if (!\defined('STDIN') || !\is_resource(\STDIN)) {
			return $this->fail(
				$format,
				'Interactive confirmation is not available. Use --force=yes to confirm forced overwrites.',
				ExitCode::USAGE_ERROR->value
			);
		}

		\fwrite(\STDOUT, \sprintf(
			"Force will overwrite %d existing file%s and create backup%s.\n",
			$count,
			$count === 1 ? '' : 's',
			$count === 1 ? '' : 's'
		));
		\fwrite(\STDOUT, "Type 'yes' to continue: ");

		$answer = \fgets(\STDIN);
		if (\strtolower(\trim((string)$answer)) !== 'yes') {
			return $this->fail($format, 'Cancelled by user.', ExitCode::GENERAL_ERROR->value);
		}

		return null;
	}

	/**
	 * Count planned entries that force-overwrite existing files.
	 *
	 * @param  array<string,mixed>  $plan
	 * @return int
	 */
	protected function countForcedOverwrites(array $plan): int {
		$count = 0;

		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			foreach ((array)($pkg['files'] ?? []) as $file) {
				if ((string)($file['reason'] ?? '') === 'forced_overwrite' || !empty($file['backup'])) {
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * Return the conflicting plan entries in the public JSON file shape.
	 *
	 * Used when a command stops before applying, so the report still names every
	 * blocking target and its reason.
	 *
	 * @param  array<string,mixed>  $plan
	 * @return list<array{name:string,installed_version:string,files:list<array<string,string>>}>
	 */
	protected function conflictPackages(array $plan): array {
		$packages = [];

		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			$files = [];
			foreach ((array)($pkg['files'] ?? []) as $file) {
				if (($file['action'] ?? null) !== 'conflict') {
					continue;
				}
				$files[] = [
					'target' => (string)($file['target'] ?? ''),
					'type'   => (string)($file['type'] ?? ''),
					'policy' => (string)($file['policy'] ?? ''),
					'status' => (string)($file['status'] ?? ''),
					'reason' => (string)($file['reason'] ?? ''),
					'action' => 'conflict',
				];
			}
			if ($files !== []) {
				$packages[] = [
					'name'              => (string)($pkg['name'] ?? ''),
					'installed_version' => (string)($pkg['installed_version'] ?? 'unknown'),
					'files'             => $files,
				];
			}
		}

		return $packages;
	}







	// ----------------------------------------------------------------
	// Exit code
	// ----------------------------------------------------------------

	/**
	 * Map the apply result to an exit code.
	 *
	 * Precedence: Filesystem failures (6), general failures (1), conflicts (4), success (0).
	 * Per-file error_type preserves the filesystem/conflict distinction. The .gitignore
	 * block outcome counts like a file. State-read validation is mapped separately by
	 * the caller to unsafe state (5).
	 */
	protected function exitCodeFor(array $result): int {
		$hasIoFailure = false;
		$hasFailure  = false;
		$hasConflict = false;
		$packages = (array)($result['packages'] ?? []);
		if (\is_array($result['gitignore'] ?? null)) {
			$packages[] = ['files' => [$result['gitignore']]];
		}
		foreach ($packages as $pkg) {
			foreach ((array)($pkg['files'] ?? []) as $file) {
				switch ((string)($file['applied'] ?? '')) {
					case 'failed':
						$hasIoFailure = $hasIoFailure || ($file['error_type'] ?? null) === 'io';
						$hasFailure = true;
						break;
					case 'conflict':
						$hasConflict = true;
						break;
					case 'wrote_new':
						$advisoryCreateOnly = (string)($result['command'] ?? '') === 'migrate'
							&& (string)($file['policy'] ?? '') === 'create-only';
						$hasConflict = $hasConflict || !$advisoryCreateOnly;
						break;
				}
			}
		}
		$phpLint = $result['migration']['php_lint'] ?? null;
		if (\is_array($phpLint) && (($phpLint['available'] ?? true) !== true || (int)($phpLint['failed'] ?? 0) > 0)) {
			$hasConflict = true;
		}
		if ($hasIoFailure) {
			return ExitCode::IO_ERROR->value;
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

	protected function emitText(array $result, int $exit): void {
		$command = (string)($result['command'] ?? $this->commandName());
		$dryRun  = (bool)($result['dry_run'] ?? false);

		$out = 'citomni-installer ' . $command . ($dryRun ? ' (dry-run)' : '') . "\n";
		if (isset($result['environment'])) {
			$out .= 'Environment: ' . (string)$result['environment'] . "\n";
		}
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
				if (isset($file['new_status'])) {
					$out .= '      sidecar: ' . (string)$file['new_status'] . "\n";
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
		$out .= $this->gitignoreText($result['gitignore'] ?? null);
		if (!empty($result['backup_dir'])) {
			$out .= "\nBackups: " . (string)$result['backup_dir'] . "\n";
		}
		if (isset($result['composer'])) {
			$composer = $result['composer'];
			$out .= \sprintf(
				"\nComposer: classmap-authoritative=%s; dump-autoload --no-scripts (%s).\n",
				$composer['classmap_authoritative'] ? 'true' : 'false',
				$composer['applied'] ? 'applied' : ($dryRun ? 'planned' : 'not applied')
			);
		}
		if (isset($result['migration']) && \is_array($result['migration'])) {
			$migration = $result['migration'];
			$blocking = \count((array)($migration['blocking_manual_review'] ?? []));
			$appSkeleton = \is_array($migration['app_skeleton'] ?? null) ? $migration['app_skeleton'] : [];
			$advisoryScaffold = \count((array)($migration['advisory_manual_review'] ?? []));
			$advisory = $advisoryScaffold + \count((array)($appSkeleton['manual_review'] ?? []));
			$out .= \sprintf("\nMigration review: blocking=%d; advisory=%d.\n", $blocking, $advisory);
			$phpLint = \is_array($migration['php_lint'] ?? null) ? $migration['php_lint'] : [];
			if ($phpLint !== []) {
				if (($phpLint['available'] ?? false) === true) {
					$out .= \sprintf(
						"PHP lint: checked=%d; passed=%d; failed=%d.\n",
						(int)($phpLint['checked'] ?? 0),
						(int)($phpLint['passed'] ?? 0),
						(int)($phpLint['failed'] ?? 0)
					);
				} else {
					$out .= 'PHP lint: unavailable - ' . (string)($phpLint['error'] ?? 'unknown error') . "\n";
				}
			}
			$sourceWarnings = \is_array($migration['scaffold_source_warnings'] ?? null) ? $migration['scaffold_source_warnings'] : [];
			if ((int)($sourceWarnings['file_count'] ?? 0) > 0) {
				$out .= \sprintf(
					"Scaffold source warnings: percent-style files=%d; tokens=%d (advisory).\n",
					(int)($sourceWarnings['file_count'] ?? 0),
					(int)($sourceWarnings['token_count'] ?? 0)
				);
			}
			if (($appSkeleton['available'] ?? true) !== true) {
				$out .= 'App-skeleton audit: unavailable - ' . (string)($appSkeleton['error'] ?? 'unknown error') . "\n";
			}
			if (!empty($result['migration_report'])) {
				$out .= 'Migration report: ' . (string)$result['migration_report'] . "\n";
			} elseif (!empty($result['migration_report_error'])) {
				$out .= 'Migration report: unavailable - ' . (string)$result['migration_report_error'] . "\n";
			} elseif ($dryRun) {
				$out .= "Migration report: not written in dry-run.\n";
			}
		}
		$out .= \sprintf("\nResult: %s (exit %d)\n", $this->summaryLabel($exit, $dryRun), $exit);

		\fwrite(\STDOUT, $out);
	}

	protected function emitJson(array $result, int $exit): void {
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
					'new_status'  => isset($file['new_status']) ? (string)$file['new_status'] : null,
					'error_type'  => isset($file['error_type']) ? (string)$file['error_type'] : null,
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
			'environment'   => isset($result['environment']) ? (string)$result['environment'] : null,
			'composer'      => isset($result['composer']) && \is_array($result['composer']) ? $result['composer'] : null,
			'packages'      => $packages,
			'gitignore'     => $this->gitignoreJson($result['gitignore'] ?? null),
		];
		if (isset($result['migration']) && \is_array($result['migration'])) {
			$payload['migration'] = $result['migration'];
			$payload['migration_report'] = $result['migration_report'] ?? null;
			$payload['migration_report_error'] = $result['migration_report_error'] ?? null;
		}

		\fwrite(\STDOUT, InstallerCli::encodeJson($payload) . "\n");
	}

	protected function summaryLabel(int $exit, bool $dryRun): string {
		$label = match ($exit) {
			0       => 'ok',
			4       => 'conflicts / manual action required',
			default => 'error',
		};
		return $dryRun ? $label . ' (no changes written)' : $label;
	}

	/**
	 * Render the .gitignore block outcome for text output.
	 *
	 * Newly ignored scaffold targets are listed because Git keeps tracking files it
	 * already tracks; they need a one-time `git rm --cached`.
	 *
	 * @param  mixed  $block  Block result from ApplyScaffoldPlan, or null when not planned.
	 * @return string         Text section, or '' when there is nothing to report.
	 */
	protected function gitignoreText(mixed $block): string {
		if (!\is_array($block)) {
			return '';
		}

		$applied = (string)($block['applied'] ?? '');
		$out = "\n.gitignore block\n" . \rtrim(\sprintf(
			"  %-32s %-12s %s",
			(string)($block['target'] ?? ''),
			$applied,
			(string)($block['reason'] ?? '')
		)) . "\n";
		if (isset($block['error'])) {
			$out .= '      error:  ' . (string)$block['error'] . "\n";
		}

		$newlyIgnored = (array)($block['newly_ignored'] ?? []);
		if (($applied === 'created' || $applied === 'updated') && $newlyIgnored !== []) {
			foreach ($newlyIgnored as $target) {
				$out .= '      now ignored: ' . (string)$target . "\n";
			}
			$out .= "      Git keeps tracking files it already tracks. Untrack them once: git rm --cached --ignore-unmatch -- <path>...\n";
		}

		return $out;
	}

	/**
	 * Shape a planned or applied .gitignore block entry for JSON output.
	 *
	 * @param  mixed  $block  Block entry from BuildScaffoldPlan or ApplyScaffoldPlan, or null.
	 * @return array<string,mixed>|null  Public fields; `applied` is null before apply.
	 */
	protected function gitignoreJson(mixed $block): ?array {
		if (!\is_array($block)) {
			return null;
		}

		return [
			'target'        => (string)($block['target'] ?? ''),
			'status'        => (string)($block['status'] ?? ''),
			'reason'        => (string)($block['reason'] ?? ''),
			'action'        => (string)($block['action'] ?? 'none'),
			'applied'       => isset($block['applied']) ? (string)$block['applied'] : null,
			'newly_ignored' => \array_values(\array_map('strval', (array)($block['newly_ignored'] ?? []))),
			'error_type'    => isset($block['error_type']) ? (string)$block['error_type'] : null,
			'error'         => isset($block['error']) ? (string)$block['error'] : null,
		];
	}

	/**
	 * Stop before applying when the planned .gitignore block cannot be written.
	 *
	 * Install and migrate commit scaffold state and Composer posture only after a fully
	 * successful apply. Malformed markers in an app-owned file must not leave them half
	 * done, so these commands check the block first, like the environment gate.
	 *
	 * @param  array<string,mixed>  $plan  Built plan.
	 * @param  string  $format  Output format.
	 * @return int|null  Conflict exit code after reporting, or null when the block is writable.
	 */
	protected function failOnGitignoreConflict(array $plan, string $format): ?int {
		$block = $plan['gitignore'] ?? null;
		if (!\is_array($block) || ($block['action'] ?? null) !== 'conflict') {
			return null;
		}

		return $this->fail(
			$format,
			\ucfirst($this->commandName()) . ' stopped before writing: ' . (string)($block['error'] ?? 'the .gitignore block cannot be updated.'),
			ExitCode::CONFLICT->value,
			[],
			$this->gitignoreJson($block)
		);
	}


	// ----------------------------------------------------------------
	// Early returns (no plan was applied)
	// ----------------------------------------------------------------

	protected function emptyResult(string $format, ?string $package): int {
		if ($package !== null) {
			return $this->fail($format, "Package '{$package}' is not installed or has no CitOmni scaffold manifest.", ExitCode::GENERAL_ERROR->value);
		}
		if ($format === 'json') {
			\fwrite(\STDOUT, InstallerCli::encodeJson([
				'ok'        => true,
				'exit_code' => ExitCode::OK->value,
				'command'   => $this->commandName(),
				'packages'  => [],
				'gitignore' => null,
			]) . "\n");
		} else {
			\fwrite(\STDOUT, "No CitOmni scaffold manifests found.\n");
		}
		return ExitCode::OK->value;
	}

	/**
	 * Report a command that stopped before applying a plan.
	 *
	 * @param  string  $format    Output format.
	 * @param  string  $message   Human-readable reason, self-contained for both formats.
	 * @param  int     $exit      Exit code.
	 * @param  list<array<string,mixed>>  $packages  Blocking plan entries for JSON, from conflictPackages().
	 * @param  array<string,mixed>|null   $gitignore  Blocking .gitignore block entry for JSON, from gitignoreJson().
	 * @return int  The exit code.
	 */
	protected function fail(string $format, string $message, int $exit, array $packages = [], ?array $gitignore = null): int {
		if ($format === 'json') {
			\fwrite(\STDOUT, InstallerCli::encodeJson([
				'ok'        => false,
				'exit_code' => $exit,
				'command'   => $this->commandName(),
				'error'     => $message,
				'packages'  => $packages,
				'gitignore' => $gitignore,
			]) . "\n");
		} else {
			\fwrite(\STDERR, $message . "\n");
		}
		return $exit;
	}
}
