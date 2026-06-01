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

namespace CitOmni\Installer\Tests\Support;

use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Exception\InstallerException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PathGuard.
 *
 * The unsafe-path cases (including Windows drive letters, UNC, and backslashes) are
 * pure string rejections in Util\Path, so they assert identically on any host OS.
 * The symlink-escape case is POSIX-specific and is skipped on Windows runners.
 */
final class PathGuardTest extends TestCase {

	private string $appRoot;
	private string $pkgRoot;


	protected function setUp(): void {
		$this->appRoot = $this->makeTempDir('citomni-app-');
		$this->pkgRoot = $this->makeTempDir('citomni-pkg-');

		\mkdir($this->pkgRoot . '/resources/scaffold/public', 0777, true);
		\file_put_contents($this->pkgRoot . '/resources/scaffold/public/index.php.stub', "<?php\n");
	}


	protected function tearDown(): void {
		$this->rrmdir($this->appRoot);
		$this->rrmdir($this->pkgRoot);
	}


	// ----------------------------------------------------------------
	// Construction
	// ----------------------------------------------------------------

	public function testRejectsMissingAppRoot(): void {
		$this->expectException(InstallerException::class);
		new PathGuard($this->appRoot . '/does/not/exist');
	}


	public function testAppRootIsCanonicalized(): void {
		$guard = new PathGuard($this->appRoot);
		$this->assertSame($this->appRoot, $guard->appRoot());
	}


	// ----------------------------------------------------------------
	// Valid resolution
	// ----------------------------------------------------------------

	public function testResolvesValidNonexistentTargetUnderAppRoot(): void {
		$guard = new PathGuard($this->appRoot);
		$this->assertSame(
			$this->appRoot . '/public/index.php',
			$guard->resolveTarget('public/index.php')
		);
	}


	public function testNormalizesRedundantSegments(): void {
		$guard    = new PathGuard($this->appRoot);
		$expected = $guard->resolveTarget('public/index.php');
		$this->assertSame($expected, $guard->resolveTarget('./public//index.php'));
	}


	public function testResolvesExistingSourceUnderPackageRoot(): void {
		$guard = new PathGuard($this->appRoot);
		$this->assertSame(
			\realpath($this->pkgRoot . '/resources/scaffold/public/index.php.stub'),
			$guard->resolveSource($this->pkgRoot, 'resources/scaffold/public/index.php.stub')
		);
	}


	public function testResolvesNonexistentSourceWhenAncestorExists(): void {
		$guard = new PathGuard($this->appRoot);
		$this->assertSame(
			\realpath($this->pkgRoot . '/resources/scaffold/public') . '/missing.stub',
			$guard->resolveSource($this->pkgRoot, 'resources/scaffold/public/missing.stub')
		);
	}


	public function testRejectsMissingPackageRoot(): void {
		$guard = new PathGuard($this->appRoot);
		$this->expectException(InstallerException::class);
		$guard->resolveSource($this->pkgRoot . '/nope', 'resources/x.stub');
	}


	// ----------------------------------------------------------------
	// Unsafe path rejection (Windows + POSIX), target and source
	// ----------------------------------------------------------------

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function unsafePaths(): array {
		return [
			'windows drive letter, backslash' => ['C:\\Windows\\System32\\evil.php'],
			'windows drive letter, forward'   => ['c:/Windows/evil.php'],
			'windows drive letter, bare'      => ['C:evil.php'],
			'unc backslash'                   => ['\\\\server\\share\\evil.php'],
			'unc forward'                     => ['//server/share/evil.php'],
			'backslash relative'              => ['public\\index.php'],
			'backslash traversal'             => ['..\\..\\Windows\\win.ini'],
			'mixed backslash traversal'       => ['public\\..\\..\\secret'],
			'posix traversal leading'         => ['../../etc/passwd'],
			'posix traversal embedded'        => ['public/../../etc/passwd'],
			'posix absolute'                  => ['/etc/passwd'],
			'phar wrapper'                    => ['phar://evil.phar/x'],
			'php wrapper'                     => ['php://filter/resource=x'],
			'empty'                           => [''],
			'dot only'                        => ['.'],
			'dot slash only'                  => ['./'],
		];
	}


	/**
	 * @dataProvider unsafePaths
	 */
	public function testRejectsUnsafeTarget(string $bad): void {
		$guard = new PathGuard($this->appRoot);
		$this->expectException(InstallerException::class);
		$guard->resolveTarget($bad);
	}


	/**
	 * @dataProvider unsafePaths
	 */
	public function testRejectsUnsafeSource(string $bad): void {
		$guard = new PathGuard($this->appRoot);
		$this->expectException(InstallerException::class);
		$guard->resolveSource($this->pkgRoot, $bad);
	}


	// ----------------------------------------------------------------
	// Reason precision (a few stable, OS-independent cases)
	// ----------------------------------------------------------------

	public function testReasonForAbsolutePath(): void {
		$this->assertReasonContains('/etc/passwd', 'absolute');
	}


	public function testReasonForDriveLetter(): void {
		$this->assertReasonContains('c:/evil.php', 'drive-letter');
	}


	public function testReasonForTraversal(): void {
		$this->assertReasonContains('a/../../b', 'traversal');
	}


	public function testReasonForBackslash(): void {
		$this->assertReasonContains('a\\b', 'backslash');
	}


	// ----------------------------------------------------------------
	// Symlink escape (POSIX only)
	// ----------------------------------------------------------------

	public function testRejectsSymlinkedAncestorEscape(): void {
		if (\DIRECTORY_SEPARATOR === '\\') {
			$this->markTestSkipped('Symlink semantics differ on Windows.');
		}

		$outside = $this->makeTempDir('citomni-outside-');
		\file_put_contents($outside . '/secret.php', "<?php\n");
		// 'public' inside app-root is a symlink pointing outside the app-root.
		\symlink($outside, $this->appRoot . '/public');

		$guard = new PathGuard($this->appRoot);

		try {
			$guard->resolveTarget('public/secret.php');
			$this->fail('Expected InstallerException for symlink escape.');
		} catch (InstallerException $e) {
			$this->assertStringContainsString('escapes', $e->getMessage());
		} finally {
			\unlink($this->appRoot . '/public');
			$this->rrmdir($outside);
		}
	}


	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	private function assertReasonContains(string $badPath, string $needle): void {
		$guard = new PathGuard($this->appRoot);

		try {
			$guard->resolveTarget($badPath);
			$this->fail('Expected InstallerException for: ' . $badPath);
		} catch (InstallerException $e) {
			$this->assertStringContainsString($needle, $e->getMessage());
		}
	}


	private function makeTempDir(string $prefix): string {
		$dir = \sys_get_temp_dir() . '/' . \uniqid($prefix, true);
		\mkdir($dir, 0777, true);

		// realpath so comparisons match PathGuard's internal canonicalization
		// (e.g. macOS /tmp -> /private/tmp).
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
