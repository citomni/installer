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

namespace CitOmni\Installer\Tests\Util;

use CitOmni\Installer\Util\PhpSourceComparator;
use CitOmni\Installer\Util\UnifiedDiff;

/*
 * Standalone suite for the pure utilities \CitOmni\Installer\Util\PhpSourceComparator
 * and \CitOmni\Installer\Util\UnifiedDiff. No files, no processes.
 *
 * Usage:
 *   php tests/util/run.php
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


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


// ----------------------------------------------------------------
// Assertions
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


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	// -- PhpSourceComparator ------------------------------------------

	'comments, PHPDoc, a license header, and whitespace do not make sources differ' => static function (): void {
		$old = "<?php\n/** Old doc. */\nreturn [ 'enabled' => true ];\n";
		$new = "<?php\n/* New license. */\n\n/** New doc. */\nreturn [\n\t'enabled' => true\n];\n";
		same(true, PhpSourceComparator::equivalent($old, $new), 'equivalent()');
	},

	'a real code difference makes sources differ' => static function (): void {
		same(false, PhpSourceComparator::equivalent("<?php\nreturn ['enabled' => true];\n", "<?php\nreturn ['enabled' => false];\n"), 'equivalent()');
	},

	'comment-like text inside a string stays significant' => static function (): void {
		same(false, PhpSourceComparator::equivalent("<?php\nreturn '/* old */';\n", "<?php\nreturn '/* new */';\n"), 'equivalent()');
	},

	'__LINE__ keeps sources line-sensitive' => static function (): void {
		same(false, PhpSourceComparator::equivalent("<?php\nreturn __LINE__;\n", "<?php\n/* inserted line */\nreturn __LINE__;\n"), 'equivalent()');
	},

	'__COMPILER_HALT_OFFSET__ keeps sources byte-sensitive' => static function (): void {
		$old = "<?php\n/* old */\necho __COMPILER_HALT_OFFSET__; __halt_compiler();DATA";
		$new = "<?php\n/* new comment */\necho __COMPILER_HALT_OFFSET__; __halt_compiler();DATA";
		same(false, PhpSourceComparator::equivalent($old, $new), 'equivalent()');
	},

	'invalid PHP fails closed with a ParseError' => static function (): void {
		try {
			PhpSourceComparator::significantTokens("<?php\nreturn [;\n");
		} catch (\ParseError) {
			return;
		}
		throw new CheckFailed('significantTokens() accepted invalid PHP');
	},

	// -- UnifiedDiff --------------------------------------------------

	'identical input produces no diff' => static function (): void {
		same('', UnifiedDiff::create("a\nb\n", "a\nb\n", 'old', 'new'), 'create()');
	},

	'a difference in line endings only is spelled out' => static function (): void {
		$diff = UnifiedDiff::create("one\r\ntwo\r\n", "one\ntwo\n", 'old', 'new');
		expect(\str_contains($diff, 'byte-level difference') && \str_contains($diff, 'line endings'), 'Diff does not explain the line endings: ' . export($diff));
	},

	'a changed line produces a readable unified diff' => static function (): void {
		$diff = UnifiedDiff::create("a\nb\nc\n", "a\nx\nc\n", 'old', 'new');
		expect(\str_contains($diff, "--- old\n+++ new\n"), 'Diff has no headers: ' . export($diff));
		expect(\str_contains($diff, "-b\n+x\n"), 'Diff does not show the changed line: ' . export($diff));
	},

];


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

$passed = 0;
$failed = 0;

foreach ($checks as $name => $check) {
	try {
		$check();
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
