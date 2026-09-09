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

namespace CitOmni\Installer\Operation;

use CitOmni\Installer\Support\AtomicFileWriter;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Util\Checksum;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Exception\FilesystemException;
use CitOmni\Installer\Exception\ConflictException;

/**
 * Execute scaffold actions, verify plan snapshots, and optionally persist state.
 *
 * BuildScaffoldPlan owns the decision graph and returns a pure array describing what
 * should happen per file. ApplyScaffoldPlan is the only place those decisions become
 * filesystem effects. It verifies planned source/target bytes, materializes files,
 * writes `.new` sidecars, backs up before replacement, and persists scaffold state.
 *
 * Behavior:
 * - Reads the current state once up front (precondition). If state cannot be read
 *   safely it throws here, before any write happens (contract: "state unsafe ->
 *   fail without write").
 * - Per plan action:
 *     1) create / update -> render the stub with the recorded placeholders, write the
 *        target atomically, stage a state entry.
 *     2) update with `backup_required` (forced overwrite) -> copy the existing target
 *        to var/backups/citomni-installer/<ts>/<target> atomically, THEN overwrite.
 *     3) write_new -> render and write `<target>.new` atomically; state is NOT updated
 *        (the recorded baseline must keep pointing at the prior rendered bytes).
 *     4) register_state -> no file write; re-resolve the target, re-render the source, and
 *        confirm the current disk bytes still match the freshly rendered baseline before
 *        staging a state entry (adopt-clean-baseline). The recorded checksums are the fresh
 *        ones, so an adopted baseline is verified against disk at apply time, not trusted
 *        from plan time.
 *     5) none / conflict -> no write. Environment no-ops still verify their snapshots.
 * - Re-resolves every write destination through PathGuard at the write site, so the
 *   "never write outside app-root / never write to /vendor/" invariant is enforced
 *   at the point of mutation, not only at plan time.
 * - Stale-plan guards compare the rendered checksum and observed target path,
 *   existence, and bytes. Changes since planning become per-file conflicts. The CLI
 *   additionally holds InstallerLock; callers using this engine directly own locking.
 *   These checks do not provide filesystem transactions against unrelated editors.
 * - State is merged into the existing state (siblings preserved) and written once,
 *   atomically, at the end of a successful run that produced at least one state change.
 *
 * Failure model (deliberate: best-effort with a full report):
 * - A per-file IO/internal error is caught, recorded as applied = "failed" on that
 *   file, and the run continues. This is the right shape for an installer: a single
 *   unwritable file must not abort materialization of the others, and because each
 *   write is independently atomic, partial application is already the reality. State
 *   is persisted for successful entries when persist_state is enabled. Environment
 *   materialization defers that commit until every file and Composer succeeds.
 *   Stale targets/plans are reported as conflicts. Hard preconditions (unsafe state) are
 *   NOT caught here and propagate so the command layer can map them to exit 5.
 *
 * Atomicity:
 * - Every write (materialized file, `.new`, backup, state) goes through a temp file in
 *   the destination directory, fflush + fsync where available, then rename into place.
 *   rename is atomic on POSIX and modern Windows.
 *
 * Notes:
 * - This class never runs Composer, never fetches over the network, and never restores
 *   historical bytes; it only renders the current stub forward.
 * - Line endings are whatever the stub ships (LF, pinned via .gitattributes upstream);
 *   the renderer does not alter them and neither does the writer.
 * - The atomic-write/ensure-dir logic lives in Support\AtomicFileWriter, shared with
 *   ScaffoldState so both go through one temp+fsync+rename implementation.
 *
 * @see \CitOmni\Installer\Operation\BuildScaffoldPlan  Produces the plan consumed here.
 */
final class ApplyScaffoldPlan {

	/** Namespace segment under var/backups/ for this installer's backups. */
	private const BACKUP_NS = 'citomni-installer';

	public function __construct(
		private readonly PathGuard $pathGuard,
		private readonly ScaffoldRenderer $renderer,
		private readonly ScaffoldState $state
	) {}


