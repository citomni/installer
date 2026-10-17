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

namespace CitOmni\Installer\Tests\Migration;

use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\AuditAppSkeletonMigration;
use CitOmni\Installer\Operation\BuildMigrationDiagnostics;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\PhpLintRunner;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Util\Checksum;

/*
 * Standalone suite for the building blocks of `migrate`: how create-only targets are
 * planned, generated `.new` sidecars, installer-config, source and retired-file
 * diagnostics, the app-skeleton audit, and the recursive PHP lint.
 *
 * Every check gets a fresh application root and package root in its own run
 * directory, which is removed afterwards. The lint check starts `php -l` processes
 * with the running PHP binary.
 *
 * Usage:
 *   php tests/migration/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; leave diagnostics silenced with @ to PHP, which the
// apply layer and PhpLintRunner rely on for expected filesystem and process failures.
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
const RUN_DIR_PREFIX = 'citomni_installer_migration_';

const PACKAGE = 'acme/pkg';

/** installed_at of the records a check writes, so a rewritten record shows. */
const RECORDED_AT = '2026-10-01T00:00:00+00:00';


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


/**
 * One check's run directory with an application root and a package root.
 */
final class Fixture {

	public function __construct(public readonly string $dir, public readonly string $app, public readonly string $package) {}


	/** Write $bytes to an application path, creating its directories. */
	public function appFile(string $relative, string $bytes): void {
		$this->put($this->app . '/' . $relative, $bytes);
	}


	/** Write $bytes to a package path, creating its directories. */
	public function packageFile(string $relative, string $bytes): void {
		$this->put($this->package . '/' . $relative, $bytes);
	}


	/**
	 * A one-file create-only manifest for $target, rendered from $source.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function createOnlyManifest(string $target, string $source): array {
		return [PACKAGE => [
			'package'           => PACKAGE,
			'version'           => 1,
			'root'              => $this->package,
			'installed_version' => '1.0.0',
			'files'             => [['target' => $target, 'source' => $source, 'type' => 'config', 'policy' => 'create-only']],
		]];
	}


	/**
	 * The migrate plan entry for one create-only target.
	 *
	 * @return array<string,mixed>
	 */
	public function migratePlanEntry(string $target, string $source): array {
		$plan = $this->builder()->build('migrate', $this->createOnlyManifest($target, $source), [PACKAGE => []]);
		return $plan['packages'][0]['files'][0];
	}


	/**
	 * Plan and apply `migrate` for one create-only target without persisting state.
	 *
	 * @return array<string,mixed> The applied entry.
	 */
	public function migrateApplied(string $target, string $source): array {
		$guard    = new PathGuard($this->app);
		$renderer = new ScaffoldRenderer();
		$state    = ScaffoldState::forAppRoot($this->app);
		$plan     = (new BuildScaffoldPlan($guard, $renderer, $state))->build('migrate', $this->createOnlyManifest($target, $source), [PACKAGE => []]);
		$result   = (new ApplyScaffoldPlan($guard, $renderer, $state))->apply($plan, ['persist_state' => false]);
		return $result['packages'][0]['files'][0];
	}


	/**
	 * The migrate plan entry for one create-only target in reconcile mode, against the recorded state.
	 *
	 * @return array<string,mixed>
	 */
	public function reconcilePlanEntry(string $target, string $source): array {
		$plan = $this->builder()->build('migrate', $this->createOnlyManifest($target, $source), [PACKAGE => []], ['migration_reconcile' => true]);
		return $plan['packages'][0]['files'][0];
	}


	/**
	 * Apply one migrate plan entry without persisting state.
	 *
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed> The apply result, including the state packages it would write.
	 */
	public function applyMigrateEntry(array $entry): array {
		$plan = ['command' => 'migrate', 'packages' => [['name' => PACKAGE, 'installed_version' => '1.0.0', 'files' => [$entry]]]];
		return (new ApplyScaffoldPlan(new PathGuard($this->app), new ScaffoldRenderer(), ScaffoldState::forAppRoot($this->app)))->apply($plan, ['persist_state' => false]);
	}


