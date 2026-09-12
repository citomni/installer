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

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Util\Path;
use CitOmni\Installer\Exception\InstallerException;

/**
 * Locates, validates, and environment-normalizes package scaffold manifests.
 *
 * Discovery remains read-only. Source-only entries are normalized immediately.
 * Environment-aware entries are fully validated during discovery, but their source
 * is selected only through selectEnvironment(), before BuildScaffoldPlan sees them.
 *
 * Manifest schema:
 * - Version 1 remains valid for source-only manifests already used by CitOmni packages.
 * - Version 2 adds the `environments` file shape.
 * - A file declares exactly one of `source` or `environments`.
 * - Environment-aware files must cover every Environment value exactly and must use
 *   policy `managed`.
 */
final class ScaffoldManifestLocator {

	/** Manifest schema versions this installer understands. */
	private const MANIFEST_SCHEMA_VERSIONS = [1, 2];

	/** Convention manifest location, relative to a package root. */
	private const CONVENTION_MANIFEST = 'install/manifest.php';

	/** Known file policies. */
	private const KNOWN_POLICIES = ['managed', 'create-only'];

	private ComposerPackageDiscovery $discovery;
	private PathGuard $pathGuard;


	/**
	 * @param ComposerPackageDiscovery $discovery Installed-package metadata source.
	 * @param PathGuard $pathGuard Guard constructed with the application root.
	 */
	public function __construct(ComposerPackageDiscovery $discovery, PathGuard $pathGuard) {
		$this->discovery = $discovery;
		$this->pathGuard = $pathGuard;
	}


	/**
	 * Build a locator for the conventional vendor/ directory under an application root.
	 *
	 * @param string $appRoot Application root.
	 * @return self
	 */
	public static function forAppRoot(string $appRoot): self {
		$appRoot = \rtrim($appRoot, "/\\");

		return new self(ComposerPackageDiscovery::forAppRoot($appRoot), new PathGuard($appRoot));
	}


	/**
	 * Discover and validate every installed package that ships a scaffold manifest.
	 *
	 * Environment-aware entries remain in validated `environments` form until an
	 * explicit environment is selected with selectEnvironment().
	 *
	 * @return array<string,array<string,mixed>> Manifests keyed by package name.
	 * @throws InstallerException When Composer metadata or a manifest is invalid.
	 */
	public function discover(): array {
		$out = [];

		foreach ($this->discovery->installedPackages() as $name => $info) {
			$manifestPath = $this->locateManifest($name, $info['root'], $info['extra']);
			if ($manifestPath === null) {
				continue;
			}

			$out[$name] = $this->loadManifest($name, $info['root'], $manifestPath, $info['version']);
		}

		$this->assertUniqueTargets($out);
		return $out;
	}


	/**
	 * Discover and validate one package's scaffold manifest.
	 *
	 * @param string $name Composer package name.
	 * @return array<string,mixed>|null Manifest, or null when the package has none.
	 * @throws InstallerException When Composer metadata or the manifest is invalid.
	 */
	public function discoverPackage(string $name): ?array {
		// Validate global ownership before a package filter can hide a collision.
		return $this->discover()[$name] ?? null;
	}


	/**
	 * Reject competing owners and file/directory collisions before any filtering.
	 *
	 * Resolved identities also detect aliases through symlinks. Windows uses a
	 * conservative case-insensitive comparison, including targets not yet created.
	 *
	 * @param array<string,array<string,mixed>> $manifests Complete discovered manifests.
	 * @return void
	 * @throws InstallerException When targets overlap or claim installer metadata.
	 */
	private function assertUniqueTargets(array $manifests): void {
		$reserved = [];
		foreach ([ScaffoldState::RELATIVE_PATH, InstallerLock::RELATIVE_PATH, 'var/backups/citomni-installer'] as $path) {
			$reserved[] = $this->pathGuard->resolveTarget($path);
		}
		$caseInsensitive = \PHP_OS_FAMILY === 'Windows';
		$owners = [];
		foreach ($manifests as $name => $manifest) {
			foreach ($manifest['files'] as $file) {
				$key = \str_replace('\\', '/', $file['target_path']);
				if (\PHP_OS_FAMILY === 'Windows') {
					$key = \strtolower($key);
				}
				foreach ($reserved as $path) {
					if (Path::isInside($path, $key, $caseInsensitive) || Path::isInside($key, $path, $caseInsensitive)) {
						throw new InstallerException('Scaffold target claims installer metadata: ' . $file['target']);
					}
				}
				if (isset($owners[$key])) {
					throw new InstallerException(\sprintf('Scaffold target %s has competing owners: %s and %s.', $file['target'], $owners[$key], $name));
				}
				$owners[$key] = $name . ' (' . $file['target'] . ')';
			}
		}
		foreach ($owners as $key => $owner) {
			$parent = \dirname($key);
			while ($parent !== '.' && $parent !== \dirname($parent)) {
				if (isset($owners[$parent])) {
					throw new InstallerException('Scaffold file/directory collision between ' . $owners[$parent] . ' and ' . $owner . '.');
				}
				$parent = \dirname($parent);
			}
		}
	}


