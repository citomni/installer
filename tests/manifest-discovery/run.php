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

namespace CitOmni\Installer\Tests\ManifestDiscovery;

use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Support\ScaffoldManifestLocator;

/*
 * Standalone suite for scaffold manifest discovery: ScaffoldManifestLocator on top of
 * ComposerPackageDiscovery.
 *
 * Every check builds an application with a hand-written vendor/composer/installed.json,
 * so discovery reads installed.json deterministically. The checks cover the
 * convention and extra.citomni.scaffold manifest locations, Composer 1 and 2
 * installed.json shapes, discoverPackage(), manifest validation, and installed.json
 * failures. Each check gets a fresh run directory, which is removed afterwards.
 *
 * Usage:
 *   php tests/manifest-discovery/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Test-local autoloading of this checkout's sources; no Composer.
\spl_autoload_register(static function (string $class): void {
	$prefix = 'CitOmni\\Installer\\';
	if (\str_starts_with($class, $prefix)) {
		require __DIR__ . '/../../src/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
	}
});

/** Prefix of every directory this suite creates; nothing else is ever removed. */
const RUN_DIR_PREFIX = 'citomni_installer_manifest_discovery_';

/** Targets a manifest must never declare. */
const UNSAFE_TARGETS = [
	'absolute'     => '/etc/passwd',
	'backslash'    => '..\\..\\x',
	'drive letter' => 'C:\\evil',
	'traversal'    => '../up',
	'wrapper'      => 'phar://x',
];

