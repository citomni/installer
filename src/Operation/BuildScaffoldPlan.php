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
use CitOmni\Installer\Util\Path;
use CitOmni\Installer\Exception\InstallerException;

/**
 * Builds the scaffold decision graph for status/install/sync/repair (contract §6-§10).
 *
 * This Operation is the single place that decides "what would happen". It is
 * read-only with respect to mutation: it performs READ IO (stat target, read
 * target bytes for drift detection, read stub bytes to render for comparison)
 * but never writes files, never writes state, and never runs Composer or the
 * network. Only ApplyScaffoldPlan acts on the returned plan.
 *
 * Behavior:
 * - Per file it computes a closed-set status (contract §11) by comparing disk
 *   bytes, recorded `rendered_checksum`, current stub, and current render.
 * - It then maps (command, policy, status, --force) to a closed-set action.
 * - Local-edit detection is checksum-based and NEVER re-render-based (§7).
 * - Drift is split into `update_available` (reason `stub_drift`) vs
 *   `placeholder_drift`, exactly as §7 prescribes, but only while disk still
 *   matches the prior baseline (clean).
 *
 * Return shape (arrays only):
 *   [
 *     'command'  => 'sync',
 *     'force'    => false,
 *     'packages' => [
 *       [
 *         'name'  => 'citomni/http',
 *         'files' => [
 *           [
 *             'target' => 'public/index.php',  // normalized
 *             'type'   => 'entrypoint',
 *             'policy' => 'managed',
 *             'status' => 'local_modified',
 *             'reason' => 'rendered_checksum_mismatch',
 *             'action' => 'write_new',
 *             'backup' => true,                 // present ONLY when a forced overwrite backs up first
 *             '_apply' => [ ... ]               // INTERNAL: consumed by ApplyScaffoldPlan, NOT for DevKit JSON
 *           ],
 *         ],
 *       ],
 *     ],
 *   ]
 *
 * Notes:
 * - The DevKit-stable JSON object (§11) is the public subset of each file entry
 *   (target/type/policy/status/reason/action[/backup]). Any underscore-prefixed
 *   key (`_apply`) is internal and MUST be stripped by the JSON formatter.
 * - Integrity failures (unsafe path, source missing, unsafe state) FAIL FAST as
 *   InstallerException rather than emitting a per-file `error`/`fail`. The
 *   `error`/`fail` closed-set members are reserved for graceful multi-file
 *   reporting, which MVP does not need.
 * - This Operation does NOT discover packages and does NOT resolve placeholders.
 *   The caller (command/adapter) runs ComposerPackageLocator + placeholder
 *   resolution (contract §4/§7) and passes the results in. This keeps the
 *   decision graph free of discovery IO and trivially testable.
 */
final class BuildScaffoldPlan {

	/**
	 * Commands that produce a plan. `doctor` is read-only validation and does
	 * not flow through here.
	 */
	private const COMMANDS = ['status', 'install', 'sync', 'repair'];

	public function __construct(private readonly PathGuard $pathGuard, private readonly ScaffoldRenderer $renderer, private readonly ScaffoldState $state) {}


	// ----------------------------------------------------------------
	// Public entry point
	// ----------------------------------------------------------------

