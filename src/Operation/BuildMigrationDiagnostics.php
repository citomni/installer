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
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Util\Checksum;
use CitOmni\Installer\Util\Path;
use CitOmni\Installer\Util\UnifiedDiff;

/**
 * Build read-only diagnostics for legacy scaffold migration.
 *
 * Behavior:
 * - Extracts placeholder requirements directly from the currently installed scaffold stubs.
 * - Separates missing installer-config values from values supplied only for the current CLI run.
 * - Reports configured placeholder keys that no current installed scaffold uses.
 * - Builds rendered target diffs from the exact source/placeholders carried by the migration plan.
 * - Never reads local non-template var/secrets target bytes into diff diagnostics.
 * - Omits diffs for config targets whose PHP array keys contain known sensitive credential fragments.
 * - Flags percent-style uppercase tokens in current scaffold sources as advisory source-hygiene warnings.
 * - Lists recorded scaffold files that no installed manifest declares any more but that still exist.
 * - Produces a compact post-apply manual-review list for `.new`, conflicts, and failures.
 */
final class BuildMigrationDiagnostics {

	private const SENSITIVE_CONFIG_KEY_FRAGMENTS = [
		'password',
		'passwd',
		'secret',
		'client_secret',
		'access_token',
		'refresh_token',
		'api_key',
		'api-key',
		'private_key',
		'auth_token',
	];


