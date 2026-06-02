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
use CitOmni\Installer\Util\Checksum;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for the only write layer in the installer.
 *
 * The tests drive real BuildScaffoldPlan plans through ApplyScaffoldPlan against a
 * throwaway app-root and package-root on disk, asserting the filesystem and state-file
 * side effects. The focus is the two delicate write paths: `.new` sidecars on local
 * modification, and mandatory backups on any forced overwrite.
 */
final class ApplyScaffoldPlanTest extends TestCase {

	private string $app;
	private string $pkg;

	private BuildScaffoldPlan $build;
	private ApplyScaffoldPlan $apply;
	private ScaffoldState $state;

	private const RENDERED_INDEX = "<?php\n// app: Demo ns: App\n";


	// ----------------------------------------------------------------
	// Fixture
	// ----------------------------------------------------------------

	protected function setUp(): void {
		$base      = \sys_get_temp_dir() . '/citomni-installer-test-' . \bin2hex(\random_bytes(6));
		$this->app = $base . '/app';
		$this->pkg = $base . '/vendor/citomni/http';

		\mkdir($this->app, 0777, true);
		\mkdir($this->pkg . '/resources/scaffold/public', 0777, true);
		\mkdir($this->pkg . '/resources/scaffold/config', 0777, true);

		// Stubs ship LF; the writer must not alter line endings.
		\file_put_contents($this->pkg . '/resources/scaffold/public/index.php.stub', "<?php\n// app: {{APP_NAME}} ns: {{APP_NAMESPACE}}\n");
		\file_put_contents($this->pkg . '/resources/scaffold/config/routes.php.stub', "<?php\nreturn []; // {{APP_NAME}}\n");

		$guard       = new PathGuard($this->app);
		$renderer    = new ScaffoldRenderer();
		$this->state = ScaffoldState::forAppRoot($this->app);
		$this->build = new BuildScaffoldPlan($guard, $renderer, $this->state);
		$this->apply = new ApplyScaffoldPlan($guard, $renderer, $this->state);
	}

	protected function tearDown(): void {
		$root = \dirname($this->app); // the per-test $base dir
		$this->rmrf($root);
	}