	/**
	 * Build the plan for a command across the given (already discovered) manifests.
	 *
	 * @param  string                            $command       One of status|install|sync|repair.
	 * @param  array<string,array<string,mixed>> $manifests     Keyed by package name. Each manifest:
	 *                                                           ['package'=>string,'version'=>int,
	 *                                                            'root'=>absolute-package-root,
	 *                                                            'files'=>list<['target','source','type','policy']>].
	 * @param  array<string,array<string,string>> $placeholders Resolved placeholders keyed by package
	 *                                                           name (PACKAGE_VERSION etc. already resolved per §7).
	 * @param  array<string,mixed>               $options       ['force'=>bool, 'target'=>?string single-file scope].
	 * @return array<string,mixed>               The plan (arrays only).
	 * @throws InstallerException  On unknown command, unsafe path, missing source, or unsafe state.
	 */
	public function build(string $command, array $manifests, array $placeholders, array $options = []): array {
		$command = \strtolower($command);
		if (!\in_array($command, self::COMMANDS, true)) {
			throw new InstallerException("Unknown command '{$command}'. Expected one of: " . \implode(', ', self::COMMANDS) . '.');
		}

		$force = (bool)($options['force'] ?? false);
		$scope = (isset($options['target']) && \is_string($options['target']) && $options['target'] !== '')
			? Path::normalizeRelative($options['target'])
			: null;

		// Read-only baseline. Throws if the state file exists but is unsafe (contract §5).
		$statePackages = $this->state->readPackages();

		$packages = [];
		foreach ($manifests as $pkgName => $manifest) {
			$packageRoot = (string)($manifest['root'] ?? '');
			if ($packageRoot === '') {
				throw new InstallerException("Manifest for '{$pkgName}' is missing a resolved 'root'.");
			}

			$pkgPlaceholders = (array)($placeholders[$pkgName] ?? []);
			$pkgState        = \is_array($statePackages[$pkgName] ?? null) ? $statePackages[$pkgName] : [];

			$files = [];
			foreach ((array)($manifest['files'] ?? []) as $file) {
				$entry = $this->planFile($command, $packageRoot, (array)$file, $pkgPlaceholders, $pkgState, $force, $scope);
				if ($entry !== null) {
					$files[] = $entry;
				}
			}

			// When a single-file scope is requested, drop packages that contribute nothing.
			if ($scope !== null && $files === []) {
				continue;
			}

			$packages[] = [
				'name' => (string)$pkgName,
				'installed_version' => (string)($manifest['installed_version'] ?? 'unknown'),
				'files' => $files,
			];
		}

		return ['command' => $command, 'force' => $force, 'packages' => $packages];
	}


	// ----------------------------------------------------------------
	// Per-file planning
	// ----------------------------------------------------------------

