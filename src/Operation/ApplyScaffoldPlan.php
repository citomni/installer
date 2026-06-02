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

use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Util\Checksum;
use CitOmni\Installer\Exception\InstallerException;

/**
 * Execute a scaffold plan: the single layer permitted to mutate app files and state.
 *
 * BuildScaffoldPlan owns the decision graph and returns a pure array describing what
 * should happen per file. ApplyScaffoldPlan is the only place those decisions become
 * filesystem effects. It performs no detection or policy reasoning of its own; it
 * trusts the plan and limits itself to materializing bytes, writing `.new` sidecars,
 * backing up before forced overwrites, and persisting the state file.
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
 *     5) none / conflict -> no write.
 * - Re-resolves every write destination through PathGuard at the write site, so the
 *   "never write outside app-root / never write to /vendor/" invariant is enforced
 *   at the point of mutation, not only at plan time.
 * - Stale-plan guard: re-renders before writing and compares the rendered checksum to
 *   the planned one. A mismatch means the stub (or resolved placeholders) changed
 *   between planning and applying; that file is failed rather than written, so the
 *   recorded rendered_checksum can never disagree with the bytes on disk.
 * - State is merged into the existing state (siblings preserved) and written once,
 *   atomically, at the end of a successful run that produced at least one state change.
 *
 * Failure model (deliberate: best-effort with a full report):
 * - A per-file IO/integrity error is caught, recorded as applied = "failed" on that
 *   file, and the run continues. This is the right shape for an installer: a single
 *   unwritable file must not abort materialization of the others, and because each
 *   write is independently atomic, partial application is already the reality. State
 *   is still persisted for the files that did succeed, so the recorded baseline always
 *   matches what is actually on disk. Hard preconditions (unreadable/unsafe state) are
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
 * - The atomic-write/ensure-dir logic intentionally mirrors ScaffoldState. Extracting a
 *   shared Support\AtomicFileWriter used by both would remove the duplication; that is a
 *   separate refactor and is left out of this file's scope.
 *
 * @see \CitOmni\Installer\Operation\BuildScaffoldPlan  Produces the plan consumed here.
 */
final class ApplyScaffoldPlan {

	/** Namespace segment under var/backups/ for this installer's backups. */
	private const BACKUP_NS = 'citomni-installer';

