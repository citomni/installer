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

namespace CitOmni\Installer\Tests\ScaffoldPlan;

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Util\Checksum;

/*
 * Standalone suite for the scaffold decision graph (BuildScaffoldPlan) and the only
 * write layer (ApplyScaffoldPlan).
 *
 * The plan checks assert the planned status, reason, action, and the internal
 * `_apply` snapshot. The apply checks drive real plans through ApplyScaffoldPlan and
 * assert files, `.new` sidecars, backups, and recorded state. Every check gets a
 * fresh application root and package root in its own run directory, which is
 * removed afterwards.
 *
 * Usage:
 *   php tests/scaffold-plan/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; leave diagnostics silenced with @ to PHP, which the
// plan and apply operations rely on for expected filesystem failures.
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
const RUN_DIR_PREFIX = 'citomni_installer_scaffold_plan_';

const PACKAGE = 'acme/pkg';

const PLACEHOLDERS = ['APP_NAME' => 'Demo', 'APP_NAMESPACE' => 'App'];

/** The managed stub, rendered with PLACEHOLDERS. Stubs ship LF; the writer must keep it. */
const INDEX_STUB = "<?php\n// app: {{APP_NAME}} ns: {{APP_NAMESPACE}}\n";
const RENDERED_INDEX = "<?php\n// app: Demo ns: App\n";

const ROUTES_STUB = "<?php\nreturn []; // {{APP_NAME}}\n";


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


/**
 * One check's application, package, and the real collaborators bound to them.
 */
final class Fixture {

	public readonly BuildScaffoldPlan $build;
	public readonly ApplyScaffoldPlan $apply;
	public readonly ScaffoldState $state;
	public readonly ScaffoldRenderer $renderer;

	public function __construct(public readonly string $dir, public readonly string $app, public readonly string $package) {
		$guard          = new PathGuard($app);
		$this->renderer = new ScaffoldRenderer();
		$this->state    = ScaffoldState::forAppRoot($app);
		$this->build    = new BuildScaffoldPlan($guard, $this->renderer, $this->state);
		$this->apply    = new ApplyScaffoldPlan($guard, $this->renderer, $this->state);
	}


	/**
	 * The package's manifest as discovery normalizes it: one managed and one create-only target.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function manifests(): array {
		return [PACKAGE => [
			'package'           => PACKAGE,
			'version'           => 1,
			'root'              => $this->package,
			'installed_version' => '1.2.0',
			'files'             => [
				['target' => 'public/index.php', 'source' => 'resources/scaffold/index.php.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
				['target' => 'config/routes.php', 'source' => 'resources/scaffold/routes.php.stub', 'type' => 'config', 'policy' => 'create-only'],
			],
		]];
	}


	/**
	 * Build a plan for $command with the fixture's manifests and placeholders.
	 *
	 * @param array<string,mixed> $options BuildScaffoldPlan options.
	 * @return array<string,mixed>
	 */
	public function plan(string $command, array $options = []): array {
		return $this->build->build($command, $this->manifests(), [PACKAGE => PLACEHOLDERS], $options);
	}


	/** Record an environment, as a completed materialization does, so apply() can persist state. */
	public function materialized(): void {
		$this->state->writePackagesAndEnvironment([], Environment::DEV);
	}