	/**
	 * Return whether any discovered manifest contains an environment-aware file.
	 *
	 * @param array<string,array<string,mixed>> $manifests Discovered manifests.
	 * @return bool
	 */
	public function hasEnvironmentAwareEntries(array $manifests): bool {
		foreach ($manifests as $manifest) {
			foreach ((array)($manifest['files'] ?? []) as $file) {
				if (isset($file['environments']) && \is_array($file['environments'])) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Return every app target controlled by environment materialization.
	 *
	 * Behavior:
	 * - Reads validated discovered manifests without selecting an environment.
	 * - Includes only entries using the manifest `environments` shape.
	 * - Returns package ownership with each normalized app-relative target.
	 * - Sorts deterministically by target, then package.
	 *
	 * Notes:
	 * - This is the public read-only contract for consumers that must avoid
	 *   mutating files owned by environment switching.
	 * - Call this with discover() output, before selectEnvironment() removes the
	 *   public `environments` shape and adds internal planner metadata.
	 *
	 * @param array<string,array<string,mixed>> $manifests Discovered manifests.
	 * @return array<int,array{package:string,target:string}> Environment-controlled targets.
	 */
	public function environmentTargets(array $manifests): array {
		$targets = [];

		foreach ($manifests as $package => $manifest) {
			foreach ((array)($manifest['files'] ?? []) as $file) {
				if (!isset($file['environments']) || !\is_array($file['environments'])) {
					continue;
				}

				$targets[] = [
					'package' => (string)$package,
					'target' => (string)$file['target'],
				];
			}
		}

		\usort($targets, static function (array $a, array $b): int {
			$targetCompare = \strcmp($a['target'], $b['target']);
			return $targetCompare !== 0 ? $targetCompare : \strcmp($a['package'], $b['package']);
		});

		return $targets;
	}


	/**
	 * Select one environment and normalize manifests for BuildScaffoldPlan.
	 *
	 * Environment-aware entries are reduced to the same source/source_path shape as
	 * ordinary entries. The internal `_environment_aware` marker lets command-specific
	 * planning distinguish authoritative targets without exposing the raw manifest map.
	 *
	 * @param array<string,array<string,mixed>> $manifests Discovered manifests.
	 * @param Environment $environment Environment whose sources should be selected.
	 * @param bool $environmentOnly When true, drop source-only entries entirely.
	 * @return array<string,array<string,mixed>> Planner-ready manifests.
	 */
	public function selectEnvironment(array $manifests, Environment $environment, bool $environmentOnly = false): array {
		$out = [];

		foreach ($manifests as $pkgName => $manifest) {
			$files = [];

			foreach ((array)($manifest['files'] ?? []) as $file) {
				if (isset($file['environments']) && \is_array($file['environments'])) {
					$selected = $file['environments'][$environment->value] ?? null;
					if (!\is_array($selected)) {
						throw new InstallerException(\sprintf(
							'Manifest normalization failed for %s target %s: environment %s is missing.',
							(string)$pkgName,
							(string)($file['target'] ?? ''),
							$environment->value
						));
					}

					$normalized = $file;
					unset($normalized['environments']);
					$normalized['source'] = $selected['source'];
					$normalized['source_path'] = $selected['source_path'];
					$normalized['_environment_aware'] = true;
					$files[] = $normalized;
					continue;
				}

				if (!$environmentOnly) {
					$files[] = $file;
				}
			}

			if ($environmentOnly && $files === []) {
				continue;
			}

			$copy = $manifest;
			$copy['files'] = $files;
			$out[$pkgName] = $copy;
		}

		return $out;
	}


	/**
	 * Normalize manifests only when no environment-aware files exist.
	 *
	 * This explicit helper is useful for read-only flows that can legitimately run
	 * without an environment. It refuses to silently discard environment-aware entries.
	 *
	 * @param array<string,array<string,mixed>> $manifests Discovered manifests.
	 * @return array<string,array<string,mixed>> Planner-ready source-only manifests.
	 * @throws InstallerException When an environment-aware entry requires a choice.
	 */
	public function selectEnvironmentAgnostic(array $manifests): array {
		if ($this->hasEnvironmentAwareEntries($manifests)) {
			throw new InstallerException('An explicit or recorded environment is required to normalize environment-aware scaffold manifests.');
		}

		return $manifests;
	}


	// ----------------------------------------------------------------
	// Manifest location and validation
	// ----------------------------------------------------------------

	/**
	 * Locate a package manifest through Composer extra or convention.
	 *
	 * @param string $name Package name.
	 * @param string $root Absolute package root.
	 * @param array<string,mixed> $extra Composer extra block.
	 * @return string|null Absolute manifest path, or null when absent.
	 * @throws InstallerException When a declared path is invalid or missing.
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

		$abs = $this->pathGuard->resolveSource($root, self::CONVENTION_MANIFEST);

		return \is_file($abs) ? $abs : null;
	}


	/**
	 * Evaluate and validate one scaffold manifest.
	 *
	 * @param string $name Package name.
	 * @param string $root Absolute package root.
	 * @param string $manifestPath Absolute manifest path.
	 * @param string $installedVersion Composer package version.
	 * @return array<string,mixed>
	 * @throws InstallerException When the manifest is invalid.
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

		$normalized = [];
		$seenTargets = [];

		foreach ($files as $index => $file) {
			$entry = $this->validateFile($name, $root, $manifestPath, $version, $index, $file);

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
	 * Validate one manifest file entry and resolve all declared paths.
	 *
	 * @param string $name Package name.
	 * @param string $root Absolute package root.
	 * @param string $manifestPath Absolute manifest path.
	 * @param int $schemaVersion Manifest schema version.
	 * @param int|string $index File index for diagnostics.
	 * @param mixed $file File declaration.
	 * @return array<string,mixed> Validated file entry.
	 * @throws InstallerException When the declaration is invalid.
	 */
	private function validateFile(string $name, string $root, string $manifestPath, int $schemaVersion, int|string $index, mixed $file): array {
		if (!\is_array($file)) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s has a non-array file entry at [%s]: %s',
				$name,
				(string)$index,
				$manifestPath
			));
		}

		foreach (['target', 'type', 'policy'] as $key) {
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

		$hasSource = \array_key_exists('source', $file);
		$hasEnvironments = \array_key_exists('environments', $file);
		if ($hasSource === $hasEnvironments) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s file [%s] must declare exactly one of "source" or "environments": %s',
				$name,
				(string)$index,
				$manifestPath
			));
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

