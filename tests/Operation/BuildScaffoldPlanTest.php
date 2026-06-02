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

use PHPUnit\Framework\TestCase;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Util\Checksum;

/**
 * Behavioural tests for the scaffold decision graph.
 *
 * These exercise the real Support/State/Util collaborators against a temporary
 * app-root and package-root on disk. The Operation never writes app files or
 * state itself; we assert on the returned plan (and the internal `_apply`
 * snapshot ApplyScaffoldPlan would consume).
 */
final class BuildScaffoldPlanTest extends TestCase {

	private string $appRoot;
	private string $packageRoot;
	private BuildScaffoldPlan $op;
	private ScaffoldRenderer $renderer;
	private ScaffoldState $state;

	/** Placeholders the stubs reference. */
	private array $placeholders = ['APP_NAMESPACE' => 'App', 'APP_NAME' => 'Acme'];

	private const PKG = 'acme/pkg';

	protected function setUp(): void {
		$base = \sys_get_temp_dir() . '/bsp_' . \bin2hex(\random_bytes(6));
		$this->appRoot     = $base . '/app';
		$this->packageRoot = $base . '/pkg';

		$this->mkdirp($this->appRoot . '/public');
		$this->mkdirp($this->appRoot . '/config');
		$this->mkdirp($this->packageRoot . '/resources/scaffold');

		// Managed stub (index) + create-only stub (routes).
		\file_put_contents($this->packageRoot . '/resources/scaffold/index.php.stub', "<?php\nnamespace {{APP_NAMESPACE}};\n// {{APP_NAME}}\n");
		\file_put_contents($this->packageRoot . '/resources/scaffold/routes.php.stub', "<?php\nreturn []; // {{APP_NAME}}\n");

		$this->renderer = new ScaffoldRenderer();
		$this->state    = ScaffoldState::forAppRoot($this->appRoot);
		$this->op       = new BuildScaffoldPlan(new PathGuard($this->appRoot), $this->renderer, $this->state);
	}

	protected function tearDown(): void {
		$this->rrmdir(\dirname($this->appRoot));
	}


	// ----------------------------------------------------------------
	// managed
	// ----------------------------------------------------------------

	public function testManagedMissingIsCreatedOnInstall(): void {
		$plan = $this->op->build('install', $this->manifest(), $this->placeholderMap());

		$index = $this->file($plan, self::PKG, 'public/index.php');
		$this->assertSame('managed', $index['policy']);
		$this->assertSame('missing', $index['status']);
		$this->assertSame('create', $index['action']);
		$this->assertArrayHasKey('_apply', $index);
		$this->assertSame($this->expectedRendered('index.php.stub'), $index['_apply']['rendered_checksum']);
	}

	public function testManagedUpToDateIsNoneOnSync(): void {
		$this->writeRenderedTarget('public/index.php', 'index.php.stub');
		$this->seedManagedState('public/index.php', 'index.php.stub');

		$plan  = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$index = $this->file($plan, self::PKG, 'public/index.php');

		$this->assertSame('up_to_date', $index['status']);
		$this->assertSame('none', $index['action']);
		$this->assertArrayNotHasKey('_apply', $index);
	}

	public function testManagedStubDriftIsUpdateAvailableAndUpdatesOnSync(): void {
		$this->writeRenderedTarget('public/index.php', 'index.php.stub');
		// Baseline records a DIFFERENT stub checksum => upstream stub appears changed.
		$this->seedManagedState('public/index.php', 'index.php.stub', $this->placeholders, 'sha256:' . \str_repeat('0', 64));

		$plan  = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$index = $this->file($plan, self::PKG, 'public/index.php');

		$this->assertSame('update_available', $index['status']);
		$this->assertSame('stub_drift', $index['reason']);
		$this->assertSame('update', $index['action']);
		$this->assertArrayNotHasKey('backup', $index); // disk is clean => no backup
	}

	public function testManagedLocalModifiedWritesNewOnSync(): void {
		\file_put_contents($this->appRoot . '/public/index.php', "<?php\n// hand edited\n");
		// Baseline points at the clean render; disk differs => local_modified.
		$this->seedManagedState('public/index.php', 'index.php.stub');

		$plan  = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$index = $this->file($plan, self::PKG, 'public/index.php');

		$this->assertSame('local_modified', $index['status']);
		$this->assertSame('rendered_checksum_mismatch', $index['reason']);
		$this->assertSame('write_new', $index['action']);
		$this->assertSame($this->appRoot . '/public/index.php.new', $index['_apply']['new_path']);
	}

	public function testManagedForceOverwriteRequiresBackup(): void {
		\file_put_contents($this->appRoot . '/public/index.php', "<?php\n// hand edited\n");
		$this->seedManagedState('public/index.php', 'index.php.stub');

		$plan  = $this->op->build('sync', $this->manifest(), $this->placeholderMap(), ['force' => true]);
		$index = $this->file($plan, self::PKG, 'public/index.php');

		$this->assertSame('local_modified', $index['status']);
		$this->assertSame('update', $index['action']);
		$this->assertTrue($index['backup']);
		$this->assertTrue($index['_apply']['backup_required']);
	}


	// ----------------------------------------------------------------
	// create-only
	// ----------------------------------------------------------------

	public function testCreateOnlyMissingIsCreatedOnSync(): void {
		$plan   = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$routes = $this->file($plan, self::PKG, 'config/routes.php');

		$this->assertSame('create-only', $routes['policy']);
		$this->assertSame('missing', $routes['status']);
		$this->assertSame('create', $routes['action']);
	}

