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

use CitOmni\Installer\Operation\AuditAppSkeletonMigration;
use CitOmni\Installer\Support\PathGuard;
use PHPUnit\Framework\TestCase;

final class AuditAppSkeletonMigrationTest extends TestCase {

	private string $appRoot;

	protected function setUp(): void {
		$this->appRoot = \sys_get_temp_dir() . '/citomni_skeleton_audit_' . \bin2hex(\random_bytes(6));
		\mkdir($this->appRoot, 0775, true);
	}

	protected function tearDown(): void {
		foreach (\glob($this->appRoot . '/*') ?: [] as $path) {
			@\unlink($path);
		}
		foreach (['.gitignore', '.gitignore.new'] as $name) {
			@\unlink($this->appRoot . '/' . $name);
		}
		@\rmdir($this->appRoot);
	}

	public function testDifferenceWritesReferenceSidecarWithoutTouchingLocalFile(): void {
		\file_put_contents($this->appRoot . '/.gitignore', "local\n");
		$audit = new AuditAppSkeletonMigration(new PathGuard($this->appRoot));

		$result = $audit->apply($this->reference("upstream\n"), false);

		$this->assertSame("local\n", \file_get_contents($this->appRoot . '/.gitignore'));
		$this->assertSame("upstream\n", \file_get_contents($this->appRoot . '/.gitignore.new'));
		$this->assertSame('written', $result['files'][0]['sidecar_status']);
		$this->assertCount(1, $result['manual_review']);
	}


	public function testLineEndingsAndOneFinalNewlineDoNotCreateAdvisoryDifference(): void {
		\file_put_contents($this->appRoot . '/.gitignore', "vendor\r\nvar/cache");
		$audit = new AuditAppSkeletonMigration(new PathGuard($this->appRoot));

		$result = $audit->apply($this->reference("vendor\nvar/cache\n"), false);

		$this->assertSame('identical', $result['files'][0]['status']);
		$this->assertSame('none', $result['files'][0]['action']);
		$this->assertSame('', $result['files'][0]['diff']);
		$this->assertSame([], $result['manual_review']);
		$this->assertFileDoesNotExist($this->appRoot . '/.gitignore.new');
	}

	public function testExistingReferenceSidecarIsOverwrittenWithCurrentReference(): void {
		\file_put_contents($this->appRoot . '/.gitignore', "local\n");
		\file_put_contents($this->appRoot . '/.gitignore.new', "stale snapshot\n");
		$audit = new AuditAppSkeletonMigration(new PathGuard($this->appRoot));

		$result = $audit->apply($this->reference("upstream\n"), false);

		$this->assertSame("upstream\n", \file_get_contents($this->appRoot . '/.gitignore.new'));
		$this->assertSame('written', $result['files'][0]['sidecar_status']);
	}

	/** @return array{sha:string,files:array<string,array{url:string,bytes:string}>} */
	private function reference(string $bytes): array {
		return [
			'sha' => \str_repeat('a', 40),
			'files' => [
				'.gitignore' => [
					'url' => 'https://example.invalid/.gitignore',
					'bytes' => $bytes,
				],
			],
		];
	}
}
