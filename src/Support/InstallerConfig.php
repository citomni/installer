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
use CitOmni\Installer\Util\Path;

/**
 * Reads the application's installer config without booting CitOmni.
 *
 * config/citomni_installer.php is app-owned and versioned. It may return two entries:
 * `placeholders`, which PlaceholderResolver validates and resolves, and `omit`, the
 * create-only scaffold targets the application does not want.
 *
 * Behavior:
 * - A missing file is allowed and reads as no config.
 * - The file is evaluated at most once per instance, in an isolated static scope. The
 *   outcome, a config or a failure, is kept, so a file that declares functions or
 *   constants never runs twice. Share one instance per command run.
 * - An existing file must be readable and return an array.
 * - omitList() validates the shape of `omit` only. Whether each entry names a
 *   create-only target is checked against the installed manifests by
 *   ScaffoldManifestLocator::omissions().
 *
 * Typical usage:
 *   $config = new InstallerConfig($appRoot);
 *   $resolver = new PlaceholderResolver($appRoot, $config);
 *   $locator = ScaffoldManifestLocator::forAppRoot($appRoot, $config);
 */
final class InstallerConfig {

	/** Fixed config location, relative to the application root. */
	public const RELATIVE_PATH = 'config/citomni_installer.php';

	private string $appRoot;
	private bool $loaded = false;
	private ?array $config = null;
	private ?InstallerException $failure = null;


	/**
	 * @param string $appRoot Application root; resolved by the caller.
	 */
	public function __construct(string $appRoot) {
		$this->appRoot = \rtrim($appRoot, "/\\");
	}


	/**
	 * Return the absolute path of the config file.
	 *
	 * @return string Path below the application root; the file may not exist.
	 */
	public function path(): string {
		return $this->appRoot . '/' . self::RELATIVE_PATH;
	}


	/**
	 * Load the config file once and return what it returned.
	 *
	 * @return array<mixed,mixed>|null The returned array, or null when the file does not exist.
	 * @throws InstallerException When the file is unreadable, fails to load, or does not return an array; repeated calls rethrow the same failure.
	 */
	public function read(): ?array {
		if (!$this->loaded) {
			$this->loaded = true;
			try {
				$this->config = $this->load();
			} catch (InstallerException $e) {
				$this->failure = $e;
			}
		}

		if ($this->failure !== null) {
			throw $this->failure;
		}

		return $this->config;
	}


	/**
	 * Return the create-only targets the application omits.
	 *
	 * Behavior:
	 * - A missing file or a missing `omit` entry means nothing is omitted.
	 * - `omit` must be a list of app-relative paths: forward slashes, no `..`, no
	 *   absolute path, drive letter or stream wrapper.
	 * - Paths are normalized like manifest targets, then deduplicated and sorted.
	 *
	 * @return list<string> Normalized app-relative targets.
	 * @throws InstallerException When the config cannot be read or `omit` has an invalid shape.
	 */
	public function omitList(): array {
		$config = $this->read();
		if ($config === null || !\array_key_exists('omit', $config)) {
			return [];
		}

		if (!\is_array($config['omit']) || !\array_is_list($config['omit'])) {
			throw new InstallerException(\sprintf(
				'Installer config omit entry must be a list of app-relative paths when present: %s',
				$this->path()
			));
		}

		$targets = [];
		foreach ($config['omit'] as $entry) {
			if (!\is_string($entry)) {
				throw new InstallerException(\sprintf(
					'Invalid omit entry in installer config %s: expected an app-relative path, got %s.',
					$this->path(),
					\get_debug_type($entry)
				));
			}

			if (!Path::isSafeRelative($entry)) {
				throw new InstallerException(\sprintf(
					'Invalid omit entry in installer config %s: %s. Use an app-relative path with forward slashes and without "..".',
					$this->path(),
					\var_export($entry, true)
				));
			}

			$targets[Path::normalizeRelative($entry)] = true;
		}

		// Keys of numeric-looking paths become ints; restore strings.
		$targets = \array_map('strval', \array_keys($targets));
		\sort($targets, \SORT_STRING);

		return $targets;
	}


	/**
	 * Evaluate the config file in an isolated static scope.
	 *
	 * @return array<mixed,mixed>|null The returned array, or null when the file does not exist.
	 * @throws InstallerException When the file is unreadable, fails to load, or does not return an array.
	 */
	private function load(): ?array {
		$path = $this->path();

		if (!\is_file($path)) {
			return null;
		}

		if (!\is_readable($path)) {
			throw new InstallerException(\sprintf('Installer config is not readable: %s', $path));
		}

		try {
			$config = (static function (string $file): mixed {
				return require $file;
			})($path);
		} catch (\Throwable $e) {
			throw new InstallerException(\sprintf(
				'Failed to load installer config %s: %s',
				$path,
				$e->getMessage()
			), 0, $e);
		}

		if (!\is_array($config)) {
			throw new InstallerException(\sprintf('Installer config must return an array: %s', $path));
		}

		return $config;
	}
}