	public function testCreateOnlyPresentIsNeverTouched(): void {
		\file_put_contents($this->appRoot . '/config/routes.php', "<?php\nreturn ['user-customised'];\n");

		$plan   = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$routes = $this->file($plan, self::PKG, 'config/routes.php');

		$this->assertSame('create_only_present', $routes['status']);
		$this->assertSame('none', $routes['action']);
		$this->assertArrayNotHasKey('_apply', $routes);
	}

	public function testCreateOnlyPresentWithForceOverwritesWithBackup(): void {
		\file_put_contents($this->appRoot . '/config/routes.php', "<?php\nreturn ['user-customised'];\n");

		$plan   = $this->op->build('sync', $this->manifest(), $this->placeholderMap(), ['force' => true]);
		$routes = $this->file($plan, self::PKG, 'config/routes.php');

		$this->assertSame('update', $routes['action']);
		$this->assertTrue($routes['backup']);
		$this->assertTrue($routes['_apply']['backup_required']);
	}


	// ----------------------------------------------------------------
	// sync without state (contract §10)
	// ----------------------------------------------------------------

	public function testSyncWithoutStateAdoptsMatchingExistingFile(): void {
		// No state file at all. Existing managed file equals the current render.
		$this->writeRenderedTarget('public/index.php', 'index.php.stub');
		$this->assertFalse($this->state->exists());

		$plan  = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$index = $this->file($plan, self::PKG, 'public/index.php');

		$this->assertSame('unknown_existing', $index['status']);
		$this->assertSame('register_state', $index['action']);
		$this->assertSame('adopt_clean_baseline', $index['reason']);
		$this->assertSame($this->expectedRendered('index.php.stub'), $index['_apply']['rendered_checksum']);
	}

	public function testSyncWithoutStateWritesNewForDivergentExistingFile(): void {
		// No state file. Existing managed file does NOT match the current render.
		\file_put_contents($this->appRoot . '/public/index.php', "<?php\n// pre-existing, unmanaged\n");
		$this->assertFalse($this->state->exists());

		$plan  = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$index = $this->file($plan, self::PKG, 'public/index.php');

		$this->assertSame('unknown_existing', $index['status']);
		$this->assertSame('write_new', $index['action']);
		$this->assertSame($this->appRoot . '/public/index.php.new', $index['_apply']['new_path']);
	}

	public function testSyncWithoutStateCreatesMissingManagedFile(): void {
		$this->assertFalse($this->state->exists());

		$plan  = $this->op->build('sync', $this->manifest(), $this->placeholderMap());
		$index = $this->file($plan, self::PKG, 'public/index.php');

		$this->assertSame('missing', $index['status']);
		$this->assertSame('create', $index['action']);
	}


	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	/** @return array<string,mixed> */
	private function manifest(): array {
		return [
			self::PKG => [
				'package'     => self::PKG,
				'version'     => 1,
				'packageRoot' => $this->packageRoot,
				'files'       => [
					['target' => 'public/index.php', 'source' => 'resources/scaffold/index.php.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
					['target' => 'config/routes.php', 'source' => 'resources/scaffold/routes.php.stub', 'type' => 'config', 'policy' => 'create-only'],
				],
			],
		];
	}

	/** @return array<string,array<string,string>> */
	private function placeholderMap(): array {
		return [self::PKG => $this->placeholders];
	}

	private function writeRenderedTarget(string $relTarget, string $stubName): void {
		$rendered = $this->renderer->render($this->readStub($stubName), $this->placeholders);
		\file_put_contents($this->appRoot . '/' . $relTarget, $rendered);
	}

	private function seedManagedState(string $relTarget, string $stubName, ?array $placeholders = null, ?string $stubChecksum = null): void {
		$placeholders ??= $this->placeholders;
		$stub          = $this->readStub($stubName);
		$rendered      = $this->renderer->render($stub, $placeholders);

		$this->state->write([
			self::PKG => [
				'installed_version' => '1.0.0',
				'files' => [
					$relTarget => [
						'source'            => 'resources/scaffold/' . $stubName,
						'type'              => 'entrypoint',
						'policy'            => 'managed',
						'stub_checksum'     => $stubChecksum ?? Checksum::sha256($stub),
						'rendered_checksum' => Checksum::sha256($rendered),
						'placeholders'      => $placeholders,
						'installed_at'      => '2025-01-01T00:00:00+00:00',
					],
				],
			],
		]);
	}

	private function expectedRendered(string $stubName): string {
		return Checksum::sha256($this->renderer->render($this->readStub($stubName), $this->placeholders));
	}

	private function readStub(string $stubName): string {
		return (string)\file_get_contents($this->packageRoot . '/resources/scaffold/' . $stubName);
	}

	/**
	 * Pull a single file entry out of the plan by package + normalized target.
	 *
	 * @param  array<string,mixed> $plan
	 * @return array<string,mixed>
	 */
	private function file(array $plan, string $package, string $target): array {
		foreach ($plan['packages'] as $pkg) {
			if ($pkg['name'] !== $package) {
				continue;
			}
			foreach ($pkg['files'] as $f) {
				if ($f['target'] === $target) {
					return $f;
				}
			}
		}
		$this->fail("File '{$target}' not found in plan for package '{$package}'.");
	}

	private function mkdirp(string $dir): void {
		if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
			throw new \RuntimeException("Cannot create dir: {$dir}");
		}
	}

	private function rrmdir(string $dir): void {
		if (!\is_dir($dir)) {
			return;
		}
		foreach (\scandir($dir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			\is_dir($path) ? $this->rrmdir($path) : @\unlink($path);
		}
		@\rmdir($dir);
	}
}
