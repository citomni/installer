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

namespace CitOmni\Installer\State;

use CitOmni\Installer\Exception\InstallerException;

/**
 * Reads and writes the app-local installer state file (contract §5).
 *
 * The state file is a plain PHP file that `return`s an array; it is NOT a Repository
 * and involves no SQL. This class owns the file envelope (format_version, generated_by,
 * generated_at) and the low-level atomic write; the `packages` payload is domain data
 * supplied by the caller (ApplyScaffoldPlan).
 *
 * Safety model:
 * - format_version is validated on every read. A file whose version this installer
 *   cannot read (e.g. a newer version written by a future installer), or that cannot
 *   be parsed/evaluated, is treated as "cannot be read safely" and read() throws.
 * - write() refuses to proceed if an existing file cannot be read safely. This is the
 *   core guarantee: an installer never clobbers a state file it does not understand,
 *   so a downgrade can never destroy a newer installer's state.
 *
 * Atomic write (§5):
 * - Render to a temp file in the SAME directory, flush, best-effort fsync when
 *   available, then rename
 *   over the target. rename() is atomic on POSIX and modern Windows when both paths are
 *   on the same filesystem (guaranteed by writing the temp file beside the target).
 *
 * Notes:
 * - App-aware (knows the app-relative location), instantiated explicitly. Not a service.
 * - Reading executes the file via include; the file is installer-owned. The include is
 *   wrapped to convert parse/runtime failures into a clean InstallerException rather than
 *   a fatal — a deliberate recoverable boundary, not casual \Throwable catching.
 */
final class ScaffoldState {

	/** Format version this installer WRITES. */
	public const FORMAT_VERSION = 1;

	/** Versions this installer can READ (migrate from). MVP reads only the current version. */
	private const READABLE_VERSIONS = [1];

	/** Value stamped into `generated_by`. */
	public const GENERATOR = 'citomni/installer';

	/** Canonical app-relative location of the state file. */
	public const RELATIVE_PATH = 'var/state/citomni/installer-scaffold.php';

	/** Absolute path to the state file. */
	private string $path;


	/**
	 * @param  string $path  Absolute path to the state file.
	 */
	public function __construct(string $path) {
		$this->path = $path;
	}


	/**
	 * Build a state handler for the canonical location under an application root.
	 *
	 * @param  string $appRoot  Application root directory.
	 * @return self
	 */
	public static function forAppRoot(string $appRoot): self {
		return new self(\rtrim($appRoot, "/\\") . '/' . self::RELATIVE_PATH);
	}


	/**
	 * @return string  Absolute path to the state file.
	 */
	public function path(): string {
		return $this->path;
	}


	/**
	 * @return bool  Whether the state file exists on disk.
	 */
	public function exists(): bool {
		return \is_file($this->path);
	}


	/**
	 * Read and validate the state file.
	 *
	 * @return array<string,mixed>|null  The full validated state, or null if no file exists.
	 * @throws InstallerException        If the file exists but cannot be read safely
	 *                                   (parse/runtime error, non-array, missing/invalid
	 *                                   format_version, unknown version, or malformed shape).
	 */
	public function read(): ?array {
		if (!\is_file($this->path)) {
			return null;
		}

		// Avoid a stale opcode cache serving a previous version of this path.
		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($this->path, true);
		}

		try {
			$data = include $this->path;
		} catch (\Throwable $e) {
			throw new InstallerException(\sprintf(
				'State file could not be read safely (parse/runtime error): %s',
				$this->path
			), 0, $e);
		}