	public function __construct(
		private readonly PathGuard $pathGuard,
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
	 * Find percent-style uppercase tokens in current package scaffold sources.
	 *
	 * This is deliberately advisory. The current installer does not interpret `%NAME%`
	 * tokens, but package sources may legitimately contain the syntax for another purpose.
	 * Reporting the raw observation helps spot leftover legacy scaffold placeholders without
	 * assigning semantics or changing migration exit behavior.
	 *
	 * @param array<string,array<string,mixed>> $manifests Discovered, not environment-selected manifests.
	 * @return array{file_count:int,token_count:int,files:list<array{package:string,source:string,targets:list<string>,tokens:list<string>}>}
	 */
	public function inspectPercentStyleSourceTokens(array $manifests): array {
		$sources = [];

		foreach ($manifests as $packageName => $manifest) {
			foreach ((array)($manifest['files'] ?? []) as $file) {
				$target = (string)($file['target'] ?? '');

				if (isset($file['source_path'])) {
					$this->collectPercentStyleSource(
						$sources,
						(string)$packageName,
						(string)($file['source'] ?? ''),
						(string)$file['source_path'],
						$target
					);
					continue;
				}

				foreach ((array)($file['environments'] ?? []) as $definition) {
					if (!\is_array($definition)) {
						continue;
					}
					$this->collectPercentStyleSource(
						$sources,
						(string)$packageName,
						(string)($definition['source'] ?? ''),
						(string)($definition['source_path'] ?? ''),
						$target
					);
				}
			}
		}

		$files = \array_values($sources);
		\usort($files, static function (array $a, array $b): int {
			$packageCompare = \strcmp($a['package'], $b['package']);
			return $packageCompare !== 0 ? $packageCompare : \strcmp($a['source'], $b['source']);
		});

		$tokenCount = 0;
		foreach ($files as $file) {
			$tokenCount += \count($file['tokens']);
		}

		return [
			'file_count' => \count($files),
			'token_count' => $tokenCount,
			'files' => $files,
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

				if ($this->isSensitiveTarget($target)) {
					$diffs[] = [
						'package' => $packageName,
						'target' => $target,
						'action' => $action,
						'reason' => $reason,
						'diff_omitted_reason' => 'sensitive_target',
					];
					continue;
				}

				$current = \file_get_contents($targetAbs);
				if ($current === false) {
					throw new FilesystemException('Cannot read migration target for diff: ' . $target);
				}

				if ($this->containsSensitiveConfigKey($target, $current)) {
					$diffs[] = [
						'package' => $packageName,
						'target' => $target,
						'action' => $action,
						'reason' => $reason,
						'diff_omitted_reason' => 'sensitive_config_key',
					];
					continue;
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
	 * Return whether local diff bytes must never enter migration diagnostics.
	 *
	 * Files below var/secrets are sensitive by default. `.tpl` files are explicit
	 * templates and may be diffed because they must not contain populated secrets.
	 */
	private function isSensitiveTarget(string $target): bool {
		$normalized = \strtolower(\str_replace('\\', '/', $target));
		return \str_starts_with($normalized, 'var/secrets/')
			&& !\str_ends_with($normalized, '.tpl');
	}


	/**
	 * Return whether a config target contains a potentially sensitive PHP array key.
	 *
	 * Only targets below config/ are inspected. Matching is case-insensitive and
	 * limited to string literals used as array keys, so comments and ordinary string
	 * values cannot suppress an otherwise useful migration diff.
	 */
	private function containsSensitiveConfigKey(string $target, string $bytes): bool {
		$normalized = \strtolower(\str_replace('\\', '/', $target));
		if (!\str_starts_with($normalized, 'config/')) {
			return false;
		}

		$tokens = \token_get_all($bytes);
		$count = \count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (!\is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
				continue;
			}

			$next = $i + 1;
			while ($next < $count) {
				$candidate = $tokens[$next];
				if (\is_array($candidate) && \in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					$next++;
					continue;
				}
				break;
			}

			if ($next >= $count || !\is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_ARROW) {
				continue;
			}

			$key = \strtolower(\substr($token[1], 1, -1));
			foreach (self::SENSITIVE_CONFIG_KEY_FRAGMENTS as $fragment) {
				if (\str_contains($key, $fragment)) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Build the consolidated blocking review list from actual apply outcomes.
	 *
	 * A failed or conflicting .gitignore block is listed under the installer's own name.
	 *
	 * @param array<string,mixed> $result Apply/materialization result.
	 * @return list<array<string,mixed>> Files that still require human or corrective action.
	 */
	public function blockingManualReview(array $result): array {
		$manual = [];
		$packages = (array)($result['packages'] ?? []);
		if (\is_array($result['gitignore'] ?? null)) {
			$packages[] = ['name' => ScaffoldState::GENERATOR, 'files' => [$result['gitignore']]];
		}

		foreach ($packages as $package) {
			$packageName = (string)($package['name'] ?? '');
			foreach ((array)($package['files'] ?? []) as $file) {
				$applied = (string)($file['applied'] ?? '');
				if (!\in_array($applied, ['wrote_new', 'conflict', 'failed'], true)) {
					continue;
				}
				if ($applied === 'wrote_new' && (string)($file['policy'] ?? '') === 'create-only') {
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
	 * Build the advisory review list for app-owned create-only migration differences.
	 *
	 * Create-only targets intentionally become app-owned after creation. A fresh `.new`
	 * reference remains useful on every migrate run, but a legitimate local difference
	 * must not prevent Composer posture or installer state from completing.
	 *
	 * @param array<string,mixed> $result Apply/materialization result.
	 * @return list<array<string,mixed>> Create-only files worth reviewing without blocking migration.
	 */
	public function advisoryManualReview(array $result): array {
		$manual = [];

		foreach ((array)($result['packages'] ?? []) as $package) {
			$packageName = (string)($package['name'] ?? '');
			foreach ((array)($package['files'] ?? []) as $file) {
				if ((string)($file['applied'] ?? '') !== 'wrote_new' || (string)($file['policy'] ?? '') !== 'create-only') {
					continue;
				}

				$item = [
					'package' => $packageName,
					'target' => (string)($file['target'] ?? ''),
					'applied' => 'wrote_new',
					'reason' => (string)($file['reason'] ?? 'manual_review'),
				];
				foreach (['new_path', 'new_status'] as $key) {
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
	 * List recorded scaffold files that no installed manifest declares any more but that still exist.
	 *
	 * Installer state keeps the record of every target the installer wrote or adopted, also after
	 * its package stops declaring the target. No command plans such a target again, so the file
	 * stays in the application until someone removes it. This read-only list names those files.
	 *
	 * Behavior:
	 * - Lists a recorded target only when no manifest declares it, whichever package records it,
	 *   and only when it is a regular file. On Windows, targets compare without regard to case.
	 * - Lists a target once, under the record with the latest installed_at, also when several
	 *   packages record it.
	 * - The status compares the file with its record:
	 *     - unmodified: a managed file that matches its recorded rendered checksum.
	 *     - local_modified: a managed file that differs from it, or whose record has none.
	 *     - app_owned: a file under any other policy, which the application owns after creation.
	 * - Skips a recorded target that is not a safe app-relative path.
	 *
	 * @param array<string,mixed> $recorded Package baselines as ScaffoldState::readPackages() returns them.
	 * @param array<string,array<string,mixed>> $manifests Manifests as ScaffoldManifestLocator::discover() returns them. A target left out of a filtered set counts as retired.
	 * @return list<array{package:string,target:string,policy:string,status:string}>
	 * @throws FilesystemException When a listed managed file cannot be read.
	 */
	public function retiredScaffoldFiles(array $recorded, array $manifests): array {
		$caseInsensitive = \PHP_OS_FAMILY === 'Windows';
		$declared = [];
		foreach ($manifests as $manifest) {
			foreach ((array)($manifest['files'] ?? []) as $file) {
				$target = (string)($file['target'] ?? '');
				$declared[$caseInsensitive ? \strtolower($target) : $target] = true;
			}
		}

		$newest = [];
		foreach ($recorded as $package => $record) {
			foreach ((array)($record['files'] ?? []) as $target => $data) {
				$target = (string)$target;
				// The installer records only safe app-relative targets; any other key must not steer a read.
				if (!\is_array($data) || !Path::isSafeRelative($target)) {
					continue;
				}

				$target = Path::normalizeRelative($target);
				$key = $caseInsensitive ? \strtolower($target) : $target;
				$installedAt = (string)($data['installed_at'] ?? '');
				if (isset($declared[$key]) || (isset($newest[$key]) && \strcmp($installedAt, $newest[$key]['installed_at']) <= 0)) {
					continue;
				}

				$newest[$key] = ['package' => (string)$package, 'target' => $target, 'installed_at' => $installedAt, 'data' => $data];
			}
		}

		$retired = [];
		foreach ($newest as $entry) {
			// Only read, never written, so a plain join suffices; resolveTarget() would fail migrate
			// on an application symlink that leaves the app root.
			$path = $this->pathGuard->appRoot() . '/' . $entry['target'];
			if (!\is_file($path)) {
				continue;
			}

			$policy = (string)($entry['data']['policy'] ?? '');
			$status = 'app_owned';
			if ($policy === 'managed') {
				$bytes = \file_get_contents($path);
				if ($bytes === false) {
					throw new FilesystemException('Cannot read retired scaffold target: ' . $entry['target']);
				}
				$recordedChecksum = (string)($entry['data']['rendered_checksum'] ?? '');
				$status = $recordedChecksum !== '' && Checksum::matches($bytes, $recordedChecksum) ? 'unmodified' : 'local_modified';
			}

			$retired[] = [
				'package' => $entry['package'],
				'target' => $entry['target'],
				'policy' => $policy,
				'status' => $status,
			];
		}

		return $retired;
	}


	/**
	 * Collect one unique package source when it contains percent-style uppercase tokens.
	 *
	 * @param array<string,array{package:string,source:string,targets:list<string>,tokens:list<string>}> $sources
	 */
	private function collectPercentStyleSource(array &$sources, string $package, string $source, string $sourcePath, string $target): void {
		if ($source === '' || $sourcePath === '') {
			return;
		}

		$bytes = $this->renderer->readStub($sourcePath);
		$matchCount = \preg_match_all('/%[A-Z][A-Z0-9_]*%/', $bytes, $matches);
		if ($matchCount === false) {
			throw new InstallerException('Failed to scan scaffold source for percent-style tokens: ' . $source);
		}
		if ($matchCount === 0) {
			return;
		}

		$tokens = \array_values(\array_unique($matches[0]));
		\sort($tokens, \SORT_STRING);
		$key = $package . "\0" . $source;
		if (!isset($sources[$key])) {
			$sources[$key] = [
				'package' => $package,
				'source' => $source,
				'targets' => [],
				'tokens' => $tokens,
			];
		}

		if ($target !== '' && !\in_array($target, $sources[$key]['targets'], true)) {
			$sources[$key]['targets'][] = $target;
			\sort($sources[$key]['targets'], \SORT_STRING);
		}
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
