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
use CitOmni\Installer\Util\GitignoreBlock;
use CitOmni\Installer\Util\Path;
use CitOmni\Installer\Util\PhpSourceComparator;
use CitOmni\Installer\Exception\ConflictException;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Exception\FilesystemException;

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
 * - With the `gitignore` option it also plans the installer block in the app's
 *   .gitignore from the complete installed rule set (see planGitignore()).
 * - Create-only targets passed in the `omit` option are reported as `omitted` and
 *   never acted on (see planOmitted()).
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
 *             '_environment_aware' => true,     // INTERNAL: present ONLY for environment-aware targets
 *             '_apply' => [ ... ]               // INTERNAL: consumed by ApplyScaffoldPlan, NOT for DevKit JSON
 *           ],
 *         ],
 *       ],
 *     ],
 *     'gitignore' => [                         // present ONLY with the `gitignore` option
 *       'target' => '.gitignore',
 *       'status' => 'update_available',
 *       'reason' => 'block_missing',
 *       'action' => 'update',
 *       'newly_ignored' => ['public/index.php'],
 *       '_apply' => [ ... ],
 *     ],
 *   ]
 *
 * Notes:
 * - The DevKit-stable JSON object (§11) is the public subset of each file entry
 *   (target/type/policy/status/reason/action[/backup]). Any underscore-prefixed
 *   key (`_apply`, `_environment_aware`) is internal and MUST be stripped by the
 *   JSON formatter.
 * - Integrity failures (unsafe path, source missing, unsafe state) FAIL FAST as
 *   InstallerException rather than emitting a per-file `error`/`fail`. The
 *   `error`/`fail` closed-set members are reserved for graceful multi-file
 *   reporting, which MVP does not need.
 * - This Operation does NOT discover packages and does NOT resolve placeholders.
 *   The caller (command/adapter) runs ScaffoldManifestLocator + placeholder
 *   resolution (contract §4/§7) and passes the results in. This keeps the
 *   decision graph free of discovery IO and trivially testable.
 */
final class BuildScaffoldPlan {

	/**
	 * Commands that produce a plan. `doctor` is read-only validation and does
	 * not flow through here.
	 */
	private const COMMANDS = ['status', 'install', 'migrate', 'sync', 'repair', 'environment'];

	public function __construct(private readonly PathGuard $pathGuard, private readonly ScaffoldRenderer $renderer, private readonly ScaffoldState $state) {}


	// ----------------------------------------------------------------
	// Public entry point
	// ----------------------------------------------------------------

