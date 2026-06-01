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

namespace CitOmni\Installer\Support;

use CitOmni\Installer\Util\Path;
use CitOmni\Installer\Exception\InstallerException;

/**
 * App-aware guard that validates manifest-relative paths and resolves them safely
 * under a trusted base: app-root for targets, package-root for sources (contract §4).
 *
 * Responsibilities split (deliberate layering):
 * - Util\Path provides the pure, OS-independent rejection rules (backslash, absolute,
 *   drive letter, UNC, stream wrapper, traversal) and the containment string test.
 * - PathGuard owns the IO concern Util cannot: resolving against a real (realpath'd)
 *   base and rejecting symlinked ancestors that escape that base.
 *
 * The manifest-validation pass is pure string work, so Windows-style attacks
 * ("C:\\...", "\\\\server\\share", "..\\..\\x", "phar://...") are rejected on every
 * platform, including a POSIX CI host.
 *
 * Behavior:
 * - Targets need not exist yet (installer is about to create them). Resolution walks
 *   the longest existing prefix, realpath-resolves it, verifies it stays inside the
 *   base, then appends the remaining (already-validated, traversal-free) tail.
 * - Sources are resolved the same way; PathGuard does NOT assert source existence.
 *   "Source missing" is a write-plan condition (§9) reported by the renderer, not a
 *   path-safety violation. PathGuard only guarantees that whatever the path resolves
 *   to cannot escape the package root.
 *
 * Notes:
 * - App-aware, instantiated explicitly. Not a service. No App, no kernel dependency:
 *   the only "awareness" is the app-root string handed to the constructor.
 * - Throws InstallerException on any unsafe input or unreadable base; never writes.
 */
final class PathGuard {

	/** Resolved, canonical application root (realpath of the constructor argument). */
	private string $appRoot;


	/**
	 * @param  string $appRoot  Absolute or relative path to the application root. Must exist.
	 * @throws InstallerException  When the app root cannot be resolved (missing/unreadable).
	 */
	public function __construct(string $appRoot) {
		$resolved = \realpath($appRoot);

		if ($resolved === false) {
			throw new InstallerException(\sprintf(
				'Application root does not exist or is not readable: %s',
				$appRoot
			));
		}

		$this->appRoot = $resolved;
	}


	/**
	 * The resolved, canonical application root.
	 *
	 * @return string
	 */
	public function appRoot(): string {
		return $this->appRoot;
	}


	/**
	 * Validate a manifest `target` and resolve it to an absolute path under app-root.
	 *
	 * The target need not exist; the returned path is where the installer may write.
	 *
	 * @param  string $relativeTarget  App-relative manifest target (uses "/").
	 * @return string                  Absolute path guaranteed to sit under app-root.
	 * @throws InstallerException      When the path is unsafe or escapes app-root.
	 */
	public function resolveTarget(string $relativeTarget): string {
		return $this->resolveWithinBase($this->appRoot, $relativeTarget, 'target');
	}


	/**
	 * Validate a manifest `source` and resolve it to an absolute path under package-root.
	 *
	 * @param  string $packageRoot      Absolute or relative package root. Must exist.
	 * @param  string $relativeSource   Package-relative manifest source (uses "/").
	 * @return string                   Absolute path guaranteed to sit under package-root.
	 * @throws InstallerException       When the package root is unreadable, or the path
	 *                                  is unsafe or escapes package-root.
	 */
	public function resolveSource(string $packageRoot, string $relativeSource): string {
		$base = \realpath($packageRoot);

		if ($base === false) {
			throw new InstallerException(\sprintf(
				'Package root does not exist or is not readable: %s',
				$packageRoot
			));
		}

		return $this->resolveWithinBase($base, $relativeSource, 'source');
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Validate a manifest-relative path and resolve it under a realpath'd base.
	 *
	 * Behavior:
	 * - Rejects unsafe paths up front (delegated to Util\Path::isSafeRelative).
	 * - Walks the longest existing prefix and realpath-resolves it, so a symlinked
	 *   intermediate directory pointing outside the base is detected and rejected.
	 * - Appends the remaining non-existent, traversal-free tail and re-checks containment.
	 *
	 * @param  string $baseReal  Already-resolved (realpath'd) base directory.
	 * @param  string $relative  Manifest-relative path using "/".
	 * @param  string $kind      Diagnostic label ("target" | "source").
	 * @return string            Absolute resolved path under $baseReal.
	 * @throws InstallerException
	 */
	private function resolveWithinBase(string $baseReal, string $relative, string $kind): string {
		$this->assertSafeRelative($kind, $relative);

		$rel   = Path::normalizeRelative($relative);
		$parts = \explode('/', $rel);

		// Walk the longest existing prefix so symlinked ancestors get resolved.
		$existing = $baseReal;
		$consumed = 0;
		foreach ($parts as $part) {
			$candidate = $existing . '/' . $part;
			if (!\file_exists($candidate)) {
				break;
			}
			$existing = $candidate;
			$consumed++;
		}

		$ancestor = \realpath($existing);
		if ($ancestor === false) {
			throw new InstallerException(\sprintf(
				'Cannot resolve %s path under base: %s',
				$kind,
				$existing
			));
		}

		if (!Path::isInside($baseReal, $ancestor)) {
			throw new InstallerException(\sprintf(
				'Resolved %s escapes its base via symlink: %s is not inside %s',
				$kind,
				$ancestor,
				$baseReal
			));
		}

		$tail     = \array_slice($parts, $consumed);
		$resolved = $tail === [] ? $ancestor : $ancestor . '/' . \implode('/', $tail);

		// Defense in depth: the validated tail carries no "..", so this must already hold.
		if (!Path::isInside($baseReal, $resolved)) {
			throw new InstallerException(\sprintf(
				'Resolved %s escapes its base: %s is not inside %s',
				$kind,
				$resolved,
				$baseReal
			));
		}

		return $resolved;
	}


	/**
	 * Assert that a manifest-relative path is safe, or throw with a precise reason.
	 *
	 * @param  string $kind  Diagnostic label ("target" | "source").
	 * @param  string $path  Manifest-relative path to validate.
	 * @return void
	 * @throws InstallerException
	 */
	private function assertSafeRelative(string $kind, string $path): void {
		if (Path::isSafeRelative($path)) {
			return;
		}

		throw new InstallerException(\sprintf(
			'Invalid manifest %s path %s: %s',
			$kind,
			\var_export($path, true),
			$this->unsafeReason($path)
		));
	}


	/**
	 * Human-readable reason a path failed Util\Path::isSafeRelative().
	 *
	 * Checks mirror the order in isSafeRelative() so the reported reason matches the
	 * rule that actually rejected the path.
	 *
	 * @param  string $path
	 * @return string
	 */
	private function unsafeReason(string $path): string {
		if ($path === '') {
			return 'path is empty';
		}
		if (Path::containsBackslash($path)) {
			return 'backslashes are not allowed (use "/" separators)';
		}
		if (Path::hasStreamWrapper($path)) {
			return 'stream wrappers are not allowed';
		}
		if (Path::hasDriveLetter($path)) {
			return 'drive-letter paths are not allowed';
		}
		if (Path::isUnc($path)) {
			return 'UNC paths are not allowed';
		}
		if (Path::isAbsolute($path)) {
			return 'absolute paths are not allowed';
		}
		if (Path::hasTraversal($path)) {
			return 'parent traversal ("..") is not allowed';
		}

		return 'path is empty after normalization';
	}

}