		$base = [
			'target'      => Path::normalizeRelative($file['target']),
			'target_path' => $targetPath,
			'type'        => $file['type'],
			'policy'      => $policy,
		];

		if ($hasSource) {
			if (!\is_string($file['source']) || $file['source'] === '') {
				throw new InstallerException(\sprintf(
					'Scaffold manifest for %s file [%s] is missing a valid string "source": %s',
					$name,
					(string)$index,
					$manifestPath
				));
			}

			try {
				$sourcePath = $this->pathGuard->resolveSource($root, $file['source']);
			} catch (InstallerException $e) {
				throw new InstallerException(\sprintf('Scaffold manifest for %s has an invalid source: %s', $name, $e->getMessage()), 0, $e);
			}

			return $base + [
				'source'      => Path::normalizeRelative($file['source']),
				'source_path' => $sourcePath,
			];
		}

		if ($schemaVersion < 2) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s file [%s] uses "environments", which requires schema version 2: %s',
				$name,
				(string)$index,
				$manifestPath
			));
		}

		if ($policy !== 'managed') {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s file [%s] is environment-aware and must use policy "managed": %s',
				$name,
				(string)$index,
				$manifestPath
			));
		}

		if (!\is_array($file['environments'])) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s file [%s] has invalid "environments"; expected an array: %s',
				$name,
				(string)$index,
				$manifestPath
			));
		}

		$expected = Environment::values();
		$actual = \array_keys($file['environments']);
		\sort($expected, \SORT_STRING);
		\sort($actual, \SORT_STRING);

		if ($actual !== $expected) {
			throw new InstallerException(\sprintf(
				'Scaffold manifest for %s file [%s] must define exactly these environments: %s: %s',
				$name,
				(string)$index,
				\implode(', ', Environment::values()),
				$manifestPath
			));
		}

		$environments = [];
		foreach (Environment::cases() as $environment) {
			$definition = $file['environments'][$environment->value];
			if (!\is_array($definition) || \array_keys($definition) !== ['source']) {
				throw new InstallerException(\sprintf(
					'Scaffold manifest for %s file [%s] environment %s must contain exactly one "source" entry: %s',
					$name,
					(string)$index,
					$environment->value,
					$manifestPath
				));
			}

			$source = $definition['source'];
			if (!\is_string($source) || $source === '') {
				throw new InstallerException(\sprintf(
					'Scaffold manifest for %s file [%s] environment %s has an invalid source: %s',
					$name,
					(string)$index,
					$environment->value,
					$manifestPath
				));
			}

			try {
				$sourcePath = $this->pathGuard->resolveSource($root, $source);
			} catch (InstallerException $e) {
				throw new InstallerException(\sprintf(
					'Scaffold manifest for %s file [%s] has an invalid %s source: %s',
					$name,
					(string)$index,
					$environment->value,
					$e->getMessage()
				), 0, $e);
			}

			$environments[$environment->value] = [
				'source'      => Path::normalizeRelative($source),
				'source_path' => $sourcePath,
			];
		}

		return $base + ['environments' => $environments];
	}
}
