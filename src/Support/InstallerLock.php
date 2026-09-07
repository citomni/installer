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

use CitOmni\Installer\Exception\ConflictException;
use CitOmni\Installer\Exception\FilesystemException;

/**
 * Own one advisory lock for the complete application write lifecycle.
 *
 * Behavior:
 * - Acquire before reading state, discovering manifests, or building a write plan.
 * - Keep the lock through confirmation, scaffold writes, Composer, and final commit.
 * - Never unlink the lock file; replacing its inode would split competing locks.
 * - Dry runs do not acquire a lock and create no lock file.
 *
 * Notes:
 * - The persistent lock is administrative metadata, not scaffold state. A rejected
 *   write command can leave its directory/file behind without materializing the app.
 * - Other programs must cooperate with this lock. Target checks also detect edits
 *   made by editors between planning and apply; they are not an OS compare-and-swap.
 * - Final to preserve the acquire/release lifecycle and resource ownership.
 */
final class InstallerLock {

	public const RELATIVE_PATH = 'var/state/citomni/installer.lock';

	/** @var resource|null */
	private $handle = null;

	public function __construct(private readonly PathGuard $pathGuard) {}

	/**
	 * Acquire immediately or fail without waiting for another command.
	 *
	 * @return void
	 * @throws ConflictException When another installer owns the lock.
	 * @throws FilesystemException When the lock cannot be opened or acquired.
	 */
	public function acquire(): void {
		if ($this->handle !== null) {
			throw new \LogicException('Installer lock is already acquired by this instance.');
		}
		$path = $this->pathGuard->resolveTarget(self::RELATIVE_PATH);
		$dir = \dirname($path);
		if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
			throw new FilesystemException('Unable to create installer lock directory: ' . $dir);
		}
		$path = $this->pathGuard->resolveTarget(self::RELATIVE_PATH);
		$handle = @\fopen($path, 'c+be');
		if ($handle === false) {
			throw new FilesystemException('Unable to open installer lock: ' . $path);
		}
		$wouldBlock = 0;
		if (!\flock($handle, \LOCK_EX | \LOCK_NB, $wouldBlock)) {
			\fclose($handle);
			if ($wouldBlock !== 0) {
				throw new ConflictException('Another installer command is writing this application. Retry after it finishes.');
			}
			throw new FilesystemException('Unable to acquire installer lock: ' . $path);
		}
		$this->handle = $handle;
	}

	/** Release the held resource while retaining the stable lock file. */
	public function release(): void {
		if ($this->handle !== null) {
			\flock($this->handle, \LOCK_UN);
			\fclose($this->handle);
			$this->handle = null;
		}
	}
}
