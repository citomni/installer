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

use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verify generated migration sidecars always represent the current desired source.
 */
final class MigrationSidecarSafetyTest extends TestCase {

	private string $base;
	private string $appRoot;
	private string $packageRoot;
	private BuildScaffoldPlan $builder;
	private ApplyScaffoldPlan $applier;

	protected function setUp(): void {
		$this->base = \sys_get_temp_dir() . '/citomni_sidecar_' . \bin2hex(\random_bytes(6));
		$this->appRoot = $this->base . '/app';
		$this->packageRoot = $this->base . '/package';
		\mkdir($this->appRoot . '/config', 0775, true);
		\mkdir($this->packageRoot . '/install', 0775, true);

		$guard = new PathGuard($this->appRoot);
		$renderer = new ScaffoldRenderer();
		$state = ScaffoldState::forAppRoot($this->appRoot);
		$this->builder = new BuildScaffoldPlan($guard, $renderer, $state);
		$this->applier = new ApplyScaffoldPlan($guard, $renderer, $state);
	}

	protected function tearDown(): void {
		$this->removeTree($this->base);
	}


	public function testExistingDifferentGeneratedSidecarIsOverwritten(): void {
		\file_put_contents($this->appRoot . '/config/example.php', "<?php\nreturn ['local' => true];\n");
		\file_put_contents($this->appRoot . '/config/example.php.new', "<?php\n// stale generated snapshot\n");
		$desired = "<?php\nreturn ['upstream' => true];\n";
		\file_put_contents($this->packageRoot . '/install/example.php.stub', $desired);

		$plan = $this->builder->build('migrate', $this->manifest(), ['acme/pkg' => []]);
		$result = $this->applier->apply($plan, ['persist_state' => false]);
		$file = $result['packages'][0]['files'][0];

		$this->assertSame('wrote_new', $file['applied']);
		$this->assertSame('written', $file['new_status']);
		$this->assertSame($desired, \file_get_contents($this->appRoot . '/config/example.php.new'));
	}


	public function testGeneratedSidecarDirectoryIsReportedAsIoFailure(): void {
		\file_put_contents($this->appRoot . '/config/example.php', "<?php\nreturn ['local' => true];\n");
		\mkdir($this->appRoot . '/config/example.php.new');
		\file_put_contents($this->packageRoot . '/install/example.php.stub', "<?php\nreturn ['upstream' => true];\n");

		$plan = $this->builder->build('migrate', $this->manifest(), ['acme/pkg' => []]);
		$result = $this->applier->apply($plan, ['persist_state' => false]);
		$file = $result['packages'][0]['files'][0];

		$this->assertSame('failed', $file['applied']);
		$this->assertSame('io', $file['error_type']);
		$this->assertStringContainsString('not a regular file', $file['error']);
	}


	/** @return array<string,array<string,mixed>> */
	private function manifest(): array {
		return [
			'acme/pkg' => [
				'package' => 'acme/pkg',
				'version' => 1,
				'root' => $this->packageRoot,
				'installed_version' => '1.0.0',
				'files' => [[
					'target' => 'config/example.php',
					'source' => 'install/example.php.stub',
					'type' => 'config',
					'policy' => 'create-only',
				]],
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
