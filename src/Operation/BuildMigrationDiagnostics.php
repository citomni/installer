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

namespace CitOmni\Installer\Operation;

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Exception\FilesystemException;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Util\UnifiedDiff;

/**
 * Build read-only diagnostics for legacy scaffold migration.
 *
 * Behavior:
 * - Extracts placeholder requirements directly from the currently installed scaffold stubs.
 * - Separates missing installer-config values from values supplied only for the current CLI run.
 * - Reports configured placeholder keys that no current installed scaffold uses.
 * - Builds rendered target diffs from the exact source/placeholders carried by the migration plan.
 * - Produces a compact post-apply manual-review list for `.new`, conflicts, and failures.
 */
final class BuildMigrationDiagnostics {

	public function __construct(
		private readonly ScaffoldRenderer $renderer,
		private readonly PlaceholderResolver $placeholders
	) {
	}


	/**
	 * Inspect installer placeholder coverage across every environment.
	 *
	 * @param array<string,array<string,mixed>> $manifests Discovered, not environment-selected manifests.
	 * @param Environment $selectedEnvironment Environment being migrated now.
	 * @param array<string,string> $cliOverrides Current CLI placeholder overrides.
	 * @return array<string,mixed> Placeholder diagnostics.
	 */
	public function inspectInstallerConfig(array $manifests, Environment $selectedEnvironment, array $cliOverrides): array {
		$requirements = $this->placeholderRequirements($manifests);
		$configured = $this->placeholders->configuredPlaceholders();
		$configResolved = $this->placeholders->resolve();
		$effectiveResolved = $this->placeholders->resolve($cliOverrides);

		$missingConfig = [];
		$missingEffective = [];
		$allRequired = [];
		$derivedSources = PlaceholderResolver::derivedPlaceholderSources();

		foreach (Environment::cases() as $environment) {
			$missingConfigInputs = [];
			$missingEffectiveInputs = [];
			$required = \array_keys($requirements[$environment->value] ?? []);
			\sort($required, \SORT_STRING);
			foreach ($required as $key) {
				$allRequired[$key] = true;
				$inputKey = $derivedSources[$key] ?? $key;
				if (!\array_key_exists($key, $configResolved)) {
					$missingConfigInputs[$inputKey] = true;
				}
				if (!\array_key_exists($key, $effectiveResolved)) {
					$missingEffectiveInputs[$inputKey] = true;
				}
			}

			$missingConfig[$environment->value] = \array_keys($missingConfigInputs);
			$missingEffective[$environment->value] = \array_keys($missingEffectiveInputs);
			\sort($missingConfig[$environment->value], \SORT_STRING);
			\sort($missingEffective[$environment->value], \SORT_STRING);
		}

		$requiredConfigInputs = $allRequired;
		foreach ($derivedSources as $derivedKey => $sourceKey) {
			if (isset($allRequired[$derivedKey])) {
				$requiredConfigInputs[$sourceKey] = true;
			}
		}

		$unused = [];
		foreach (\array_keys($configured) as $key) {
			if (!isset($requiredConfigInputs[$key])) {
				$unused[] = $key;
			}
		}
		\sort($unused, \SORT_STRING);

		$cliOnly = [];
		foreach ($missingConfig[$selectedEnvironment->value] as $key) {
			if (\array_key_exists($key, $effectiveResolved)) {
				$cliOnly[] = $key;
			}
		}

		return [
			'selected_environment' => $selectedEnvironment->value,
			'requirements' => $requirements,
			'missing_from_config' => $missingConfig,
			'missing_effective' => $missingEffective,
			'missing_effective_selected' => $missingEffective[$selectedEnvironment->value],
			'provided_for_selected_by_cli_or_derivation' => $cliOnly,
			'unused_configured' => $unused,
		];
	}


