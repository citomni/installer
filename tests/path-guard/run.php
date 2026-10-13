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

namespace CitOmni\Installer\Tests\PathGuard;

use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Support\PathGuard;

/*
 * Standalone suite for \CitOmni\Installer\Support\PathGuard.
 *
 * The checks cover the application root, target and source resolution, the
 * rejection of unsafe paths with a reason, and a symlink that escapes the
 * application root. Every check gets a fresh run directory with an application
 * root and a package root; the directory is removed afterwards.
 *
 * Usage:
 *   php tests/path-guard/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; leave diagnostics silenced with @ to PHP, which the
// symlink probe relies on where symlinks cannot be created.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
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
const RUN_DIR_PREFIX = 'citomni_installer_path_guard_';

/** Paths that must never resolve, as target or as source. */
const UNSAFE_PATHS = [
	'windows drive letter, backslash' => 'C:\\Windows\\System32\\evil.php',
	'windows drive letter, forward'   => 'c:/Windows/evil.php',
	'windows drive letter, bare'      => 'C:evil.php',
	'unc backslash'                   => '\\\\server\\share\\evil.php',
	'unc forward'                     => '//server/share/evil.php',
	'backslash relative'              => 'public\\index.php',
	'backslash traversal'             => '..\\..\\Windows\\win.ini',
	'mixed backslash traversal'       => 'public\\..\\..\\secret',
	'posix traversal leading'         => '../../etc/passwd',
	'posix traversal embedded'        => 'public/../../etc/passwd',
	'posix absolute'                  => '/etc/passwd',
	'phar wrapper'                    => 'phar://evil.phar/x',
	'php wrapper'                     => 'php://filter/resource=x',
	'empty'                           => '',
	'dot only'                        => '.',
	'dot slash only'                  => './',
];


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


/**
 * A check this platform cannot perform, reported as SKIP with its reason.
 */
final class CheckSkipped extends \RuntimeException {
}


/**
 * The fixture of one check: a run directory with an application and a package root.
 */
final class Fixture {

