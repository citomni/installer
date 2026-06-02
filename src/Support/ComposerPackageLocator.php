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
 * Discovers and validates package scaffold manifests (contract §4).
 *
 * Strictly read-only: this class never runs Composer, never mutates composer.json or
 * composer.lock, and never writes to disk. It reads Composer's installed metadata and
 * each package's scaffold manifest file, validates them, and returns normalized data.
 *
 * Discovery (per §4):
 * - Package set, install paths, versions and `extra` come from vendor/composer/installed.json.
 *   Composer\InstalledVersions is the runtime API named first by the contract, but it does
 *   not expose `extra` (which is exactly what extra.citomni.scaffold discovery needs) and is
 *   a process-global that cannot describe an arbitrary vendor tree. It is therefore consulted
 *   only to refine a package's install path / version when it already knows that package;
 *   otherwise installed.json is authoritative.
 * - Per package, the manifest is located at extra.citomni.scaffold (package-relative path) if
 *   declared, else the convention resources/citomni/scaffold.php. A declared extra path wins
 *   and is required to exist; a package with neither is simply skipped (no scaffold).
 *
 * Validation (per §4):
 * - Manifest `package` MUST equal the Composer package name.
 * - Manifest `version` (schema version, not semver) MUST be a known value; unknown -> error.
 * - Each file needs string target/source/type/policy; policy MUST be a known policy.
 * - Path safety via PathGuard: every target resolves under app-root, every source under
 *   package-root; "..", absolute, drive-letter, UNC and stream-wrapper paths are rejected.
 * - Duplicate targets within a manifest -> error.
 *
 * Notes:
 * - App-aware (via the injected PathGuard's app-root) and instantiated explicitly. Not a service.
 * - Manifests are PHP files evaluated via include; parse/runtime failures become InstallerException.
 */
final class ComposerPackageLocator {

	/** Manifest SCHEMA versions this installer understands. */
	private const MANIFEST_SCHEMA_VERSIONS = [1];

	/** Convention manifest location, relative to a package root. */
	private const CONVENTION_MANIFEST = 'resources/citomni/scaffold.php';

	/** Known file policies (contract §8). */
	private const KNOWN_POLICIES = ['managed', 'create-only', 'sample'];

	/** Absolute path to the Composer vendor directory. */
	private string $vendorDir;

	/** Path safety guard (carries the app-root). */
	private PathGuard $pathGuard;


	/**
	 * @param  string    $vendorDir  Absolute path to the Composer vendor/ directory.
	 * @param  PathGuard $pathGuard  Guard constructed with the application root.
	 */
	public function __construct(string $vendorDir, PathGuard $pathGuard) {
		$this->vendorDir = \rtrim($vendorDir, "/\\");
		$this->pathGuard = $pathGuard;
	}


	/**
	 * Build a locator for the conventional vendor/ directory under an application root.
	 *
	 * @param  string $appRoot  Application root (must exist).
	 * @return self
	 * @throws InstallerException  If the application root cannot be resolved.
	 */
	public static function forAppRoot(string $appRoot): self {
		$appRoot = \rtrim($appRoot, "/\\");

		return new self($appRoot . '/vendor', new PathGuard($appRoot));
	}


	/**
	 * Discover and validate every installed package that ships a scaffold manifest.
	 *
	 * @return array<string,array<string,mixed>>  Normalized manifests keyed by package name.
	 * @throws InstallerException  If installed.json is missing/unreadable or any manifest is invalid.
	 */
	public function discover(): array {
		$out = [];

		foreach ($this->readInstalledPackages() as $name => $info) {
			$manifestPath = $this->locateManifest($name, $info['root'], $info['extra']);
			if ($manifestPath === null) {
				continue;
			}

			$out[$name] = $this->loadManifest($name, $info['root'], $manifestPath, $info['version']);
		}

		return $out;
	}


	/**
	 * Discover and validate a single package's scaffold manifest.
	 *
	 * @param  string $name  Composer package name (vendor/name).
	 * @return array<string,mixed>|null  Normalized manifest, or null if the package is not
	 *                                   installed or ships no scaffold manifest.
	 * @throws InstallerException  If the manifest exists but is invalid.
	 */
	public function discoverPackage(string $name): ?array {
		$packages = $this->readInstalledPackages();
		if (!isset($packages[$name])) {
			return null;
		}

		$info         = $packages[$name];
		$manifestPath = $this->locateManifest($name, $info['root'], $info['extra']);
		if ($manifestPath === null) {
			return null;
		}

		return $this->loadManifest($name, $info['root'], $manifestPath, $info['version']);
	}


	// ----------------------------------------------------------------
	// Composer metadata
	// ----------------------------------------------------------------

	/**
	 * Read installed packages from vendor/composer/installed.json.
	 *
	 * @return array<string,array{version:string,root:string,extra:array<string,mixed>}>
	 * @throws InstallerException
	 */
	private function readInstalledPackages(): array {
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


	// ----------------------------------------------------------------
	// Manifest location & validation
	// ----------------------------------------------------------------

	/**
	 * Locate a package's manifest: declared extra path (must exist) else convention (if present).
	 *
	 * @param  string              $name
	 * @param  string              $root   Absolute package root.
	 * @param  array<string,mixed> $extra  Package `extra` block.
	 * @return string|null  Absolute manifest path, or null if the package ships no scaffold.
	 * @throws InstallerException  If the declared extra path is unsafe or points at a missing file.
	 */
	private function locateManifest(string $name, string $root, array $extra): ?string {
		$declared = $extra['citomni']['scaffold'] ?? null;

		if ($declared !== null) {
			if (!\is_string($declared) || $declared === '') {
				throw new InstallerException(\sprintf('extra.citomni.scaffold for %s must be a non-empty string path', $name));
			}

			try {
				$abs = $this->pathGuard->resolveSource($root, $declared);
			} catch (InstallerException $e) {
				throw new InstallerException(
					\sprintf('Invalid extra.citomni.scaffold path for %s: %s', $name, $e->getMessage()),
					0,
					$e
				);
			}

			if (!\is_file($abs)) {
				throw new InstallerException(\sprintf(
					'Declared scaffold manifest not found for %s: %s (resolved: %s)',
					$name,
					$declared,
					$abs
				));
			}

			return $abs;
		}

		// Convention path is constant and safe; resolveSource still confirms it stays under root.
		$abs = $this->pathGuard->resolveSource($root, self::CONVENTION_MANIFEST);

		return \is_file($abs) ? $abs : null;
	}


	/**
	 * Evaluate and validate a manifest file into a normalized structure.
	 *
	 * @param  string $name
	 * @param  string $root              Absolute package root.
	 * @param  string $manifestPath      Absolute manifest path.
	 * @param  string $installedVersion  Composer version (metadata only).
	 * @return array<string,mixed>
	 * @throws InstallerException
	 */
	private function loadManifest(string $name, string $root, string $manifestPath, string $installedVersion): array {
		try {
			$manifest = include $manifestPath;
		} catch (\Throwable $e) {
			throw new InstallerException(
				\sprintf('Scaffold manifest could not be read for %s: %s', $name, $manifestPath),
				0,
				$e
			);
		}

		if (!\is_array($manifest)) {
			throw new InstallerException(\sprintf('Scaffold manifest for %s did not return an array: %s', $name, $manifestPath));
		}

		$declaredPackage = $manifest['package'] ?? null;
		if (!\is_string($declaredPackage) || $declaredPackage !== $name) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest package mismatch: manifest declares %s but package is %s (%s)',
				\var_export($declaredPackage, true),
				$name,
				$manifestPath
			));
		}

		$version = $manifest['version'] ?? null;
		if (!\is_int($version) || !\in_array($version, self::MANIFEST_SCHEMA_VERSIONS, true)) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s has unknown schema version %s (supported: %s): %s',
				$name,
				\var_export($version, true),
				\implode(', ', self::MANIFEST_SCHEMA_VERSIONS),
				$manifestPath
			));
		}

		$files = $manifest['files'] ?? null;
		if (!\is_array($files)) {
			throw new InstallerException(\sprintf('Scaffold manifest for %s is missing a valid "files" array: %s', $name, $manifestPath));
		}

		$normalized  = [];
		$seenTargets = [];
		foreach ($files as $index => $file) {
			$entry = $this->validateFile($name, $root, $manifestPath, $index, $file);

			if (isset($seenTargets[$entry['target']])) {
				throw new InstallerException(\sprintf(
					'Scaffold manifest for %s declares duplicate target %s: %s',
					$name,
					$entry['target'],
					$manifestPath
				));
			}
			$seenTargets[$entry['target']] = true;

			$normalized[] = $entry;
		}

		return [
			'package'           => $name,
			'version'           => $version,
			'root'              => $root,
			'installed_version' => $installedVersion,
			'manifest_path'     => $manifestPath,
			'files'             => $normalized,
		];
	}


	/**
	 * Validate one manifest file entry and resolve its target/source paths.
	 *
	 * @param  string     $name
	 * @param  string     $root          Absolute package root.
	 * @param  string     $manifestPath
	 * @param  int|string $index         File index within the manifest (for diagnostics).
	 * @param  mixed      $file
	 * @return array{target:string,target_path:string,source:string,source_path:string,type:string,policy:string}
	 * @throws InstallerException
	 */
	private function validateFile(string $name, string $root, string $manifestPath, int|string $index, mixed $file): array {
		if (!\is_array($file)) {
			throw new InstallerException(\sprintf('Scaffold manifest for %s has a non-array file entry at [%s]: %s', $name, (string)$index, $manifestPath));
		}

		foreach (['target', 'source', 'type', 'policy'] as $key) {
			if (!isset($file[$key]) || !\is_string($file[$key]) || $file[$key] === '') {
				throw new InstallerException(\sprintf(
					'Scaffold manifest for %s file [%s] is missing a valid string "%s": %s',
					$name,
					(string)$index,
					$key,
					$manifestPath
				));
			}
		}

		$policy = $file['policy'];
		if (!\in_array($policy, self::KNOWN_POLICIES, true)) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s file [%s] has unknown policy %s (known: %s): %s',
				$name,
				(string)$index,
				\var_export($policy, true),
				\implode(', ', self::KNOWN_POLICIES),
				$manifestPath
			));
		}

		try {
			$targetPath = $this->pathGuard->resolveTarget($file['target']);
		} catch (InstallerException $e) {
			throw new InstallerException(\sprintf('Scaffold manifest for %s has an invalid target: %s', $name, $e->getMessage()), 0, $e);
		}

		try {
			$sourcePath = $this->pathGuard->resolveSource($root, $file['source']);
		} catch (InstallerException $e) {
			throw new InstallerException(\sprintf('Scaffold manifest for %s has an invalid source: %s', $name, $e->getMessage()), 0, $e);
		}

		return [
			'target'      => Path::normalizeRelative($file['target']),
			'target_path' => $targetPath,
			'source'      => Path::normalizeRelative($file['source']),
			'source_path' => $sourcePath,
			'type'        => $file['type'],
			'policy'      => $policy,
		];
	}


	/**
	 * @param  string $path
	 * @return bool  Whether a path looks absolute (POSIX, drive-letter or UNC).
	 */
	private function isAbsolutePath(string $path): bool {
		return Path::isAbsolute($path) || Path::hasDriveLetter($path) || Path::isUnc($path);
	}

}
