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

use CitOmni\Installer\Exception\FilesystemException;
use CitOmni\Installer\Support\AtomicFileWriter;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Util\UnifiedDiff;

/**
 * Compare app-owned root files with a pinned citomni/app-skeleton reference set.
 *
 * Behavior:
 * - .gitignore, .gitattributes, and composer.json are text-compared after normalizing
 *   line endings and ignoring one final newline difference.
 * - composer.json remains advisory because real apps are expected to diverge and the
 *   installer manages classmap-authoritative by environment.
 * - A differing or missing app-owned file is never replaced. The reference is written
 *   as `<target>.new` for human review.
 * - Generated `.new` reference sidecars are overwritten on every real run so they always represent the current pinned skeleton reference.
 *
 * Notes:
 * - This audit is advisory and does not participate in scaffold state.
 * - The caller owns remote fetching so network failure can be recovered independently
 *   from local scaffold migration.
 */
final class AuditAppSkeletonMigration {

	public function __construct(private readonly PathGuard $pathGuard) {
	}


	/**
	 * Apply one app-skeleton reference audit.
	 *
	 * @param array{sha:string,files:array<string,array{url:string,bytes:string}>} $reference
	 * @param bool $dryRun Whether sidecars must remain unwritten.
	 * @return array<string,mixed> Structured audit result with per-file diffs.
	 */
	public function apply(array $reference, bool $dryRun): array {
		$sha = (string)($reference['sha'] ?? '');
		$resultFiles = [];
		$manual = [];

		foreach ((array)($reference['files'] ?? []) as $target => $remote) {
			$target = (string)$target;
			$desired = (string)($remote['bytes'] ?? '');
			$url = (string)($remote['url'] ?? '');
			$targetAbs = $this->pathGuard->resolveTarget($target);
			$newAbs = $this->pathGuard->resolveTarget($target . '.new');

			if (\file_exists($targetAbs) && !\is_file($targetAbs)) {
				throw new FilesystemException('App-skeleton audit target is not a regular file: ' . $target);
			}

			$exists = \is_file($targetAbs);
			$current = '';
			if ($exists) {
				$current = \file_get_contents($targetAbs);
				if ($current === false) {
					throw new FilesystemException('Cannot read app-skeleton audit target: ' . $target);
				}
			}

			$currentComparable = $this->normalizeTextForComparison($current);
			$desiredComparable = $this->normalizeTextForComparison($desired);

			if ($exists && $currentComparable === $desiredComparable) {
				$resultFiles[] = [
					'target' => $target,
					'status' => 'identical',
					'action' => 'none',
					'source_url' => $url,
					'diff' => '',
				];
				continue;
			}

			$sidecarStatus = $this->writeSidecar($newAbs, $desired, $dryRun);
			$reason = $exists ? 'app_skeleton_difference' : 'app_skeleton_target_missing';
			$resultFiles[] = [
				'target' => $target,
				'status' => $exists ? 'different' : 'missing',
				'action' => 'write_new',
				'reason' => $reason,
				'new_path' => $newAbs,
				'sidecar_status' => $sidecarStatus,
				'source_url' => $url,
				'diff' => UnifiedDiff::create($currentComparable, $desiredComparable, $target, $target . '.new'),
			];
			$manual[] = [
				'target' => $target,
				'reason' => $reason,
				'new_path' => $newAbs,
				'sidecar_status' => $sidecarStatus,
			];
		}

		return [
			'available' => true,
			'source' => 'citomni/app-skeleton',
			'commit' => $sha,
			'dry_run' => $dryRun,
			'files' => $resultFiles,
			'manual_review' => $manual,
		];
	}


	/**
	 * Normalize app-skeleton text for human-review equality.
	 *
	 * CRLF/CR differences and the presence of one final newline are not actionable
	 * app-skeleton drift. Real textual changes remain significant.
	 */
	private function normalizeTextForComparison(string $bytes): string {
		$normalized = \str_replace(["\r\n", "\r"], "\n", $bytes);
		return \str_ends_with($normalized, "\n") ? \substr($normalized, 0, -1) : $normalized;
	}

	/**
	 * Write the current generated reference sidecar.
	 *
	 * @return string written|planned|conflict
	 */
	private function writeSidecar(string $newAbs, string $desired, bool $dryRun): string {
		if (\file_exists($newAbs) && !\is_file($newAbs)) {
			return 'conflict';
		}

		if ($dryRun) {
			return 'planned';
		}

		AtomicFileWriter::write($newAbs, $desired);
		return 'written';
	}
}