	/**
	 * Detect status and decide the action for a single manifest file entry.
	 *
	 * @return array<string,mixed>|null  Plan entry, or null when filtered out by single-file scope.
	 * @throws InstallerException        On unsafe path, missing source, or unreadable target.
	 */
	private function planFile(string $command, string $packageRoot, array $file, array $pkgPlaceholders, array $pkgState, bool $force, ?string $scope): ?array {
		$target = (string)($file['target'] ?? '');
		$source = (string)($file['source'] ?? '');
		$type   = (string)($file['type'] ?? '');
		$policy = (string)($file['policy'] ?? '');

		if ($target === '' || $source === '' || $policy === '') {
			throw new InstallerException('Manifest file entry is missing target, source, or policy.');
		}

		$normTarget = Path::normalizeRelative($target);
		if ($scope !== null && $normTarget !== $scope) {
			return null;
		}

		if ($policy === 'sample') {
			throw new InstallerException("Policy 'sample' is post-MVP and not supported (target '{$normTarget}').");
		}
		if ($policy !== 'managed' && $policy !== 'create-only') {
			throw new InstallerException("Unknown policy '{$policy}' for target '{$normTarget}'.");
		}

		// Resolve + path-safety (contract §4). Source must physically exist (§9: "Source missing -> Fail").
		$targetAbs = $this->pathGuard->resolveTarget($target);
		$sourceAbs = $this->pathGuard->resolveSource($packageRoot, $source);
		if (!\is_file($sourceAbs)) {
			throw new InstallerException("Scaffold source missing in package: '{$source}'.");
		}

		$exists    = \is_file($targetAbs);
		$stateFile = \is_array($pkgState['files'][$normTarget] ?? null) ? $pkgState['files'][$normTarget] : null;

		// -- 1. Detect status -------------------------------------------------
		$disk      = null;          // raw target bytes (only read for managed + existing)
		$currentCk = null;          // [stubChecksum, renderedChecksum] for CURRENT placeholders

		if ($policy === 'create-only') {
			// create-only has no upstream-drift semantics (contract §8).
			$status = $exists ? 'create_only_present' : 'missing';
			$reason = $exists ? 'create_only_present' : 'target_missing';
		} else {
			// managed
			if (!$exists) {
				$status = 'missing';
				$reason = 'target_missing';
			} else {
				$disk = \file_get_contents($targetAbs);
				if ($disk === false) {
					throw new InstallerException("Cannot read target for drift detection: '{$normTarget}'.");
				}

				if ($stateFile === null) {
					$status = 'unknown_existing';
					$reason = 'not_in_state';
				} else {
					$storedRendered = (string)($stateFile['rendered_checksum'] ?? '');

					// Local-edit detection is checksum vs disk only (contract §7) - never re-render based.
					if ($storedRendered === '' || !Checksum::matches($disk, $storedRendered)) {
						$status = 'local_modified';
						$reason = 'rendered_checksum_mismatch';
					} else {
						// Disk is clean. Re-render with current inputs to classify drift.
						$currentCk = $this->render($sourceAbs, $pkgPlaceholders);

						if (Checksum::matches($disk, $currentCk[1])) {
							// Current render equals disk: nothing to update (even if stub metadata drifted).
							$status = 'up_to_date';
							$reason = 'matches_baseline';
						} else {
							$storedStub = isset($stateFile['stub_checksum']) ? (string)$stateFile['stub_checksum'] : '';
							if ($storedStub !== '' && !Checksum::equals($currentCk[0], $storedStub)) {
								$status = 'update_available';
								$reason = 'stub_drift';
							} else {
								// Stub unchanged but render differs => placeholders changed (contract §7).
								$status = 'placeholder_drift';
								$reason = 'placeholder_drift';
							}
						}
					}
				}
			}
		}

		// -- 2. Decide action -------------------------------------------------
		$decision = $this->decide($command, $policy, $status, $force, [
			'targetAbs'       => $targetAbs,
			'sourceAbs'       => $sourceAbs,
			'normTarget'      => $normTarget,
			'normSource'      => Path::normalizeRelative($source),
			'pkgPlaceholders' => $pkgPlaceholders,
			'stateFile'       => $stateFile,
			'disk'            => $disk,
			'currentCk'       => $currentCk,
		]);

		// -- 3. Assemble entry (public fields in DevKit order, then internal) -
		$entry = [
			'target' => $normTarget,
			'source' => Path::normalizeRelative($source),
			'type'   => $type,
			'policy' => $policy,
			'status' => $status,
			'reason' => $decision['reason'] ?? $reason,
			'action' => $decision['action'],
		];
		if (!empty($decision['backup'])) {
			$entry['backup'] = true;
		}
		if (!empty($decision['apply'])) {
			$entry['_apply'] = $decision['apply'];
		}

		return $entry;
	}


	// ----------------------------------------------------------------
	// Action decision (command-specific)
	// ----------------------------------------------------------------

	/**
	 * Map (command, policy, status, force) to a plan action and optional apply data.
	 *
	 * @param  array<string,mixed> $ctx  Per-file working context (paths, placeholders, state, disk, cached checksums).
	 * @return array<string,mixed>       ['action'=>string, 'reason'?=>string, 'backup'?=>bool, 'apply'?=>array].
	 */
	private function decide(string $command, string $policy, string $status, bool $force, array $ctx): array {
		switch ($command) {
			case 'status':
				// Read-only report: status carries the meaning, action is inert.
				return ['action' => 'none'];

			case 'install':
				return $this->decideInstall($status, $force, $ctx);

			case 'sync':
				return $this->decideSync($policy, $status, $force, $ctx);

			case 'repair':
				return $this->decideRepair($status, $ctx);
		}

		// Unreachable: build() validated the command up front.
		throw new InstallerException("Unhandled command '{$command}'.");
	}