	private function manifests(): array {
		return ['citomni/http' => [
			'root'              => $this->pkg,
			'installed_version' => '1.2.0',
			'files'             => [
				['target' => 'public/index.php', 'source' => 'resources/scaffold/public/index.php.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
				['target' => 'config/routes.php', 'source' => 'resources/scaffold/config/routes.php.stub', 'type' => 'routes', 'policy' => 'create-only'],
			],
		]];
	}

	private function placeholders(): array {
		return ['citomni/http' => ['APP_NAME' => 'Demo', 'APP_NAMESPACE' => 'App']];
	}

	/** Pull a single file result out of a one-package apply/plan result by target. */
	private function fileResult(array $result, string $target): array {
		foreach ($result['packages'][0]['files'] as $file) {
			if (($file['target'] ?? null) === $target) {
				return $file;
			}
		}
		self::fail("No file result for target '{$target}'.");
	}

	private function rmrf(string $path): void {
		if (\is_file($path) || \is_link($path)) { @\unlink($path); return; }
		if (!\is_dir($path)) { return; }
		foreach (\scandir($path) ?: [] as $e) {
			if ($e === '.' || $e === '..') { continue; }
			$this->rmrf($path . '/' . $e);
		}
		@\rmdir($path);
	}


	// ----------------------------------------------------------------
	// install / create
	// ----------------------------------------------------------------

	public function testInstallCreatesFilesAndSeedsState(): void {
		$result = $this->apply->apply($this->build->build('install', $this->manifests(), $this->placeholders()));

		self::assertTrue($result['ok']);
		self::assertFileExists($this->app . '/public/index.php');
		self::assertFileExists($this->app . '/config/routes.php');
		self::assertSame(self::RENDERED_INDEX, \file_get_contents($this->app . '/public/index.php'));
		self::assertTrue($this->state->exists());

		$state = $this->state->read();
		$index = $state['packages']['citomni/http']['files']['public/index.php'];
		$routes = $state['packages']['citomni/http']['files']['config/routes.php'];

		// managed stores both checksums; create-only stores stub only (diagnostic), no rendered.
		self::assertArrayHasKey('rendered_checksum', $index);
		self::assertArrayHasKey('stub_checksum', $index);
		self::assertArrayNotHasKey('rendered_checksum', $routes);
		self::assertArrayHasKey('stub_checksum', $routes);
		self::assertSame('1.2.0', $state['packages']['citomni/http']['installed_version']);
		self::assertTrue(Checksum::matches(self::RENDERED_INDEX, $index['rendered_checksum']));
	}


	// ----------------------------------------------------------------
	// write_new (.new sidecar)
	// ----------------------------------------------------------------

	public function testSyncOnLocallyModifiedFileWritesNewSidecarAndLeavesStateUntouched(): void {
		$this->apply->apply($this->build->build('install', $this->manifests(), $this->placeholders()));

		// Simulate a local edit; the recorded rendered_checksum will no longer match disk.
		$modified = self::RENDERED_INDEX . "// LOCAL EDIT\n";
		\file_put_contents($this->app . '/public/index.php', $modified);

		$stateBefore = \file_get_contents($this->state->path());

		$result = $this->apply->apply($this->build->build('sync', $this->manifests(), $this->placeholders()));
		$file   = $this->fileResult($result, 'public/index.php');

		self::assertSame('local_modified', $file['status']);
		self::assertSame('write_new', $file['action']);
		self::assertSame('wrote_new', $file['applied']);

		// Sidecar written with the fresh render; the live file keeps the local edit.
		self::assertFileExists($this->app . '/public/index.php.new');
		self::assertSame(self::RENDERED_INDEX, \file_get_contents($this->app . '/public/index.php.new'));
		self::assertSame($modified, \file_get_contents($this->app . '/public/index.php'));

		// write_new must not move the recorded baseline forward.
		self::assertSame($stateBefore, \file_get_contents($this->state->path()));
		self::assertSame($this->app . '/public/index.php.new', $file['new_path']);
	}


	// ----------------------------------------------------------------
	// backup on forced overwrite
	// ----------------------------------------------------------------

	public function testForcedOverwriteOfModifiedFileBacksUpThenWritesAndUpdatesState(): void {
		$this->apply->apply($this->build->build('install', $this->manifests(), $this->placeholders()));

		$modified = self::RENDERED_INDEX . "// LOCAL EDIT\n";
		\file_put_contents($this->app . '/public/index.php', $modified);

		$result = $this->apply->apply($this->build->build('sync', $this->manifests(), $this->placeholders(), ['force' => true]));
		$file   = $this->fileResult($result, 'public/index.php');

		self::assertSame('updated', $file['applied']);
		self::assertArrayHasKey('backup_path', $file);

		// Backup must capture the pre-overwrite (modified) bytes, under var/backups/citomni-installer.
		self::assertFileExists($file['backup_path']);
		self::assertStringContainsString('/var/backups/citomni-installer/', $file['backup_path']);
		self::assertSame($modified, \file_get_contents($file['backup_path']));
		self::assertNotNull($result['backup_dir']);

		// Live file is now the fresh render; state baseline matches it.
		self::assertSame(self::RENDERED_INDEX, \file_get_contents($this->app . '/public/index.php'));
		$state = $this->state->read();
		self::assertTrue(Checksum::matches(
			\file_get_contents($this->app . '/public/index.php'),
			$state['packages']['citomni/http']['files']['public/index.php']['rendered_checksum']
		));
	}

	public function testAnyForcedOverwriteBacksUpEvenACleanFile(): void {
		$this->apply->apply($this->build->build('install', $this->manifests(), $this->placeholders()));
		$clean = \file_get_contents($this->app . '/public/index.php');

		$result = $this->apply->apply($this->build->build('install', $this->manifests(), $this->placeholders(), ['force' => true]));
		$file   = $this->fileResult($result, 'public/index.php');

		self::assertArrayHasKey('backup_path', $file);
		self::assertFileExists($file['backup_path']);
		self::assertSame($clean, \file_get_contents($file['backup_path']));
	}


	// ----------------------------------------------------------------
	// dry-run & safety
	// ----------------------------------------------------------------

	public function testDryRunWritesNothing(): void {
		$result = $this->apply->apply($this->build->build('install', $this->manifests(), $this->placeholders()), ['dry_run' => true]);

		self::assertTrue($result['dry_run']);
		self::assertFalse($result['state_written']);
		self::assertFileDoesNotExist($this->app . '/public/index.php');
		self::assertFileDoesNotExist($this->app . '/config/routes.php');
		self::assertFalse($this->state->exists());

		// The plan outcome is still reported (created), it just is not materialized.
		self::assertSame('created', $this->fileResult($result, 'public/index.php')['applied']);
	}

	public function testStalePlanIsFailedAndDoesNotWrite(): void {
		$this->apply->apply($this->build->build('install', $this->manifests(), $this->placeholders()));
		\file_put_contents($this->app . '/public/index.php', "tampered\n");

		// Build the forced plan, THEN change the stub on disk so the plan is stale.
		$plan = $this->build->build('sync', $this->manifests(), $this->placeholders(), ['force' => true]);
		\file_put_contents($this->pkg . '/resources/scaffold/public/index.php.stub', "<?php\n// CHANGED {{APP_NAME}}\n");

		$result = $this->apply->apply($plan);
		$file   = $this->fileResult($result, 'public/index.php');

		self::assertSame('failed', $file['applied']);
		self::assertStringContainsString('stale', $file['error']);
		self::assertFalse($result['ok']);
		self::assertSame("tampered\n", \file_get_contents($this->app . '/public/index.php'));
	}
}
