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

/**
 * Persist a human-readable audit report for one legacy installer migration run.
 *
 * Reports live under var/logs rather than var/state because they are historical audit
 * artifacts, not machine-readable current installer state.
 */
final class MigrationReportWriter {

	private const REPORT_ROOT = 'var/logs/citomni-installer/migrations';

	public function __construct(private readonly PathGuard $pathGuard) {
	}


	/**
	 * Write one Markdown migration report atomically.
	 *
	 * @param array<string,mixed> $result Completed migration result with `migration` diagnostics.
	 * @return string Absolute report path.
	 */
	public function write(array $result): string {
		$path = $this->nextReportPath(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		AtomicFileWriter::write($path, $this->render($result));
		return $path;
	}


	/** @param array<string,mixed> $result */
	private function render(array $result): string {
		$migration = \is_array($result['migration'] ?? null) ? $result['migration'] : [];
		$blocking = (array)($migration['blocking_manual_review'] ?? []);
		$advisoryScaffold = $this->sortReviewItemsByTarget((array)($migration['advisory_manual_review'] ?? []));
		$retired = $this->sortReviewItemsByTarget((array)($migration['retired_scaffold_files'] ?? []));
		$appSkeleton = \is_array($migration['app_skeleton'] ?? null) ? $migration['app_skeleton'] : [];
		$advisory = (array)($appSkeleton['manual_review'] ?? []);
		$config = \is_array($migration['installer_config'] ?? null) ? $migration['installer_config'] : [];
		$phpLint = \is_array($migration['php_lint'] ?? null) ? $migration['php_lint'] : [];
		$sourceWarnings = \is_array($migration['scaffold_source_warnings'] ?? null) ? $migration['scaffold_source_warnings'] : [];
		$diffIndex = $this->diffIndex((array)($migration['scaffold_diffs'] ?? []));
		$counts = $this->counts((array)($result['packages'] ?? []));
		$generated = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);

		$out = "# CitOmni Installer Migration Report\n\n";
		$out .= '- Generated: `' . $generated . "`\n";
		$out .= '- Environment: `' . (string)($result['environment'] ?? '') . "`\n";
		$out .= '- Migration mode: `' . (string)($migration['mode'] ?? 'unknown') . "`\n";
		if (isset($migration['previous_environment']) && $migration['previous_environment'] !== null) {
			$out .= '- Previous environment: `' . (string)$migration['previous_environment'] . "`\n";
		}
		$out .= '- Scaffold result: `' . (($result['ok'] ?? false) ? 'ok' : 'manual action or error') . "`\n";
		$out .= '- Installer state written: `' . (!empty($result['state_written']) ? 'yes' : 'no') . "`\n\n";

		$out .= "## Summary\n\n";
		foreach ($counts as $label => $count) {
			$out .= '- ' . $label . ': ' . $count . "\n";
		}
		$out .= '- Blocking manual-review files: ' . \count($blocking) . "\n";
		$out .= '- Advisory create-only scaffold review files: ' . \count($advisoryScaffold) . "\n";
		$out .= '- Retired scaffold files: ' . \count($retired) . "\n";
		$out .= '- Advisory app-skeleton review files: ' . \count($advisory) . "\n";
		if ($phpLint !== []) {
			$out .= '- PHP lint checked: ' . (int)($phpLint['checked'] ?? 0) . "\n";
			$out .= '- PHP lint failed: ' . (int)($phpLint['failed'] ?? 0) . "\n";
		}
		$out .= '- Current scaffold sources with percent-style token warnings: ' . (int)($sourceWarnings['file_count'] ?? 0) . "\n";
		$out .= "\n";

		$out .= "## Migration review\n\n";
		$out .= "### Blocking scaffold files\n\n";
		if ($blocking === []) {
			$out .= "None.\n\n";
		} else {
			foreach ($blocking as $item) {
				$out .= '- `' . (string)($item['package'] ?? '') . ': ' . (string)($item['target'] ?? '') . '` — ' . (string)($item['reason'] ?? 'manual review') . "\n";
			}
			$out .= "\nThese files block completion of the scaffold migration until the local and `.new` versions have been reconciled.\n\n";
		}

		$out .= "### Advisory create-only scaffold files\n\n";
		if ($advisoryScaffold === []) {
			$out .= "None.\n\n";
		} else {
			foreach ($advisoryScaffold as $item) {
				$out .= '- `' . (string)($item['package'] ?? '') . ': ' . (string)($item['target'] ?? '') . '` — ' . (string)($item['reason'] ?? 'manual review');
				if (isset($item['new_path'])) {
					$out .= ' — review `' . (string)$item['new_path'] . '`';
				}
				$out .= "\n";
			}
			$out .= "\nThese create-only files are app-owned after creation. Local differences are expected, remain untouched, and do not prevent Composer posture or v2 scaffold state from being committed. The `.new` files are refreshed current-source references and will continue to be reported on later migrate runs while differences remain.\n\n";
		}

		$out .= "### Retired scaffold files\n\n";
		if ($retired === []) {
			$out .= "None.\n\n";
		} else {
			foreach ($retired as $item) {
				$out .= '- `' . (string)($item['package'] ?? '') . ': ' . (string)($item['target'] ?? '') . '` — ' . (string)($item['status'] ?? '') . "\n";
			}
			$out .= "\nInstaller state records these files, but no installed package declares them any more. The installer neither updates nor deletes them; delete each file once nothing in the application uses it. `unmodified` is a managed file that still matches the bytes the installer recorded, `local_modified` a managed file changed since, and `app_owned` a create-only file. The installer block in `.gitignore` does not list a retired managed file, so Git may show it as untracked.\n\n";
		}

		$out .= "### PHP lint failures\n\n";
		$lintFailures = [];
		foreach ((array)($phpLint['files'] ?? []) as $lintFile) {
			if (empty($lintFile['ok'])) {
				$lintFailures[] = $lintFile;
			}
		}
		if (($phpLint['available'] ?? true) !== true) {
			$out .= 'Lint audit unavailable: ' . (string)($phpLint['error'] ?? 'unknown error') . "\n\n";
		} elseif ($lintFailures === []) {
			$out .= "None.\n\n";
		} else {
			foreach ($lintFailures as $lintFile) {
				$out .= '- `' . (string)($lintFile['path'] ?? '') . '` — php -l exit ' . (int)($lintFile['exit_code'] ?? -1) . "\n";
			}
			$out .= "\nThese syntax failures require manual correction.\n\n";
		}

		$out .= "### Advisory app-skeleton files\n\n";
		if ($advisory === []) {
			$out .= "None.\n\n";
		} else {
			foreach ($advisory as $item) {
				$out .= '- `' . (string)($item['target'] ?? '') . '` — review `' . (string)($item['new_path'] ?? '') . '`';
				if (isset($item['sidecar_status'])) {
					$out .= ' (' . (string)$item['sidecar_status'] . ')';
				}
				$out .= "\n";
			}
			$out .= "\nThese are app-owned files. Their differences are advisory and do not prevent v2 scaffold state from being committed.\n\n";
		}

		$out .= "## Installer configuration\n\n";
		if ($config === []) {
			$out .= "No installer-config diagnostics were produced.\n\n";
		} else {
			$out .= 'Selected environment: `' . (string)($config['selected_environment'] ?? '') . "`\n\n";
			$out .= "### Missing from config/citomni_installer.php\n\n";
			foreach ((array)($config['missing_from_config'] ?? []) as $environment => $keys) {
				$out .= '- ' . $environment . ': ' . $this->inlineKeys((array)$keys) . "\n";
			}
			$cliOnly = (array)($config['provided_for_selected_by_cli_or_derivation'] ?? []);
			if ($cliOnly !== []) {
				$out .= "\n### Available for this run but absent from installer config\n\n";
				$out .= $this->bulletKeys($cliOnly) . "\n";
			}
			$out .= "\n### Configured but unused by current installed scaffold\n\n";
			$unused = (array)($config['unused_configured'] ?? []);
			$out .= $unused === [] ? "None.\n\n" : $this->bulletKeys($unused) . "\n";
			$out .= "Unused means no currently installed scaffold stub references the key. It is not automatically classified as deprecated.\n\n";
		}

		$out .= "## Current scaffold source warnings\n\n";
		$warningFiles = (array)($sourceWarnings['files'] ?? []);
		if ($warningFiles === []) {
			$out .= "No percent-style uppercase tokens were found in current package scaffold sources.\n\n";
		} else {
			$out .= "Possible percent-style tokens were found in current package scaffold sources. This is advisory only; the scanner observes syntax and does not classify whether a token is intentional or leftover legacy placeholder syntax.\n\n";
			foreach ($warningFiles as $warning) {
				$out .= '- `' . (string)($warning['package'] ?? '') . ': ' . (string)($warning['source'] ?? '') . '` — ';
				$out .= $this->inlineKeys((array)($warning['tokens'] ?? [])) . "\n";
				$targets = (array)($warning['targets'] ?? []);
				if ($targets !== []) {
					$out .= '  - Targets: ' . \implode(', ', \array_map(static fn(string $target): string => '`' . $target . '`', $targets)) . "\n";
				}
			}
			$out .= "\nThe current installer does not interpret `%NAME%` tokens. Review these package sources to determine whether each occurrence is intentional. These warnings do not affect the migrate exit code.\n\n";
		}

		$out .= "## PHP lint\n\n";
		if ($phpLint === []) {
			$out .= "No PHP lint diagnostics were produced.\n\n";
		} elseif (($phpLint['available'] ?? false) !== true) {
			$out .= 'PHP lint unavailable: ' . (string)($phpLint['error'] ?? 'unknown error') . "\n\n";
		} else {
			$out .= '- PHP binary: `' . (string)($phpLint['php_binary'] ?? '') . "`\n";
			$out .= '- Checked: ' . (int)($phpLint['checked'] ?? 0) . "\n";
			$out .= '- Passed: ' . (int)($phpLint['passed'] ?? 0) . "\n";
			$out .= '- Failed: ' . (int)($phpLint['failed'] ?? 0) . "\n\n";
			foreach ((array)($phpLint['files'] ?? []) as $lintFile) {
				$out .= '### `' . (string)($lintFile['path'] ?? '') . "`\n\n";
				$out .= '- Result: `' . (!empty($lintFile['ok']) ? 'pass' : 'fail') . "`\n";
				$out .= '- Exit code: ' . (int)($lintFile['exit_code'] ?? -1) . "\n\n";
				$output = (string)($lintFile['output'] ?? '');
				$out .= "```text\n" . \rtrim($output) . "\n```\n\n";
			}
		}

		$out .= "## Scaffold files\n\n";
		foreach ((array)($result['packages'] ?? []) as $package) {
			$packageName = (string)($package['name'] ?? '');
			$out .= '### ' . $packageName . "\n\n";
			foreach ((array)($package['files'] ?? []) as $file) {
				$target = (string)($file['target'] ?? '');
				$out .= '#### `' . $target . "`\n\n";
				$out .= '- Result: `' . (string)($file['applied'] ?? '') . "`\n";
				$out .= '- Reason: `' . (string)($file['reason'] ?? '') . "`\n";
				if (isset($file['backup_path'])) {
					$out .= '- Backup: `' . (string)$file['backup_path'] . "`\n";
				}
				if (isset($file['new_path'])) {
					$out .= '- Sidecar: `' . (string)$file['new_path'] . "`\n";
				}
				if (isset($file['error'])) {
					$out .= '- Error: ' . (string)$file['error'] . "\n";
				}
				$out .= "\n";

				$key = $packageName . "\0" . $target;
				if (isset($diffIndex[$key])) {
					$diagnostic = $diffIndex[$key];
					$omittedReason = $diagnostic['diff_omitted_reason'] ?? null;
					if ($omittedReason === 'sensitive_target') {
						$out .= "- Diff: omitted because the target is sensitive\n\n";
					} elseif ($omittedReason === 'sensitive_config_key') {
						$out .= "- Diff: omitted because the config target contains a potentially sensitive key\n\n";
					} elseif (isset($diagnostic['diff']) && (string)$diagnostic['diff'] !== '') {
						$out .= $this->diffBlock((string)$diagnostic['diff']) . "\n";
					}
				}
			}
		}

		$gitignore = \is_array($result['gitignore'] ?? null) ? $result['gitignore'] : null;
		if ($gitignore !== null) {
			$out .= "## .gitignore block\n\n";
			$out .= '- Result: `' . (string)($gitignore['applied'] ?? '') . "`\n";
			$out .= '- Reason: `' . (string)($gitignore['reason'] ?? '') . "`\n";
			if (isset($gitignore['error'])) {
				$out .= '- Error: ' . (string)$gitignore['error'] . "\n";
			}
			$newlyIgnored = (array)($gitignore['newly_ignored'] ?? []);
			if (\in_array($gitignore['applied'] ?? null, ['created', 'updated'], true) && $newlyIgnored !== []) {
				$out .= '- Newly ignored scaffold targets: ' . \implode(', ', \array_map(static fn(mixed $target): string => '`' . (string)$target . '`', $newlyIgnored)) . "\n";
				$out .= "\nGit keeps tracking files it already tracks. Untrack these targets once with `git rm --cached --ignore-unmatch -- <path>`.\n";
			}
			$out .= "\n";
		}

		$out .= "## citomni/app-skeleton reference audit\n\n";
		if (($appSkeleton['available'] ?? false) !== true) {
			$out .= 'Reference audit unavailable: ' . (string)($appSkeleton['error'] ?? 'unknown error') . "\n";
			return $out;
		}

		$out .= '- Repository: `citomni/app-skeleton`' . "\n";
		$out .= '- Commit: `' . (string)($appSkeleton['commit'] ?? '') . "`\n\n";
		foreach ((array)($appSkeleton['files'] ?? []) as $file) {
			$target = (string)($file['target'] ?? '');
			$out .= '### `' . $target . "`\n\n";
			$out .= '- Status: `' . (string)($file['status'] ?? '') . "`\n";
			if (isset($file['new_path'])) {
				$out .= '- Reference sidecar: `' . (string)$file['new_path'] . "`\n";
				$out .= '- Sidecar status: `' . (string)($file['sidecar_status'] ?? '') . "`\n";
			}
			if ($target === 'composer.json' && (string)($file['status'] ?? '') !== 'identical') {
				$out .= "- Note: The app's composer.json is expected to diverge. Review selectively; `classmap-authoritative` is environment-managed by citomni/installer.\n";
			}
			$out .= "\n";
			$diff = (string)($file['diff'] ?? '');
			if ($diff !== '') {
				$out .= $this->diffBlock($diff) . "\n";
			}
		}

		return $out;
	}


