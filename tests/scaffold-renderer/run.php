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

namespace CitOmni\Installer\Tests\ScaffoldRenderer;

use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Support\ScaffoldRenderer;

/*
 * Standalone suite for \CitOmni\Installer\Support\ScaffoldRenderer.
 *
 * The checks cover token replacement, the single-pass guarantee, unknown and
 * malformed tokens, byte preservation, placeholdersIn(), and readStub(). Only the
 * readStub() checks touch disk, through one temporary file each.
 *
 * Usage:
 *   php tests/scaffold-renderer/run.php
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

/** Placeholder values most checks render with. */
const VALUES = [
	'APP_NAMESPACE'       => 'App',
	'APP_NAME'            => 'My App',
	'CITOMNI_ENVIRONMENT' => 'prod',
	'PACKAGE_VERSION'     => '1.2.0',
];

/** Stubs whose tokens break the canonical grammar. */
const MALFORMED_STUBS = [
	'lowercase'      => '{{app_name}}',
	'leading digit'  => '{{1APP}}',
	'hyphen'         => '{{APP-NAME}}',
	'empty'          => '{{}}',
	'newline inside' => "{{APP_\nNAME}}",
	'dot'            => '{{APP.NAME}}',
];


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


/** Return the InstallerException $run throws; fail the check when it throws none. */
function rejected(callable $run, string $what): InstallerException {
	try {
		$run();
	} catch (InstallerException $e) {
		return $e;
	}
	throw new CheckFailed($what . ' was accepted');
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'every canonical token is replaced' => static function (ScaffoldRenderer $r): void {
		same(
			'ns=App name=My App env=prod ver=1.2.0',
			$r->render('ns={{APP_NAMESPACE}} name={{APP_NAME}} env={{CITOMNI_ENVIRONMENT}} ver={{PACKAGE_VERSION}}', VALUES),
			'render()'
		);
	},

	'whitespace inside the braces is trimmed' => static function (ScaffoldRenderer $r): void {
		same('My App/App', $r->render('{{ APP_NAME }}/{{ APP_NAMESPACE }}', VALUES), 'render()');
	},

	'a repeated token is replaced everywhere' => static function (ScaffoldRenderer $r): void {
		same('App/App/App', $r->render('{{APP_NAMESPACE}}/{{APP_NAMESPACE}}/{{APP_NAMESPACE}}', VALUES), 'render()');
	},

	'values are inserted verbatim, backslashes and dollar signs included' => static function (ScaffoldRenderer $r): void {
		$values = ['APP_NAMESPACE' => 'App\\Domain\\Sub', 'APP_NAME' => 'Cost is $5 \\1'];
		same('namespace App\\Domain\\Sub; // Cost is $5 \\1', $r->render('namespace {{APP_NAMESPACE}}; // {{APP_NAME}}', $values), 'render()');
	},

	'an empty value removes the token' => static function (ScaffoldRenderer $r): void {
		same('a=', $r->render('a={{APP_NAME}}', ['APP_NAME' => '']), 'render()');
	},

	'unused values are ignored' => static function (ScaffoldRenderer $r): void {
		same('App', $r->render('{{APP_NAMESPACE}}', VALUES), 'render()');
	},

	'a value that looks like a token is not expanded again' => static function (ScaffoldRenderer $r): void {
		$values = ['APP_NAME' => '{{CITOMNI_ENVIRONMENT}}', 'CITOMNI_ENVIRONMENT' => 'prod'];
		same('{{CITOMNI_ENVIRONMENT}}', $r->render('{{APP_NAME}}', $values), 'render()');
	},

	'an unknown placeholder fails and names the token' => static function (ScaffoldRenderer $r): void {
		$e = rejected(static fn() => $r->render('x={{NOT_A_TOKEN}}', VALUES), 'An unknown placeholder');
		expect(\str_contains($e->getMessage(), 'Unknown placeholder: {{NOT_A_TOKEN}}'), 'Message does not name the token: ' . $e->getMessage());
	},

	'an unknown placeholder fails even when the others resolve' => static function (ScaffoldRenderer $r): void {
		rejected(static fn() => $r->render('{{APP_NAME}} {{MISSING}}', VALUES), 'A stub with one unknown placeholder');
	},

	'malformed tokens fail' => static function (ScaffoldRenderer $r): void {
		$accepted = [];
		foreach (MALFORMED_STUBS as $label => $stub) {
			try {
				$r->render($stub, VALUES);
				$accepted[] = $label;
			} catch (InstallerException) {
			}
		}
		same([], $accepted, 'malformed tokens accepted');
	},

	'a value that is not a string fails' => static function (ScaffoldRenderer $r): void {
		$e = rejected(static fn() => $r->render('{{PACKAGE_VERSION}}', ['PACKAGE_VERSION' => 120]), 'An integer value');
		expect(\str_contains($e->getMessage(), 'must resolve to a string'), 'Message does not say "must resolve to a string": ' . $e->getMessage());
	},

	'braces that are not placeholders are left untouched' => static function (ScaffoldRenderer $r): void {
		// Single-brace interpolation, nested arrays, and arrow functions: none are "{{...}}".
		$stub = 'echo "{$user->name}"; $a = [[1],[2]]; $f = fn() => 1;';
		same($stub, $r->render($stub, VALUES), 'render()');
	},

	'CRLF is preserved and no trailing newline is added' => static function (ScaffoldRenderer $r): void {
		same("<?php\r\n// My App\r\nreturn 1;", $r->render("<?php\r\n// {{APP_NAME}}\r\nreturn 1;", VALUES), 'render()');
	},

	'placeholdersIn() returns sorted, unique names' => static function (ScaffoldRenderer $r): void {
		same(['APP_NAME', 'APP_NAMESPACE', 'PACKAGE_VERSION'], $r->placeholdersIn('{{PACKAGE_VERSION}} {{APP_NAME}} {{APP_NAME}} {{APP_NAMESPACE}}'), 'placeholdersIn()');
	},

	'placeholdersIn() is empty for a stub without tokens' => static function (ScaffoldRenderer $r): void {
		same([], $r->placeholdersIn('no tokens here {$x}'), 'placeholdersIn()');
	},

	'placeholdersIn() rejects a malformed token' => static function (ScaffoldRenderer $r): void {
		rejected(static fn() => $r->placeholdersIn('{{ bad token }}'), 'A malformed token');
	},

	'readStub() returns the raw bytes' => static function (ScaffoldRenderer $r): void {
		$path = (string)\tempnam(\sys_get_temp_dir(), 'citomni_installer_stub_');
		$raw  = "<?php\r\nnamespace {{APP_NAMESPACE}};\r\n";
		\file_put_contents($path, $raw);
		try {
			same($raw, $r->readStub($path), 'readStub()');
		} finally {
			\unlink($path);
		}
	},

	'readStub() fails for a missing file' => static function (ScaffoldRenderer $r): void {
		rejected(static fn() => $r->readStub(\sys_get_temp_dir() . '/citomni_installer_missing_' . \bin2hex(\random_bytes(6))), 'A missing stub');
	},

];


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

$passed = 0;
$failed = 0;

foreach ($checks as $name => $check) {
	try {
		$check(new ScaffoldRenderer());
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