	/** Prefix for temp files created during atomic writes. */
	private const TMP_PREFIX = '.citomni-installer.';

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
	 * @param  array  $options  {dry_run?: bool}. In dry-run, stubs are still rendered and
	 *                          validated (so problems surface) but nothing is written.
	 * @return array  Result: {command, dry_run, ok, backup_dir, state_written, packages[]},
	 *                where each file gains an `applied` outcome (created|updated|wrote_new|
	 *                registered|skipped|conflict|failed) plus optional backup_path/new_path/error.
	 * @throws InstallerException  If the existing state file is present but unreadable/unsafe,
	 *                             or if the final state write fails.
	 */
	public function apply(array $plan, array $options = []): array {
		$dryRun  = (bool)($options['dry_run'] ?? false);
		$command = (string)($plan['command'] ?? '');

		// -- 1. Precondition: existing state must be readable before we touch anything ----
		// readPackages() validates format_version and structure; an unsafe state throws here,
		// guaranteeing no file is written when state cannot be trusted.
		$statePackages = $this->state->readPackages();

		$ts  = $this->timestamp();
		$now = $this->nowIso8601();

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
				if ($applied === 'failed' || $applied === 'conflict') {
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
		if (!$dryRun && $stateDirty) {
			$this->state->write($statePackages);
			$stateWritten = true;
		}

		return [
			'command'       => $command,
			'dry_run'       => $dryRun,
			'ok'            => $ok,
			'backup_dir'    => $anyBackup ? $this->backupRoot($ts) : null,
			'state_written' => $stateWritten,
			'packages'      => $resultPackages,
		];
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

		// No-write outcomes carry no `_apply` block.
		if ($action === 'none') {
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
			return ['entry' => $base + ['applied' => 'failed', 'error' => $e->getMessage()], 'state' => null];
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
		$targetAbs = $this->pathGuard->resolveTarget($normTarget);

		// Render forward + guard against a stale plan.
		[$stubCk, $renderedCk, $bytes] = $this->renderForApply($sourceAbs, $placeholders);
		$this->assertPlanFresh($normTarget, $renderedCk, $plannedRendered);

		// Forced overwrite: back up the current bytes before clobbering them.
		$backupPath = null;
		if ($backupRequired && \is_file($targetAbs)) {
			$backupPath = $this->backupPathFor($normTarget, $ts);
			if (!$dryRun) {
				$this->backupExisting($targetAbs, $backupPath);
			}
		}

		if (!$dryRun) {
			$this->atomicWrite($targetAbs, $bytes);
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
			$this->atomicWrite($newPath, $bytes);
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
		$targetAbs = $this->pathGuard->resolveTarget($normTarget);
		if (!\is_file($targetAbs)) {
			throw new InstallerException(\sprintf(
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
			throw new InstallerException(\sprintf("Cannot read target for baseline adoption: '%s'.", $normTarget));
		}
		if (!Checksum::matches($disk, $renderedCk)) {
			throw new InstallerException(\sprintf(
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
	 * Refuse to write when the freshly rendered bytes no longer match the planned baseline.
	 */
	private function assertPlanFresh(string $normTarget, string $renderedCk, string $plannedRendered): void {
		if ($plannedRendered !== '' && !Checksum::equals($renderedCk, $plannedRendered)) {
			throw new InstallerException(\sprintf(
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
	private function backupExisting(string $targetAbs, string $backupAbs): void {
		$bytes = \file_get_contents($targetAbs);
		if ($bytes === false) {
			throw new InstallerException(\sprintf('Unable to read file for backup: %s', $targetAbs));
		}
		$this->atomicWrite($backupAbs, $bytes);
	}

	/**
	 * Absolute path of the run-level backup directory (for reporting).
	 */
	private function backupRoot(string $ts): string {
		return $this->pathGuard->appRoot() . '/var/backups/' . self::BACKUP_NS . '/' . $ts;
	}


	// ----------------------------------------------------------------
	// Low-level atomic IO
	// ----------------------------------------------------------------

	/**
	 * Write bytes atomically: temp file in the same dir, fflush/fsync, rename into place.
	 */
	private function atomicWrite(string $absPath, string $bytes): void {
		$dir = \dirname($absPath);
		$this->ensureDir($dir);

		// random_bytes() throws \Random\RandomException if the CSPRNG is unavailable. Keep the
		// failure inside this class's exception currency: InstallerException is what the per-file
		// handler catches and what the command layer maps to an exit code. Letting the native
		// type escape would bypass both.
		try {
			$rand = \bin2hex(\random_bytes(8));
		} catch (\Throwable $e) {
			throw new InstallerException(\sprintf('Unable to generate a temp file name (CSPRNG unavailable) in: %s', $dir), 0, $e);
		}

		$tmp    = $dir . '/' . self::TMP_PREFIX . $rand . '.tmp';
		$handle = \fopen($tmp, 'wb');
		if ($handle === false) {
			throw new InstallerException(\sprintf('Unable to open temp file for writing: %s', $tmp));
		}
		try {
			$written = \fwrite($handle, $bytes);
			if ($written === false || $written !== \strlen($bytes)) {
				throw new InstallerException(\sprintf('Failed to write complete temp file: %s', $tmp));
			}
			\fflush($handle);
			if (\function_exists('fsync')) {
				@\fsync($handle);
			}
		} catch (\Throwable $e) {
			\fclose($handle);
			@\unlink($tmp);
			throw $e instanceof InstallerException ? $e : new InstallerException(\sprintf('Failed writing temp file: %s', $tmp), 0, $e);
		}
		\fclose($handle);

		if (!@\rename($tmp, $absPath)) {
			@\unlink($tmp);
			throw new InstallerException(\sprintf('Failed to move file into place atomically: %s', $absPath));
		}
		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($absPath, true);
		}
	}

	/**
	 * Create a directory (recursively) if it does not already exist.
	 */
	private function ensureDir(string $dir): void {
		if (\is_dir($dir)) {
			return;
		}
		if (!\mkdir($dir, 0775, true) && !\is_dir($dir)) {
			throw new InstallerException(\sprintf('Unable to create directory: %s', $dir));
		}
	}


	// ----------------------------------------------------------------
	// Clocks
	// ----------------------------------------------------------------

	/** ISO-8601 (UTC) timestamp for state records. */
	private function nowIso8601(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
	}

	/** Compact, sortable, filesystem-safe UTC stamp for the backup directory name. */
	private function timestamp(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\\THis\\Z');
	}
}
