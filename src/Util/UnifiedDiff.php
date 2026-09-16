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

namespace CitOmni\Installer\Util;

/**
 * Build deterministic line-based unified diffs for migration reports.
 *
 * Behavior:
 * - Produces one complete hunk so the report always contains enough surrounding context.
 * - Uses an LCS matrix for ordinary scaffold-sized files.
 * - Falls back to a bounded coarse diff when the matrix would exceed the configured cap.
 * - Normalizes CRLF/CR to LF for display only; source bytes are never modified.
 *
 * Notes:
 * - This helper is for human diagnostics, not patch application.
 * - The matrix cap prevents unusually large app-owned files from turning a migration
 *   report into an avoidable memory spike.
 */
final class UnifiedDiff {

	/** Maximum LCS matrix size before using the bounded coarse fallback. */
	private const MAX_MATRIX_CELLS = 250000;

	private function __construct() {
	}


	/**
	 * Create a unified diff between two text payloads.
	 *
	 * @param string $old Old bytes.
	 * @param string $new New bytes.
	 * @param string $oldLabel Old-side label.
	 * @param string $newLabel New-side label.
	 * @return string Empty string when the inputs are byte-identical.
	 */
	public static function create(string $old, string $new, string $oldLabel, string $newLabel): string {
		if ($old === $new) {
			return '';
		}

		$oldLines = self::lines($old);
		$newLines = self::lines($new);
		if ($oldLines === $newLines) {
			return '--- ' . $oldLabel . "\n"
				. '+++ ' . $newLabel . "\n"
				. "@@ byte-level difference @@\n"
				. "! Text lines are identical; bytes differ in line endings and/or final newline.\n";
		}

		$cells = \count($oldLines) * \count($newLines);
		$operations = $cells <= self::MAX_MATRIX_CELLS
			? self::lcsOperations($oldLines, $newLines)
			: self::coarseOperations($oldLines, $newLines);

		$oldCount = \count($oldLines);
		$newCount = \count($newLines);
		$oldStart = $oldCount === 0 ? 0 : 1;
		$newStart = $newCount === 0 ? 0 : 1;

		$out = '--- ' . $oldLabel . "\n";
		$out .= '+++ ' . $newLabel . "\n";
		$out .= \sprintf('@@ -%d,%d +%d,%d @@', $oldStart, $oldCount, $newStart, $newCount) . "\n";

		foreach ($operations as [$prefix, $line]) {
			$out .= $prefix . $line . "\n";
		}

		return $out;
	}


	/** @return list<string> */
	private static function lines(string $bytes): array {
		if ($bytes === '') {
			return [];
		}

		$normalized = \str_replace(["\r\n", "\r"], "\n", $bytes);
		$lines = \explode("\n", $normalized);
		if (\str_ends_with($normalized, "\n")) {
			\array_pop($lines);
		}

		return $lines;
	}


	/**
	 * Build minimal line operations through a bounded LCS matrix.
	 *
	 * @param list<string> $old
	 * @param list<string> $new
	 * @return list<array{0:string,1:string}>
	 */
	private static function lcsOperations(array $old, array $new): array {
		$n = \count($old);
		$m = \count($new);
		$matrix = [];
		$matrix[$n] = \array_fill(0, $m + 1, 0);

		for ($i = $n - 1; $i >= 0; $i--) {
			$row = \array_fill(0, $m + 1, 0);
			$next = $matrix[$i + 1];

			for ($j = $m - 1; $j >= 0; $j--) {
				$row[$j] = $old[$i] === $new[$j]
					? 1 + $next[$j + 1]
					: \max($next[$j], $row[$j + 1]);
			}

			$matrix[$i] = $row;
		}

		$operations = [];
		$i = 0;
		$j = 0;
		while ($i < $n && $j < $m) {
			if ($old[$i] === $new[$j]) {
				$operations[] = [' ', $old[$i]];
				$i++;
				$j++;
				continue;
			}

			if ($matrix[$i + 1][$j] >= $matrix[$i][$j + 1]) {
				$operations[] = ['-', $old[$i]];
				$i++;
			} else {
				$operations[] = ['+', $new[$j]];
				$j++;
			}
		}

		while ($i < $n) {
			$operations[] = ['-', $old[$i++]];
		}
		while ($j < $m) {
			$operations[] = ['+', $new[$j++]];
		}

		return $operations;
	}


	/**
	 * Produce a bounded fallback diff using the common prefix and suffix as anchors.
	 *
	 * @param list<string> $old
	 * @param list<string> $new
	 * @return list<array{0:string,1:string}>
	 */
	private static function coarseOperations(array $old, array $new): array {
		$n = \count($old);
		$m = \count($new);
		$prefix = 0;
		$maxPrefix = \min($n, $m);
		while ($prefix < $maxPrefix && $old[$prefix] === $new[$prefix]) {
			$prefix++;
		}

		$suffix = 0;
		$maxSuffix = \min($n - $prefix, $m - $prefix);
		while ($suffix < $maxSuffix && $old[$n - 1 - $suffix] === $new[$m - 1 - $suffix]) {
			$suffix++;
		}

		$operations = [];
		for ($i = 0; $i < $prefix; $i++) {
			$operations[] = [' ', $old[$i]];
		}
		for ($i = $prefix; $i < $n - $suffix; $i++) {
			$operations[] = ['-', $old[$i]];
		}
		for ($i = $prefix; $i < $m - $suffix; $i++) {
			$operations[] = ['+', $new[$i]];
		}
		for ($i = $suffix; $i > 0; $i--) {
			$operations[] = [' ', $old[$n - $i]];
		}

		return $operations;
	}
}
