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

namespace CitOmni\Installer\Tests\ScaffoldState;

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\State\ScaffoldState;

/*
 * Standalone suite for \CitOmni\Installer\State\ScaffoldState.
 *
 * The checks cover reads without state, the format 2 envelope, round trips, the
 * rule that only writePackagesAndEnvironment() creates state, atomic writes, and
 * that unknown, malformed, or legacy state blocks reads and writes without being
 * touched. Every check gets a fresh application root in its own run directory,
 * which is removed afterwards.
 *
 * Usage:
 *   php tests/scaffold-state/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; leave diagnostics silenced with @ to PHP, which
// ScaffoldState relies on for opcache_invalidate() and AtomicFileWriter for cleanup.
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
const RUN_DIR_PREFIX = 'citomni_installer_scaffold_state_';

/** A recorded package baseline as install writes it. */
const SAMPLE_PACKAGES = [
	'citomni/http' => [
		'installed_version' => '1.2.0',
		'files' => [
			'public/index.php' => [
				'source'            => 'resources/scaffold/public/index.php.stub',
				'type'              => 'entrypoint',
				'policy'            => 'managed',
				'stub_checksum'     => 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
				'rendered_checksum' => 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
				'placeholders'      => ['APP_NAMESPACE' => 'App'],
				'installed_at'      => '2026-06-02T10:00:00+00:00',
			],
		],
	],
];


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
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


/** Return the InstallerException $run throws; fail the check when it throws none. */
function rejected(callable $run, string $what): InstallerException {
	try {
		$run();
	} catch (InstallerException $e) {
		return $e;
	}
	throw new CheckFailed($what . ' was accepted');
}


/** Write raw bytes to the canonical state path, creating its directories. */
function writeRawState(string $appRoot, string $contents): string {
	$path = $appRoot . '/' . ScaffoldState::RELATIVE_PATH;
	if (!\is_dir(\dirname($path))) {
		\mkdir(\dirname($path), 0700, true);
	}
	\file_put_contents($path, $contents);
	return $contents;
}


/** Create a fresh run directory and return its canonical application root. */
function makeAppRoot(): string {
	$dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . RUN_DIR_PREFIX . \bin2hex(\random_bytes(6));
	\mkdir($dir . '/app', 0700, true);
	return (string)\realpath($dir . '/app');
}


