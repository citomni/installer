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
 * Resolves scaffold placeholder values without booting CitOmni.
 *
 * Reads the `placeholders` entry of the app-local installer config through
 * InstallerConfig and overlays explicit CLI values. This is intentionally small and
 * boring: no composer.json inference, no runtime config reads, no App dependency, and
 * no template behavior. Tiny gates, fewer trolls.
 *
 * Behavior:
 * - Missing config/citomni_installer.php is allowed and contributes no placeholders.
 * - Existing config may omit placeholders and then contributes no placeholders.
 * - Existing placeholders entry MUST be an array when present.
 * - CLI overrides win over config values.
 * - Placeholder keys use ScaffoldRenderer's canonical key grammar.
 * - Placeholder values MUST be strings.
 * - PROD_ROOT_URL and STAGE_ROOT_URL MUST NOT have a trailing slash.
 *
 * Typical usage:
 *   $resolver = new PlaceholderResolver($appRoot, $config);
 *   $values = $resolver->resolve(['APP_NAMESPACE' => 'App']);
 *
 * @throws InstallerException When app root, config shape, placeholder key, or value is invalid.
 */
final class PlaceholderResolver {

	/** Canonical placeholder key: uppercase, digits, underscores; must start with a letter. */
	private const KEY_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

	/** Root URL placeholders must be canonical base URLs without a trailing slash. */
	private const ROOT_URL_PLACEHOLDERS = [
		'PROD_ROOT_URL' => true,
		'STAGE_ROOT_URL' => true,
	];

	/** Derived placeholders keyed by derived host => canonical root URL source. */
	private const DERIVED_ROOT_HOSTS = [
		'PROD_ROOT_HOST' => 'PROD_ROOT_URL',
		'STAGE_ROOT_HOST' => 'STAGE_ROOT_URL',
	];

	private InstallerConfig $config;


	/**
	 * Create a placeholder resolver for an app root.
	 *
	 * Pass the InstallerConfig that the other collaborators of the same command use, so
	 * config/citomni_installer.php is evaluated once per run.
	 *
	 * @param string $appRoot Absolute or process-relative existing app root path.
	 * @param InstallerConfig|null $config Shared installer config; null creates one for the resolved app root.
	 * @throws InstallerException When the app root is empty, missing, not a directory, or unreadable.
	 */
	public function __construct(string $appRoot, ?InstallerConfig $config = null) {
		$appRoot = \rtrim(\trim($appRoot), "\\/");
		if ($appRoot === '') {
			throw new InstallerException('App root must not be empty.');
		}

		$resolvedAppRoot = \realpath($appRoot);
		if ($resolvedAppRoot === false || !\is_dir($resolvedAppRoot)) {
			throw new InstallerException(\sprintf('App root does not exist or is not a directory: %s', $appRoot));
		}

		if (!\is_readable($resolvedAppRoot)) {
			throw new InstallerException(\sprintf('App root is not readable: %s', $resolvedAppRoot));
		}

		$this->config = $config ?? new InstallerConfig($resolvedAppRoot);
	}


	/**
	 * Resolve placeholders from config and CLI overrides.
	 *
	 * Behavior:
	 * - Reads config/citomni_installer.php from app root when present.
	 * - Uses an empty config placeholder set when the config file is missing.
	 * - Applies CLI overrides on top of config placeholders.
	 * - Returns keys sorted for deterministic snapshots and diagnostics.
	 *
	 * @param array<string,mixed> $cliOverrides CLI-provided placeholder overrides.
	 * @return array<string,string> Resolved placeholder values.
	 * @throws InstallerException When config or CLI placeholder data is invalid.
	 */
	public function resolve(array $cliOverrides = []): array {
		$configPlaceholders = $this->loadConfigPlaceholders();
		$overridePlaceholders = $this->validatedPlaceholderMap($cliOverrides, 'CLI overrides');

		$this->assertNoDerivedHostInputs($configPlaceholders, 'installer config');
		$this->assertNoDerivedHostInputs($overridePlaceholders, 'CLI overrides');

		$resolved = \array_replace($configPlaceholders, $overridePlaceholders);
		$this->deriveRootHosts($resolved);
		\ksort($resolved, \SORT_STRING);

		return $resolved;
	}


	/**
	 * Return the validated placeholders configured directly by the application.
	 *
	 * Derived values are intentionally excluded so migration diagnostics can distinguish
	 * real config inputs from values synthesized by the resolver.
	 *
	 * @return array<string,string> Sorted configured placeholder map.
	 */
	public function configuredPlaceholders(): array {
		$configured = $this->loadConfigPlaceholders();
		$this->assertNoDerivedHostInputs($configured, 'installer config');
		\ksort($configured, \SORT_STRING);
		return $configured;
	}


