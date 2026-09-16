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

use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldRenderer;
use PHPUnit\Framework\TestCase;

final class MigrationCreateOnlyPlanTest extends TestCase {

	private string $base;
	private string $appRoot;
	private string $packageRoot;
	private BuildScaffoldPlan $builder;

	protected function setUp(): void {
		$this->base = \sys_get_temp_dir() . '/citomni_migrate_plan_' . \bin2hex(\random_bytes(6));
		$this->appRoot = $this->base . '/app';
		$this->packageRoot = $this->base . '/package';
		\mkdir($this->appRoot . '/config', 0775, true);
		\mkdir($this->packageRoot . '/install', 0775, true);

		$pathGuard = new PathGuard($this->appRoot);
		$this->builder = new BuildScaffoldPlan(
			$pathGuard,
			new ScaffoldRenderer(),
			ScaffoldState::forAppRoot($this->appRoot)
		);
	}

	protected function tearDown(): void {
		$this->removeTree($this->base);
	}

	public function testPhpWithOnlyDocumentationChangesIsBackedUpAndRefreshed(): void {
		$target = 'config/example.php';
		$this->writeTarget($target, "<?php\n/** Old docs. */\nreturn ['enabled' => true];\n");
		$this->writeStub('example.php.stub', "<?php\n/* New license. */\n/** New docs. */\nreturn [\n\t'enabled' => true\n];\n");

		$file = $this->planFile($target, 'install/example.php.stub');

		$this->assertSame('update', $file['action']);
		$this->assertSame('migration_php_nonfunctional_change', $file['reason']);
		$this->assertTrue($file['backup']);
	}

	public function testPhpWithCodeDifferenceWritesNew(): void {
		$target = 'config/example.php';
		$this->writeTarget($target, "<?php\nreturn ['enabled' => true];\n");
		$this->writeStub('example.php.stub', "<?php\nreturn ['enabled' => false];\n");

		$file = $this->planFile($target, 'install/example.php.stub');

		$this->assertSame('write_new', $file['action']);
		$this->assertSame('migration_php_code_difference', $file['reason']);
	}

	public function testTplIsAuthoritativelyRefreshed(): void {
		$target = 'config/example.php.tpl';
		$this->writeTarget($target, "<?php\nreturn ['old' => true];\n");
		$this->writeStub('example.php.tpl.stub', "<?php\nreturn ['new' => true];\n");

		$file = $this->planFile($target, 'install/example.php.tpl.stub');

		$this->assertSame('update', $file['action']);
		$this->assertSame('migration_template_refresh', $file['reason']);
		$this->assertTrue($file['backup']);
	}

	public function testNonPhpCreateOnlyDifferenceWritesNew(): void {
		$target = 'config/example.txt';
		$this->writeTarget($target, "old\n");
		$this->writeStub('example.txt.stub', "new\n");

		$file = $this->planFile($target, 'install/example.txt.stub');

		$this->assertSame('write_new', $file['action']);
		$this->assertSame('migration_content_difference', $file['reason']);
	}

	/** @return array<string,mixed> */
	private function planFile(string $target, string $source): array {
		$manifest = [
			'acme/pkg' => [
				'package' => 'acme/pkg',
				'version' => 1,
				'root' => $this->packageRoot,
				'installed_version' => '1.0.0',
				'files' => [[
					'target' => $target,
					'source' => $source,
					'type' => 'config',
					'policy' => 'create-only',
				]],
			],
		];

		$plan = $this->builder->build('migrate', $manifest, ['acme/pkg' => []]);
		return $plan['packages'][0]['files'][0];
	}

	private function writeTarget(string $target, string $bytes): void {
		$path = $this->appRoot . '/' . $target;
		$dir = \dirname($path);
		if (!\is_dir($dir)) {
			\mkdir($dir, 0775, true);
		}
		\file_put_contents($path, $bytes);
	}

	private function writeStub(string $name, string $bytes): void {
		\file_put_contents($this->packageRoot . '/install/' . $name, $bytes);
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
			\is_dir($child) ? $this->removeTree($child) : @\unlink($child);
		}
		@\rmdir($path);
	}
}