	// ----------------------------------------------------------------
	// Public entry point
	// ----------------------------------------------------------------

	/**
	 * Apply a plan produced by BuildScaffoldPlan.
	 *
	 * @param  array  $plan     Plan array: {command, force, packages[]} where each file
	 *                          carries action/status/policy and an optional `_apply` block.
	 * @param  array  $options  {dry_run?: bool, persist_state?: bool}. In dry-run, stubs are still rendered and
	 *                          validated (so problems surface) but nothing is written.
	 * @return array  Result: {command, dry_run, ok, backup_dir, state_written, packages[]},
	 *                where each file gains an `applied` outcome (created|updated|wrote_new|
	 *                registered|skipped|conflict|failed) plus optional backup_path/new_path/error.
	 * @throws InstallerException  If the existing state file is present but unreadable/unsafe,
	 *                             or if the final state write fails.
	 */
	public function apply(array $plan, array $options = []): array {
		$dryRun = (bool)($options['dry_run'] ?? false);
		$persistState = (bool)($options['persist_state'] ?? true);
		$command = (string)($plan['command'] ?? '');
		$migration = $command === 'migrate';

		// -- 1. Precondition: existing state must be safe before we touch anything --------
		// Normal lifecycle commands require current state. Migration accepts only a known
		// v1 state (or no state after an interrupted attempt) and rebuilds baselines from
		// current manifests, never from the legacy package map.
		$legacyVersion = $migration ? $this->state->legacyVersionForMigration() : null;
		$statePackages = $migration ? [] : $this->state->readPackages();

		$ts  = $this->timestamp();
		$now = $this->nowIso8601();
		$backupReserved = false;
		$needsBackupRoot = $legacyVersion !== null;
		if (!$needsBackupRoot) {
			foreach ($plan['packages'] as $package) {
				foreach ($package['files'] as $file) {
					if (!empty($file['_apply']['backup_required'])) {
						$needsBackupRoot = true;
						break 2;
					}
				}
			}
		}

		if (!$dryRun && $needsBackupRoot) {
			$root = $this->pathGuard->resolveTarget('var/backups/' . self::BACKUP_NS . '/' . $ts);
			// mkdir must create this run's leaf, never reuse an earlier run.
			if (!@\mkdir($root, 0775, true)) {
				throw new FilesystemException('Unable to reserve a unique backup directory: ' . $root);
			}
			$backupReserved = true;
		}

		$stateBackupPath = null;
		if ($legacyVersion !== null) {
			$statePath = $this->state->path();
			$stateBackupPath = $this->backupPathFor(ScaffoldState::RELATIVE_PATH, $ts);
			if (!$dryRun) {
				$legacyBytes = \file_get_contents($statePath);
				if ($legacyBytes === false) {
					throw new FilesystemException('Unable to read legacy state for backup: ' . $statePath);
				}
				$this->backupExisting($statePath, $stateBackupPath, Checksum::sha256($legacyBytes));
				if (!@\unlink($statePath)) {
					throw new FilesystemException('Unable to remove legacy state after backup: ' . $statePath);
				}
				if (\function_exists('opcache_invalidate')) {
					@\opcache_invalidate($statePath, true);
				}
			}
		}

		$resultPackages = [];
		$stateDirty     = false;
		$anyBackup      = false;
		$ok             = true;

		// -- 2. Walk the plan; apply each file independently ------------------------------
		foreach ((array)($plan['packages'] ?? []) as $pkg) {
			$pkgName          = (string)($pkg['name'] ?? '');
			$installedVersion = (string)($pkg['installed_version'] ?? 'unknown');
			$resultFiles      = [];

			foreach ((array)($pkg['files'] ?? []) as $file) {
				$res   = $this->applyFile((array)$file, $dryRun, $ts, $now);
				$entry = $res['entry'];
				$resultFiles[] = $entry;

				$applied = (string)($entry['applied'] ?? '');
				if ($applied === 'failed' || $applied === 'conflict' || $applied === 'wrote_new') {
					$ok = false;
				}
				if (isset($entry['backup_path'])) {
					$anyBackup = true;
				}

				if ($res['state'] !== null) {
					$statePackages = $this->mergeStateEntry($statePackages, $pkgName, $installedVersion, $res['state']);
					$stateDirty    = true;
				}
			}

			$resultPackages[] = [
				'name'              => $pkgName,
				'installed_version' => $installedVersion,
				'files'             => $resultFiles,
			];
		}

		// -- 3. Persist state once (only if something actually changed) -------------------
		$stateWritten = false;
		if (!$dryRun && $persistState && $stateDirty) {
			$this->state->writePackages($statePackages);
			$stateWritten = true;
		}

		$result = [
			'command'       => $command,
			'dry_run'       => $dryRun,
			'ok'            => $ok,
			'backup_dir'    => ($anyBackup || $backupReserved || $stateBackupPath !== null) ? $this->backupRoot($ts) : null,
			'state_written' => $stateWritten,
			'packages'      => $resultPackages,
		];

		if (!$persistState) {
			$result['_state_packages'] = $statePackages;
		}

		return $result;
	}


