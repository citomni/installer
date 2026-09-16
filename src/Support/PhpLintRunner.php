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
 * Recursively lint app-owned PHP sources with the currently running PHP binary.
 *
 * Behavior:
 * - Scans `.php` and `.php.tpl` files under the application root.
 * - Excludes dependencies, generated/cache/history trees, uploads, Git metadata,
 *   node_modules, and root-private `_...` workspaces.
 * - Runs `PHP_BINARY -l` as an argv array, never through a shell.
 * - Captures the complete combined stdout/stderr for each file for migration reports.
 *
 * Notes:
 * - This is a migration audit, not a request-time service.
 * - `.new` files are intentionally not matched; they are generated reference snapshots.
 */
final class PhpLintRunner {

	/** App-relative directory prefixes excluded from lint traversal. */
	private const EXCLUDED_PREFIXES = [
		'.git',
		'node_modules',
		'public/uploads',
		'var/backups',
		'var/cache',
		'var/logs',
		'vendor',
	];

	public function __construct(private readonly PathGuard $pathGuard) {
	}


	/**
	 * Lint every app-owned PHP/PHP-template file in deterministic path order.
	 *
	 * @return array{available:bool,php_binary:string,checked:int,passed:int,failed:int,files:list<array{path:string,ok:bool,exit_code:int,output:string}>}
	 * @throws InstallerException When PHP lint cannot be executed.
	 */
	public function run(): array {
		if (!\function_exists('proc_open')) {
			throw new InstallerException('PHP lint audit requires proc_open(), but it is unavailable.');
		}

		$paths = $this->discover();
		$files = [];
		$passed = 0;
		$failed = 0;

		foreach ($paths as $relative => $absolute) {
			$result = $this->lint($absolute);
			$ok = $result['exit_code'] === 0;
			$ok ? $passed++ : $failed++;
			$files[] = [
				'path' => $relative,
				'ok' => $ok,
				'exit_code' => $result['exit_code'],
				'output' => $result['output'],
			];
		}

		return [
			'available' => true,
			'php_binary' => \PHP_BINARY,
			'checked' => \count($files),
			'passed' => $passed,
			'failed' => $failed,
			'files' => $files,
		];
	}


	/** @return array<string,string> Relative path => absolute path. */
	private function discover(): array {
		$root = $this->pathGuard->appRoot();
		$found = [];
		$directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
		$filter = new \RecursiveCallbackFilterIterator(
			$directory,
			function (\SplFileInfo $current): bool {
				$relative = $this->relativePath($current->getPathname());
				if ($current->isDir()) {
					return !$this->excludedDirectory($relative);
				}

				return $current->isFile() && $this->isPhpSource($relative);
			}
		);
		$iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);

		foreach ($iterator as $file) {
			if (!$file instanceof \SplFileInfo || !$file->isFile()) {
				continue;
			}
			$relative = $this->relativePath($file->getPathname());
			if (!$this->isPhpSource($relative)) {
				continue;
			}
			$found[$relative] = $file->getPathname();
		}

		\ksort($found, \SORT_STRING);
		return $found;
	}


	private function excludedDirectory(string $relative): bool {
		$relative = \trim(\str_replace('\\', '/', $relative), '/');
		if ($relative === '') {
			return false;
		}

		$first = \strtok($relative, '/');
		if (\is_string($first) && \str_starts_with($first, '_')) {
			return true;
		}

		foreach (self::EXCLUDED_PREFIXES as $prefix) {
			if ($relative === $prefix || \str_starts_with($relative, $prefix . '/')) {
				return true;
			}
		}

		return false;
	}


	private function isPhpSource(string $relative): bool {
		$lower = \strtolower($relative);
		return \str_ends_with($lower, '.php') || \str_ends_with($lower, '.php.tpl');
	}


	private function relativePath(string $absolute): string {
		$root = \str_replace('\\', '/', $this->pathGuard->appRoot());
		$path = \str_replace('\\', '/', $absolute);
		$relative = \ltrim(\substr($path, \strlen($root)), '/');
		return $relative;
	}


	/** @return array{exit_code:int,output:string} */
	private function lint(string $path): array {
		$descriptorSpec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$pipes = [];
		$process = @\proc_open([\PHP_BINARY, '-l', $path], $descriptorSpec, $pipes, $this->pathGuard->appRoot());
		if (!\is_resource($process)) {
			throw new InstallerException('Unable to start PHP lint process for: ' . $path);
		}

		\fclose($pipes[0]);
		$stdout = \stream_get_contents($pipes[1]);
		$stderr = \stream_get_contents($pipes[2]);
		\fclose($pipes[1]);
		\fclose($pipes[2]);
		$exit = \proc_close($process);

		if (!\is_int($exit) || $exit < 0) {
			throw new InstallerException('PHP lint process did not return a valid exit code for: ' . $path);
		}

		$output = \trim((string)$stdout . ((string)$stderr !== '' ? "\n" . (string)$stderr : ''));
		return ['exit_code' => $exit, 'output' => $output];
	}
}