	/** Record a managed baseline for public/index.php; $stubChecksum fakes an older upstream stub. */
	public function recordIndexBaseline(?string $stubChecksum = null): void {
		$this->state->writePackagesAndEnvironment([
			PACKAGE => [
				'installed_version' => '1.0.0',
				'files' => [
					'public/index.php' => [
						'source'            => 'resources/scaffold/index.php.stub',
						'type'              => 'entrypoint',
						'policy'            => 'managed',
						'stub_checksum'     => $stubChecksum ?? Checksum::sha256(INDEX_STUB),
						'rendered_checksum' => Checksum::sha256(RENDERED_INDEX),
						'placeholders'      => PLACEHOLDERS,
						'installed_at'      => '2025-01-01T00:00:00+00:00',
					],
				],
			],
		], Environment::DEV);
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


/**
 * Pull one file entry out of a plan or apply result by target.
 *
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function entry(array $result, string $target): array {
	foreach ($result['packages'] ?? [] as $pkg) {
		foreach ($pkg['files'] ?? [] as $file) {
			if (($file['target'] ?? null) === $target) {
				return $file;
			}
		}
	}
	throw new CheckFailed("No entry for '{$target}' in " . export($result));
}


/** Create a fresh run directory with an application root and a package with both stubs. */
function makeFixture(): Fixture {
	$dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . RUN_DIR_PREFIX . \bin2hex(\random_bytes(6));
	\mkdir($dir . '/app/public', 0700, true);
	\mkdir($dir . '/app/config', 0700, true);
	\mkdir($dir . '/package/resources/scaffold', 0700, true);
	\file_put_contents($dir . '/package/resources/scaffold/index.php.stub', INDEX_STUB);
	\file_put_contents($dir . '/package/resources/scaffold/routes.php.stub', ROUTES_STUB);

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

	// -- Plan: managed targets --------------------------------------

	'install plans a missing managed target as create with its rendered checksum' => static function (Fixture $f): void {
		$index = entry($f->plan('install'), 'public/index.php');
		same(['managed', 'missing', 'create'], [$index['policy'], $index['status'], $index['action']], 'policy, status, action');
		same(Checksum::sha256(RENDERED_INDEX), $index['_apply']['rendered_checksum'] ?? null, '_apply.rendered_checksum');
	},

	'sync leaves an up-to-date managed target alone' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/public/index.php', RENDERED_INDEX);
		$f->recordIndexBaseline();

		$index = entry($f->plan('sync'), 'public/index.php');
		same(['up_to_date', 'none'], [$index['status'], $index['action']], 'status, action');
		expect(!\array_key_exists('_apply', $index), 'An up-to-date target carries an _apply snapshot.');
	},

	'sync updates a clean managed target whose stub changed upstream' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/public/index.php', RENDERED_INDEX);
		$f->recordIndexBaseline();
		\file_put_contents($f->package . '/resources/scaffold/index.php.stub', INDEX_STUB . "// upstream change\n");

		$index = entry($f->plan('sync'), 'public/index.php');
		same(['update_available', 'stub_drift', 'update'], [$index['status'], $index['reason'], $index['action']], 'status, reason, action');
		expect(!\array_key_exists('backup', $index), 'A clean update plans a backup.');
	},

	'sync refreshes the baseline of a target that already matches a changed stub' => static function (Fixture $f): void {
		// The recorded stub checksum is older, but the file already equals the current render.
		\file_put_contents($f->app . '/public/index.php', RENDERED_INDEX);
		$f->recordIndexBaseline('sha256:' . \str_repeat('0', 64));

		$index = entry($f->plan('sync'), 'public/index.php');
		same(['up_to_date', 'adopt_clean_baseline', 'register_state'], [$index['status'], $index['reason'], $index['action']], 'status, reason, action');
		same(Checksum::sha256(INDEX_STUB), $index['_apply']['stub_checksum'] ?? null, '_apply.stub_checksum');
	},

	'sync plans .new for a locally modified managed target' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/public/index.php', "<?php\n// hand edited\n");
		$f->recordIndexBaseline();