	// ----------------------------------------------------------------
	// Per-file dispatch
	// ----------------------------------------------------------------

	/**
	 * Apply a single plan file entry.
	 *
	 * @return array{entry: array, state: array|null}  `state` is a {target, data} pair to
	 *         merge into the package state, or null when the action does not change state.
	 */
	private function applyFile(array $file, bool $dryRun, string $ts, string $now): array {
		$action = (string)($file['action'] ?? 'none');
		$base   = [
			'target' => (string)($file['target'] ?? ''),
			'type'   => (string)($file['type'] ?? ''),
			'policy' => (string)($file['policy'] ?? ''),
			'status' => (string)($file['status'] ?? ''),
			'reason' => (string)($file['reason'] ?? ''),
			'action' => $action,
		];

		// Ordinary no-ops have no snapshot; environment no-ops must still be verified.
		if ($action === 'none' && !isset($file['_apply'])) {
			return ['entry' => $base + ['applied' => 'skipped'], 'state' => null];
		}
		if ($action === 'conflict') {
			return ['entry' => $base + ['applied' => 'conflict'], 'state' => null];
		}

		$apply = $file['_apply'] ?? null;
		if (!\is_array($apply)) {
			return ['entry' => $base + ['applied' => 'failed', 'error' => 'plan entry is actionable but carries no _apply block'], 'state' => null];
		}

		try {
			switch ($action) {
				case 'none':
					$this->assertTargetFresh($apply);
					[, $renderedCk] = $this->renderForApply($apply['source_abs'], $apply['placeholders']);
					$this->assertPlanFresh($apply['target'], $renderedCk, $apply['rendered_checksum']);
					return ['entry' => $base + ['applied' => 'skipped'], 'state' => null];
				case 'create':
				case 'update':
					return $this->applyWrite($base, $apply, $action, $dryRun, $ts, $now);
				case 'write_new':
					return $this->applyWriteNew($base, $apply, $dryRun);
				case 'register_state':
					return $this->applyRegister($base, $apply, $now);
				default:
					return ['entry' => $base + ['applied' => 'failed', 'error' => "unknown plan action '{$action}'"], 'state' => null];
			}
		} catch (InstallerException $e) {
			// Per-file recoverable failure: report and let the run continue (see class docblock).
			return [
				'entry' => $base + [
					'applied' => $e instanceof ConflictException ? 'conflict' : 'failed',
					'error_type' => $e instanceof FilesystemException ? 'io' : ($e instanceof ConflictException ? 'conflict' : 'general'),
					'error' => $e->getMessage(),
				],
				'state' => null,
			];
		}
	}


	// ----------------------------------------------------------------
	// Action handlers
	// ----------------------------------------------------------------