	/**
	 * install: first materialization. Create missing only; never overwrite existing without --force.
	 *
	 * @param  array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function decideInstall(string $status, bool $force, array $ctx): array {
		if ($status === 'missing') {
			return $this->createWith($ctx, $ctx['pkgPlaceholders']);
		}
		if ($force) {
			return $this->forceOverwrite($ctx);
		}

		if ($status === 'unknown_existing' || $status === 'local_modified') {
			return ['action' => 'conflict', 'reason' => $status];
		}

		return ['action' => 'none'];
	}

	/**
	 * sync: controlled update of managed files; create-only mostly untouched.
	 *
	 * No state for a package => missing behaves as install; existing-unknown is
	 * adopted as clean baseline when it matches the current render, else stop / .new
	 * (contract §10).
	 *
	 * @param  array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function decideSync(string $policy, string $status, bool $force, array $ctx): array {
		if ($policy === 'create-only') {
			if ($status === 'missing') {
				return $this->createWith($ctx, $ctx['pkgPlaceholders']);
			}
			// create_only_present: never touched without --force (contract §8).
			return $force ? $this->forceOverwrite($ctx) : ['action' => 'none'];
		}

		// managed
		switch ($status) {
			case 'missing':
				return $this->createWith($ctx, $ctx['pkgPlaceholders']);

			case 'up_to_date':
				return ['action' => 'none'];

			case 'update_available':
			case 'placeholder_drift':
				// Disk matches prior baseline => safe to update; no backup required.
				return $this->updateClean($ctx);

			case 'local_modified':
				return $force ? $this->forceOverwrite($ctx) : $this->writeNew($ctx);

			case 'unknown_existing':
				// disk is guaranteed a string here: unknown_existing only arises for an
				// existing managed target, whose bytes planFile() already read.
				$ck   = $this->render($ctx['sourceAbs'], $ctx['pkgPlaceholders']);
				$disk = (string)$ctx['disk'];
				if (Checksum::matches($disk, $ck[1])) {
					// Matches current render: adopt as clean baseline (register only, no file write).
					return [
						'action' => 'register_state',
						'reason' => 'adopt_clean_baseline',
						'apply'  => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck),
					];
				}
				// Cache the render so forceOverwrite/writeNew don't read the stub a second time.
				$ctx['currentCk'] = $ck;
				return $force ? $this->forceOverwrite($ctx) : $this->writeNew($ctx);
		}

		return ['action' => 'none'];
	}

	/**
	 * repair: recreate MISSING files from RECORDED placeholders; update baseline
	 * to current stub on recreate; warn on stub drift. Never touches existing files.
	 *
	 * @param  array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function decideRepair(string $status, array $ctx): array {
		if ($status !== 'missing') {
			return ['action' => 'none', 'reason' => 'exists_untouched'];
		}

		$stateFile = $ctx['stateFile'];
		if (!\is_array($stateFile) || !\is_array($stateFile['placeholders'] ?? null)) {
			// Nothing recorded to reproduce render input from. repair is not install.
			return ['action' => 'none', 'reason' => 'no_recorded_state'];
		}

		$recorded = $stateFile['placeholders'];
		$ck       = $this->render($ctx['sourceAbs'], $recorded);

		$recordedStub = isset($stateFile['stub_checksum']) ? (string)$stateFile['stub_checksum'] : '';
		$reason = ($recordedStub !== '' && !Checksum::equals($ck[0], $recordedStub))
			? 'recreated_stub_drift'      // warn: upstream stub changed since the recorded baseline
			: 'recreated_from_recorded';

		return ['action' => 'create', 'reason' => $reason, 'apply' => $this->applyState($ctx, $recorded, $ck)];
	}


	// ----------------------------------------------------------------
	// Apply-data builders
	// ----------------------------------------------------------------

	/**
	 * @param  array<string,mixed>   $ctx
	 * @param  array<string,string>  $placeholders
	 * @return array<string,mixed>
	 */
	private function createWith(array $ctx, array $placeholders): array {
		$ck = $this->renderIfNeeded($ctx, $placeholders);
		return ['action' => 'create', 'apply' => $this->applyState($ctx, $placeholders, $ck)];
	}

