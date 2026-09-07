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
 * Executes Composer commands in one application root.
 *
 * This class owns process mechanics only. It has no knowledge of CitOmni
 * environments, Composer policy, scaffold state, or command orchestration.
 *
 * Behavior:
 * - Commands are always executed as argv arrays; no shell command string is built.
 * - On Windows, Composer's PHAR launcher is preferred and executed through PHP_BINARY.
 * - stdout/stderr are captured through temporary files to avoid Windows pipe stalls.
 */
final class ComposerRunner {

	private const TEMP_PREFIX = 'citomni_composer_';

	private string $appRoot;
	private string $binary;
	private ?array $commandPrefix = null;


	/**
	 * @param string $appRoot Application root used as Composer working directory.
	 * @param string $binary Composer executable name or absolute path.
	 * @throws InstallerException When the app root is invalid.
	 * @throws \InvalidArgumentException When the binary is empty.
	 */
	public function __construct(string $appRoot, string $binary = 'composer') {
		$resolved = \realpath($appRoot);
		if ($resolved === false || !\is_dir($resolved)) {
			throw new InstallerException(\sprintf('Composer working directory does not exist: %s', $appRoot));
		}

		$binary = \trim($binary);
		if ($binary === '') {
			throw new \InvalidArgumentException('Composer binary cannot be empty.');
		}

		$this->appRoot = $resolved;
		$this->binary = $binary;
	}


	/**
	 * Check whether Composer can be started successfully in the application root.
	 *
	 * @return bool True when `composer --version` exits successfully.
	 */
	public function isAvailable(): bool {
		try {
			$result = $this->run(['--version', '--no-ansi']);
		} catch (InstallerException) {
			return false;
		}

		return $result['exit_code'] === 0;
	}


	/**
	 * Verify Composer's effective project and vendor directory before materialization.
	 *
	 * @return void
	 * @throws InstallerException When an override selects a different project/tree.
	 */
	public function assertProjectContext(): void {
		$project = \getenv('COMPOSER');
		if (\is_string($project) && $project !== '') {
			$absolute = \str_starts_with($project, '/') || \preg_match('/^[A-Za-z]:[\\\\\/]/', $project) === 1 || \str_starts_with($project, '\\\\');
			$path = $absolute ? $project : $this->appRoot . '/' . $project;
			if ($this->pathIdentity($path) !== $this->pathIdentity($this->appRoot . '/composer.json')) {
				throw new InstallerException('COMPOSER selects a different project file. Use the application composer.json for materialization.');
			}
		}
		$result = $this->run(['config', 'vendor-dir', '--absolute', '--no-plugins', '--no-scripts', '--no-interaction']);
		if ($result['exit_code'] !== 0) {
			throw new InstallerException('Unable to determine Composer vendor directory: ' . \trim($result['stderr'] ?: $result['stdout']));
		}
		$vendor = \trim($result['stdout']);
		if ($vendor === '' || $this->pathIdentity($vendor) !== $this->pathIdentity($this->appRoot . '/vendor')) {
			throw new InstallerException('Composer vendor-dir must resolve to this application vendor directory. Check COMPOSER_VENDOR_DIR and local/global Composer configuration.');
		}
	}

	/** Compare existing filesystem identities without relying on the process cwd. */
	private function pathIdentity(string $path): string {
		$real = \realpath($path);
		if ($real === false) {
			throw new InstallerException('Composer context path does not exist: ' . $path);
		}
		$real = \str_replace('\\', '/', $real);
		return \PHP_OS_FAMILY === 'Windows' ? \strtolower($real) : $real;
	}


