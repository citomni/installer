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

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Support\AtomicFileWriter;
use CitOmni\Installer\Support\PathGuard;

/**
 * Reads and writes the app-local installer scaffold state.
 *
 * State format 2 is intentionally strict. An existing state file always records a
 * valid environment alongside its package baselines. Older formats are rejected;
 * the installer never guesses or migrates an environment from legacy state.
 *
 * Safety model:
 * - Every read validates the complete state envelope.
 * - Unknown, old, newer, or malformed state is unsafe and causes an exception.
 * - Existing unsafe state is never overwritten.
 * - writePackages() preserves the already recorded environment and cannot create
 *   initial state on its own.
 * - writePackagesAndEnvironment() is the only initial/environment-changing write.
 */
final class ScaffoldState {

	/** Format version this installer reads and writes. */
	public const FORMAT_VERSION = 2;

	/** Value stamped into `generated_by`. */
	public const GENERATOR = 'citomni/installer';

	/** Canonical app-relative location of the state file. */
	public const RELATIVE_PATH = 'var/state/citomni/installer-scaffold.php';

	/** @param PathGuard $pathGuard Guard for the canonical app-local state path. */
	public function __construct(private readonly PathGuard $pathGuard) {}


	/**
	 * Build a state handler for the canonical location under an application root.
	 *
	 * @param string $appRoot Application root directory.
	 * @return self
	 */
	public static function forAppRoot(string $appRoot): self {
		return new self(new PathGuard($appRoot));
	}


	/**
	 * @return string Absolute path to the state file.
	 */
	public function path(): string {
		return $this->pathGuard->resolveTarget(self::RELATIVE_PATH);
	}


	/**
	 * @return bool Whether the state file exists on disk.
	 */
	public function exists(): bool {
		return \is_file($this->path());
	}


	/**
	 * Read and validate the state file.
	 *
	 * @return array<string,mixed>|null The full validated state, or null when absent.
	 * @throws InstallerException When an existing state file cannot be read safely.
	 */
	public function read(): ?array {
		$path = $this->path();
		\clearstatcache(true, $path);
		if (!\file_exists($path)) {
			return null;
		}
		if (!\is_file($path)) {
			throw new InstallerException('State path is not a regular file: ' . $path);
		}

		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($path, true);
		}

		try {
			$data = include $path;
		} catch (\Throwable $e) {
			throw new InstallerException(\sprintf(
				'State file could not be read safely (parse/runtime error): %s',
				$path
			), 0, $e);
		}

		return $this->validate($data);
	}


	/**
	 * Read just the package baseline map.
	 *
	 * @return array<string,mixed> Packages map, or an empty array when no state exists.
	 * @throws InstallerException When an existing state file cannot be read safely.
	 */
	public function readPackages(): array {
		$state = $this->read();

		return $state === null ? [] : $state['packages'];
	}


	/**
	 * Return the recorded materialized environment.
	 *
	 * Null is possible only when no state file exists. Any existing v2 state with a
	 * missing or invalid environment is unsafe and rejected by read().
	 *
	 * @return Environment|null
	 * @throws InstallerException When an existing state file cannot be read safely.
	 */
	public function environment(): ?Environment {
		$state = $this->read();
		if ($state === null) {
			return null;
		}

		return Environment::from($state['environment']);
	}


	/**
	 * Persist package baselines while preserving the recorded environment.
	 *
	 * This method is for lifecycle operations such as sync and repair. It cannot
	 * create initial state because there is no environment to preserve in that case.
	 *
	 * @param array<string,mixed> $packages Package baseline map.
	 * @return void
	 * @throws InstallerException When no state exists or existing state is unsafe.
	 */
	public function writePackages(array $packages): void {
		$state = $this->read();
		if ($state === null) {
			throw new InstallerException(
				'Cannot write scaffold package state before an environment has been materialized. Run install --environment=<dev|stage|prod> first.'
			);
		}

		$this->writeState($packages, Environment::from($state['environment']));
	}


	/**
	 * Persist package baselines and the authoritative materialized environment.
	 *
	 * This is the commit marker used by initial installation and environment switching.
	 * Callers must invoke it only after the corresponding scaffold and Composer
	 * materialization completed successfully.
	 *
	 * @param array<string,mixed> $packages Package baseline map.
	 * @param Environment $environment Materialized environment to record.
	 * @return void
	 * @throws InstallerException When existing state is unsafe or the write fails.
	 */
	public function writePackagesAndEnvironment(array $packages, Environment $environment): void {
		$this->assertWritable();
		$this->writeState($packages, $environment);
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Ensure any existing state file is safe before it can be replaced.
	 *
	 * @return void
	 * @throws InstallerException
	 */
	private function assertWritable(): void {
		$this->read();
	}


	/**
	 * Write one fully formed v2 state file.
	 *
	 * @param array<string,mixed> $packages Package baseline map.
	 * @param Environment $environment Materialized environment.
	 * @return void
	 * @throws InstallerException On IO failure.
	 */
	private function writeState(array $packages, Environment $environment): void {
		$state = [
			'format_version' => self::FORMAT_VERSION,
			'generated_by'   => self::GENERATOR,
			'generated_at'   => $this->nowIso8601(),
			'environment'    => $environment->value,
			'packages'       => $packages,
		];

		$code = "<?php\n\n"
			. "/*\n"
			. " * CitOmni installer scaffold state - GENERATED FILE, DO NOT EDIT BY HAND.\n"
			. " * Managed by " . self::GENERATOR . ". Manual edits may be overwritten.\n"
			. " */\n\n"
			. 'return ' . \var_export($state, true) . ";\n";

		AtomicFileWriter::write($this->path(), $code);
	}


	/**
	 * Validate the included value as a complete v2 state array.
	 *
	 * @param mixed $data Included state value.
	 * @return array<string,mixed>
	 * @throws InstallerException When the state is malformed or unsupported.
	 */
	private function validate(mixed $data): array {
		if (!\is_array($data)) {
			throw new InstallerException(\sprintf(
				'State file did not return an array; refusing to use it: %s',
				$this->path()
			));
		}

		$version = $data['format_version'] ?? null;
		if (!\is_int($version)) {
			throw new InstallerException(\sprintf(
				'State file is missing a valid integer format_version: %s',
				$this->path()
			));
		}

		if ($version !== self::FORMAT_VERSION) {
			if ($version > self::FORMAT_VERSION) {
				throw new InstallerException(\sprintf(
					'State file format_version %d is newer than this installer supports (%d); upgrade citomni/installer: %s',
					$version,
					self::FORMAT_VERSION,
					$this->path()
				));
			}

			throw new InstallerException(\sprintf(
				'State file format_version %d is not supported. Legacy state is not migrated; recreate installer state with the current materialization flow: %s',
				$version,
				$this->path()
			));
		}

		$environment = $data['environment'] ?? null;
		if (!\is_string($environment) || Environment::tryFrom($environment) === null) {
			throw new InstallerException(\sprintf(
				'State file is malformed: "environment" must be one of %s: %s',
				\implode(', ', Environment::values()),
				$this->path()
			));
		}

		if (!\array_key_exists('packages', $data) || !\is_array($data['packages'])) {
			throw new InstallerException(\sprintf(
				'State file is malformed: "packages" must be an array: %s',
				$this->path()
			));
		}

		return $data;
	}


	/**
	 * @return string Current UTC time as an ISO-8601 string.
	 */
	private function nowIso8601(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
	}
}