	/**
	 * @param  array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function updateClean(array $ctx): array {
		$ck = $ctx['currentCk'] ?? $this->render($ctx['sourceAbs'], $ctx['pkgPlaceholders']);
		return ['action' => 'update', 'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck)];
	}

	/**
	 * Forced overwrite of an existing file: backup is mandatory before the write (contract §9).
	 *
	 * @param  array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function forceOverwrite(array $ctx): array {
		$ck    = $this->renderIfNeeded($ctx, $ctx['pkgPlaceholders']);
		$apply = $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck);
		$apply['backup_required'] = true;
		return ['action' => 'update', 'backup' => true, 'reason' => 'forced_overwrite', 'apply' => $apply];
	}

	/**
	 * Non-destructive conflict outcome: write `<target>.new`, leave the live file and its baseline alone.
	 *
	 * @param  array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function writeNew(array $ctx): array {
		$ck    = $this->renderIfNeeded($ctx, $ctx['pkgPlaceholders']);
		$apply = $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck);
		$apply['new_path']      = $ctx['targetAbs'] . '.new';
		$apply['updates_state'] = false;
		return ['action' => 'write_new', 'apply' => $apply];
	}

	/**
	 * Build the snapshot ApplyScaffoldPlan needs to materialize + record the file.
	 *
	 * @param  array<string,mixed>  $ctx
	 * @param  array<string,string> $placeholders  Placeholders actually used for this render.
	 * @param  array{0:string,1:string} $ck        [stubChecksum, renderedChecksum].
	 * @return array<string,mixed>
	 */
	private function applyState(array $ctx, array $placeholders, array $ck): array {
		return [
			'target'            => $ctx['normTarget'],
			'source'            => $ctx['normSource'],
			'target_abs'        => $ctx['targetAbs'],
			'source_abs'        => $ctx['sourceAbs'],
			'placeholders'      => $placeholders,
			'stub_checksum'     => $ck[0],
			'rendered_checksum' => $ck[1],
		];
	}


	// ----------------------------------------------------------------
	// Rendering helpers (read IO only)
	// ----------------------------------------------------------------

	/**
	 * Read the stub and compute both checksums for a given placeholder set.
	 *
	 * @param  array<string,string> $placeholders
	 * @return array{0:string,1:string}  [stubChecksum, renderedChecksum].
	 * @throws InstallerException  When the stub is unreadable or a placeholder is malformed/unknown.
	 */
	private function render(string $sourceAbs, array $placeholders): array {
		$stub             = $this->renderer->readStub($sourceAbs);
		$stubChecksum     = Checksum::sha256($stub);
		$rendered         = $this->renderer->render($stub, $placeholders);
		$renderedChecksum = Checksum::sha256($rendered);
		return [$stubChecksum, $renderedChecksum];
	}

	/**
	 * Reuse the checksums computed during detection when the placeholders match,
	 * otherwise render fresh. Avoids a second stub read for the common path.
	 *
	 * @param  array<string,mixed>  $ctx
	 * @param  array<string,string> $placeholders
	 * @return array{0:string,1:string}
	 */
	private function renderIfNeeded(array $ctx, array $placeholders): array {
		if (($ctx['currentCk'] ?? null) !== null && $placeholders === $ctx['pkgPlaceholders']) {
			return $ctx['currentCk'];
		}
		return $this->render($ctx['sourceAbs'], $placeholders);
	}
}