	/** Record $target in v2 state as a create-only file rendered from $source without placeholders. */
	public function recordCreateOnly(string $target, string $source, string $stubChecksum): void {
		ScaffoldState::forAppRoot($this->app)->writePackagesAndEnvironment([PACKAGE => ['installed_version' => '1.0.0', 'files' => [$target => [
			'source'        => $source,
			'type'          => 'config',
			'policy'        => 'create-only',
			'stub_checksum' => $stubChecksum,
			'placeholders'  => [],
			'installed_at'  => RECORDED_AT,
		]]]], Environment::DEV);
	}


	private function builder(): BuildScaffoldPlan {
		return new BuildScaffoldPlan(new PathGuard($this->app), new ScaffoldRenderer(), ScaffoldState::forAppRoot($this->app));
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


/** Diagnostics reading config/citomni_installer.php below the fixture's application root. */
function diagnostics(Fixture $f): BuildMigrationDiagnostics {
	return new BuildMigrationDiagnostics(new PathGuard($f->app), new ScaffoldRenderer(), new PlaceholderResolver($f->app));
}


/**
 * An environment-aware entry whose prod source uses a percent-style token and a derived host.
 *
 * @return array<string,array<string,mixed>>
 */
function environmentManifests(Fixture $f): array {
	$f->packageFile('dev.stub', "<?php\nreturn [];\n");
	$f->packageFile('stage.stub', "<?php\nreturn [];\n");
	$f->packageFile('prod.stub', "<?php\n// %PROD_ROOT_URL%\nreturn ['host' => '{{PROD_ROOT_HOST}}'];\n");

	$environments = [];
	foreach (['dev', 'stage', 'prod'] as $environment) {
		$environments[$environment] = ['source' => $environment . '.stub', 'source_path' => $f->package . '/' . $environment . '.stub'];
	}
	return [PACKAGE => ['files' => [['target' => 'config/example.php', 'environments' => $environments]]]];
}


/**
 * An app-skeleton reference holding one .gitignore.
 *
 * @return array{sha:string,files:array<string,array{url:string,bytes:string}>}
 */
function skeletonReference(string $bytes): array {
	return [
		'sha'   => \str_repeat('a', 40),
		'files' => ['.gitignore' => ['url' => 'https://example.invalid/.gitignore', 'bytes' => $bytes]],
	];
}


/** Create a fresh run directory with an application root and a package root. */
function makeFixture(): Fixture {
	$dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . RUN_DIR_PREFIX . \bin2hex(\random_bytes(6));
	\mkdir($dir . '/app/config', 0700, true);
	\mkdir($dir . '/package/install', 0700, true);
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

	// -- Planning create-only targets -------------------------------

	'PHP that differs only in documentation is refreshed with a backup' => static function (Fixture $f): void {
		$f->appFile('config/example.php', "<?php\n/** Old docs. */\nreturn ['enabled' => true];\n");
		$f->packageFile('install/example.php.stub', "<?php\n/* New license. */\n/** New docs. */\nreturn [\n\t'enabled' => true\n];\n");

		$entry = $f->migratePlanEntry('config/example.php', 'install/example.php.stub');
		same(['update', 'migration_php_nonfunctional_change', true], [$entry['action'], $entry['reason'], $entry['backup'] ?? null], 'action, reason, backup');
	},

	'PHP with a code difference gets a .new reference' => static function (Fixture $f): void {
		$f->appFile('config/example.php', "<?php\nreturn ['enabled' => true];\n");
		$f->packageFile('install/example.php.stub', "<?php\nreturn ['enabled' => false];\n");

		$entry = $f->migratePlanEntry('config/example.php', 'install/example.php.stub');
		same(['write_new', 'migration_php_code_difference'], [$entry['action'], $entry['reason']], 'action, reason');
	},

	'a .tpl target is refreshed authoritatively with a backup' => static function (Fixture $f): void {
		$f->appFile('config/example.php.tpl', "<?php\nreturn ['old' => true];\n");
		$f->packageFile('install/example.php.tpl.stub', "<?php\nreturn ['new' => true];\n");

		$entry = $f->migratePlanEntry('config/example.php.tpl', 'install/example.php.tpl.stub');
		same(['update', 'migration_template_refresh', true], [$entry['action'], $entry['reason'], $entry['backup'] ?? null], 'action, reason, backup');
	},

	'a create-only difference outside PHP gets a .new reference' => static function (Fixture $f): void {
		$f->appFile('config/example.txt', "old\n");
		$f->packageFile('install/example.txt.stub', "new\n");

		$entry = $f->migratePlanEntry('config/example.txt', 'install/example.txt.stub');
		same(['write_new', 'migration_content_difference'], [$entry['action'], $entry['reason']], 'action, reason');
	},

	'a clean create-only target whose record holds the current input is skipped and keeps its record' => static function (Fixture $f): void {
		$stub = "<?php\nreturn ['enabled' => true];\n";
		$f->appFile('config/example.php', $stub);
		$f->packageFile('install/example.php.stub', $stub);
		$f->recordCreateOnly('config/example.php', 'install/example.php.stub', Checksum::sha256($stub));

		$entry = $f->reconcilePlanEntry('config/example.php', 'install/example.php.stub');
		same(['none', 'create_only_present'], [$entry['action'], $entry['reason']], 'action, reason');
		$result = $f->applyMigrateEntry($entry);
		same('skipped', $result['packages'][0]['files'][0]['applied'] ?? null, 'applied');
		same(RECORDED_AT, $result['_state_packages'][PACKAGE]['files']['config/example.php']['installed_at'] ?? null, 'recorded installed_at');
	},

	'a clean create-only target is recorded when its record is missing or outdated' => static function (Fixture $f): void {
		$stub = "<?php\nreturn ['enabled' => true];\n";
		$f->appFile('config/example.php', $stub);
		$f->packageFile('install/example.php.stub', $stub);

		ScaffoldState::forAppRoot($f->app)->writePackagesAndEnvironment([], Environment::DEV);
		$entry = $f->reconcilePlanEntry('config/example.php', 'install/example.php.stub');
		same(['register_state', 'adopt_clean_baseline'], [$entry['action'], $entry['reason']], 'without a record');

		$f->recordCreateOnly('config/example.php', 'install/example.php.stub', Checksum::sha256("<?php\nreturn [];\n"));
		$entry = $f->reconcilePlanEntry('config/example.php', 'install/example.php.stub');
		same(['register_state', 'adopt_clean_baseline'], [$entry['action'], $entry['reason']], 'with another stub checksum');
	},

	'a skipped create-only target that changes after planning conflicts' => static function (Fixture $f): void {
		$stub = "<?php\nreturn ['enabled' => true];\n";
		$f->appFile('config/example.php', $stub);
		$f->packageFile('install/example.php.stub', $stub);
		$f->recordCreateOnly('config/example.php', 'install/example.php.stub', Checksum::sha256($stub));

		$entry = $f->reconcilePlanEntry('config/example.php', 'install/example.php.stub');
		$f->appFile('config/example.php', "<?php\nreturn ['enabled' => false];\n");
		same('conflict', $f->applyMigrateEntry($entry)['packages'][0]['files'][0]['applied'] ?? null, 'applied');
	},

	// -- Generated sidecars -----------------------------------------

	'an existing, different generated sidecar is overwritten with the current source' => static function (Fixture $f): void {
		$desired = "<?php\nreturn ['upstream' => true];\n";
		$f->appFile('config/example.php', "<?php\nreturn ['local' => true];\n");
		$f->appFile('config/example.php.new', "<?php\n// stale generated snapshot\n");
		$f->packageFile('install/example.php.stub', $desired);

		$entry = $f->migrateApplied('config/example.php', 'install/example.php.stub');
		same(['wrote_new', 'written'], [$entry['applied'], $entry['new_status'] ?? null], 'applied, new_status');
		same($desired, \file_get_contents($f->app . '/config/example.php.new'), 'config/example.php.new');
	},

	'a directory in place of the sidecar is an IO failure' => static function (Fixture $f): void {
		$f->appFile('config/example.php', "<?php\nreturn ['local' => true];\n");
		\mkdir($f->app . '/config/example.php.new');
		$f->packageFile('install/example.php.stub', "<?php\nreturn ['upstream' => true];\n");

		$entry = $f->migrateApplied('config/example.php', 'install/example.php.stub');
		same(['failed', 'io'], [$entry['applied'], $entry['error_type'] ?? null], 'applied, error_type');
		expect(\str_contains((string)($entry['error'] ?? ''), 'not a regular file'), 'Error does not say "not a regular file": ' . export($entry['error'] ?? null));
	},

	// -- Diagnostics ------------------------------------------------

	'a missing derived placeholder is reported as its configurable source key' => static function (Fixture $f): void {
		$result = diagnostics($f)->inspectInstallerConfig(environmentManifests($f), Environment::PROD, []);
		same(['PROD_ROOT_URL'], $result['missing_from_config']['prod'] ?? null, 'missing_from_config.prod');
		same(['PROD_ROOT_URL'], $result['missing_effective_selected'] ?? null, 'missing_effective_selected');
	},

	'a configured source of a required derived host is neither missing nor unused' => static function (Fixture $f): void {
		$f->appFile('config/citomni_installer.php', "<?php\nreturn ['placeholders' => ['PROD_ROOT_URL' => 'https://example.com']];\n");

		$result = diagnostics($f)->inspectInstallerConfig(environmentManifests($f), Environment::PROD, []);
		same([], $result['missing_from_config']['prod'] ?? null, 'missing_from_config.prod');
		same([], $result['missing_effective_selected'] ?? null, 'missing_effective_selected');
		same([], $result['unused_configured'] ?? null, 'unused_configured');
	},

	'percent-style source tokens are advisory and listed once per source' => static function (Fixture $f): void {
		$result = diagnostics($f)->inspectPercentStyleSourceTokens(environmentManifests($f));
		same([1, 1], [$result['file_count'], $result['token_count']], 'file_count, token_count');
		$file = $result['files'][0] ?? [];
		same([PACKAGE, 'prod.stub', ['config/example.php'], ['%PROD_ROOT_URL%']], [$file['package'] ?? null, $file['source'] ?? null, $file['targets'] ?? null, $file['tokens'] ?? null], 'package, source, targets, tokens');
	},

	'blocking review lists .new writes, conflicts, and failures, but not updates' => static function (Fixture $f): void {
		$manual = diagnostics($f)->blockingManualReview([
			'packages' => [[
				'name'  => PACKAGE,
				'files' => [
					['target' => 'a.php', 'applied' => 'wrote_new', 'reason' => 'code_difference'],
					['target' => 'b.php', 'applied' => 'conflict', 'reason' => 'stale'],
					['target' => 'c.php', 'applied' => 'failed', 'reason' => 'write', 'error' => 'denied'],
					['target' => 'd.php', 'applied' => 'updated', 'reason' => 'ok'],
				],
			]],
		]);
		same(['a.php', 'b.php', 'c.php'], \array_column($manual, 'target'), 'blocking targets');
	},

	'retired files are existing recorded targets that no manifest declares, with their baseline status' => static function (Fixture $f): void {
		$f->appFile('bin/clean', "clean\n");
		$f->appFile('bin/edited', "edited\n");
		$f->appFile('src/Demo.php', "<?php\n");
		$f->appFile('config/moved.php', "<?php\n");
		\file_put_contents($f->dir . '/outside', "outside\n");
		$recorded = [PACKAGE => ['files' => [
			'bin/clean'        => ['policy' => 'managed', 'rendered_checksum' => Checksum::sha256("clean\n")],
			'bin/edited'       => ['policy' => 'managed', 'rendered_checksum' => Checksum::sha256("original\n")],
			'src/Demo.php'     => ['policy' => 'create-only'],
			'bin/deleted'      => ['policy' => 'managed', 'rendered_checksum' => Checksum::sha256("deleted\n")],
			'config/moved.php' => ['policy' => 'create-only'],
			'../outside'       => ['policy' => 'create-only'],
		]]];
		$manifests = ['acme/other' => ['files' => [['target' => 'config/moved.php']]]];

		same([
			['package' => PACKAGE, 'target' => 'bin/clean', 'policy' => 'managed', 'status' => 'unmodified'],
			['package' => PACKAGE, 'target' => 'bin/edited', 'policy' => 'managed', 'status' => 'local_modified'],
			['package' => PACKAGE, 'target' => 'src/Demo.php', 'policy' => 'create-only', 'status' => 'app_owned'],
		], diagnostics($f)->retiredScaffoldFiles($recorded, $manifests), 'retired files');
	},

	'a retired target recorded by several packages is listed once, under its newest record' => static function (Fixture $f): void {
		$f->appFile('config/shared.php', "<?php\n");
		$recorded = [
			'acme/old'    => ['files' => ['config/shared.php' => ['policy' => 'create-only', 'installed_at' => '2026-08-01T00:00:00+00:00']]],
			'acme/newest' => ['files' => ['config/shared.php' => ['policy' => 'create-only', 'installed_at' => '2026-10-01T00:00:00+00:00']]],
			'acme/middle' => ['files' => ['config/shared.php' => ['policy' => 'create-only', 'installed_at' => '2026-09-01T00:00:00+00:00']]],
		];

		same(['acme/newest'], \array_column(diagnostics($f)->retiredScaffoldFiles($recorded, []), 'package'), 'packages');
	},

	// -- App-skeleton audit -----------------------------------------

	'a difference writes the reference as .new and leaves the local file alone' => static function (Fixture $f): void {
		$f->appFile('.gitignore', "local\n");

		$result = (new AuditAppSkeletonMigration(new PathGuard($f->app)))->apply(skeletonReference("upstream\n"), false);
		same("local\n", \file_get_contents($f->app . '/.gitignore'), '.gitignore');
		same("upstream\n", @\file_get_contents($f->app . '/.gitignore.new'), '.gitignore.new');
		same('written', $result['files'][0]['sidecar_status'] ?? null, 'sidecar_status');
		same(1, \count($result['manual_review']), 'manual_review entries');
	},

	'line endings and one final newline are no difference' => static function (Fixture $f): void {
		$f->appFile('.gitignore', "vendor\r\nvar/cache");

		$result = (new AuditAppSkeletonMigration(new PathGuard($f->app)))->apply(skeletonReference("vendor\nvar/cache\n"), false);
		$file = $result['files'][0] ?? [];
		same(['identical', 'none', ''], [$file['status'] ?? null, $file['action'] ?? null, $file['diff'] ?? null], 'status, action, diff');
		same([], $result['manual_review'], 'manual_review');
		expect(!\file_exists($f->app . '/.gitignore.new'), 'An identical file got a .gitignore.new.');
	},

	'an existing reference sidecar is replaced with the current reference' => static function (Fixture $f): void {
		$f->appFile('.gitignore', "local\n");
		$f->appFile('.gitignore.new', "stale snapshot\n");

		$result = (new AuditAppSkeletonMigration(new PathGuard($f->app)))->apply(skeletonReference("upstream\n"), false);
		same("upstream\n", \file_get_contents($f->app . '/.gitignore.new'), '.gitignore.new');
		same('written', $result['files'][0]['sidecar_status'] ?? null, 'sidecar_status');
	},

	// -- PHP lint ---------------------------------------------------

	'PHP lint covers app PHP and PHP templates and skips vendor and backups' => static function (Fixture $f): void {
		$f->appFile('src/Good.php', "<?php\nreturn true;\n");
		$f->appFile('templates/Bad.php.tpl', "<?php\nreturn [;\n");
		$f->appFile('vendor/acme/pkg/Bad.php', "<?php\nreturn [;\n");
		$f->appFile('var/backups/old/Bad.php', "<?php\nreturn [;\n");

		$result = (new PhpLintRunner(new PathGuard($f->app)))->run();
		same([true, 2, 1, 1], [$result['available'], $result['checked'], $result['passed'], $result['failed']], 'available, checked, passed, failed');
		same(['src/Good.php', 'templates/Bad.php.tpl'], \array_column($result['files'], 'path'), 'linted files');
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