		return $this->validate($data);
	}


	/**
	 * Convenience: read just the `packages` map.
	 *
	 * @return array<string,mixed>  Packages map, or an empty array if no file exists.
	 * @throws InstallerException   If the file exists but cannot be read safely.
	 */
	public function readPackages(): array {
		$state = $this->read();

		return $state === null ? [] : $state['packages'];
	}


	/**
	 * Write the state file atomically.
	 *
	 * Refuses to write if an existing file cannot be read safely (unknown format_version,
	 * corrupt, malformed). On success the file is created idempotently, including parent
	 * directories.
	 *
	 * @param  array<string,mixed> $packages  Domain payload (packages map).
	 * @return void
	 * @throws InstallerException  If an existing file is unsafe, or on any IO failure.
	 */
	public function write(array $packages): void {
		// Never clobber a state file we cannot confirm as a known, safe format.
		$this->assertWritable();

		$state = [
			'format_version' => self::FORMAT_VERSION,
			'generated_by'   => self::GENERATOR,
			'generated_at'   => $this->nowIso8601(),
			'packages'       => $packages,
		];

		$code = "<?php\n\n"
			. "/*\n"
			. " * CitOmni installer scaffold state - GENERATED FILE, DO NOT EDIT BY HAND.\n"
			. " * Managed by " . self::GENERATOR . ". Manual edits may be overwritten.\n"
			. " */\n\n"
			. 'return ' . \var_export($state, true) . ";\n";

		$this->atomicWrite($code);
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Ensure any existing state file can be read safely before we overwrite it.
	 *
	 * @return void
	 * @throws InstallerException
	 */
	private function assertWritable(): void {
		// read() throws if an existing file is unreadable or of an unknown format_version.
		$this->read();
	}


	/**
	 * Validate the included value as a known, well-formed state array.
	 *
	 * @param  mixed $data
	 * @return array<string,mixed>
	 * @throws InstallerException
	 */
	private function validate(mixed $data): array {
		if (!\is_array($data)) {
			throw new InstallerException(\sprintf(
				'State file did not return an array; refusing to use it: %s',
				$this->path
			));
		}

		$version = $data['format_version'] ?? null;
		if (!\is_int($version)) {
			throw new InstallerException(\sprintf(
				'State file is missing a valid integer format_version: %s',
				$this->path
			));
		}

		if (!\in_array($version, self::READABLE_VERSIONS, true)) {
			if ($version > self::FORMAT_VERSION) {
				throw new InstallerException(\sprintf(
					'State file format_version %d is newer than this installer supports (%d); upgrade citomni/installer: %s',
					$version,
					self::FORMAT_VERSION,
					$this->path
				));
			}

			throw new InstallerException(\sprintf(
				'State file format_version %d is not supported and no migration is available: %s',
				$version,
				$this->path
			));
		}

		if (!\array_key_exists('packages', $data) || !\is_array($data['packages'])) {
			throw new InstallerException(\sprintf(
				'State file is malformed: "packages" must be an array: %s',
				$this->path
			));
		}

		return $data;
	}


	/**
	 * Atomically write rendered PHP to the state path (temp + fsync + rename).
	 *
	 * @param  string $code  Full PHP file contents to write.
	 * @return void
	 * @throws InstallerException
	 */
	private function atomicWrite(string $code): void {
		$dir = \dirname($this->path);
		$this->ensureDir($dir);

		// Temp file lives in the same directory so rename() stays on one filesystem.
		$tmp    = $dir . '/.installer-scaffold.' . \bin2hex(\random_bytes(8)) . '.tmp';
		$handle = \fopen($tmp, 'wb');
		if ($handle === false) {
			throw new InstallerException(\sprintf('Unable to open temp state file for writing: %s', $tmp));
		}

		try {
			$written = \fwrite($handle, $code);
			if ($written === false || $written !== \strlen($code)) {
				throw new InstallerException(\sprintf('Failed to write complete temp state file: %s', $tmp));
			}

			\fflush($handle);
			// Best-effort durability; not every filesystem supports fsync.
			if (\function_exists('fsync')) {
				@\fsync($handle);
			}
		} catch (\Throwable $e) {
			\fclose($handle);
			@\unlink($tmp);
			throw $e instanceof InstallerException
				? $e
				: new InstallerException(\sprintf('Failed writing temp state file: %s', $tmp), 0, $e);
		}

		\fclose($handle);

		if (!@\rename($tmp, $this->path)) {
			@\unlink($tmp);
			throw new InstallerException(\sprintf('Failed to move state file into place atomically: %s', $this->path));
		}

		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($this->path, true);
		}
	}


	/**
	 * Create a directory (recursively) if it does not already exist.
	 *
	 * @param  string $dir
	 * @return void
	 * @throws InstallerException
	 */
	private function ensureDir(string $dir): void {
		if (\is_dir($dir)) {
			return;
		}

		if (!\mkdir($dir, 0775, true) && !\is_dir($dir)) {
			throw new InstallerException(\sprintf('Unable to create state directory: %s', $dir));
		}
	}


	/**
	 * @return string  Current UTC time as an ISO-8601 (ATOM) string.
	 */
	private function nowIso8601(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
	}

}