	/**
	 * Expose derived placeholder dependencies for read-only diagnostics.
	 *
	 * @return array<string,string> Derived key => configured source key.
	 */
	public static function derivedPlaceholderSources(): array {
		return self::DERIVED_ROOT_HOSTS;
	}


	/**
	 * Reject direct inputs for derived ROOT_HOST placeholders.
	 *
	 * @param array<string,string> $placeholders Validated placeholder map.
	 * @param string $source Human-readable source name.
	 * @return void
	 * @throws InstallerException When a derived host is supplied directly.
	 */
	private function assertNoDerivedHostInputs(array $placeholders, string $source): void {
		foreach (self::DERIVED_ROOT_HOSTS as $hostKey => $urlKey) {
			if (!\array_key_exists($hostKey, $placeholders)) {
				continue;
			}

			throw new InstallerException(\sprintf(
				'Placeholder {{%s}} is derived from {{%s}} and must not be configured directly in %s.',
				$hostKey,
				$urlKey,
				$source
			));
		}
	}


	/**
	 * Derive ROOT_HOST placeholders from the final root URL values.
	 *
	 * CLI root URL overrides have already been applied when this method runs, so the
	 * host always corresponds to the exact URL that will be used for rendering.
	 *
	 * @param array<string,string> $resolved Final placeholder map, modified in place.
	 * @return void
	 * @throws InstallerException When a non-empty root URL has no parseable host.
	 */
	private function deriveRootHosts(array &$resolved): void {
		foreach (self::DERIVED_ROOT_HOSTS as $hostKey => $urlKey) {
			if (!\array_key_exists($urlKey, $resolved)) {
				continue;
			}

			$url = $resolved[$urlKey];
			if ($url === '') {
				$resolved[$hostKey] = '';
				continue;
			}

			$host = \parse_url(\trim($url), \PHP_URL_HOST);
			if (!\is_string($host) || $host === '') {
				throw new InstallerException(\sprintf(
					'Placeholder {{%s}} must be an absolute URL with a host so {{%s}} can be derived.',
					$urlKey,
					$hostKey
				));
			}

			$resolved[$hostKey] = \strtolower($host);
		}
	}

	/**
	 * Load placeholder values from the app-local installer config.
	 *
	 * @return array<string,string> Config placeholder values.
	 * @throws InstallerException When the existing config cannot be loaded or has invalid shape.
	 */
	private function loadConfigPlaceholders(): array {
		$config = $this->config->read();
		if ($config === null || !\array_key_exists('placeholders', $config)) {
			return [];
		}

		if (!\is_array($config['placeholders'])) {
			throw new InstallerException(\sprintf(
				'Installer config placeholders entry must be an array when present: %s',
				$this->config->path()
			));
		}

		return $this->validatedPlaceholderMap($config['placeholders'], 'installer config');
	}


	/**
	 * Validate and normalize a placeholder map.
	 *
	 * @param array<mixed,mixed> $placeholders Placeholder map to validate.
	 * @param string $source Human-readable source label for error messages.
	 * @return array<string,string> Validated placeholder map.
	 * @throws InstallerException When a key or value is invalid.
	 */
	private function validatedPlaceholderMap(array $placeholders, string $source): array {
		$validated = [];

		foreach ($placeholders as $key => $value) {
			if (!\is_string($key)) {
				throw new InstallerException(\sprintf(
					'Invalid placeholder key in %s: key must be a string, got %s',
					$source,
					\get_debug_type($key)
				));
			}

			if (\preg_match(self::KEY_PATTERN, $key) !== 1) {
				throw new InstallerException(\sprintf(
					'Invalid placeholder key in %s: %s. Expected [A-Z][A-Z0-9_]*',
					$source,
					$key
				));
			}

			if (!\is_string($value)) {
				throw new InstallerException(\sprintf(
					'Placeholder {{%s}} in %s must be a string, got %s',
					$key,
					$source,
					\get_debug_type($value)
				));
			}

			if (isset(self::ROOT_URL_PLACEHOLDERS[$key]) && \str_ends_with(\rtrim($value), '/')) {
				throw new InstallerException(\sprintf(
					'Placeholder {{%s}} in %s must not end with "/". Remove the trailing slash and retry.',
					$key,
					$source
				));
			}

			$validated[$key] = $value;
		}

		return $validated;
	}

}