/** One valid managed file entry. */
const VALID_FILES = [
	['target' => 'public/index.php', 'source' => 'resources/scaffold/public/index.php.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
];


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


/**
 * One check's application root and the packages written into its vendor directory.
 */
final class App {

	/** @var list<array<string,mixed>> installed.json entries */
	private array $installed = [];

	public function __construct(public readonly string $dir, public readonly string $root) {
		\mkdir($root . '/vendor/composer', 0700, true);
	}


	/**
	 * Add an installed package; options: manifest_at, manifest, stubs, extra, version.
	 *
	 * @param array<string,mixed> $opts
	 */
	public function package(string $name, array $opts = []): self {
		$root = $this->root . '/vendor/' . $name;
		\mkdir($root, 0700, true);

		if (isset($opts['manifest_at'])) {
			$this->put($root . '/' . $opts['manifest_at'], '<?php return ' . \var_export($opts['manifest'] ?? [], true) . ";\n");
		}
		foreach ($opts['stubs'] ?? [] as $stub) {
			$this->put($root . '/' . $stub, "<?php\n");
		}

		$entry = ['name' => $name, 'version' => $opts['version'] ?? '1.0.0', 'install-path' => '../' . $name];
		if (isset($opts['extra'])) {
			$entry['extra'] = $opts['extra'];
		}
		$this->installed[] = $entry;
		return $this;
	}


	/** Write vendor/composer/installed.json, in Composer 2 form or as Composer 1's top-level list. */
	public function writeInstalledJson(bool $composer1 = false): self {
		$payload = $composer1 ? $this->installed : ['packages' => $this->installed];
		\file_put_contents($this->root . '/vendor/composer/installed.json', \json_encode($payload, \JSON_PRETTY_PRINT));
		return $this;
	}


	/** @return array<string,array<string,mixed>> */
	public function discover(): array {
		return ScaffoldManifestLocator::forAppRoot($this->root)->discover();
	}


	private function put(string $path, string $bytes): void {
		if (!\is_dir(\dirname($path))) {
			\mkdir(\dirname($path), 0700, true);
		}
		\file_put_contents($path, $bytes);
	}
}


// ----------------------------------------------------------------
// Assertions and helpers
// ----------------------------------------------------------------

/** Fail the current check with $message unless $condition holds. */
function expect(bool $condition, string $message): void {
	if (!$condition) {
		throw new CheckFailed($message);
	}
}


/** Fail the current check unless $actual is identical to $expected. */
function same(mixed $expected, mixed $actual, string $what): void {
	if ($expected !== $actual) {
		throw new CheckFailed($what . ': expected ' . export($expected) . ', got ' . export($actual));
	}
}


/** One-line rendering of a value for failure messages. */
function export(mixed $value): string {
	return (string)\json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
}


/** Forward slashes only, so paths compare equal on Windows, where realpath() returns backslashes. */
function norm(string $path): string {
	return \str_replace('\\', '/', $path);
}


/** Fail unless $run throws an InstallerException whose message contains $needle. */
function rejectedWith(callable $run, string $needle, string $what): void {
	try {
		$run();
	} catch (InstallerException $e) {
		expect($needle === '' || \str_contains($e->getMessage(), $needle), $what . ': message does not contain "' . $needle . '": ' . $e->getMessage());
		return;
	}
	throw new CheckFailed($what . ' was accepted');
}


/**
 * A manifest array as a package ships it.
 *
 * @param list<array<string,string>> $files
 * @return array<string,mixed>
 */
function manifest(string $package, int $version, array $files): array {
	return ['package' => $package, 'version' => $version, 'files' => $files];
}


/** Create a fresh run directory with an empty application. */
function makeApp(): App {
	$dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . RUN_DIR_PREFIX . \bin2hex(\random_bytes(6));
	\mkdir($dir . '/app', 0700, true);
	return new App((string)\realpath($dir), (string)\realpath($dir . '/app'));
}


/** Remove a directory created by makeApp(). Any other path is left alone. */
function removeRunDir(string $dir): void {
	if (!\str_starts_with(\basename($dir), RUN_DIR_PREFIX) || !\is_dir($dir) || \is_link($dir)) {
		return;
	}

	$items = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($items as $item) {
		if ($item->isDir() && !$item->isLink()) {
			\rmdir($item->getPathname());
		} else {
			\unlink($item->getPathname());
		}
	}
	\rmdir($dir);
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	// -- Discovery --------------------------------------------------

	'a convention manifest is discovered and normalized' => static function (App $app): void {
		$found = $app->package('citomni/http', [
			'manifest_at' => 'install/manifest.php',
			'manifest'    => manifest('citomni/http', 1, VALID_FILES),
			'stubs'       => ['resources/scaffold/public/index.php.stub'],
		])->writeInstalledJson()->discover();

		$http = $found['citomni/http'] ?? null;
		expect(\is_array($http), 'citomni/http was not discovered: ' . export(\array_keys($found)));
		same(['citomni/http', 1, '1.0.0'], [$http['package'], $http['version'], $http['installed_version']], 'package, version, installed_version');
		same(['public/index.php', 'managed'], [$http['files'][0]['target'], $http['files'][0]['policy']], 'target, policy');
		expect(\str_ends_with(norm($http['manifest_path']), '/install/manifest.php'), 'manifest_path: ' . $http['manifest_path']);
		expect(\str_ends_with(norm($http['files'][0]['source_path']), '/resources/scaffold/public/index.php.stub'), 'source_path: ' . $http['files'][0]['source_path']);
	},

	'a manifest declared in extra.citomni.scaffold is discovered' => static function (App $app): void {
		$found = $app->package('citomni/http', [
			'extra'       => ['citomni' => ['scaffold' => 'scaffold/manifest.php']],
			'manifest_at' => 'scaffold/manifest.php',
			'manifest'    => manifest('citomni/http', 1, VALID_FILES),
		])->writeInstalledJson()->discover();

		expect(\str_ends_with(norm($found['citomni/http']['manifest_path'] ?? ''), '/scaffold/manifest.php'), 'manifest_path: ' . export($found['citomni/http']['manifest_path'] ?? null));
	},

	'extra.citomni.scaffold wins over the convention manifest' => static function (App $app): void {
		$app->package('citomni/http', [
			'extra'       => ['citomni' => ['scaffold' => 'scaffold/manifest.php']],
			'manifest_at' => 'scaffold/manifest.php',
			'manifest'    => manifest('citomni/http', 1, [['target' => 'config/extra.php', 'source' => 's/extra.stub', 'type' => 'config', 'policy' => 'create-only']]),
		]);
		// A convention manifest that must be ignored.
		\mkdir($app->root . '/vendor/citomni/http/install');
		\file_put_contents(
			$app->root . '/vendor/citomni/http/install/manifest.php',
			'<?php return ' . \var_export(manifest('citomni/http', 1, [['target' => 'config/conv.php', 'source' => 's/conv.stub', 'type' => 'config', 'policy' => 'create-only']]), true) . ";\n"
		);

		$found = $app->writeInstalledJson()->discover();
		same('config/extra.php', $found['citomni/http']['files'][0]['target'] ?? null, 'discovered target');
	},

	'a package without a manifest is skipped' => static function (App $app): void {
		same([], $app->package('acme/lib')->writeInstalledJson()->discover(), 'discover()');
	},

	'a Composer 1 top-level installed.json list is read' => static function (App $app): void {
		$found = $app->package('citomni/http', [
			'manifest_at' => 'install/manifest.php',
			'manifest'    => manifest('citomni/http', 1, VALID_FILES),
		])->writeInstalledJson(true)->discover();

		same(['citomni/http'], \array_keys($found), 'discovered packages');
	},

	'discoverPackage() returns one manifest, or null for a missing package or manifest' => static function (App $app): void {
		$app->package('citomni/http', [
			'manifest_at' => 'install/manifest.php',
			'manifest'    => manifest('citomni/http', 1, VALID_FILES),
		])->package('acme/lib')->writeInstalledJson();

		$locator = ScaffoldManifestLocator::forAppRoot($app->root);
		same('citomni/http', $locator->discoverPackage('citomni/http')['package'] ?? null, 'discoverPackage(citomni/http)');
		same(null, $locator->discoverPackage('not/installed'), 'discoverPackage(not/installed)');
		same(null, $locator->discoverPackage('acme/lib'), 'discoverPackage(acme/lib)');
	},

	// -- Manifest validation ----------------------------------------

	'a manifest naming another package fails' => static function (App $app): void {
		$app->package('citomni/http', ['manifest_at' => 'install/manifest.php', 'manifest' => manifest('wrong/name', 1, VALID_FILES)])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'mismatch', 'A package name mismatch');
	},

	'an unknown schema version fails' => static function (App $app): void {
		$app->package('citomni/http', ['manifest_at' => 'install/manifest.php', 'manifest' => manifest('citomni/http', 3, VALID_FILES)])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'unknown schema version', 'Schema version 3');
	},

	'a source that leaves the package root fails' => static function (App $app): void {
		$files = [['target' => 'public/index.php', 'source' => '../../../etc/passwd', 'type' => 'entrypoint', 'policy' => 'managed']];
		$app->package('citomni/http', ['manifest_at' => 'install/manifest.php', 'manifest' => manifest('citomni/http', 1, $files)])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'invalid source', 'An escaping source');
	},

	'unsafe targets fail' => static function (App $unused): void {
		$accepted = [];
		foreach (UNSAFE_TARGETS as $label => $target) {
			$app = makeApp();
			try {
				$files = [['target' => $target, 'source' => 'resources/x.stub', 'type' => 'entrypoint', 'policy' => 'managed']];
				$app->package('citomni/http', ['manifest_at' => 'install/manifest.php', 'manifest' => manifest('citomni/http', 1, $files)])->writeInstalledJson();
				try {
					$app->discover();
					$accepted[] = $label;
				} catch (InstallerException) {
				}
			} finally {
				removeRunDir($app->dir);
			}
		}
		same([], $accepted, 'unsafe targets accepted');
	},

	'a duplicate target fails, also after normalization' => static function (App $app): void {
		$files = [
			['target' => 'public/index.php', 'source' => 'a.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
			['target' => 'public/./index.php', 'source' => 'b.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
		];
		$app->package('citomni/http', ['manifest_at' => 'install/manifest.php', 'manifest' => manifest('citomni/http', 1, $files)])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'duplicate target', 'A duplicate target');
	},

	'an unknown policy fails' => static function (App $app): void {
		$files = [['target' => 'public/index.php', 'source' => 'a.stub', 'type' => 'entrypoint', 'policy' => 'frobnicate']];
		$app->package('citomni/http', ['manifest_at' => 'install/manifest.php', 'manifest' => manifest('citomni/http', 1, $files)])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'unknown policy', 'Policy "frobnicate"');
	},

	'a file entry without a source fails' => static function (App $app): void {
		$files = [['target' => 'public/index.php', 'type' => 'entrypoint', 'policy' => 'managed']];
		$app->package('citomni/http', ['manifest_at' => 'install/manifest.php', 'manifest' => manifest('citomni/http', 1, $files)])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'exactly one of "source" or "environments"', 'An entry without a source');
	},

	'a declared extra.citomni.scaffold manifest that is missing fails' => static function (App $app): void {
		$app->package('citomni/http', ['extra' => ['citomni' => ['scaffold' => 'scaffold/missing.php']]])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'Declared scaffold manifest not found', 'A missing declared manifest');
	},

	'an extra.citomni.scaffold path that leaves the package fails' => static function (App $app): void {
		$app->package('citomni/http', ['extra' => ['citomni' => ['scaffold' => '../../outside.php']]])->writeInstalledJson();
		rejectedWith(static fn() => $app->discover(), 'Invalid extra.citomni.scaffold', 'An escaping extra.citomni.scaffold');
	},

	// -- installed.json ---------------------------------------------

	'a missing installed.json fails' => static function (App $app): void {
		rejectedWith(static fn() => $app->discover(), 'installed.json not found', 'A missing installed.json');
	},

	'an installed.json that is not JSON fails' => static function (App $app): void {
		\file_put_contents($app->root . '/vendor/composer/installed.json', '{not json');
		rejectedWith(static fn() => $app->discover(), 'not valid JSON', 'Invalid JSON');
	},

];


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

$passed = 0;
$failed = 0;

foreach ($checks as $name => $check) {
	$app = null;
	try {
		// Setup and cleanup belong to the check: a directory that cannot be created or
		// removed fails it.
		try {
			$app = makeApp();
			$check($app);
		} finally {
			if ($app !== null) {
				removeRunDir($app->dir);
			}
		}
		$passed++;
		\fwrite(\STDOUT, "PASS {$name}\n");
	} catch (CheckFailed $e) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - {$e->getMessage()}\n");
	} catch (\Throwable $e) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - " . $e::class . ': ' . $e->getMessage() . "\n");
	}
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
