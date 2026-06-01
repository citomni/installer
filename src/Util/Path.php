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
 * Pure path-safety primitives for manifest-relative paths and containment checks.
 *
 * These are the string-level building blocks behind Support\PathGuard (contract §4).
 * They contain NO filesystem access: PathGuard performs the realpath resolution and
 * then composes these predicates. Keeping the logic here makes it unit-testable
 * without a filesystem and keeps PathGuard focused on IO + error policy.
 *
 * Two distinct domains are handled, deliberately not conflated:
 *
 * 1) Manifest-relative paths (manifest `target` / `source`): MUST use "/" only,
 *    MUST be relative, and MUST NOT contain "..", drive letters, UNC prefixes, or
 *    stream wrappers. A backslash is INVALID and is rejected, never silently
 *    converted (the predicates below reflect that). See isSafeRelative()/normalizeRelative().
 *
 * 2) Already-resolved absolute paths (post-realpath, platform separators): used only
 *    for the containment test. See isInside().
 *
 * Notes:
 * - Pure utility: no IO, no App, no state.
 * - Containment is byte-exact and case-sensitive; on case-insensitive filesystems the
 *   caller resolves via realpath first, which canonicalizes both operands consistently.
 *   No platform-specific complexity is added in MVP (§5).
 */
final class Path {

	/**
	 * Not instantiable: pure static utility.
	 */
	private function __construct() {
	}


	// ----------------------------------------------------------------
	// Predicates (manifest-relative path defects)
	// ----------------------------------------------------------------

	/**
	 * True if the path contains a backslash (invalid in manifest paths).
	 *
	 * @param  string $path
	 * @return bool
	 */
	public static function containsBackslash(string $path): bool {
		return \str_contains($path, '\\');
	}


	/**
	 * True if the path is POSIX-absolute (leading "/").
	 *
	 * @param  string $path
	 * @return bool
	 */
	public static function isAbsolute(string $path): bool {
		return \str_starts_with($path, '/');
	}


	/**
	 * True if the path begins with a Windows drive letter (e.g. "C:\", "C:/", "C:foo").
	 *
	 * @param  string $path
	 * @return bool
	 */
	public static function hasDriveLetter(string $path): bool {
		return (bool)\preg_match('/^[A-Za-z]:/', $path);
	}


	/**
	 * True if the path is a UNC-style root ("\\server\share" or "//server/share").
	 *
	 * @param  string $path
	 * @return bool
	 */
	public static function isUnc(string $path): bool {
		return \str_starts_with($path, '\\\\') || \str_starts_with($path, '//');
	}


	/**
	 * True if the path references a stream wrapper ("php://", "phar://", etc.).
	 *
	 * A legitimate scaffold-relative path never contains "://", so any occurrence is rejected.
	 *
	 * @param  string $path
	 * @return bool
	 */
	public static function hasStreamWrapper(string $path): bool {
		return \str_contains($path, '://');
	}


	/**
	 * True if any "/"-separated segment is a parent reference ("..").
	 *
	 * @param  string $path
	 * @return bool
	 */
	public static function hasTraversal(string $path): bool {
		foreach (\explode('/', $path) as $segment) {
			if ($segment === '..') {
				return true;
			}
		}

		return false;
	}


	// ----------------------------------------------------------------
	// Composite gate + normalization (manifest-relative paths)
	// ----------------------------------------------------------------

	/**
	 * Validate a manifest-relative path against every rule in contract §4.
	 *
	 * This is the single gate PathGuard should call before resolving/comparing. The
	 * granular predicates above exist so PathGuard can report a precise reason; this
	 * method is the authoritative pass/fail.
	 *
	 * Behavior:
	 * - Rejects: empty, backslash, stream wrapper, drive letter, UNC, absolute, "..".
	 * - Rejects paths that normalize to empty (e.g. "." or "./").
	 *
	 * @param  string $path  Manifest-relative path using "/" separators.
	 * @return bool          True iff the path is a safe, non-empty relative path.
	 */
	public static function isSafeRelative(string $path): bool {
		if ($path === '') {
			return false;
		}

		if (self::containsBackslash($path)) {
			return false;
		}

		if (self::hasStreamWrapper($path)) {
			return false;
		}

		if (self::hasDriveLetter($path)) {
			return false;
		}

		if (self::isUnc($path)) {
			return false;
		}

		if (self::isAbsolute($path)) {
			return false;
		}

		if (self::hasTraversal($path)) {
			return false;
		}

		return self::normalizeRelative($path) !== '';
	}


	/**
	 * Collapse a relative path to its canonical form for use as a comparison key.
	 *
	 * Behavior:
	 * - Drops empty segments (collapses "//", strips trailing "/") and "." segments.
	 * - Does NOT resolve "..": traversal is rejected by isSafeRelative(), not folded here.
	 * - Does NOT touch backslashes: those are invalid and rejected upstream.
	 *
	 * Notes:
	 * - Assumes a path that has already passed isSafeRelative(). On unvalidated input it
	 *   is total (never throws) but may return a still-unsafe string.
	 *
	 * @param  string $path  Manifest-relative path using "/" separators.
	 * @return string        Canonical "/"-joined path (may be "" for "."/"./").
	 */
	public static function normalizeRelative(string $path): string {
		$out = [];

		foreach (\explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			$out[] = $segment;
		}

		return \implode('/', $out);
	}


	// ----------------------------------------------------------------
	// Containment (resolved absolute paths)
	// ----------------------------------------------------------------

	/**
	 * True if a resolved path is the base itself or nested under it.
	 *
	 * Backs the "resolved target MUST stay under app-root / source under package-root"
	 * rule (§4). Operates on already-resolved absolute paths (post-realpath); it does
	 * not access the filesystem and does not resolve "." or "..".
	 *
	 * Behavior:
	 * - Normalizes separators to "/" and strips trailing slashes on both operands.
	 * - Uses a "/" boundary so "/app" does not match "/application".
	 * - Returns false on empty base.
	 *
	 * @param  string $base  Resolved absolute base directory (app-root or package-root).
	 * @param  string $path  Resolved absolute candidate path.
	 * @return bool          True iff $path equals $base or sits beneath it.
	 */
	public static function isInside(string $base, string $path): bool {
		$base = \rtrim(\str_replace('\\', '/', $base), '/');
		$path = \rtrim(\str_replace('\\', '/', $path), '/');

		if ($base === '') {
			return false;
		}

		if ($path === $base) {
			return true;
		}

		return \str_starts_with($path, $base . '/');
	}

}