	/**
	 * Materialize a managed/create-only target (create or update), backing up first when forced.
	 */
	private function applyWrite(array $base, array $apply, string $action, bool $dryRun, string $ts, string $now): array {
		$normTarget      = (string)($apply['target'] ?? '');
		$source          = (string)($apply['source'] ?? '');
		$sourceAbs       = (string)($apply['source_abs'] ?? '');
		$placeholders    = (array)($apply['placeholders'] ?? []);
		$plannedRendered = (string)($apply['rendered_checksum'] ?? '');
		$backupRequired  = !empty($apply['backup_required']);

		// Authoritative write destination, re-validated under app-root at the write site.
		$targetAbs = $this->assertTargetFresh($apply);

		// Render forward + guard against a stale plan.
		[$stubCk, $renderedCk, $bytes] = $this->renderForApply($sourceAbs, $placeholders);
		$this->assertPlanFresh($normTarget, $renderedCk, $plannedRendered);

		// Forced overwrite: back up the current bytes before clobbering them.
		$backupPath = null;
		if ($backupRequired && \is_file($targetAbs)) {
			$backupPath = $this->backupPathFor($normTarget, $ts);
			if (!$dryRun) {
				$this->backupExisting($targetAbs, $backupPath, $apply['target_checksum']);
			}
		}

		if (!$dryRun) {
			$targetAbs = $this->assertTargetFresh($apply);
			AtomicFileWriter::write($targetAbs, $bytes);
		}

		$entry = $base + ['applied' => ($action === 'create' ? 'created' : 'updated')];
		if ($backupPath !== null) {
			$entry['backup_path'] = $backupPath;
		}

		return [
			'entry' => $entry,
			'state' => [
				'target' => $normTarget,
				'data'   => $this->buildStateData($base['type'], $base['policy'], $source, $placeholders, $stubCk, $renderedCk, $now),
			],
		];
	}

	/**
	 * Write a `<target>.new` sidecar without touching the live file or the recorded baseline.
	 *
	 * write_new is chosen by the plan when a managed target is locally modified (or an
	 * unknown existing file does not match the current render) and --force was not given.
	 * State is intentionally left unchanged so the next run still reports the local edit.
	 */
	private function applyWriteNew(array $base, array $apply, bool $dryRun): array {
		$normTarget      = (string)($apply['target'] ?? '');
		$sourceAbs       = (string)($apply['source_abs'] ?? '');
		$placeholders    = (array)($apply['placeholders'] ?? []);
		$plannedRendered = (string)($apply['rendered_checksum'] ?? '');

		$targetAbs = $this->pathGuard->resolveTarget($normTarget);
		$newPath   = $targetAbs . '.new';

		[, $renderedCk, $bytes] = $this->renderForApply($sourceAbs, $placeholders);
		$this->assertPlanFresh($normTarget, $renderedCk, $plannedRendered);

		if (!$dryRun) {
			AtomicFileWriter::write($newPath, $bytes);
		}

		return [
			'entry' => $base + ['applied' => 'wrote_new', 'new_path' => $newPath],
			'state' => null, // write_new never updates state
		];
	}