	/**
	 * Build rendered file diffs and blocking manual-review entries from a migration plan.
	 *
	 * @param array<string,mixed> $plan Migration plan.
	 * @return array{scaffold_diffs:list<array<string,mixed>>}
	 */
	public function inspectPlan(array $plan): array {
		$diffs = [];

		foreach ((array)($plan['packages'] ?? []) as $package) {
			$packageName = (string)($package['name'] ?? '');
			foreach ((array)($package['files'] ?? []) as $file) {
				$action = (string)($file['action'] ?? 'none');
				$target = (string)($file['target'] ?? '');
				$reason = (string)($file['reason'] ?? '');


				if ($action !== 'update' && $action !== 'write_new') {
					continue;
				}

				$apply = $file['_apply'] ?? null;
				if (!\is_array($apply)) {
					continue;
				}

				$targetAbs = (string)($apply['target_abs'] ?? '');
				$sourceAbs = (string)($apply['source_abs'] ?? '');
				if ($targetAbs === '' || $sourceAbs === '' || !\is_file($targetAbs)) {
					continue;
				}

				$current = \file_get_contents($targetAbs);
				if ($current === false) {
					throw new FilesystemException('Cannot read migration target for diff: ' . $target);
				}
				$stub = $this->renderer->readStub($sourceAbs);
				$desired = $this->renderer->render($stub, (array)($apply['placeholders'] ?? []));

				$newLabel = $action === 'write_new' ? $target . '.new' : 'desired/' . $target;
				$diff = UnifiedDiff::create($current, $desired, $target, $newLabel);
				if ($diff === '') {
					continue;
				}

				$diffs[] = [
					'package' => $packageName,
					'target' => $target,
					'action' => $action,
					'reason' => $reason,
					'diff' => $diff,
				];
			}
		}

		return ['scaffold_diffs' => $diffs];
	}


	/**
	 * Build the consolidated blocking review list from actual apply outcomes.
	 *
	 * @param array<string,mixed> $result Apply/materialization result.
	 * @return list<array<string,mixed>> Files that still require human or corrective action.
	 */
	public function blockingManualReview(array $result): array {
		$manual = [];

		foreach ((array)($result['packages'] ?? []) as $package) {
			$packageName = (string)($package['name'] ?? '');
			foreach ((array)($package['files'] ?? []) as $file) {
				$applied = (string)($file['applied'] ?? '');
				if (!\in_array($applied, ['wrote_new', 'conflict', 'failed'], true)) {
					continue;
				}

				$item = [
					'package' => $packageName,
					'target' => (string)($file['target'] ?? ''),
					'applied' => $applied,
					'reason' => (string)($file['reason'] ?? 'manual_review'),
				];
				foreach (['new_path', 'new_status', 'error_type', 'error'] as $key) {
					if (isset($file[$key])) {
						$item[$key] = (string)$file[$key];
					}
				}
				$manual[] = $item;
			}
		}

		return $manual;
	}


	/**
	 * Extract placeholder usages from all source variants in discovered manifests.
	 *
	 * @param array<string,array<string,mixed>> $manifests
	 * @return array<string,array<string,list<array{package:string,target:string,source:string}>>>
	 */
	private function placeholderRequirements(array $manifests): array {
		$requirements = [];
		foreach (Environment::cases() as $environment) {
			$requirements[$environment->value] = [];
		}

		$cache = [];
		foreach ($manifests as $packageName => $manifest) {
			foreach ((array)($manifest['files'] ?? []) as $file) {
				$target = (string)($file['target'] ?? '');

				if (isset($file['source_path'])) {
					$sourcePath = (string)$file['source_path'];
					$source = (string)($file['source'] ?? '');
					$keys = $this->placeholderKeys($sourcePath, $cache);
					foreach (Environment::cases() as $environment) {
						$this->addRequirements($requirements[$environment->value], $keys, (string)$packageName, $target, $source);
					}
					continue;
				}

				foreach ((array)($file['environments'] ?? []) as $environmentName => $definition) {
					if (!\is_array($definition)) {
						continue;
					}
					$sourcePath = (string)($definition['source_path'] ?? '');
					$source = (string)($definition['source'] ?? '');
					$keys = $this->placeholderKeys($sourcePath, $cache);
					$this->addRequirements($requirements[(string)$environmentName], $keys, (string)$packageName, $target, $source);
				}
			}
		}

		foreach ($requirements as &$byKey) {
			\ksort($byKey, \SORT_STRING);
		}
		unset($byKey);

		return $requirements;
	}


	/**
	 * @param array<string,list<string>> $cache
	 * @return list<string>
	 */
	private function placeholderKeys(string $sourcePath, array &$cache): array {
		if ($sourcePath === '') {
			return [];
		}
		if (isset($cache[$sourcePath])) {
			return $cache[$sourcePath];
		}

		$stub = $this->renderer->readStub($sourcePath);
		$cache[$sourcePath] = $this->renderer->placeholdersIn($stub);
		return $cache[$sourcePath];
	}


	/**
	 * @param array<string,list<array{package:string,target:string,source:string}>> $bucket
	 * @param list<string> $keys
	 */
	private function addRequirements(array &$bucket, array $keys, string $package, string $target, string $source): void {
		foreach ($keys as $key) {
			$bucket[$key][] = [
				'package' => $package,
				'target' => $target,
				'source' => $source,
			];
		}
	}
}