/** Remove a directory created by makeAppRoot(). Any other path is left alone. */
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

	// -- Reads, writes, and round trips -----------------------------

	'a missing state file reads as no state' => static function (string $app): void {
		$state = ScaffoldState::forAppRoot($app);
		same(null, $state->read(), 'read()');
		same([], $state->readPackages(), 'readPackages()');
		same(null, $state->environment(), 'environment()');
		same(false, $state->exists(), 'exists()');
	},

	'the first write creates the state directories and file' => static function (string $app): void {
		$state = ScaffoldState::forAppRoot($app);
		$state->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV);
		expect(\is_file($app . '/' . ScaffoldState::RELATIVE_PATH), 'No state file at ' . ScaffoldState::RELATIVE_PATH);
		same(true, $state->exists(), 'exists()');
	},

	'a write stamps the format 2 envelope with the environment' => static function (string $app): void {
		$state = ScaffoldState::forAppRoot($app);
		$state->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV);

		$full = $state->read();
		same(ScaffoldState::FORMAT_VERSION, $full['format_version'] ?? null, 'format_version');
		same(ScaffoldState::GENERATOR, $full['generated_by'] ?? null, 'generated_by');
		same('dev', $full['environment'] ?? null, 'environment');
		expect(\strtotime((string)($full['generated_at'] ?? '')) !== false, 'generated_at is not a parseable timestamp: ' . export($full['generated_at'] ?? null));
	},

	'packages round-trip unchanged' => static function (string $app): void {
		$state = ScaffoldState::forAppRoot($app);
		$state->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV);
		same(SAMPLE_PACKAGES, $state->readPackages(), 'readPackages()');
	},

	'forAppRoot() places the state file under the application root' => static function (string $app): void {
		same($app . '/' . ScaffoldState::RELATIVE_PATH, ScaffoldState::forAppRoot($app . '/')->path(), 'path()');
	},

	'a later write replaces the recorded packages' => static function (string $app): void {
		$state = ScaffoldState::forAppRoot($app);
		$state->writePackagesAndEnvironment(['a/b' => ['installed_version' => '1.0.0', 'files' => []]], Environment::DEV);
		$state->writePackagesAndEnvironment(['c/d' => ['installed_version' => '2.0.0', 'files' => []]], Environment::DEV);

		$packages = $state->readPackages();
		same(['c/d'], \array_keys($packages), 'recorded packages');
		same('2.0.0', $packages['c/d']['installed_version'], 'installed_version');
	},

	'writePackages() keeps the recorded environment' => static function (string $app): void {
		$state = ScaffoldState::forAppRoot($app);
		$state->writePackagesAndEnvironment([], Environment::PROD);
		$state->writePackages(SAMPLE_PACKAGES);

		same(Environment::PROD, $state->environment(), 'environment()');
		same(SAMPLE_PACKAGES, $state->readPackages(), 'readPackages()');
	},

	'writePackages() cannot create the first state' => static function (string $app): void {
		$state = ScaffoldState::forAppRoot($app);
		$e = rejected(static fn() => $state->writePackages(SAMPLE_PACKAGES), 'writePackages() without state');
		expect(\str_contains($e->getMessage(), 'before an environment has been materialized'), 'Message does not explain the missing environment: ' . $e->getMessage());
		same(false, $state->exists(), 'exists()');
	},

	'a write leaves no temporary files behind' => static function (string $app): void {
		ScaffoldState::forAppRoot($app)->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV);
		same(['installer-scaffold.php'], \array_values(\array_diff(\scandir($app . '/var/state/citomni'), ['.', '..'])), 'files in var/state/citomni');
	},

	// -- Unsafe state blocks reads and writes -----------------------

	'a newer format_version blocks a write and leaves the file untouched' => static function (string $app): void {
		$before = writeRawState($app, "<?php\nreturn ['format_version' => " . (ScaffoldState::FORMAT_VERSION + 1) . ", 'environment' => 'dev', 'packages' => []];\n");
		$state = ScaffoldState::forAppRoot($app);

		$e = rejected(static fn() => $state->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV), 'A write over a newer format_version');
		expect(\str_contains($e->getMessage(), 'newer'), 'Message does not say "newer": ' . $e->getMessage());
		same($before, \file_get_contents($state->path()), 'state file bytes');
	},

	'a newer format_version blocks a read' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn ['format_version' => 99, 'environment' => 'dev', 'packages' => []];\n");
		rejected(static fn() => ScaffoldState::forAppRoot($app)->read(), 'format_version 99');
	},

	'format_version 0 blocks a write' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn ['format_version' => 0, 'environment' => 'dev', 'packages' => []];\n");
		rejected(static fn() => ScaffoldState::forAppRoot($app)->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV), 'format_version 0');
	},

	'legacy format_version 1 blocks a read' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn ['format_version' => 1, 'generated_by' => 'citomni/installer', 'packages' => []];\n");
		$e = rejected(static fn() => ScaffoldState::forAppRoot($app)->read(), 'Legacy format_version 1');
		expect(\str_contains($e->getMessage(), 'not supported'), 'Message does not say "not supported": ' . $e->getMessage());
	},

	'a format_version that is not an integer blocks a write' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn ['format_version' => '" . ScaffoldState::FORMAT_VERSION . "', 'environment' => 'dev', 'packages' => []];\n");
		rejected(static fn() => ScaffoldState::forAppRoot($app)->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV), 'A string format_version');
	},

	'a missing format_version blocks a write' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn ['environment' => 'dev', 'packages' => []];\n");
		rejected(static fn() => ScaffoldState::forAppRoot($app)->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV), 'A missing format_version');
	},

	'a parse error blocks a write and leaves the file untouched' => static function (string $app): void {
		$before = writeRawState($app, "<?php\nreturn [ ;\n");
		$state = ScaffoldState::forAppRoot($app);

		$e = rejected(static fn() => $state->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV), 'A write over a parse error');
		expect(\str_contains($e->getMessage(), 'could not be read safely'), 'Message does not say "could not be read safely": ' . $e->getMessage());
		same($before, \file_get_contents($state->path()), 'state file bytes');
	},

	'a state file that returns no array blocks a write' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn 5;\n");
		rejected(static fn() => ScaffoldState::forAppRoot($app)->writePackagesAndEnvironment(SAMPLE_PACKAGES, Environment::DEV), 'A state file returning 5');
	},

	'a state without packages blocks a read' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn ['format_version' => " . ScaffoldState::FORMAT_VERSION . ", 'environment' => 'dev'];\n");
		rejected(static fn() => ScaffoldState::forAppRoot($app)->read(), 'A state without packages');
	},

	'a state without a valid environment blocks a read' => static function (string $app): void {
		writeRawState($app, "<?php\nreturn ['format_version' => " . ScaffoldState::FORMAT_VERSION . ", 'environment' => 'test', 'packages' => []];\n");
		rejected(static fn() => ScaffoldState::forAppRoot($app)->read(), 'Environment "test"');
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
			$app = makeAppRoot();
			$check($app);
		} finally {
			if ($app !== null) {
				removeRunDir(\dirname($app));
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