	/**
	 * Adopt an existing, clean file as the recorded baseline (no file write).
	 *
	 * register_state is the only action that records state about an on-disk file without
	 * rewriting it, so the "recorded baseline always matches disk" invariant has to be
	 * re-established here at apply time rather than trusted from the plan:
	 * - Re-resolve the target through PathGuard (consistent with the create/update write site).
	 * - Require the file to still exist.
	 * - Re-render the source forward and apply the same stale-plan guard used elsewhere, so a
	 *   stub/placeholder change between build and apply fails the adoption instead of recording
	 *   a baseline the user never saw.
	 * - Confirm the current disk bytes still match the freshly rendered baseline; if the file
	 *   changed since the plan was built, fail rather than record a baseline that already lies.
	 * The recorded checksums are the fresh ones (rendered_checksum == hash(disk) by the check
	 * above; stub_checksum is the current stub, so future stub-drift detection stays correct).
	 */
	private function applyRegister(array $base, array $apply, string $now): array {
		$normTarget      = (string)($apply['target'] ?? '');
		$source          = (string)($apply['source'] ?? '');
		$sourceAbs       = (string)($apply['source_abs'] ?? '');
		$placeholders    = (array)($apply['placeholders'] ?? []);
		$plannedRendered = (string)($apply['rendered_checksum'] ?? '');

		// Authoritative path, re-validated under app-root at the (state) write site.
		$targetAbs = $this->assertTargetFresh($apply);
		if (!\is_file($targetAbs)) {
			throw new ConflictException(\sprintf(
				"Cannot adopt baseline for '%s': the file no longer exists on disk.",
				$normTarget
			));
		}

		// Re-render forward and refuse a stale plan (same guard as create/update/write_new).
		[$stubCk, $renderedCk] = $this->renderForApply($sourceAbs, $placeholders);
		$this->assertPlanFresh($normTarget, $renderedCk, $plannedRendered);

		// Re-verify the adopt-clean condition against current disk bytes at apply time.
		$disk = \file_get_contents($targetAbs);
		if ($disk === false) {
			throw new FilesystemException(\sprintf("Cannot read target for baseline adoption: '%s'.", $normTarget));
		}
		if (!Checksum::matches($disk, $renderedCk)) {
			throw new ConflictException(\sprintf(
				"Cannot adopt baseline for '%s': disk bytes no longer match the rendered baseline "
				. '(the file changed since the plan was built). Re-run the plan.',
				$normTarget
			));
		}

		return [
			'entry' => $base + ['applied' => 'registered'],
			'state' => [
				'target' => $normTarget,
				'data'   => $this->buildStateData($base['type'], $base['policy'], $source, $placeholders, $stubCk, $renderedCk, $now),
			],
		];
	}


	// ----------------------------------------------------------------
	// State assembly
	// ----------------------------------------------------------------

	/**
	 * Build a single file's state record per contract §5.
	 *
	 * managed     -> stores both stub_checksum and rendered_checksum (drift baseline).
	 * create-only -> stores stub_checksum as a diagnostic only; rendered_checksum is
	 *                omitted because create-only files have no upstream-drift semantics.
	 */
	private function buildStateData(string $type, string $policy, string $source, array $placeholders, string $stubCk, string $renderedCk, string $now): array {
		$data = [
			'source' => $source,
			'type'   => $type,
			'policy' => $policy,
		];
		if ($policy === 'managed') {
			$data['stub_checksum']     = $stubCk;
			$data['rendered_checksum'] = $renderedCk;
		} elseif ($stubCk !== '') {
			$data['stub_checksum'] = $stubCk; // diagnostic only
		}
		$data['placeholders'] = $placeholders;
		$data['installed_at'] = $now;
		return $data;
	}

	/**
	 * Merge one file record into the package state, preserving sibling files.
	 */
	private function mergeStateEntry(array $statePackages, string $pkgName, string $installedVersion, array $stateUpdate): array {
		if (!isset($statePackages[$pkgName]) || !\is_array($statePackages[$pkgName])) {
			$statePackages[$pkgName] = ['installed_version' => $installedVersion, 'files' => []];
		}
		if (!isset($statePackages[$pkgName]['files']) || !\is_array($statePackages[$pkgName]['files'])) {
			$statePackages[$pkgName]['files'] = [];
		}
		$statePackages[$pkgName]['installed_version']             = $installedVersion;
		$statePackages[$pkgName]['files'][$stateUpdate['target']] = $stateUpdate['data'];
		return $statePackages;
	}


	// ----------------------------------------------------------------
	// Rendering & integrity
	// ----------------------------------------------------------------

	/**
	 * Render the stub forward and return [stub_checksum, rendered_checksum, rendered_bytes].
	 */
	private function renderForApply(string $sourceAbs, array $placeholders): array {
		$stub       = $this->renderer->readStub($sourceAbs);
		$stubCk     = Checksum::sha256($stub);
		$rendered   = $this->renderer->render($stub, $placeholders);
		$renderedCk = Checksum::sha256($rendered);
		return [$stubCk, $renderedCk, $rendered];
	}

