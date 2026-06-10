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

use CitOmni\Installer\Support\ComposerPackageLocator;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Exception\InstallerException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ComposerPackageLocator.
 *
 * Each test builds a throwaway app/vendor tree with a hand-written installed.json so the
 * installed.json discovery branch is exercised deterministically. Fixture packages are not
 * part of the test runner's own Composer install, so Composer\InstalledVersions reports them
 * as not installed and the installed.json fallback is used (matching production for unknown
 * packages).
 */
final class ComposerPackageLocatorTest extends TestCase {

	private string $appRoot;
	private string $vendorDir;


	protected function setUp(): void {
		$this->appRoot   = $this->makeTempDir('citomni-loc-');
		$this->vendorDir = $this->appRoot . '/vendor';
		\mkdir($this->vendorDir . '/composer', 0777, true);
	}


	protected function tearDown(): void {
		$this->rrmdir($this->appRoot);
	}


	// ----------------------------------------------------------------
	// Fixture helpers
	// ----------------------------------------------------------------

	private function locator(): ComposerPackageLocator {
		return new ComposerPackageLocator($this->vendorDir, new PathGuard($this->appRoot));
	}


	/**
	 * @param array{manifest_at?:string,manifest?:array<string,mixed>,stubs?:list<string>,extra?:array<string,mixed>,version?:string} $opts
	 * @return array<string,mixed>  An installed.json entry for the package.
	 */
	private function package(string $name, array $opts = []): array {
		$root = $this->vendorDir . '/' . $name;
		\mkdir($root, 0777, true);

		if (isset($opts['manifest_at'])) {
			$path = $root . '/' . $opts['manifest_at'];
			@\mkdir(\dirname($path), 0777, true);
			\file_put_contents($path, '<?php return ' . \var_export($opts['manifest'] ?? [], true) . ";\n");
		}

		foreach ($opts['stubs'] ?? [] as $rel) {
			$path = $root . '/' . $rel;
			@\mkdir(\dirname($path), 0777, true);
			\file_put_contents($path, "<?php\n");
		}

		$entry = [
			'name'         => $name,
			'version'      => $opts['version'] ?? '1.0.0',
			'install-path' => '../' . $name, // relative to vendor/composer/
		];

		if (isset($opts['extra'])) {
			$entry['extra'] = $opts['extra'];
		}

		return $entry;
	}


	/**
	 * @param list<array<string,mixed>> $entries
	 */
	private function writeInstalledJson(array $entries, bool $composer1Format = false): void {
		$payload = $composer1Format ? \array_values($entries) : ['packages' => \array_values($entries)];
		\file_put_contents($this->vendorDir . '/composer/installed.json', \json_encode($payload, \JSON_PRETTY_PRINT));
	}


	/**
	 * @param list<array<string,string>> $files
	 * @return array<string,mixed>
	 */
	private function manifest(string $package, int $version, array $files): array {
		return ['package' => $package, 'version' => $version, 'files' => $files];
	}


