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

use CitOmni\Installer\Exception\InstallerException;

/**
 * Low-level atomic file writer shared by the installer's write sites.
 *
 * Extracted from ApplyScaffoldPlan and ScaffoldState, which previously carried two
 * byte-for-byte equivalent copies of this logic. It is the single implementation of the
 * installer's write primitive: render to a temp file in the destination directory, flush,
 * best-effort fsync, then rename into place. rename() is atomic on POSIX and modern Windows
 * when both paths live on the same filesystem, which is guaranteed by placing the temp file
 * beside the target.
 *
 * Behavior:
 * - The parent directory is created (recursively, 0775) if missing, before writing.
 * - The temp file name uses a CSPRNG suffix; if the CSPRNG is unavailable the failure is
 *   translated to InstallerException rather than letting \Random\RandomException escape,
 *   so callers keep a single exception currency.
 * - A short write, an open failure, or a failed rename all throw InstallerException. On any
 *   failure the temp file is removed on a best-effort basis.
 * - After a successful rename, opcache_invalidate() is called (best effort) so a subsequent
 *   include of the same path does not serve stale opcodes.
 *
 * Notes:
 * - Stateless, dependency-free, no App and no cfg. Deliberately shaped as a static helper in
 *   the same spirit as Util\Checksum and Util\Path; it lives in Support rather than Util only
 *   because it performs IO. There is nothing to instantiate.
 * - Line endings are never altered: whatever bytes the caller passes are the bytes written.
 * - This class does NOT own backup semantics. A backup is just "read the current bytes, then
 *   write them somewhere else atomically" and stays with the caller that decides to back up.
 */
final class AtomicFileWriter {

	/** Prefix for temp files created during atomic writes. */
	private const TMP_PREFIX = '.citomni-installer.';

	private function __construct() {
	}


	/**
	 * Write bytes to a destination path atomically (temp + fflush/fsync + rename).
	 *
	 * @param  string $absPath  Absolute destination path.
	 * @param  string $bytes    Exact bytes to write (no transformation is applied).
	 * @return void
	 * @throws InstallerException  If the directory cannot be created, the CSPRNG is
	 *                             unavailable, the temp file cannot be opened/written, or the
	 *                             rename into place fails.
	 */
	public static function write(string $absPath, string $bytes): void {
		$dir = \dirname($absPath);
		self::ensureDir($dir);

		// random_bytes() throws \Random\RandomException if the CSPRNG is unavailable. Keep the
		// failure inside the installer's exception currency: InstallerException is what the
		// callers' per-file handlers catch and what the command layer maps to an exit code.
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
			// Best-effort durability; not every filesystem supports fsync.
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

		// Avoid a stale opcode cache serving a previous version of this path.
		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($absPath, true);
		}
	}


	/**
	 * Create a directory (recursively) if it does not already exist.
	 *
	 * @param  string $dir  Absolute directory path.
	 * @return void
	 * @throws InstallerException  If the directory does not exist and cannot be created.
	 */
	private static function ensureDir(string $dir): void {
		if (\is_dir($dir)) {
			return;
		}
		if (!\mkdir($dir, 0775, true) && !\is_dir($dir)) {
			throw new InstallerException(\sprintf('Unable to create directory: %s', $dir));
		}
	}
}