	/**
	 * Execute one Composer command using proc_open argv form.
	 *
	 * stdout and stderr are redirected to temporary files instead of anonymous
	 * pipes. This avoids Windows pipe-read stalls while preserving shell-free argv
	 * execution and deterministic output capture.
	 *
	 * @param array<int,string> $args Arguments after the Composer executable.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 * @throws \InvalidArgumentException When an argument is not a string.
	 * @throws InstallerException When Composer cannot be resolved or started.
	 */
	public function run(array $args): array {
		$normalized = $this->normalizeArgs($args);
		$command = \array_merge($this->commandPrefix(), $normalized);

		$stdoutFile = '';
		$stderrFile = '';
		$process = null;

		try {
			$stdoutFile = $this->createTempFile();
			$stderrFile = $this->createTempFile();
			$nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
			$descriptors = [
				0 => ['file', $nullDevice, 'r'],
				1 => ['file', $stdoutFile, 'w'],
				2 => ['file', $stderrFile, 'w'],
			];

			$pipes = [];
			$process = @\proc_open($command, $descriptors, $pipes, $this->appRoot);
			if (!\is_resource($process)) {
				throw new InstallerException(\sprintf('Unable to start Composer executable: %s', $this->binary));
			}

			$exitCode = -1;
			while (true) {
				$status = \proc_get_status($process);
				if (($status['running'] ?? false) === false) {
					$exitCode = (int)($status['exitcode'] ?? -1);
					break;
				}

				\usleep(20_000);
			}

			$closeCode = \proc_close($process);
			$process = null;
			if ($exitCode < 0 && $closeCode >= 0) {
				$exitCode = $closeCode;
			}

			return [
				'exit_code' => $exitCode,
				'stdout' => $this->readTempFile($stdoutFile),
				'stderr' => $this->readTempFile($stderrFile),
			];
		} finally {
			if (\is_resource($process)) {
				$status = \proc_get_status($process);
				if (($status['running'] ?? false) === true) {
					@\proc_terminate($process);
				}
				@\proc_close($process);
			}

			$this->removeTempFile($stdoutFile);
			$this->removeTempFile($stderrFile);
		}
	}


	// ----------------------------------------------------------------
	// Command resolution
	// ----------------------------------------------------------------

	/**
	 * Resolve the shell-free command prefix used for all Composer calls.
	 *
	 * On Windows the Composer installer normally exposes composer.bat plus a sibling
	 * composer.phar. Batch files require cmd.exe, so the runner deliberately bypasses
	 * the batch wrapper and executes the PHAR with the same PHP binary as the installer.
	 *
	 * @return array<int,string> Executable argv prefix.
	 * @throws InstallerException When no shell-free Composer executable can be resolved.
	 */
	private function commandPrefix(): array {
		if ($this->commandPrefix !== null) {
			return $this->commandPrefix;
		}

		if (\is_file($this->binary) && \strtolower(\pathinfo($this->binary, \PATHINFO_EXTENSION)) === 'phar') {
			return $this->commandPrefix = [\PHP_BINARY, \realpath($this->binary)];
		}

		if (\PHP_OS_FAMILY !== 'Windows') {
			return $this->commandPrefix = [$this->binary];
		}

		$explicit = $this->resolveExplicitWindowsBinary($this->binary);
		if ($explicit !== null) {
			return $this->commandPrefix = $explicit;
		}

		$phar = $this->findOnPath($this->binary . '.phar');
		if ($phar !== null) {
			return $this->commandPrefix = [\PHP_BINARY, $phar];
		}

		foreach ([$this->binary . '.exe', $this->binary . '.com'] as $name) {
			$executable = $this->findOnPath($name);
			if ($executable !== null) {
				return $this->commandPrefix = [$executable];
			}
		}

		$batch = $this->findOnPath($this->binary . '.bat');
		if ($batch !== null) {
			$phar = \dirname($batch) . '/' . \pathinfo($batch, \PATHINFO_FILENAME) . '.phar';
			if (\is_file($phar)) {
				return $this->commandPrefix = [\PHP_BINARY, $phar];
			}
		}

		throw new InstallerException(\sprintf(
			'Unable to resolve a shell-free Composer executable on Windows for: %s',
			$this->binary
		));
	}


