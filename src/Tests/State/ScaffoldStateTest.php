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

namespace CitOmni\Installer\Tests\State;

use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Exception\InstallerException;
use PHPUnit\Framework\TestCase;

final class ScaffoldStateTest extends TestCase {

	private string $root;
	private string $statePath;


	protected function setUp(): void {
		$this->root      = $this->makeTempDir('citomni-state-');
		$this->statePath = $this->root . '/' . ScaffoldState::RELATIVE_PATH;
	}


	protected function tearDown(): void {
		$this->rrmdir($this->root);
	}


	private function samplePackages(): array {
		return [
			'citomni/http' => [
				'installed_version' => '1.2.0',
				'files' => [
					'public/index.php' => [
						'source'            => 'resources/scaffold/public/index.php.stub',
						'type'              => 'entrypoint',
						'policy'            => 'managed',
						'stub_checksum'     => 'sha256:' . \str_repeat('a', 64),
						'rendered_checksum' => 'sha256:' . \str_repeat('b', 64),
						'placeholders'      => ['APP_NAMESPACE' => 'App'],
						'installed_at'      => '2026-06-02T10:00:00+00:00',
					],
				],
			],
		];
	}


	private function writeRawState(string $contents): string {
		\mkdir(\dirname($this->statePath), 0775, true);
		\file_put_contents($this->statePath, $contents);

		return $contents;
	}


	// ----------------------------------------------------------------
	// Basic read / write
	// ----------------------------------------------------------------

	public function testReadReturnsNullWhenAbsent(): void {
		$state = new ScaffoldState($this->statePath);
		$this->assertNull($state->read());
		$this->assertSame([], $state->readPackages());
		$this->assertFalse($state->exists());
	}


	public function testWriteCreatesNestedDirsAndFile(): void {
		$state = new ScaffoldState($this->statePath);
		$state->write($this->samplePackages());

		$this->assertFileExists($this->statePath);
		$this->assertTrue($state->exists());
	}


	public function testWriteStampsEnvelope(): void {
		$state = new ScaffoldState($this->statePath);
		$state->write($this->samplePackages());

		$full = $state->read();
		$this->assertIsArray($full);
		$this->assertSame(ScaffoldState::FORMAT_VERSION, $full['format_version']);
		$this->assertSame(ScaffoldState::GENERATOR, $full['generated_by']);
		$this->assertNotFalse(\strtotime($full['generated_at']), 'generated_at must be a parseable timestamp');
	}


	public function testRoundTripPackages(): void {
		$state    = new ScaffoldState($this->statePath);
		$packages = $this->samplePackages();
		$state->write($packages);

		$this->assertSame($packages, $state->readPackages());
	}


	public function testForAppRootBuildsCanonicalPath(): void {
		$state = ScaffoldState::forAppRoot('/var/www/app/');
		$this->assertSame('/var/www/app/' . ScaffoldState::RELATIVE_PATH, $state->path());
	}


	public function testWriteOverwritesExistingValidState(): void {
		$state = new ScaffoldState($this->statePath);
		$state->write(['a/b' => ['installed_version' => '1.0.0', 'files' => []]]);
		$state->write(['c/d' => ['installed_version' => '2.0.0', 'files' => []]]);

		$packages = $state->readPackages();
		$this->assertArrayNotHasKey('a/b', $packages);
		$this->assertSame('2.0.0', $packages['c/d']['installed_version']);
	}


	// ----------------------------------------------------------------
	// Atomicity
	// ----------------------------------------------------------------

	public function testNoTempFilesLeftBehind(): void {
		$state = new ScaffoldState($this->statePath);
		$state->write($this->samplePackages());

		$leftovers = \glob(\dirname($this->statePath) . '/.installer-scaffold.*.tmp');
		$this->assertSame([], $leftovers);
	}


	// ----------------------------------------------------------------
	// Unknown / unsafe format_version must block writes
	// ----------------------------------------------------------------

	public function testNewerVersionBlocksWriteAndLeavesFileUntouched(): void {
		$before = $this->writeRawState("<?php\nreturn ['format_version' => 2, 'packages' => []];\n");
		$state  = new ScaffoldState($this->statePath);

		try {
			$state->write($this->samplePackages());
			$this->fail('Expected InstallerException for newer format_version.');
		} catch (InstallerException $e) {
			$this->assertStringContainsString('newer', $e->getMessage());
		}

		$this->assertSame($before, \file_get_contents($this->statePath), 'Blocked write must not alter the file.');
	}


	public function testNewerVersionBlocksRead(): void {
		$this->writeRawState("<?php\nreturn ['format_version' => 99, 'packages' => []];\n");
		$state = new ScaffoldState($this->statePath);

		$this->expectException(InstallerException::class);
		$state->read();
	}


	public function testZeroVersionBlocksWrite(): void {
		$this->writeRawState("<?php\nreturn ['format_version' => 0, 'packages' => []];\n");
		$state = new ScaffoldState($this->statePath);

		$this->expectException(InstallerException::class);
		$state->write($this->samplePackages());
	}


	public function testNonIntegerVersionBlocksWrite(): void {
		$this->writeRawState("<?php\nreturn ['format_version' => '1', 'packages' => []];\n");
		$state = new ScaffoldState($this->statePath);

		$this->expectException(InstallerException::class);
		$state->write($this->samplePackages());
	}


	public function testMissingVersionBlocksWrite(): void {
		$this->writeRawState("<?php\nreturn ['packages' => []];\n");
		$state = new ScaffoldState($this->statePath);

		$this->expectException(InstallerException::class);
		$state->write($this->samplePackages());
	}


	// ----------------------------------------------------------------
	// Corrupt / malformed files must block writes
	// ----------------------------------------------------------------

	public function testParseErrorBlocksWriteAndLeavesFileUntouched(): void {
		$before = $this->writeRawState("<?php\nreturn [ ;\n"); // syntax error
		$state  = new ScaffoldState($this->statePath);

		try {
			$state->write($this->samplePackages());
			$this->fail('Expected InstallerException for parse error.');
		} catch (InstallerException $e) {
			$this->assertStringContainsString('could not be read safely', $e->getMessage());
		}

		$this->assertSame($before, \file_get_contents($this->statePath));
	}


	public function testNonArrayReturnBlocksWrite(): void {
		$this->writeRawState("<?php\nreturn 5;\n");
		$state = new ScaffoldState($this->statePath);

		$this->expectException(InstallerException::class);
		$state->write($this->samplePackages());
	}


	public function testMissingPackagesBlocksRead(): void {
		$this->writeRawState("<?php\nreturn ['format_version' => 1];\n");
		$state = new ScaffoldState($this->statePath);

		$this->expectException(InstallerException::class);
		$state->read();
	}


	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	private function makeTempDir(string $prefix): string {
		$dir = \sys_get_temp_dir() . '/' . \uniqid($prefix, true);
		\mkdir($dir, 0777, true);

		return \realpath($dir);
	}


	private function rrmdir(string $dir): void {
		if (!\is_dir($dir)) {
			return;
		}

		foreach (\scandir($dir) as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $dir . '/' . $item;

			if (\is_link($path) || !\is_dir($path)) {
				\unlink($path);
				continue;
			}

			$this->rrmdir($path);
		}

		\rmdir($dir);
	}

}