		$index = entry($f->plan('sync'), 'public/index.php');
		same(['local_modified', 'rendered_checksum_mismatch', 'write_new'], [$index['status'], $index['reason'], $index['action']], 'status, reason, action');
		same(norm($f->app . '/public/index.php.new'), norm((string)($index['_apply']['new_path'] ?? '')), '_apply.new_path');
	},

	'forced sync overwrites a locally modified managed target with a backup' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/public/index.php', "<?php\n// hand edited\n");
		$f->recordIndexBaseline();

		$index = entry($f->plan('sync', ['force' => true]), 'public/index.php');
		same(['local_modified', 'update', true], [$index['status'], $index['action'], $index['backup'] ?? null], 'status, action, backup');
		same(true, $index['_apply']['backup_required'] ?? null, '_apply.backup_required');
	},

	// -- Plan: create-only targets ----------------------------------

	'sync creates a missing create-only target' => static function (Fixture $f): void {
		$routes = entry($f->plan('sync'), 'config/routes.php');
		same(['create-only', 'missing', 'create'], [$routes['policy'], $routes['status'], $routes['action']], 'policy, status, action');
	},

	'sync never touches an existing create-only target' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/config/routes.php', "<?php\nreturn ['user-customised'];\n");

		$routes = entry($f->plan('sync'), 'config/routes.php');
		same(['create_only_present', 'none'], [$routes['status'], $routes['action']], 'status, action');
		expect(!\array_key_exists('_apply', $routes), 'An existing create-only target carries an _apply snapshot.');
	},

	'forced sync overwrites an existing create-only target with a backup' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/config/routes.php', "<?php\nreturn ['user-customised'];\n");

		$routes = entry($f->plan('sync', ['force' => true]), 'config/routes.php');
		same(['update', true], [$routes['action'], $routes['backup'] ?? null], 'action, backup');
		same(true, $routes['_apply']['backup_required'] ?? null, '_apply.backup_required');
	},

	// -- Plan: no recorded state ------------------------------------

	'sync without state adopts an existing file that matches the render' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/public/index.php', RENDERED_INDEX);

		$index = entry($f->plan('sync'), 'public/index.php');
		same(['unknown_existing', 'adopt_clean_baseline', 'register_state'], [$index['status'], $index['reason'], $index['action']], 'status, reason, action');
		same(Checksum::sha256(RENDERED_INDEX), $index['_apply']['rendered_checksum'] ?? null, '_apply.rendered_checksum');
	},

	'sync without state plans .new for an existing file that differs' => static function (Fixture $f): void {
		\file_put_contents($f->app . '/public/index.php', "<?php\n// pre-existing, unmanaged\n");

		$index = entry($f->plan('sync'), 'public/index.php');
		same(['unknown_existing', 'write_new'], [$index['status'], $index['action']], 'status, action');
		same(norm($f->app . '/public/index.php.new'), norm((string)($index['_apply']['new_path'] ?? '')), '_apply.new_path');
	},

	'sync without state creates a missing managed target' => static function (Fixture $f): void {
		$index = entry($f->plan('sync'), 'public/index.php');
		same(['missing', 'create'], [$index['status'], $index['action']], 'status, action');
	},

	// -- Apply ------------------------------------------------------

	'install creates the files and records managed and create-only baselines' => static function (Fixture $f): void {
		$f->materialized();
		$result = $f->apply->apply($f->plan('install'));

		same(true, $result['ok'], 'ok');
		same(RENDERED_INDEX, @\file_get_contents($f->app . '/public/index.php'), 'public/index.php');
		expect(\is_file($f->app . '/config/routes.php'), 'config/routes.php was not created.');

		$package = $f->state->readPackages()[PACKAGE] ?? [];
		$index   = $package['files']['public/index.php'] ?? [];
		$routes  = $package['files']['config/routes.php'] ?? [];
		same('1.2.0', $package['installed_version'] ?? null, 'installed_version');
		// Managed records both checksums; create-only records the stub checksum only.
		same(true, Checksum::matches(RENDERED_INDEX, (string)($index['rendered_checksum'] ?? '')), 'managed rendered_checksum matches the file');
		expect(isset($index['stub_checksum']), 'The managed baseline has no stub_checksum.');
		expect(isset($routes['stub_checksum']) && !\array_key_exists('rendered_checksum', $routes), 'The create-only baseline must hold stub_checksum and no rendered_checksum: ' . export($routes));
	},

	'sync writes .new for a local modification and leaves the baseline alone' => static function (Fixture $f): void {
		$f->materialized();
		$f->apply->apply($f->plan('install'));
		$modified = RENDERED_INDEX . "// LOCAL EDIT\n";
		\file_put_contents($f->app . '/public/index.php', $modified);
		$stateBefore = \file_get_contents($f->state->path());

		$file = entry($f->apply->apply($f->plan('sync')), 'public/index.php');
		same(['local_modified', 'write_new', 'wrote_new'], [$file['status'], $file['action'], $file['applied']], 'status, action, applied');
		same(norm($f->app . '/public/index.php.new'), norm((string)($file['new_path'] ?? '')), 'new_path');
		same(RENDERED_INDEX, @\file_get_contents($f->app . '/public/index.php.new'), 'public/index.php.new');
		same($modified, \file_get_contents($f->app . '/public/index.php'), 'public/index.php');
		same($stateBefore, \file_get_contents($f->state->path()), 'state file bytes');
	},

	'forced sync backs up the modified bytes, then writes and records the render' => static function (Fixture $f): void {
		$f->materialized();
		$f->apply->apply($f->plan('install'));
		$modified = RENDERED_INDEX . "// LOCAL EDIT\n";
		\file_put_contents($f->app . '/public/index.php', $modified);

		$result = $f->apply->apply($f->plan('sync', ['force' => true]));
		$file   = entry($result, 'public/index.php');
		same('updated', $file['applied'], 'applied');
		$backup = (string)($file['backup_path'] ?? '');
		expect(\str_contains(norm($backup), '/var/backups/citomni-installer/'), 'Backup is not under var/backups/citomni-installer: ' . $backup);
		same($modified, @\file_get_contents($backup), 'backup bytes');
		expect($result['backup_dir'] !== null, 'The result names no backup_dir.');

		same(RENDERED_INDEX, \file_get_contents($f->app . '/public/index.php'), 'public/index.php');
		$recorded = (string)($f->state->readPackages()[PACKAGE]['files']['public/index.php']['rendered_checksum'] ?? '');
		same(true, Checksum::matches(RENDERED_INDEX, $recorded), 'recorded rendered_checksum matches the new file');
	},

	'a forced overwrite backs up even a clean file' => static function (Fixture $f): void {
		$f->materialized();
		$f->apply->apply($f->plan('install'));
		$clean = \file_get_contents($f->app . '/public/index.php');

		$file = entry($f->apply->apply($f->plan('install', ['force' => true])), 'public/index.php');
		same($clean, @\file_get_contents((string)($file['backup_path'] ?? '')), 'backup bytes');
	},

	'a dry run writes nothing and still reports the outcome' => static function (Fixture $f): void {
		$result = $f->apply->apply($f->plan('install'), ['dry_run' => true]);

		same([true, false], [$result['dry_run'], $result['state_written']], 'dry_run, state_written');
		expect(!\file_exists($f->app . '/public/index.php') && !\file_exists($f->app . '/config/routes.php'), 'A dry run created scaffold files.');
		same(false, $f->state->exists(), 'state exists');
		same('created', entry($result, 'public/index.php')['applied'], 'applied');
	},

	'a plan that went stale is a conflict and leaves the stale target alone' => static function (Fixture $f): void {
		$f->materialized();
		$f->apply->apply($f->plan('install'));
		\file_put_contents($f->app . '/public/index.php', "tampered\n");

		// Build the forced plan, then change the stub so the plan no longer matches.
		$plan = $f->plan('sync', ['force' => true]);
		\file_put_contents($f->package . '/resources/scaffold/index.php.stub', "<?php\n// CHANGED {{APP_NAME}}\n");

		$result = $f->apply->apply($plan);
		$file   = entry($result, 'public/index.php');
		same(['conflict', 'conflict', false], [$file['applied'], $file['error_type'] ?? null, $result['ok']], 'applied, error_type, ok');
		expect(\str_contains((string)($file['error'] ?? ''), 'stale'), 'Error does not say "stale": ' . export($file['error'] ?? null));
		same("tampered\n", \file_get_contents($f->app . '/public/index.php'), 'public/index.php');
	},

];


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

$passed = 0;
$failed = 0;

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