	private function validFiles(): array {
		return [
			['target' => 'public/index.php', 'source' => 'resources/scaffold/public/index.php.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
		];
	}


	// ----------------------------------------------------------------
	// Discovery
	// ----------------------------------------------------------------

	public function testDiscoversConventionManifest(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $this->validFiles()),
				'stubs'       => ['resources/scaffold/public/index.php.stub'],
			]),
		]);

		$result = $this->locator()->discover();

		$this->assertArrayHasKey('citomni/http', $result);
		$this->assertSame('citomni/http', $result['citomni/http']['package']);
		$this->assertSame(1, $result['citomni/http']['version']);
		$this->assertSame('1.0.0', $result['citomni/http']['installed_version']);
		$this->assertSame('public/index.php', $result['citomni/http']['files'][0]['target']);
		$this->assertSame('managed', $result['citomni/http']['files'][0]['policy']);
		$this->assertStringEndsWith('/install/manifest.php', $result['citomni/http']['manifest_path']);
		$this->assertStringEndsWith('/resources/scaffold/public/index.php.stub', $result['citomni/http']['files'][0]['source_path']);
	}


	public function testDiscoversExtraManifest(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'extra'       => ['citomni' => ['scaffold' => 'scaffold/manifest.php']],
				'manifest_at' => 'scaffold/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $this->validFiles()),
			]),
		]);

		$result = $this->locator()->discover();

		$this->assertStringEndsWith('/scaffold/manifest.php', $result['citomni/http']['manifest_path']);
	}


	public function testExtraWinsOverConvention(): void {
		$extra = $this->manifest('citomni/http', 1, [
			['target' => 'config/extra.php', 'source' => 's/extra.stub', 'type' => 'config', 'policy' => 'create-only'],
		]);
		$entry = $this->package('citomni/http', [
			'extra'       => ['citomni' => ['scaffold' => 'scaffold/manifest.php']],
			'manifest_at' => 'scaffold/manifest.php',
			'manifest'    => $extra,
		]);

		// Also drop a convention manifest that should be ignored.
		$conv = $this->manifest('citomni/http', 1, [
			['target' => 'config/conv.php', 'source' => 's/conv.stub', 'type' => 'config', 'policy' => 'create-only'],
		]);
		$convPath = $this->vendorDir . '/citomni/http/install/manifest.php';
		@\mkdir(\dirname($convPath), 0777, true);
		\file_put_contents($convPath, '<?php return ' . \var_export($conv, true) . ";\n");

		$this->writeInstalledJson([$entry]);

		$result = $this->locator()->discover();
		$this->assertSame('config/extra.php', $result['citomni/http']['files'][0]['target']);
	}


	public function testSkipsPackageWithoutManifest(): void {
		$this->writeInstalledJson([$this->package('acme/lib')]);
		$this->assertSame([], $this->locator()->discover());
	}


	public function testComposer1TopLevelListIsParsed(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $this->validFiles()),
			]),
		], true);

		$this->assertArrayHasKey('citomni/http', $this->locator()->discover());
	}


	// ----------------------------------------------------------------
	// discoverPackage
	// ----------------------------------------------------------------

	public function testDiscoverPackage(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $this->validFiles()),
			]),
			$this->package('acme/lib'),
		]);

		$locator = $this->locator();
		$this->assertSame('citomni/http', $locator->discoverPackage('citomni/http')['package']);
		$this->assertNull($locator->discoverPackage('not/installed'));
		$this->assertNull($locator->discoverPackage('acme/lib'));
	}


	// ----------------------------------------------------------------
	// Manifest validation failures
	// ----------------------------------------------------------------

	public function testPackageNameMismatchFails(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('wrong/name', 1, $this->validFiles()),
			]),
		]);

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/mismatch/');
		$this->locator()->discover();
	}


	public function testUnknownSchemaVersionFails(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 2, $this->validFiles()),
			]),
		]);

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/unknown schema version/');
		$this->locator()->discover();
	}


	public function testSourceEscapingPackageRootFails(): void {
		$files = [['target' => 'public/index.php', 'source' => '../../../etc/passwd', 'type' => 'entrypoint', 'policy' => 'managed']];
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $files),
			]),
		]);

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/invalid source/');
		$this->locator()->discover();
	}


	/**
	 * @return array<string,array{0:string}>
	 */
	public static function unsafeTargets(): array {
		return [
			'absolute'     => ['/etc/passwd'],
			'backslash'    => ['..\\..\\x'],
			'drive letter' => ['C:\\evil'],
			'traversal'    => ['../up'],
			'wrapper'      => ['phar://x'],
		];
	}


	/**
	 * @dataProvider unsafeTargets
	 */
	public function testUnsafeTargetFails(string $badTarget): void {
		$files = [['target' => $badTarget, 'source' => 'resources/x.stub', 'type' => 'entrypoint', 'policy' => 'managed']];
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $files),
			]),
		]);

		$this->expectException(InstallerException::class);
		$this->locator()->discover();
	}


	public function testDuplicateTargetFails(): void {
		$files = [
			['target' => 'public/index.php', 'source' => 'a.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
			['target' => 'public/./index.php', 'source' => 'b.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
		];
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $files),
			]),
		]);

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/duplicate target/');
		$this->locator()->discover();
	}


	public function testUnknownPolicyFails(): void {
		$files = [['target' => 'public/index.php', 'source' => 'a.stub', 'type' => 'entrypoint', 'policy' => 'frobnicate']];
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $files),
			]),
		]);

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/unknown policy/');
		$this->locator()->discover();
	}


	public function testMissingSourceKeyFails(): void {
		$files = [['target' => 'public/index.php', 'type' => 'entrypoint', 'policy' => 'managed']];
		$this->writeInstalledJson([
			$this->package('citomni/http', [
				'manifest_at' => 'install/manifest.php',
				'manifest'    => $this->manifest('citomni/http', 1, $files),
			]),
		]);

		$this->expectException(InstallerException::class);
		$this->locator()->discover();
	}


	public function testExtraDeclaredButMissingFileFails(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', ['extra' => ['citomni' => ['scaffold' => 'scaffold/missing.php']]]),
		]);

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/Declared scaffold manifest not found/');
		$this->locator()->discover();
	}


	public function testExtraUnsafePathFails(): void {
		$this->writeInstalledJson([
			$this->package('citomni/http', ['extra' => ['citomni' => ['scaffold' => '../../outside.php']]]),
		]);

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/Invalid extra\.citomni\.scaffold/');
		$this->locator()->discover();
	}


	// ----------------------------------------------------------------
	// installed.json failures
	// ----------------------------------------------------------------

	public function testMissingInstalledJsonThrows(): void {
		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/installed\.json not found/');
		$this->locator()->discover();
	}


	public function testInvalidJsonThrows(): void {
		\file_put_contents($this->vendorDir . '/composer/installed.json', '{not json');

		$this->expectException(InstallerException::class);
		$this->expectExceptionMessageMatches('/not valid JSON/');
		$this->locator()->discover();
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