	public function __construct(public readonly string $dir, public readonly string $appRoot, public readonly string $packageRoot) {}
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


/** End the current check as skipped. */
function skip(string $reason): never {
	throw new CheckSkipped($reason);
}


/** Return the InstallerException $run throws; fail the check when it throws none. */
function rejected(callable $run, string $what): InstallerException {
	try {
		$run();
	} catch (InstallerException $e) {
		return $e;
	}
	throw new CheckFailed($what . ' was accepted');
}


/** Create a fresh run directory holding an application root and a package root with one stub. */
function makeFixture(): Fixture {
	$dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . RUN_DIR_PREFIX . \bin2hex(\random_bytes(6));
	\mkdir($dir . '/app', 0700, true);
	\mkdir($dir . '/package/resources/scaffold/public', 0700, true);
	\file_put_contents($dir . '/package/resources/scaffold/public/index.php.stub', "<?php\n");

	// Canonical roots, as PathGuard composes its results from them: macOS /tmp is
	// /private/tmp, and on Windows realpath() returns backslashes.
	return new Fixture((string)\realpath($dir), (string)\realpath($dir . '/app'), (string)\realpath($dir . '/package'));
}


/** Remove a directory created by makeFixture(). Any other path is left alone. */
function removeRunDir(string $dir): void {
	if (!\str_starts_with(\basename($dir), RUN_DIR_PREFIX) || !\is_dir($dir) || \is_link($dir)) {
		return;
	}

	$items = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($items as $item) {
		if ($item->isLink()) {
			// Windows removes a link to a directory with rmdir(), any other link with unlink().
			if (!@\rmdir($item->getPathname())) {
				\unlink($item->getPathname());
			}
		} elseif ($item->isDir()) {
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

	'a missing application root is rejected' => static function (Fixture $f): void {
		rejected(static fn() => new PathGuard($f->appRoot . '/does/not/exist'), 'A missing application root');
	},

	'the application root is canonicalized' => static function (Fixture $f): void {
		same($f->appRoot, (new PathGuard($f->appRoot . '/./'))->appRoot(), 'appRoot()');
	},

	'a valid target that does not exist yet resolves below the application root' => static function (Fixture $f): void {
		same($f->appRoot . '/public/index.php', (new PathGuard($f->appRoot))->resolveTarget('public/index.php'), 'resolveTarget()');
	},

	'redundant segments are normalized' => static function (Fixture $f): void {
		$guard = new PathGuard($f->appRoot);
		same($guard->resolveTarget('public/index.php'), $guard->resolveTarget('./public//index.php'), 'resolveTarget() of ./public//index.php');
	},

	'an existing source resolves below the package root' => static function (Fixture $f): void {
		same(
			\realpath($f->packageRoot . '/resources/scaffold/public/index.php.stub'),
			(new PathGuard($f->appRoot))->resolveSource($f->packageRoot, 'resources/scaffold/public/index.php.stub'),
			'resolveSource()'
		);
	},

	'a missing source resolves when an ancestor exists' => static function (Fixture $f): void {
		same(
			\realpath($f->packageRoot . '/resources/scaffold/public') . '/missing.stub',
			(new PathGuard($f->appRoot))->resolveSource($f->packageRoot, 'resources/scaffold/public/missing.stub'),
			'resolveSource()'
		);
	},

	'a missing package root is rejected' => static function (Fixture $f): void {
		$guard = new PathGuard($f->appRoot);
		rejected(static fn() => $guard->resolveSource($f->packageRoot . '/nope', 'resources/x.stub'), 'A missing package root');
	},

	'unsafe targets are rejected' => static function (Fixture $f): void {
		$guard = new PathGuard($f->appRoot);
		$accepted = [];
		foreach (UNSAFE_PATHS as $label => $path) {
			try {
				$guard->resolveTarget($path);
				$accepted[] = $label;
			} catch (InstallerException) {
			}
		}
		same([], $accepted, 'unsafe targets accepted');
	},

	'unsafe sources are rejected' => static function (Fixture $f): void {
		$guard = new PathGuard($f->appRoot);
		$accepted = [];
		foreach (UNSAFE_PATHS as $label => $path) {
			try {
				$guard->resolveSource($f->packageRoot, $path);
				$accepted[] = $label;
			} catch (InstallerException) {
			}
		}
		same([], $accepted, 'unsafe sources accepted');
	},

	'an absolute path is rejected as absolute' => static function (Fixture $f): void {
		$e = rejected(static fn() => (new PathGuard($f->appRoot))->resolveTarget('/etc/passwd'), '/etc/passwd');
		expect(\str_contains($e->getMessage(), 'absolute'), 'Reason does not say "absolute": ' . $e->getMessage());
	},

	'a drive letter is rejected as a drive letter' => static function (Fixture $f): void {
		$e = rejected(static fn() => (new PathGuard($f->appRoot))->resolveTarget('c:/evil.php'), 'c:/evil.php');
		expect(\str_contains($e->getMessage(), 'drive-letter'), 'Reason does not say "drive-letter": ' . $e->getMessage());
	},

	'traversal is rejected as traversal' => static function (Fixture $f): void {
		$e = rejected(static fn() => (new PathGuard($f->appRoot))->resolveTarget('a/../../b'), 'a/../../b');
		expect(\str_contains($e->getMessage(), 'traversal'), 'Reason does not say "traversal": ' . $e->getMessage());
	},

	'a backslash is rejected as a backslash' => static function (Fixture $f): void {
		$e = rejected(static fn() => (new PathGuard($f->appRoot))->resolveTarget('a\\b'), 'a\\b');
		expect(\str_contains($e->getMessage(), 'backslash'), 'Reason does not say "backslash": ' . $e->getMessage());
	},

	'a symlinked ancestor that leaves the application root is rejected' => static function (Fixture $f): void {
		\mkdir($f->dir . '/outside');
		\file_put_contents($f->dir . '/outside/secret.php', "<?php\n");
		if (!@\symlink($f->dir . '/outside', $f->appRoot . '/public')) {
			skip('symlink creation is unavailable');
		}

		$e = rejected(static fn() => (new PathGuard($f->appRoot))->resolveTarget('public/secret.php'), 'A target behind an escaping symlink');
		expect(\str_contains($e->getMessage(), 'escapes'), 'Reason does not say "escapes": ' . $e->getMessage());
	},

];


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

$passed  = 0;
$failed  = 0;
$skipped = 0;

foreach ($checks as $name => $check) {
	$fixture = null;
	try {
		// Setup and cleanup belong to the check: a directory that cannot be created or
		// removed fails it.
		try {
			$fixture = makeFixture();
			$check($fixture);
		} finally {
			if ($fixture !== null) {
				removeRunDir($fixture->dir);
			}
		}
		$passed++;
		\fwrite(\STDOUT, "PASS {$name}\n");
	} catch (CheckSkipped $e) {
		$skipped++;
		\fwrite(\STDOUT, "SKIP {$name}: {$e->getMessage()}\n");
	} catch (CheckFailed $e) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - {$e->getMessage()}\n");
	} catch (\Throwable $e) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - " . $e::class . ': ' . $e->getMessage() . "\n");
	}
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
exit($failed === 0 ? 0 : 1);