	/** Return the next non-conflicting human-readable migration report path. */
	private function nextReportPath(\DateTimeImmutable $timestamp): string {
		$basename = 'citomni-installer-migration-' . $timestamp->format('Ymd\\THi\\Z');
		$sequence = 1;

		do {
			$suffix = $sequence === 1 ? '' : '-' . $sequence;
			$path = $this->pathGuard->resolveTarget(self::REPORT_ROOT . '/' . $basename . $suffix . '.md');
			$sequence++;
		} while (\file_exists($path));

		return $path;
	}


	/** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
	private function sortReviewItemsByTarget(array $items): array {
		\usort($items, static function(array $left, array $right): int {
			$target = \strcmp((string)($left['target'] ?? ''), (string)($right['target'] ?? ''));
			if ($target !== 0) {
				return $target;
			}

			return \strcmp((string)($left['package'] ?? ''), (string)($right['package'] ?? ''));
		});

		return $items;
	}


	/** @param array<int,array<string,mixed>> $packages */
	private function counts(array $packages): array {
		$counts = [
			'Created' => 0,
			'Updated' => 0,
			'Registered' => 0,
			'Skipped' => 0,
			'Wrote .new' => 0,
			'Conflicts' => 0,
			'Failed' => 0,
		];
		$map = [
			'created' => 'Created',
			'updated' => 'Updated',
			'registered' => 'Registered',
			'skipped' => 'Skipped',
			'wrote_new' => 'Wrote .new',
			'conflict' => 'Conflicts',
			'failed' => 'Failed',
		];
		foreach ($packages as $package) {
			foreach ((array)($package['files'] ?? []) as $file) {
				$key = $map[(string)($file['applied'] ?? '')] ?? null;
				if ($key !== null) {
					$counts[$key]++;
				}
			}
		}
		return $counts;
	}


	/** @param list<array<string,mixed>> $diffs @return array<string,array<string,mixed>> */
	private function diffIndex(array $diffs): array {
		$index = [];
		foreach ($diffs as $diff) {
			$key = (string)($diff['package'] ?? '') . "\0" . (string)($diff['target'] ?? '');
			$index[$key] = $diff;
		}
		return $index;
	}


	private function diffBlock(string $diff): string {
		return "````diff\n" . \rtrim($diff) . "\n````\n";
	}


	/** @param list<string> $keys */
	private function inlineKeys(array $keys): string {
		if ($keys === []) {
			return 'none';
		}
		return \implode(', ', \array_map(static fn(string $key): string => '`' . $key . '`', $keys));
	}


	/** @param list<string> $keys */
	private function bulletKeys(array $keys): string {
		return \implode('', \array_map(static fn(string $key): string => '- `' . $key . "`\n", $keys));
	}
}