	/**
	 * Resolve an explicitly supplied Windows binary path or filename.
	 *
	 * @param string $binary Configured Composer binary.
	 * @return array<int,string>|null Executable argv prefix, or null when PATH lookup should continue.
	 * @throws InstallerException When an explicit batch/cmd launcher has no sibling PHAR.
	 */
	private function resolveExplicitWindowsBinary(string $binary): ?array {
		$path = \is_file($binary) ? (\realpath($binary) ?: $binary) : null;
		if ($path === null && \pathinfo($binary, \PATHINFO_EXTENSION) !== '') {
			$path = $this->findOnPath($binary);
		}
		if ($path === null) {
			return null;
		}

		$extension = \strtolower((string)\pathinfo($path, \PATHINFO_EXTENSION));
		if ($extension === 'phar') {
			return [\PHP_BINARY, $path];
		}
		if ($extension === 'exe' || $extension === 'com') {
			return [$path];
		}
		if ($extension === 'bat' || $extension === 'cmd') {
			$phar = \dirname($path) . '/' . \pathinfo($path, \PATHINFO_FILENAME) . '.phar';
			if (\is_file($phar)) {
				return [\PHP_BINARY, $phar];
			}

			throw new InstallerException(\sprintf(
				'Composer batch launcher has no sibling PHAR for shell-free execution: %s',
				$path
			));
		}

		return null;
	}


	/**
	 * Find one file by exact filename in PATH without invoking a shell.
	 *
	 * @param string $filename Filename to locate.
	 * @return string|null Absolute path when found.
	 */
	private function findOnPath(string $filename): ?string {
		$path = \getenv('PATH');
		if (!\is_string($path) || $path === '') {
			$path = \getenv('Path');
		}
		if (!\is_string($path) || $path === '') {
			return null;
		}

		foreach (\explode(\PATH_SEPARATOR, $path) as $directory) {
			$directory = \trim($directory, " \t\n\r\0\x0B\"");
			if ($directory === '') {
				continue;
			}

			$candidate = \rtrim($directory, "/\\") . '/' . $filename;
			if (!\is_file($candidate)) {
				continue;
			}

			$resolved = \realpath($candidate);
			return $resolved === false ? $candidate : $resolved;
		}

		return null;
	}


	// ----------------------------------------------------------------
	// Process helpers
	// ----------------------------------------------------------------

	/**
	 * @param array<int,mixed> $args Composer arguments.
	 * @return array<int,string> Normalized argument list.
	 * @throws \InvalidArgumentException When an argument is not a string.
	 */
	private function normalizeArgs(array $args): array {
		$normalized = [];
		foreach (\array_values($args) as $i => $arg) {
			if (!\is_string($arg)) {
				throw new \InvalidArgumentException("Composer argument at position {$i} must be a string.");
			}
			$normalized[] = $arg;
		}

		return $normalized;
	}


	/**
	 * Create one temporary output capture file.
	 *
	 * @return string Absolute temporary path.
	 * @throws InstallerException When the file cannot be created.
	 */
	private function createTempFile(): string {
		$path = \tempnam(\sys_get_temp_dir(), self::TEMP_PREFIX);
		if ($path === false) {
			throw new InstallerException('Unable to create temporary file for Composer output.');
		}

		return $path;
	}


	/**
	 * Read one temporary output capture file.
	 *
	 * @param string $path Absolute temporary path.
	 * @return string Captured bytes.
	 * @throws InstallerException When the file cannot be read.
	 */
	private function readTempFile(string $path): string {
		$bytes = \file_get_contents($path);
		if ($bytes === false) {
			throw new InstallerException(\sprintf('Unable to read Composer output file: %s', $path));
		}

		return $bytes;
	}


	/**
	 * Remove one temporary output capture file best-effort.
	 *
	 * @param string $path Absolute temporary path.
	 * @return void
	 */
	private function removeTempFile(string $path): void {
		if (\is_file($path)) {
			@\unlink($path);
		}
	}
}