	/**
	 * Build the plan for a command across the given (already discovered) manifests.
	 *
	 * @param  string                            $command       One of status|install|migrate|sync|repair|environment.
	 * @param  array<string,array<string,mixed>> $manifests     Keyed by package name. Each manifest:
	 *                                                           ['package'=>string,'version'=>int,
	 *                                                            'root'=>absolute-package-root,
	 *                                                            'files'=>list<['target','source','type','policy']>].
	 * @param  array<string,array<string,string>> $placeholders Resolved placeholders keyed by package
	 *                                                           name (PACKAGE_VERSION etc. already resolved per §7).
	 * @param  array<string,mixed>               $options       ['force'=>bool, 'target'=>?string single-file scope,
	 *                                                           'environment_materialization'=>bool,
	 *                                                           'migration_reconcile'=>bool,
	 *                                                           'migration_environment_changed'=>bool,
	 *                                                           'gitignore'=>array{ignore:list<string>,exceptions:list<string>,targets:list<string>}
	 *                                                           block input for ALL installed packages, from
	 *                                                           ScaffoldManifestLocator::gitignoreRules(),
	 *                                                           'omit'=>list<string> create-only targets the application
	 *                                                           omits, from ScaffoldManifestLocator::omissions()].
	 * @return array<string,mixed>               The plan (arrays only).
	 * @throws InstallerException  On unknown command, unsafe path, missing source, or unsafe state.
	 */
	public function build(string $command, array $manifests, array $placeholders, array $options = []): array {
		$command = \strtolower($command);
		if (!\in_array($command, self::COMMANDS, true)) {
			throw new InstallerException("Unknown command '{$command}'. Expected one of: " . \implode(', ', self::COMMANDS) . '.');
		}

		$force = (bool)($options['force'] ?? false);
		$environmentMaterialization = (bool)($options['environment_materialization'] ?? false);
		$migrationReconcile = $command === 'migrate' && (bool)($options['migration_reconcile'] ?? false);
		$migrationEnvironmentChanged = $migrationReconcile && (bool)($options['migration_environment_changed'] ?? false);
		$scope = (isset($options['target']) && \is_string($options['target']) && $options['target'] !== '')
			? Path::normalizeRelative($options['target'])
			: null;
		$omit = [];
		foreach ((array)($options['omit'] ?? []) as $omitted) {
			$omit[Path::normalizeRelative((string)$omitted)] = true;
		}

		// Legacy migration deliberately rebuilds state from current manifests and disk.
		// Current v2 migration reconciliation keeps the recorded managed baseline so local
		// edits remain distinguishable from clean upstream drift.
		$statePackages = $command === 'migrate' && !$migrationReconcile ? [] : $this->state->readPackages();

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
				// Normalize only when something is omitted; planFile() validates the target itself.
				$normTarget = $omit === [] ? '' : Path::normalizeRelative((string)($file['target'] ?? ''));
				if (isset($omit[$normTarget])) {
					$entry = $this->planOmitted((array)$file, $normTarget, $scope);
				} else {
					$entry = $this->planFile(
						$command,
						$packageRoot,
						(array)$file,
						$pkgPlaceholders,
						$pkgState,
						$force,
						$scope,
						$environmentMaterialization,
						$migrationReconcile,
						$migrationEnvironmentChanged
					);
				}
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

		$plan = ['command' => $command, 'force' => $force, 'packages' => $packages];
		if (\is_array($options['gitignore'] ?? null)) {
			$plan['gitignore'] = $this->planGitignore($command, $options['gitignore']);
		}

		return $plan;
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
	private function planFile(
		string $command,
		string $packageRoot,
		array $file,
		array $pkgPlaceholders,
		array $pkgState,
		bool $force,
		?string $scope,
		bool $environmentMaterialization,
		bool $migrationReconcile,
		bool $migrationEnvironmentChanged
	): ?array {
		$target = (string)($file['target'] ?? '');
		$source = (string)($file['source'] ?? '');
		$type   = (string)($file['type'] ?? '');
		$policy = (string)($file['policy'] ?? '');
		$environmentAware = !empty($file['_environment_aware']);

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

		if (\file_exists($targetAbs) && !\is_file($targetAbs)) {
			throw new FilesystemException('Scaffold target is not a regular file: ' . $normTarget);
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
					throw new FilesystemException("Cannot read target for drift detection: '{$normTarget}'.");
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
		$decision = $this->decide($command, $policy, $status, $force, $environmentAware, $environmentMaterialization, [
			'targetAbs'       => $targetAbs,
			'sourceAbs'       => $sourceAbs,
			'normTarget'      => $normTarget,
			'normSource'      => Path::normalizeRelative($source),
			'pkgPlaceholders' => $pkgPlaceholders,
			'stateFile'       => $stateFile,
			'disk'            => $disk,
			'currentCk'       => $currentCk,
			'exists'          => $exists,
			'type'            => $type,
			'policy'          => $policy,
			'migrationReconcile' => $migrationReconcile,
			'migrationEnvironmentChanged' => $migrationEnvironmentChanged,
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
		if ($environmentAware) {
			$entry['_environment_aware'] = true;
		}
		if (!empty($decision['apply'])) {
			$entry['_apply'] = $decision['apply'];
		}

		return $entry;
	}


	/**
	 * Report a create-only target that the application omits.
	 *
	 * The application lists the target under `omit` in config/citomni_installer.php.
	 * Every command leaves the path alone: nothing is created, updated, backed up,
	 * recorded in state or written as `.new`, also with --force. Existing bytes stay as
	 * they are, and the target's stub is never read or rendered.
	 *
	 * @param array<string,mixed> $file Manifest file entry.
	 * @param string $normTarget Normalized target.
	 * @param string|null $scope Single-file scope, if any.
	 * @return array<string,mixed>|null Plan entry with status `omitted`, or null when filtered out by scope.
	 * @throws InstallerException When the target is not create-only; ScaffoldManifestLocator::omissions() rejects those first.
	 */
	private function planOmitted(array $file, string $normTarget, ?string $scope): ?array {
		if ($scope !== null && $normTarget !== $scope) {
			return null;
		}

		$policy = (string)($file['policy'] ?? '');
		if ($policy !== 'create-only') {
			throw new InstallerException("Only create-only targets can be omitted; '{$normTarget}' uses policy '{$policy}'.");
		}

		return [
			'target' => $normTarget,
			'source' => Path::normalizeRelative((string)($file['source'] ?? '')),
			'type'   => (string)($file['type'] ?? ''),
			'policy' => $policy,
			'status' => 'omitted',
			'reason' => 'listed_in_omit',
			'action' => 'none',
		];
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
	private function decide(string $command, string $policy, string $status, bool $force, bool $environmentAware, bool $environmentMaterialization, array $ctx): array {
		switch ($command) {
			case 'status':
				// Read-only report: status carries the meaning, action is inert.
				return ['action' => 'none'];

			case 'install':
				if ($environmentMaterialization && $environmentAware) {
					return $this->decideInitialEnvironment($status, $ctx);
				}
				return $this->decideInstall($status, $force, $ctx);

			case 'migrate':
				return $this->decideMigrate($policy, $status, $environmentAware, $ctx);

			case 'sync':
				return $this->decideSync($policy, $status, $force, $ctx);

			case 'repair':
				return $this->decideRepair($status, $ctx);

			case 'environment':
				return $this->decideEnvironment($status, $ctx);
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

		if ($status === 'create_only_present' && $ctx['stateFile'] === null) {
			$stub = $this->renderer->readStub($ctx['sourceAbs']);
			foreach ($this->renderer->placeholdersIn($stub) as $key) {
				if (!\array_key_exists($key, $ctx['pkgPlaceholders'])) {
					return ['action' => 'none'];
				}
			}
			$rendered = $this->renderer->render($stub, $ctx['pkgPlaceholders']);
			$disk = \file_get_contents($ctx['targetAbs']);
			if ($disk === false) {
				throw new FilesystemException('Cannot read create-only target: ' . $ctx['normTarget']);
			}
			if ($disk !== $rendered) {
				return ['action' => 'none'];
			}
			$ctx['disk'] = $disk;
			return [
				'action' => 'register_state',
				'reason' => 'adopt_clean_baseline',
				'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], [Checksum::sha256($stub), Checksum::sha256($rendered)]),
			];
		}

		if ($status === 'unknown_existing' || $status === 'local_modified') {
			$ck = $ctx['currentCk'] ?? $this->render($ctx['sourceAbs'], $ctx['pkgPlaceholders']);
			if (Checksum::matches((string)$ctx['disk'], $ck[1])) {
				return [
					'action' => 'register_state',
					'reason' => 'adopt_clean_baseline',
					'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck),
				];
			}
			return ['action' => 'conflict', 'reason' => $status];
		}

		return ['action' => 'none'];
	}


	/**
	 * migrate: rebuild the current baseline without trusting legacy state.
	 *
	 * Managed files remain authoritative. Existing create-only targets receive migration-only
	 * assistance: templates are authoritative, PHP files are refreshed automatically only
	 * when their significant token stream is unchanged, and real content/code differences
	 * become `.new` sidecars for human review.
	 *
	 * @param string $policy File policy.
	 * @param string $status Detected status with migration state intentionally ignored.
	 * @param bool $environmentAware Whether this target was selected from an environment map.
	 * @param array<string,mixed> $ctx Per-file planning context.
	 * @return array<string,mixed>
	 */
	private function decideMigrate(string $policy, string $status, bool $environmentAware, array $ctx): array {
		if ($policy === 'create-only') {
			return $this->decideMigrateCreateOnly($status, $ctx);
		}

		if (!empty($ctx['migrationReconcile'])) {
			if ($environmentAware && !empty($ctx['migrationEnvironmentChanged'])) {
				return $this->decideEnvironment($status, $ctx);
			}

			return $this->decideMigrateReconcileManaged($status, $ctx);
		}

		if ($status === 'missing') {
			return $this->createWith($ctx, $ctx['pkgPlaceholders']);
		}

		$ck = $ctx['currentCk'] ?? $this->render($ctx['sourceAbs'], $ctx['pkgPlaceholders']);
		$disk = (string)$ctx['disk'];

		if (Checksum::matches($disk, $ck[1])) {
			return [
				'action' => 'register_state',
				'reason' => $environmentAware ? 'adopt_environment_baseline' : 'adopt_clean_baseline',
				'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck),
			];
		}

		$apply = $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck);
		$apply['backup_required'] = true;

		return [
			'action' => 'update',
			'backup' => true,
			'reason' => 'migration_overwrite',
			'apply' => $apply,
		];
	}


	/**
	 * Reconcile a managed target against current v2 state and current package source.
	 *
	 * This is sync-like, with one migration-specific recovery guarantee: if a prior
	 * interrupted/blocked migrate already wrote today's desired bytes but could not
	 * commit state, the next run adopts those bytes instead of misclassifying them as
	 * a local edit.
	 *
	 * @param string $status Current managed status.
	 * @param array<string,mixed> $ctx Per-file planning context.
	 * @return array<string,mixed>
	 */
	private function decideMigrateReconcileManaged(string $status, array $ctx): array {
		switch ($status) {
			case 'missing':
				return $this->createWith($ctx, $ctx['pkgPlaceholders']);

			case 'up_to_date':
				$ck = $ctx['currentCk'];
				return $this->recordedBaselineMatches($ctx, $ck)
					? ['action' => 'none']
					: ['action' => 'register_state', 'reason' => 'adopt_clean_baseline', 'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck)];

			case 'update_available':
			case 'placeholder_drift':
				return $this->updateClean($ctx);

			case 'local_modified':
			case 'unknown_existing':
				$ck = $ctx['currentCk'] ?? $this->render($ctx['sourceAbs'], $ctx['pkgPlaceholders']);
				$disk = (string)$ctx['disk'];
				if (Checksum::matches($disk, $ck[1])) {
					return [
						'action' => 'register_state',
						'reason' => 'adopt_current_migration_baseline',
						'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck),
					];
				}
				$ctx['currentCk'] = $ck;
				return $this->writeNew($ctx);
		}

		return ['action' => 'none'];
	}


	/**
	 * Decide migration behavior for an existing or missing create-only target.
	 *
	 * @param string $status Current create-only status.
	 * @param array<string,mixed> $ctx Per-file planning context.
	 * @return array<string,mixed>
	 */
	private function decideMigrateCreateOnly(string $status, array $ctx): array {
		if ($status === 'missing') {
			return $this->createWith($ctx, $ctx['pkgPlaceholders']);
		}

		$stub = $this->renderer->readStub($ctx['sourceAbs']);
		$rendered = $this->renderer->render($stub, $ctx['pkgPlaceholders']);
		$ck = [Checksum::sha256($stub), Checksum::sha256($rendered)];
		$disk = \file_get_contents($ctx['targetAbs']);
		if ($disk === false) {
			throw new FilesystemException('Cannot read create-only target during migration: ' . $ctx['normTarget']);
		}

		$ctx['disk'] = $disk;
		$ctx['currentCk'] = $ck;
		if (Checksum::matches($disk, $ck[1])) {
			return [
				'action' => 'register_state',
				'reason' => 'adopt_clean_baseline',
				'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck),
			];
		}

		$target = \strtolower($ctx['normTarget']);
		if (\str_ends_with($target, '.tpl')) {
			return $this->migrationOverwrite($ctx, $ck, 'migration_template_refresh');
		}

		if (\str_ends_with($target, '.php')) {
			try {
				$desiredTokens = PhpSourceComparator::significantTokens($rendered);
			} catch (\ParseError $e) {
				throw new InstallerException(
					'Rendered migration scaffold is invalid PHP for ' . $ctx['normTarget'] . ': ' . $e->getMessage(),
					0,
					$e
				);
			}

			try {
				$localTokens = PhpSourceComparator::significantTokens($disk);
			} catch (\ParseError) {
				$decision = $this->writeNew($ctx);
				$decision['reason'] = 'migration_local_php_parse_error';
				return $decision;
			}

			if ($localTokens === $desiredTokens) {
				return $this->migrationOverwrite($ctx, $ck, 'migration_php_nonfunctional_change');
			}

			$decision = $this->writeNew($ctx);
			$decision['reason'] = 'migration_php_code_difference';
			return $decision;
		}

		$decision = $this->writeNew($ctx);
		$decision['reason'] = 'migration_content_difference';
		return $decision;
	}


	/** Build a backed-up authoritative migration replacement. */
	private function migrationOverwrite(array $ctx, array $ck, string $reason): array {
		$apply = $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck);
		$apply['backup_required'] = true;
		return [
			'action' => 'update',
			'backup' => true,
			'reason' => $reason,
			'apply' => $apply,
		];
	}


	/**
	 * Initial environment materialization compatibility gate.
	 *
	 * Behavior:
	 * - Missing targets are created.
	 * - A clean recorded baseline with drift (`update_available`, `placeholder_drift`)
	 *   is left for sync, exactly as decideInstall() does. InstallCommand rejects a
	 *   different recorded environment before planning, so such a baseline belongs to
	 *   the requested environment at an older package revision.
	 * - Other existing targets are adopted only when their bytes already equal the
	 *   desired rendering. Otherwise they conflict with their status as the reason:
	 *   without a matching baseline the installer cannot tell another environment, an
	 *   older package revision and a local edit apart.
	 *
	 * Notes:
	 * - --force never applies here; switching environments belongs to `environment`.
	 *
	 * @param array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function decideInitialEnvironment(string $status, array $ctx): array {
		if ($status === 'missing') {
			return $this->createWith($ctx, $ctx['pkgPlaceholders']);
		}

		if ($status === 'update_available' || $status === 'placeholder_drift') {
			return ['action' => 'none'];
		}

		$ck = $ctx['currentCk'] ?? $this->render($ctx['sourceAbs'], $ctx['pkgPlaceholders']);
		$disk = (string)$ctx['disk'];

		if (!Checksum::matches($disk, $ck[1])) {
			return [
				'action' => 'conflict',
				'reason' => $status,
			];
		}

		if ($this->recordedBaselineMatches($ctx, $ck)) {
			return ['action' => 'none', 'reason' => 'environment_up_to_date', 'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck)];
		}

		return [
			'action' => 'register_state',
			'reason' => 'adopt_environment_baseline',
			'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck),
		];
	}

	/**
	 * Environment switch: desired bytes are authoritative for managed targets.
	 *
	 * @param array<string,mixed> $ctx
	 * @return array<string,mixed>
	 */
	private function decideEnvironment(string $status, array $ctx): array {
		if ($status === 'missing') {
			return $this->createWith($ctx, $ctx['pkgPlaceholders']);
		}

		$ck = $ctx['currentCk'] ?? $this->render($ctx['sourceAbs'], $ctx['pkgPlaceholders']);
		$disk = (string)$ctx['disk'];

		if (Checksum::matches($disk, $ck[1])) {
			if ($this->recordedBaselineMatches($ctx, $ck)) {
				return ['action' => 'none', 'reason' => 'environment_up_to_date', 'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck)];
			}

			return [
				'action' => 'register_state',
				'reason' => 'adopt_environment_baseline',
				'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck),
			];
		}

		$apply = $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck);
		$apply['backup_required'] = true;

		return [
			'action' => 'update',
			'backup' => true,
			'reason' => 'environment_overwrite',
			'apply' => $apply,
		];
	}

	/**
	 * Compare the complete reproduction input, even when rendered bytes are equal.
	 *
	 * @param array<string,mixed> $ctx Current file context.
	 * @param array{0:string,1:string} $ck Current stub and rendered checksums.
	 * @return bool Whether repair can already reproduce the current source/input.
	 */
	private function recordedBaselineMatches(array $ctx, array $ck): bool {
		$stateFile = $ctx['stateFile'];
		return \is_array($stateFile)
			&& ($stateFile['source'] ?? null) === $ctx['normSource']
			&& ($stateFile['type'] ?? null) === $ctx['type']
			&& ($stateFile['policy'] ?? null) === $ctx['policy']
			&& ($stateFile['placeholders'] ?? null) === $ctx['pkgPlaceholders']
			&& Checksum::equals((string)($stateFile['stub_checksum'] ?? ''), $ck[0])
			&& Checksum::equals((string)($stateFile['rendered_checksum'] ?? ''), $ck[1]);
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
				$ck = $ctx['currentCk'];
				return $this->recordedBaselineMatches($ctx, $ck)
					? ['action' => 'none']
					: ['action' => 'register_state', 'reason' => 'adopt_clean_baseline', 'apply' => $this->applyState($ctx, $ctx['pkgPlaceholders'], $ck)];

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
	// .gitignore block
	// ----------------------------------------------------------------

	/**
	 * Plan the installer-owned block in the application's .gitignore.
	 *
	 * Behavior:
	 * - A missing .gitignore in a Git checkout is created with the block alone
	 *   (`update_available`, reason `gitignore_missing`, action `create`). Outside a Git
	 *   checkout, such as an exported build or a deploy tree, and whenever the block would
	 *   be empty, a missing .gitignore is reported as `absent` and left missing.
	 * - A .gitignore the installer cannot maintain is reported as `unsupported` and left
	 *   alone; it never fails the command. That covers a symlink, which Git does not read
	 *   from the working tree, a path PathGuard rejects, a non-regular file, and
	 *   unreadable bytes.
	 * - The block is generated from the manifests, so any difference is an update
	 *   without backup and without state. Git history keeps the previous bytes.
	 * - Malformed markers are a conflict for write commands and only a report for status.
	 * - `newly_ignored` lists managed targets the current block does not ignore yet.
	 *   Files that Git already tracks stay tracked until they are untracked.
	 *
	 * @param string $command Planner command.
	 * @param array<string,mixed> $rules Output of ScaffoldManifestLocator::gitignoreRules().
	 * @return array<string,mixed> Block entry; `_apply` is present only for a create or update.
	 * @throws InstallerException When a managed target cannot be expressed as a .gitignore line.
	 */
	private function planGitignore(string $command, array $rules): array {
		$targets = \array_map('strval', (array)($rules['targets'] ?? []));
		$body = GitignoreBlock::body((array)($rules['ignore'] ?? []), (array)($rules['exceptions'] ?? []), $targets);
		$entry = ['target' => GitignoreBlock::FILE];
		$unsupported = static fn(string $reason, string $error): array => $entry + [
			'status' => 'unsupported',
			'reason' => $reason,
			'action' => 'none',
			'newly_ignored' => [],
			'error' => $error,
		];

		// Git opens .gitignore without following symlinks, so a block behind one has no effect.
		$link = $this->pathGuard->appRoot() . '/' . GitignoreBlock::FILE;
		\clearstatcache(true, $link);
		if (\is_link($link)) {
			return $unsupported('gitignore_symlink', GitignoreBlock::FILE . ' is a symlink, and Git does not read a symlinked .gitignore from the working tree.');
		}

		try {
			$path = $this->pathGuard->resolveTarget(GitignoreBlock::FILE);
		} catch (InstallerException $e) {
			return $unsupported('gitignore_unsafe_path', $e->getMessage());
		}

		\clearstatcache(true, $path);
		if (\file_exists($path) && !\is_file($path)) {
			return $unsupported('gitignore_not_regular_file', 'Not a regular file: ' . GitignoreBlock::FILE);
		}

		$exists = \is_file($path);
		if (!$exists && ($body === [] || !$this->insideGitCheckout())) {
			return $entry + ['status' => 'absent', 'reason' => 'gitignore_missing', 'action' => 'none', 'newly_ignored' => []];
		}

		$bytes = $exists ? @\file_get_contents($path) : '';
		if ($bytes === false) {
			return $unsupported('gitignore_unreadable', 'Cannot read ' . GitignoreBlock::FILE . '.');
		}

		try {
			$current = GitignoreBlock::extract($bytes);
			$updated = GitignoreBlock::apply($bytes, $body);
		} catch (ConflictException $e) {
			return $entry + [
				'status' => 'malformed',
				'reason' => 'block_malformed',
				'action' => $command === 'status' ? 'none' : 'conflict',
				'newly_ignored' => [],
				'error' => $e->getMessage(),
			];
		}

		if ($updated === $bytes) {
			return $entry + ['status' => 'up_to_date', 'reason' => 'matches_manifests', 'action' => 'none', 'newly_ignored' => []];
		}

		$newlyIgnored = [];
		foreach ($targets as $target) {
			if ($current === null || !\in_array(GitignoreBlock::literal($target), $current, true)) {
				$newlyIgnored[] = $target;
			}
		}
		$newlyIgnored = \array_values(\array_unique($newlyIgnored));
		\sort($newlyIgnored, \SORT_STRING);

		$entry += [
			'status' => 'update_available',
			'reason' => match (true) {
				!$exists => 'gitignore_missing',
				$current === null => 'block_missing',
				$body === [] => 'block_obsolete',
				default => 'block_drift',
			},
			'action' => $command === 'status' ? 'none' : ($exists ? 'update' : 'create'),
			'newly_ignored' => $newlyIgnored,
		];
		if ($entry['action'] !== 'none') {
			$entry['_apply'] = [
				'target' => GitignoreBlock::FILE,
				'target_abs' => $path,
				'target_exists' => $exists,
				'target_checksum' => Checksum::sha256($bytes),
				'body' => $body,
			];
		}

		return $entry;
	}


	/**
	 * Return whether the application root lies inside a Git checkout.
	 *
	 * Behavior:
	 * - Looks for a `.git` entry in the application root and in every parent directory up
	 *   to the filesystem root. A directory marks a repository; a file marks a linked
	 *   worktree or a submodule.
	 *
	 * Notes:
	 * - Presence only: Git is neither run nor required, and the repository is not
	 *   validated. Walks the resolved application root, like Git's own discovery.
	 * - Probes are silent, so an open_basedir restriction reads as "no .git here" instead
	 *   of printing warnings into JSON output.
	 *
	 * @return bool True when a `.git` directory or file exists at or above the app root.
	 */
	private function insideGitCheckout(): bool {
		$directory = $this->pathGuard->appRoot();
		while (true) {
			if (@\file_exists($directory . '/.git')) {
				return true;
			}
			$parent = \dirname($directory);
			if ($parent === $directory) {
				return false;
			}
			$directory = $parent;
		}
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
		$disk = $ctx['disk'];
		if ($ctx['exists'] && $disk === null) {
			$disk = \file_get_contents($ctx['targetAbs']);
			if ($disk === false) {
				throw new FilesystemException('Cannot snapshot target: ' . $ctx['normTarget']);
			}
		}
		return [
			'target_exists'     => $ctx['exists'],
			'target_checksum'   => $ctx['exists'] ? Checksum::sha256($disk) : null,
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
