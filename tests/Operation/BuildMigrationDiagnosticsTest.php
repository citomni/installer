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

namespace CitOmni\Installer\Tests\Operation;

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Operation\BuildMigrationDiagnostics;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verify migration diagnostics expose actionable installer-config inputs.
 */
final class BuildMigrationDiagnosticsTest extends TestCase {

	private string $root;
	private string $packageRoot;

	protected function setUp(): void {
		$this->root = \sys_get_temp_dir() . '/citomni_diag_' . \bin2hex(\random_bytes(6));
		$this->packageRoot = $this->root . '/package';
		\mkdir($this->root . '/config', 0775, true);
		\mkdir($this->packageRoot, 0775, true);

		\file_put_contents($this->packageRoot . '/dev.stub', "<?php\nreturn [];\n");
		\file_put_contents($this->packageRoot . '/stage.stub', "<?php\nreturn [];\n");
		\file_put_contents($this->packageRoot . '/prod.stub', "<?php\n// %PROD_ROOT_URL%\nreturn ['host' => '{{PROD_ROOT_HOST}}'];\n");
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
	}


	public function testMissingDerivedPlaceholderReportsConfigurableSourceKey(): void {
		$diagnostics = $this->diagnostics();
		$result = $diagnostics->inspectInstallerConfig($this->manifests(), Environment::Prod, []);

		$this->assertSame(['PROD_ROOT_URL'], $result['missing_from_config']['prod']);
		$this->assertSame(['PROD_ROOT_URL'], $result['missing_effective_selected']);
	}


	public function testPercentStyleSourceTokensAreAdvisoryAndDeduplicatedPerSource(): void {
		$result = $this->diagnostics()->inspectPercentStyleSourceTokens($this->manifests());

		$this->assertSame(1, $result['file_count']);
		$this->assertSame(1, $result['token_count']);
		$this->assertSame('acme/pkg', $result['files'][0]['package']);
		$this->assertSame('prod.stub', $result['files'][0]['source']);
		$this->assertSame(['config/example.php'], $result['files'][0]['targets']);
		$this->assertSame(['%PROD_ROOT_URL%'], $result['files'][0]['tokens']);
	}


	public function testBlockingReviewIncludesWriteNewConflictsAndFailures(): void {
		$manual = $this->diagnostics()->blockingManualReview([
			'packages' => [[
				'name' => 'acme/pkg',
				'files' => [
					['target' => 'a.php', 'applied' => 'wrote_new', 'reason' => 'code_difference'],
					['target' => 'b.php', 'applied' => 'conflict', 'reason' => 'stale'],
					['target' => 'c.php', 'applied' => 'failed', 'reason' => 'write', 'error' => 'denied'],
					['target' => 'd.php', 'applied' => 'updated', 'reason' => 'ok'],
				],
			]],
		]);

		$this->assertSame(['a.php', 'b.php', 'c.php'], \array_column($manual, 'target'));
	}


	public function testDerivedSourceIsNotReportedUnusedWhenDerivedHostIsRequired(): void {
		\file_put_contents(
			$this->root . '/config/citomni_installer.php',
			"<?php\nreturn ['placeholders' => ['PROD_ROOT_URL' => 'https://example.com']];\n"
		);

		$diagnostics = $this->diagnostics();
		$result = $diagnostics->inspectInstallerConfig($this->manifests(), Environment::Prod, []);

		$this->assertSame([], $result['missing_from_config']['prod']);
		$this->assertSame([], $result['missing_effective_selected']);
		$this->assertSame([], $result['unused_configured']);
	}


	private function diagnostics(): BuildMigrationDiagnostics {
		return new BuildMigrationDiagnostics(
			new ScaffoldRenderer(),
			new PlaceholderResolver($this->root)
		);
	}


	/** @return array<string,array<string,mixed>> */
	private function manifests(): array {
		return [
			'acme/pkg' => [
				'files' => [
					[
						'target' => 'config/example.php',
						'environments' => [
							'dev' => [
								'source' => 'dev.stub',
								'source_path' => $this->packageRoot . '/dev.stub',
							],
							'stage' => [
								'source' => 'stage.stub',
								'source_path' => $this->packageRoot . '/stage.stub',
							],
							'prod' => [
								'source' => 'prod.stub',
								'source_path' => $this->packageRoot . '/prod.stub',
							],
						],
					],
				],
			],
		];
	}


	private function removeTree(string $path): void {
		if (!\is_dir($path)) {
			return;
		}

		$items = \scandir($path);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$child = $path . '/' . $item;
			if (\is_dir($child)) {
				$this->removeTree($child);
			} else {
				@\unlink($child);
			}
		}
		@\rmdir($path);
	}
}
