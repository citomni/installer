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
 * Discovers installed Composer packages from vendor/composer/installed.json.
 *
 * Strictly read-only: this class never runs Composer, never mutates composer.json or
 * composer.lock, and never writes to disk. It answers exactly one question — "which packages
 * are installed, where do they live, and what is their `extra` block?" — and knows nothing
 * about scaffold manifests, environment manifests, or any other consumer-specific concern.
 * The manifest locators (scaffold, environment, ...) are built on top of it.
 *
 * Discovery:
 * - Package set, install paths, versions and `extra` come from vendor/composer/installed.json.
 *   Composer\InstalledVersions is consulted only to refine a package's install path / version
 *   when it already knows that package; it does not expose `extra` and is a process-global that
 *   cannot describe an arbitrary vendor tree, so installed.json remains authoritative.
 * - Composer 2 wraps entries in {"packages": [...]}; Composer 1 used a top-level list. Both are
 *   handled.
 *
 * Notes:
 * - App-root-aware only in the sense of taking a vendor/ path; instantiated explicitly. Not a
 *   service and not App-aware (this package does not boot CitOmni).
 * - No path safety is applied here: install paths come from Composer, not from a manifest. The
 *   locators that consume these roots apply PathGuard when resolving manifest-declared sources.
 */
final class ComposerPackageDiscovery {

	/** Absolute path to the Composer vendor directory. */
	private string $vendorDir;


	/**
	 * @param  string $vendorDir  Absolute path to the Composer vendor/ directory.
	 */
	public function __construct(string $vendorDir) {
		$this->vendorDir = \rtrim($vendorDir, "/\\");
	}


	/**
	 * Build a discovery for the conventional vendor/ directory under an application root.
	 *
	 * @param  string $appRoot  Application root.
	 * @return self
	 */
	public static function forAppRoot(string $appRoot): self {
		return new self(\rtrim($appRoot, "/\\") . '/vendor');
	}


	/**
	 * Read installed packages from vendor/composer/installed.json.
	 *
	 * @return array<string,array{version:string,root:string,extra:array<string,mixed>}>
	 *         Keyed by package name; `root` is an absolute, existing path.
	 * @throws InstallerException  If installed.json is missing, unreadable, or malformed.
	 */
	public function installedPackages(): array {
		$path = $this->vendorDir . '/composer/installed.json';

		if (!\is_file($path)) {
			throw new InstallerException(\sprintf('Composer installed.json not found: %s', $path));
		}

		$json = \file_get_contents($path);
		if ($json === false) {
			throw new InstallerException(\sprintf('Composer installed.json is not readable: %s', $path));
		}

		$data = \json_decode($json, true);
		if (!\is_array($data)) {
			throw new InstallerException(\sprintf('Composer installed.json is not valid JSON: %s', $path));
		}

		// Composer 2 wraps entries in {"packages": [...]}; Composer 1 used a top-level list.
		$entries = \array_key_exists('packages', $data) ? $data['packages'] : $data;
		if (!\is_array($entries)) {
			throw new InstallerException(\sprintf('Composer installed.json is malformed (packages): %s', $path));
		}

		$out = [];
		foreach ($entries as $entry) {
			if (!\is_array($entry) || !isset($entry['name']) || !\is_string($entry['name'])) {
				continue;
			}

			$name = $entry['name'];
			$root = $this->resolveRoot($name, $entry);
			if ($root === null) {
				continue;
			}

			$out[$name] = [
				'version' => $this->resolveVersion($name, $entry),
				'root'    => $root,
				'extra'   => (isset($entry['extra']) && \is_array($entry['extra'])) ? $entry['extra'] : [],
			];
		}

		return $out;
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Resolve a package's absolute install path (InstalledVersions preferred, then installed.json).
	 *
	 * @param  string              $name
	 * @param  array<string,mixed> $entry  installed.json entry for the package.
	 * @return string|null  Absolute, existing package root; null if it cannot be resolved.
	 */
	private function resolveRoot(string $name, array $entry): ?string {
		if (
			\class_exists(\Composer\InstalledVersions::class)
			&& \method_exists(\Composer\InstalledVersions::class, 'getInstallPath')
			&& \Composer\InstalledVersions::isInstalled($name)
		) {
			$path = \Composer\InstalledVersions::getInstallPath($name);
			if (\is_string($path)) {
				$real = \realpath($path);
				if ($real !== false) {
					return $real;
				}
			}
		}

		$installPath = $entry['install-path'] ?? null;
		if (\is_string($installPath) && $installPath !== '') {
			$base = $this->isAbsolutePath($installPath)
				? $installPath
				: $this->vendorDir . '/composer/' . $installPath;

			$real = \realpath($base);
			if ($real !== false) {
				return $real;
			}
		}

		$real = \realpath($this->vendorDir . '/' . $name);

		return $real === false ? null : $real;
	}


	/**
	 * Resolve a package's pretty version (InstalledVersions preferred, then installed.json).
	 *
	 * @param  string              $name
	 * @param  array<string,mixed> $entry
	 * @return string
	 */
	private function resolveVersion(string $name, array $entry): string {
		if (
			\class_exists(\Composer\InstalledVersions::class)
			&& \Composer\InstalledVersions::isInstalled($name)
		) {
			$version = \Composer\InstalledVersions::getPrettyVersion($name);
			if (\is_string($version)) {
				return $version;
			}
		}

		$version = $entry['version'] ?? null;

		return \is_string($version) ? $version : 'unknown';
	}


	/**
	 * @param  string $path
	 * @return bool  Whether a path looks absolute (POSIX, drive-letter or UNC).
	 */
	private function isAbsolutePath(string $path): bool {
		return Path::isAbsolute($path) || Path::hasDriveLetter($path) || Path::isUnc($path);
	}

}