	/**
	 * Recheck the exact target observed by the plan before writing or adopting it.
	 *
	 * @param array<string,mixed> $apply Internal plan snapshot.
	 * @return string Current guarded absolute target path.
	 * @throws ConflictException When the target path, existence, or bytes changed.
	 * @throws FilesystemException When an existing target cannot be read.
	 */
	private function assertTargetFresh(array $apply): string {
		$path = $this->pathGuard->resolveTarget($apply['target']);
		$plannedPath = \str_replace('\\', '/', $apply['target_abs']);
		$currentPath = \str_replace('\\', '/', $path);
		if (\PHP_OS_FAMILY === 'Windows') {
			$plannedPath = \strtolower($plannedPath);
			$currentPath = \strtolower($currentPath);
		}
		if ($plannedPath !== $currentPath) {
			throw new ConflictException('Target path changed since planning: ' . $apply['target']);
		}
		\clearstatcache(true, $path);
		if (!$apply['target_exists']) {
			if (\file_exists($path) || \is_link($path)) {
				throw new ConflictException('Target appeared since planning: ' . $apply['target']);
			}
			return $path;
		}
		if (!\is_file($path)) {
			throw new ConflictException('Target disappeared or changed type since planning: ' . $apply['target']);
		}
		$bytes = \file_get_contents($path);
		if ($bytes === false) {
			throw new FilesystemException('Unable to read target before apply: ' . $apply['target']);
		}
		if (!Checksum::matches($bytes, $apply['target_checksum'])) {
			throw new ConflictException('Target bytes changed since planning. Re-run the command: ' . $apply['target']);
		}
		return $path;
	}


	/**
	 * Refuse to write when the freshly rendered bytes no longer match the planned baseline.
	 */
	private function assertPlanFresh(string $normTarget, string $renderedCk, string $plannedRendered): void {
		if ($plannedRendered !== '' && !Checksum::equals($renderedCk, $plannedRendered)) {
			throw new ConflictException(\sprintf(
				"Plan is stale for '%s': the rendered output no longer matches the planned baseline "
				. '(the stub or resolved placeholders changed since the plan was built). Re-run the plan.',
				$normTarget
			));
		}
	}


	// ----------------------------------------------------------------
	// Backups
	// ----------------------------------------------------------------

	/**
	 * Resolve the backup destination for a target under var/backups/<ns>/<ts>/<target>.
	 */
	private function backupPathFor(string $normTarget, string $ts): string {
		return $this->pathGuard->resolveTarget('var/backups/' . self::BACKUP_NS . '/' . $ts . '/' . $normTarget);
	}

	/**
	 * Atomically copy the current bytes of an existing target into the backup tree.
	 */
	private function backupExisting(string $targetAbs, string $backupAbs, string $expectedChecksum): void {
		$bytes = \file_get_contents($targetAbs);
		if ($bytes === false) {
			throw new FilesystemException(\sprintf('Unable to read file for backup: %s', $targetAbs));
		}
		if (!Checksum::matches($bytes, $expectedChecksum)) {
			throw new ConflictException('Target changed while preparing its backup: ' . $targetAbs);
		}
		if (\file_exists($backupAbs) || \is_link($backupAbs)) {
			throw new FilesystemException('Refusing to replace an existing backup: ' . $backupAbs);
		}
		AtomicFileWriter::write($backupAbs, $bytes);
	}

	/**
	 * Absolute path of the run-level backup directory (for reporting).
	 */
	private function backupRoot(string $ts): string {
		return $this->pathGuard->appRoot() . '/var/backups/' . self::BACKUP_NS . '/' . $ts;
	}


	// ----------------------------------------------------------------
	// Clocks
	// ----------------------------------------------------------------

	/** ISO-8601 (UTC) timestamp for state records. */
	private function nowIso8601(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
	}

	/** Filesystem-safe UTC microsecond stamp plus a random suffix; mkdir reserves it. */
	private function timestamp(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\\THis.u\\Z') . '-' . \bin2hex(\random_bytes(8));
	}
}
