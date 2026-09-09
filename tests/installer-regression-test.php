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

use CitOmni\Installer\Cli\InstallerCli;
use CitOmni\Installer\Enum\Environment;
use CitOmni\Installer\Exception\InstallerException;
use CitOmni\Installer\Operation\ApplyEnvironmentMaterialization;
use CitOmni\Installer\Operation\ApplyScaffoldPlan;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\AtomicFileWriter;
use CitOmni\Installer\Support\ComposerPackageDiscovery;
use CitOmni\Installer\Support\ComposerRunner;
use CitOmni\Installer\Support\InstallerLock;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldManifestLocator;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Util\Path;

// Test-local autoloading exercises this checkout without installing or booting an app.
spl_autoload_register(static function (string $class): void {
	$prefix = 'CitOmni\\Installer\\';
	if (str_starts_with($class, $prefix)) {
		require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	}
});

if (($argv[1] ?? '') === '--child') {
	exit((new InstallerCli($argv[2]))->run(array_merge(['citomni-installer'], array_slice($argv, 3))));
}
if (PHP_VERSION_ID < 80500) {
	fwrite(STDERR, "PHP 8.5 or newer is required.\n");
	exit(1);
}

/** Fail independently of PHP's zend.assertions setting. */
function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

/** Check a public failure contract without matching implementation details. */
function expectFailure(callable $run, string $message): void {
	try {
		$run();
	} catch (InstallerException) {
		return;
	}
	throw new RuntimeException($message);
}

/** Write test fixtures with their required parent directories. */
function put(string $path, string $bytes): void {
	if (!is_dir(dirname($path))) {
		mkdir(dirname($path), 0775, true);
	}
	check(file_put_contents($path, $bytes) === strlen($bytes), 'Fixture write failed.');
}

/** Remove only this test run's owned tree; never follow directory symlinks. */
function removeTree(string $path): void {
	if (is_link($path) || !is_dir($path)) {
		if (file_exists($path) || is_link($path)) {
			if (!is_link($path)) {
				@chmod($path, 0600);
			}
			unlink($path);
		}
		return;
	}
	foreach (scandir($path) as $name) {
		if ($name !== '.' && $name !== '..') {
			removeTree($path . '/' . $name);
		}
	}
	rmdir($path);
}

/** Run a child with file-backed output so Windows pipe buffering cannot stall tests. */
function child(array $command, string $cwd): array {
	$out = tempnam(sys_get_temp_dir(), 'citomni_test_');
	$err = tempnam(sys_get_temp_dir(), 'citomni_test_');
	try {
		$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
		$process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $cwd);
		check(is_resource($process), 'Child process failed to start.');
		$exit = proc_close($process);
		return [$exit, file_get_contents($out), file_get_contents($err)];
	} finally {
		unlink($out);
		unlink($err);
	}
}

/** Invoke the actual CLI dispatcher in a fresh process with fake Composer on PATH. */
function command(string $app, array $args, bool $json = true): array {
	if ($json) {
		$args[] = '--format=json';
	}
	[$exit, $out, $err] = child(array_merge([PHP_BINARY, __FILE__, '--child', $app], $args), $app);
	$payload = $json ? json_decode($out, true) : null;
	if ($json) {
		check(is_array($payload), 'Expected JSON response. Output was ' . $out . $err);
	}
	return [$exit, $payload, $out . $err];
}

/** Add an installed Composer package with a schema 2 scaffold manifest. */
function package(string $app, string $name, array $files, array $stubs): void {
	$root = $app . '/vendor/' . $name;
	foreach ($stubs as $path => $bytes) {
		put($root . '/' . $path, $bytes);
	}
	put($root . '/install/manifest.php', '<?php return ' . var_export(['package' => $name, 'version' => 2, 'files' => $files], true) . ';');
	$path = $app . '/vendor/composer/installed.json';
	$data = is_file($path) ? json_decode(file_get_contents($path), true) : ['packages' => []];
	$data['packages'][] = ['name' => $name, 'version' => '1.0.0', 'install-path' => '../' . $name];
	put($path, json_encode($data));
}

/** Make an isolated app containing both scaffold policies and environment-aware files. */
function fixture(string $base, string $name): string {
	$app = $base . '/apps/' . $name;
	put($app . '/composer.json', json_encode(['name' => 'test/app', 'config' => ['classmap-authoritative' => false]]));
	package($app, 'test/core', [
		['target' => 'public/environment.txt', 'type' => 'text', 'policy' => 'managed', 'environments' => [
			'dev' => ['source' => 'install/dev.stub'],
			'stage' => ['source' => 'install/stage.stub'],
			'prod' => ['source' => 'install/prod.stub'],
		]],
		['target' => 'bin/tool', 'source' => 'install/tool.stub', 'type' => 'entrypoint', 'policy' => 'managed'],
		['target' => 'config/app.php', 'source' => 'install/config.stub', 'type' => 'config', 'policy' => 'create-only'],
	], [
		'install/dev.stub' => "dev\n",
		'install/stage.stub' => "stage\n",
		'install/prod.stub' => "prod\n",
		'install/tool.stub' => "tool version 1\n",
		'install/config.stub' => "<?php return ['name' => '{{APP_NAME}}'];\n",
	]);
	put($app . '/config/citomni_installer.php', "<?php return ['placeholders' => ['APP_NAME' => 'fixture']];");
	return $app;
}

/** Build a plan through real package discovery and the actual engine. */
function engine(string $app, string $verb, array $values = [], array $options = []): array {
	$guard = new PathGuard($app);
	$state = ScaffoldState::forAppRoot($app);
	$renderer = new ScaffoldRenderer();
	$locator = ScaffoldManifestLocator::forAppRoot($app);
	$manifests = $locator->selectEnvironment($locator->discover(), $state->environment() ?? Environment::DEV, $verb === 'environment');
	$placeholders = array_fill_keys(array_keys($manifests), $values + ['APP_NAME' => 'fixture']);
	$plan = (new BuildScaffoldPlan($guard, $renderer, $state))->build($verb, $manifests, $placeholders, $options);
	return [$plan, new ApplyScaffoldPlan($guard, $renderer, $state), $state];
}

/** Install a fixture and return its validated state. */
function installed(string $app): ScaffoldState {
	[$exit, , $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0, 'Fixture install failed. ' . $output);
	return ScaffoldState::forAppRoot($app);
}

/** Look up a target result without depending on package ordering. */
function resultFile(array $result, string $target): array {
	foreach ($result['packages'] as $pkg) {
		foreach ($pkg['files'] as $file) {
			if ($file['target'] === $target) {
				return $file;
			}
		}
	}
	throw new RuntimeException('Result did not contain target ' . $target);
}

$base = sys_get_temp_dir() . '/citomni-installer-regression-' . bin2hex(random_bytes(8));
mkdir($base);
$savedEnvironment = [];
foreach (['PATH', 'COMPOSER', 'COMPOSER_VENDOR_DIR'] as $key) {
	$savedEnvironment[$key] = getenv($key);
}
$fake = <<<'FAKE'
<?php
$root = getcwd();
$verb = $argv[1] ?? '';
if (is_file($root . '/check-lock')) {
	$handle = fopen($root . '/var/state/citomni/installer.lock', 'c+b');
	if (flock($handle, LOCK_EX | LOCK_NB)) {
		fwrite(STDERR, 'Installer lock was not held during Composer.');
		exit(30);
	}
	fclose($handle);
}
if ($verb === '--version') {
	echo 'Composer fixture';
	exit(is_file($root . '/fail-version') ? 20 : 0);
}
if ($verb === 'config' && ($argv[2] ?? '') === 'vendor-dir') {
	$config = json_decode(file_get_contents($root . '/composer.json'), true);
	$path = getenv('COMPOSER_VENDOR_DIR') ?: ($config['config']['vendor-dir'] ?? ($root . '/vendor'));
	echo realpath($path) ?: $path;
	exit(0);
}
if ($verb === 'config' && ($argv[2] ?? '') === 'classmap-authoritative') {
	if (is_file($root . '/fail-config')) {
		fwrite(STDERR, 'Controlled Composer config failure.');
		exit(21);
	}
	$config = json_decode(file_get_contents($root . '/composer.json'), true);
	$config['config']['classmap-authoritative'] = $argv[3] === 'true';
	file_put_contents($root . '/composer.json', json_encode($config));
	exit(0);
}
if ($verb === 'dump-autoload') {
	if (!in_array('--no-scripts', $argv, true)) {
		fwrite(STDERR, 'Root scripts were not disabled.');
		exit(22);
	}
	if (is_file($root . '/fail-dump')) {
		fwrite(STDERR, 'Controlled Composer dump failure.');
		exit(23);
	}
	file_put_contents($root . '/vendor/autoload.php', '<?php // Fixture autoload output.');
	exit(0);
}
fwrite(STDERR, 'Unexpected Composer command.');
exit(24);
FAKE;
put($base . '/fake-bin/composer.phar', $fake);
put($base . '/fake-bin/composer', '#!' . PHP_BINARY . "\n" . $fake);
chmod($base . '/fake-bin/composer', 0755);
putenv('PATH=' . $base . '/fake-bin' . PATH_SEPARATOR . ($savedEnvironment['PATH'] ?: ''));
putenv('COMPOSER');
putenv('COMPOSER_VENDOR_DIR');
$tests = [];

$tests['initial install recovers after Composer failures without force'] = static function () use ($base): void {
	foreach (['config', 'dump'] as $failure) {
		$app = fixture($base, 'recover-' . $failure);
		put($app . '/fail-' . $failure, '1');
		[$exit] = command($app, ['install', '--environment=dev']);
		check($exit !== 0, 'Controlled Composer failure must fail install.');
		check(!ScaffoldState::forAppRoot($app)->exists(), 'Failed initial install committed state.');
		$managed = file_get_contents($app . '/bin/tool');
		$owned = file_get_contents($app . '/config/app.php');
		unlink($app . '/fail-' . $failure);
		$state = installed($app);
		check(file_get_contents($app . '/bin/tool') === $managed, 'Recovery rewrote managed bytes.');
		check(file_get_contents($app . '/config/app.php') === $owned, 'Recovery rewrote create-only bytes.');
		check(isset($state->readPackages()['test/core']['files']['config/app.php']), 'Exact create-only recovery lost repair state.');
		unlink($app . '/config/app.php');
		[$exit] = command($app, ['repair']);
		check($exit === 0 && file_get_contents($app . '/config/app.php') === $owned, 'Recovered create-only file could not be repaired.');
	}
};
$tests['existing create-only edits survive recovery and unresolved tokens'] = static function () use ($base): void {
	$app = fixture($base, 'owned-edits');
	put($app . '/fail-dump', '1');
	command($app, ['install', '--environment=dev']);
	put($app . '/config/app.php', '<?php return ["local" => true];');
	unlink($app . '/config/citomni_installer.php');
	unlink($app . '/fail-dump');
	[$exit, , $out] = command($app, ['install', '--environment=dev']);
	check($exit === 0, 'Existing app-owned config should not require rendering inputs. ' . $out);
	check(file_get_contents($app . '/config/app.php') === '<?php return ["local" => true];', 'Create-only edit was overwritten.');
};
$tests['conflicting ordinary managed file is not adopted'] = static function () use ($base): void {
	$app = fixture($base, 'unknown-edits');
	put($app . '/bin/tool', 'local file');
	[$exit] = command($app, ['install', '--environment=dev']);
	check($exit === 4 && file_get_contents($app . '/bin/tool') === 'local file', 'Conflicting file was adopted or overwritten.');
	check(!ScaffoldState::forAppRoot($app)->exists(), 'Conflict committed initial state.');
};
$tests['global target collisions fail even behind a package filter'] = static function () use ($base): void {
	$app = fixture($base, 'collision');
	package($app, 'test/other', [['target' => 'bin/tool', 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'create-only']], ['install/other.stub' => 'other']);
	$locator = ScaffoldManifestLocator::forAppRoot($app);
	expectFailure(fn() => $locator->discover(), 'Cross-package collision was accepted.');
	expectFailure(fn() => $locator->discoverPackage('test/core'), 'Package filter hid a collision.');
	[$exit] = command($app, ['install', '--environment=dev']);
	check($exit !== 0 && !is_file($app . '/bin/tool'), 'Collision wrote a target.');
};
$tests['target aliases and file/directory overlaps fail before apply'] = static function () use ($base): void {
	$app = fixture($base, 'parent-collision');
	package($app, 'test/other', [['target' => 'bin', 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
	expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'File/directory collision was accepted.');
	$app = fixture($base, 'alias-collision');
	mkdir($app . '/bin');
	if (@symlink($app . '/bin', $app . '/alias')) {
		package($app, 'test/other', [['target' => 'alias/tool', 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
		expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'Symlink alias collision was accepted.');
	} else {
		echo "  SKIP Symlink alias check (creation unavailable).\n";
	}
};
$tests['installer metadata is reserved without excluding other app state'] = static function () use ($base): void {
	foreach ([ScaffoldState::RELATIVE_PATH, InstallerLock::RELATIVE_PATH, 'var/backups/citomni-installer/file', 'var/state'] as $index => $target) {
		$app = fixture($base, 'reserved-' . $index);
		package($app, 'test/other', [['target' => $target, 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
		expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'Installer metadata was accepted as scaffold.');
	}
	$app = fixture($base, 'unreserved');
	package($app, 'test/other', [['target' => 'var/state/citomni/other.php', 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
	check(count(ScaffoldManifestLocator::forAppRoot($app)->discover()) === 2, 'Unrelated application state was reserved.');
	mkdir($app . '/var/state/citomni', 0775, true);
	if (@symlink($app . '/var/state/citomni', $app . '/state-alias')) {
		package($app, 'test/alias', [['target' => 'state-alias/installer.lock', 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
		expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'Metadata alias was accepted as scaffold.');
	} else {
		echo "  SKIP Metadata alias check (symlink creation unavailable).\n";
	}
};
$tests['target containment protects vendor and handles Windows case explicitly'] = static function () use ($base): void {
	check(Path::isInside('C:/App/vendor', 'c:/APP/VENDOR/pkg/file', true), 'Windows path case was not folded.');
	check(!Path::isInside('C:/App/vendor', 'C:/App/vendor-other', true), 'Path boundary was lost.');
	check(!Path::isInside('/app/vendor', '/app/VENDOR/pkg', false), 'Case-sensitive path identity was lost.');
	$app = fixture($base, 'vendor-guard');
	$guard = new PathGuard($app);
	expectFailure(fn() => $guard->resolveTarget('vendor/test/core/file'), 'Vendor target was accepted.');
	check(is_file($guard->resolveSource($app . '/vendor/test/core', 'install/tool.stub')), 'Vendor source was rejected.');
	if (@symlink($app . '/vendor/test', $app . '/vendor-alias')) {
		expectFailure(fn() => $guard->resolveTarget('vendor-alias/core/file'), 'Vendor alias target was accepted.');
	} else {
		echo "  SKIP Vendor alias check (symlink creation unavailable).\n";
	}
	if (PHP_OS_FAMILY === 'Windows') {
		package($app, 'test/other', [['target' => 'BIN/TOOL', 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
		expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'Windows case alias collision was accepted.');
	} else {
		echo "  SKIP Windows filesystem case-collision check.\n";
	}
};
$tests['stale update and stale creation preserve intervening changes'] = static function () use ($base): void {
	$app = fixture($base, 'stale');
	installed($app);
	put($app . '/vendor/test/core/install/tool.stub', "tool version 2\n");
	[$plan, $apply] = engine($app, 'sync');
	put($app . '/bin/tool', 'editor change');
	$result = $apply->apply($plan);
	check(resultFile($result, 'bin/tool')['applied'] === 'conflict', 'Stale update did not conflict.');
	check(file_get_contents($app . '/bin/tool') === 'editor change', 'Stale update lost editor changes.');
	unlink($app . '/config/app.php');
	[$plan, $apply] = engine($app, 'repair');
	put($app . '/config/app.php', 'new app-owned file');
	$result = $apply->apply($plan);
	check(resultFile($result, 'config/app.php')['applied'] === 'conflict', 'Stale create did not conflict.');
	check(file_get_contents($app . '/config/app.php') === 'new app-owned file', 'Stale create overwrote a new file.');
};
$tests['unchanged environment targets are rechecked after planning'] = static function () use ($base): void {
	$app = fixture($base, 'stale-none');
	installed($app);
	[$plan, $apply] = engine($app, 'environment');
	put($app . '/public/environment.txt', 'editor change');
	$result = $apply->apply($plan, ['persist_state' => false]);
	check(!$result['ok'], 'Environment no-op accepted a changed target.');
};
$tests['equal bytes refresh source and placeholders for later repair'] = static function () use ($base): void {
	$app = fixture($base, 'same-bytes');
	put($app . '/vendor/test/core/install/dev.stub', 'example.test');
	put($app . '/vendor/test/core/install/prod.stub', '{{PROD_ROOT_HOST}}');
	installed($app);
	[$exit, $result, $out] = command($app, ['environment', 'prod', '--placeholder=PROD_ROOT_URL=https://example.test']);
	check($exit === 0, 'Same-byte switch failed. ' . $out);
	check(resultFile($result, 'public/environment.txt')['applied'] === 'registered', 'Same-byte switch failed to refresh state.');
	check($result['backup_dir'] === null, 'Same-byte switch created a backup.');
	unlink($app . '/public/environment.txt');
	[$exit, , $out] = command($app, ['repair']);
	check($exit === 0 && file_get_contents($app . '/public/environment.txt') === 'example.test', 'Repair lost prod rendering inputs. ' . $out);
};
$tests['package scope cannot establish a global initial environment'] = static function () use ($base): void {
	$app = fixture($base, 'initial-scope');
	[$exit] = command($app, ['install', '--environment=dev', '--package=test/core']);
	check($exit === 2 && !ScaffoldState::forAppRoot($app)->exists(), 'Scoped initial install committed global state.');
	installed($app);
	[$exit] = command($app, ['install', '--environment=dev', '--package=test/core']);
	check($exit === 0, 'Scoped install after initial materialization should remain supported.');
};
$tests['rapid repeated overwrites preserve independent backups'] = static function () use ($base): void {
	$app = fixture($base, 'backups');
	installed($app);
	$dirs = [];
	foreach (['first edit', 'second edit'] as $bytes) {
		put($app . '/bin/tool', $bytes);
		[$plan, $apply] = engine($app, 'sync', [], ['force' => true, 'target' => 'bin/tool']);
		$result = $apply->apply($plan);
		check($result['ok'], 'Forced overwrite failed.');
		$dirs[] = $result['backup_dir'];
		check(file_get_contents($result['backup_dir'] . '/bin/tool') === $bytes, 'Backup lost overwritten bytes.');
	}
	check($dirs[0] !== $dirs[1], 'Two apply calls reused a backup directory.');
	check(file_get_contents($dirs[0] . '/bin/tool') === 'first edit', 'Later backup replaced an earlier backup.');
};
$tests['Composer project and vendor overrides fail before scaffold writes'] = static function () use ($base): void {
	$app = fixture($base, 'composer-context');
	put($app . '/alternate.json', '{}');
	mkdir($app . '/other-vendor');
	foreach (['COMPOSER' => $app . '/alternate.json', 'COMPOSER_VENDOR_DIR' => $app . '/other-vendor'] as $key => $value) {
		putenv($key . '=' . $value);
		try {
			[$exit] = command($app, ['install', '--environment=dev']);
			check($exit !== 0 && !is_file($app . '/bin/tool'), 'Composer override wrote scaffold.');
			check(!ScaffoldState::forAppRoot($app)->exists(), 'Composer override committed state.');
		} finally {
			putenv($key);
		}
	}
};
$tests['missing Composer fails without materializing files or state'] = static function () use ($base): void {
	$app = fixture($base, 'composer-unavailable');
	put($app . '/fail-version', '1');
	[$exit] = command($app, ['install', '--environment=dev']);
	check($exit !== 0 && !is_file($app . '/bin/tool') && !ScaffoldState::forAppRoot($app)->exists(), 'Missing Composer materialized the app.');
};
$tests['app lock excludes another writer and is held throughout Composer'] = static function () use ($base): void {
	$app = fixture($base, 'lock');
	$lock = new InstallerLock(new PathGuard($app));
	$lock->acquire();
	try {
		[$exit] = command($app, ['install', '--environment=dev']);
		check($exit === 4 && !is_file($app . '/bin/tool'), 'Competing writer was not excluded.');
	} finally {
		$lock->release();
	}
	put($app . '/check-lock', '1');
	installed($app);
	check(is_file($app . '/' . InstallerLock::RELATIVE_PATH), 'Lock file was removed, permitting split locks.');
};
$tests['dry run writes neither scaffold state nor lock and prints Composer plan'] = static function () use ($base): void {
	$app = fixture($base, 'dry-run');
	$composer = file_get_contents($app . '/composer.json');
	[$exit, , $output] = command($app, ['install', '--environment=dev', '--dry-run'], false);
	check($exit === 0 && str_contains($output, 'classmap-authoritative=false') && str_contains($output, 'dump-autoload --no-scripts'), 'Text dry-run omitted Composer plan.');
	check(!is_file($app . '/bin/tool') && !is_dir($app . '/var'), 'Dry-run created scaffold, state or lock metadata.');
	check(file_get_contents($app . '/composer.json') === $composer, 'Dry-run changed composer.json.');
};
$tests['failed environment switch retains old state and is recoverable'] = static function () use ($base): void {
	$app = fixture($base, 'switch-recovery');
	$state = installed($app);
	$before = file_get_contents($state->path());
	put($app . '/fail-dump', '1');
	[$exit] = command($app, ['environment', 'prod']);
	check($exit !== 0 && file_get_contents($state->path()) === $before, 'Failed switch committed state or baselines.');
	check(file_get_contents($app . '/public/environment.txt') === "prod\n", 'Fixture failed before the intended Composer stage.');
	unlink($app . '/fail-dump');
	[$exit, $result] = command($app, ['environment', 'prod']);
	check($exit === 0 && $state->environment() === Environment::PROD, 'Environment retry failed.');
	check($result['backup_dir'] === null, 'Retry backed up already-correct files.');
};
$tests['unsafe state paths fail and doctor reports write-access failure'] = static function () use ($base): void {
	$app = fixture($base, 'state-symlink');
	mkdir($app . '/var', 0775, true);
	$outside = $base . '/external-state';
	mkdir($outside);
	if (!@symlink($outside, $app . '/var/state')) {
		echo "  SKIP State symlink check (creation unavailable).\n";
		return;
	}
	expectFailure(fn() => ScaffoldState::forAppRoot($app)->writePackagesAndEnvironment([], Environment::DEV), 'State escaped application root.');
	[$exit, $result] = command($app, ['doctor']);
	check($exit !== 0, 'Doctor accepted an unsafe state path.');
	$checks = array_column($result['checks'], null, 'name');
	check($checks['write_access']['status'] === 'fail', 'Doctor silently skipped unsafe write access.');
	check(scandir($outside) === ['.', '..'], 'Unsafe state write reached the external directory.');
};
$tests['atomic replacement preserves restrictive and executable Unix modes'] = static function () use ($base): void {
	if (PHP_OS_FAMILY === 'Windows') {
		echo "  SKIP Unix permission check (Windows).\n";
		return;
	}
	foreach ([0600, 0755] as $mode) {
		$path = $base . '/mode-' . $mode;
		put($path, 'before');
		chmod($path, $mode);
		AtomicFileWriter::write($path, 'after');
		clearstatcache(true, $path);
		check((fileperms($path) & 0777) === $mode && file_get_contents($path) === 'after', 'Atomic replacement changed file permissions.');
	}
};
$tests['foreign Composer InstalledVersions cannot redirect discovery'] = static function () use ($base): void {
	$app = fixture($base, 'discovery');
	$foreign = $base . '/foreign-package';
	mkdir($foreign);
	$code = '<?php namespace Composer; class InstalledVersions { public static function isInstalled($n) { return true; } public static function getInstallPath($n) { return ' . var_export($foreign, true) . '; } public static function getPrettyVersion($n) { return "foreign"; } }';
	put($base . '/foreign-versions.php', $code);
	require $base . '/foreign-versions.php';
	$data = ComposerPackageDiscovery::forAppRoot($app)->installedPackages()['test/core'];
	check($data['root'] === realpath($app . '/vendor/test/core') && $data['version'] === '1.0.0', 'Discovery mixed foreign autoload metadata into app packages.');
};
$tests['apply IO failures map to exit 6 and unknown status packages fail'] = static function () use ($base): void {
	$app = fixture($base, 'io-exit');
	installed($app);
	put($app . '/bin/tool', 'local edit');
	mkdir($app . '/bin/tool.new');
	[$exit, $result] = command($app, ['sync', 'bin/tool']);
	check($exit === 6 && resultFile($result, 'bin/tool')['error_type'] === 'io', 'Filesystem failure lost its IO exit code.');
	[$exit] = command($app, ['status', '--package=test/missing']);
	check($exit !== 0, 'Unknown status package falsely succeeded.');
};
$tests['migrate rebuilds legacy materialization from current manifests'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-legacy');
	put($app . '/public/environment.txt', "legacy dev\n");
	put($app . '/bin/tool', "legacy tool\n");
	$customConfig = "<?php return ['name' => 'local custom'];\n";
	put($app . '/config/app.php', $customConfig);
	$legacyState = [
		'format_version' => 1,
		'generated_by' => 'citomni/installer',
		'generated_at' => '2026-01-01T00:00:00+00:00',
		'packages' => ['obsolete/package' => ['files' => ['obsolete.txt' => ['policy' => 'managed']]]],
	];
	$statePath = $app . '/' . ScaffoldState::RELATIVE_PATH;
	put($statePath, '<?php return ' . var_export($legacyState, true) . ';');
	$legacyStateBytes = file_get_contents($statePath);

	[$exit, $result, $output] = command($app, ['migrate', '--environment=dev']);
	check($exit === 0, 'Legacy migration failed. ' . $output);
	check(file_get_contents($app . '/public/environment.txt') === "dev\n", 'Migration did not refresh the environment-aware target.');
	check(file_get_contents($app . '/bin/tool') === "tool version 1\n", 'Migration did not refresh an ordinary managed target.');
	check(file_get_contents($app . '/config/app.php') === $customConfig, 'Migration overwrote an existing create-only target.');

	$state = ScaffoldState::forAppRoot($app);
	check($state->environment() === Environment::DEV, 'Migration did not commit v2 dev state.');
	$validated = $state->read();
	check(($validated['format_version'] ?? null) === ScaffoldState::FORMAT_VERSION, 'Migration did not write the current state format.');
	check(!isset($validated['packages']['obsolete/package']), 'Migration copied obsolete legacy package state into v2.');

	$stateBackup = (string)$result['backup_dir'] . '/' . ScaffoldState::RELATIVE_PATH;
	check(is_file($stateBackup), 'Migration did not back up legacy state.');
	check(file_get_contents($stateBackup) === $legacyStateBytes, 'Legacy state backup bytes differ from the original.');
	check(is_file((string)$result['backup_dir'] . '/public/environment.txt'), 'Migration did not back up the replaced environment target.');
	check(is_file((string)$result['backup_dir'] . '/bin/tool'), 'Migration did not back up the replaced managed target.');
};

$tests['migrate dry-run is read-only and interrupted migration can resume without state'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-dry-run');
	put($app . '/public/environment.txt', "legacy dev\n");
	put($app . '/bin/tool', "legacy tool\n");
	$statePath = $app . '/' . ScaffoldState::RELATIVE_PATH;
	put($statePath, "<?php return ['format_version' => 1, 'generated_by' => 'citomni/installer', 'packages' => []];");
	$beforeState = file_get_contents($statePath);
	$beforeComposer = file_get_contents($app . '/composer.json');

	[$exit, $result, $output] = command($app, ['migrate', '--environment=dev', '--dry-run']);
	check($exit === 0, 'Migration dry-run failed. ' . $output);
	check(file_get_contents($statePath) === $beforeState, 'Migration dry-run changed legacy state.');
	check(file_get_contents($app . '/public/environment.txt') === "legacy dev\n", 'Migration dry-run changed scaffold.');
	check(file_get_contents($app . '/composer.json') === $beforeComposer, 'Migration dry-run changed Composer posture.');
	check(!is_dir((string)$result['backup_dir']), 'Migration dry-run created the planned backup directory.');

	unlink($statePath);
	[$exit, , $output] = command($app, ['migrate', '--environment=dev']);
	check($exit === 0, 'Migration could not resume after legacy state was already removed. ' . $output);
	check(ScaffoldState::forAppRoot($app)->environment() === Environment::DEV, 'Resumed migration did not commit current state.');
};

$tests['migrate rejects an already current application'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-current');
	installed($app);
	$before = file_get_contents(ScaffoldState::forAppRoot($app)->path());
	[$exit] = command($app, ['migrate', '--environment=dev']);
	check($exit === 4, 'Migration accepted an already-current v2 application.');
	check(file_get_contents(ScaffoldState::forAppRoot($app)->path()) === $before, 'Rejected migration changed current state.');
};

$tests['state format and environment-switch boundaries remain strict'] = static function () use ($base): void {
	$app = fixture($base, 'boundaries');
	$state = installed($app);
	$before = file_get_contents($state->path());
	[$exit] = command($app, ['install', '--environment=prod', '--force=yes']);
	check($exit === 4 && file_get_contents($state->path()) === $before, 'Install became an environment-switch interface.');
	[$exit] = command($app, ['sync', '--environment=prod']);
	check($exit === 2, 'Sync accepted an explicit environment.');
	put($state->path(), "<?php return ['format_version' => 1, 'packages' => []];");
	[$exit] = command($app, ['environment', 'prod']);
	check($exit === 5, 'Legacy state was migrated or accepted.');
};


// Optional actual Composer verification, separate from deterministic failure injection.
foreach (array_slice($argv, 1) as $argument) {
	if (!str_starts_with($argument, '--composer=')) {
		fwrite(STDERR, "Usage: php tests/installer-regression-test.php [--composer=/path/to/composer.phar]\n");
		removeTree($base);
		exit(2);
	}
	$composerPath = substr($argument, strlen('--composer='));
	$tests['real Composer materializes dev/stage/prod/dev and skips root scripts'] = static function () use ($base, $composerPath): void {
		$app = fixture($base, 'real composer');
		$config = [
			'name' => 'test/app',
			'version' => '1.0.0',
			'require' => ['php' => '>=8.5'],
			'autoload' => ['psr-4' => ['TestApp\\' => 'src/']],
			'config' => ['classmap-authoritative' => false, 'optimize-autoloader' => true],
			'scripts' => ['post-autoload-dump' => '@php scripts/on-dump.php'],
		];
		put($app . '/composer.json', json_encode($config));
		put($app . '/src/Known.php', '<?php namespace TestApp; final class Known {}');
		put($app . '/scripts/on-dump.php', '<?php file_put_contents(__DIR__ . "/../root-script-ran", "1");');
		$oldComposerHome = getenv('COMPOSER_HOME');
		$oldSuperuser = getenv('COMPOSER_ALLOW_SUPERUSER');
		$composerHome = $base . '/composer-home';
		mkdir($composerHome);
		putenv('COMPOSER_HOME=' . $composerHome);
		putenv('COMPOSER_ALLOW_SUPERUSER=1');
		try {
			$guard = new PathGuard($app);
			$state = ScaffoldState::forAppRoot($app);
			$renderer = new ScaffoldRenderer();
			$locator = ScaffoldManifestLocator::forAppRoot($app);
			$builder = new BuildScaffoldPlan($guard, $renderer, $state);
			$applier = new ApplyScaffoldPlan($guard, $renderer, $state);
			$materializer = new ApplyEnvironmentMaterialization($applier, new ComposerRunner($app, $composerPath), $state);
			$lock = new InstallerLock($guard);
			foreach ([Environment::DEV, Environment::STAGE, Environment::PROD, Environment::DEV] as $i => $environment) {
				$lock->acquire();
				try {
					$manifests = $locator->selectEnvironment($locator->discover(), $environment, $i !== 0);
					$values = array_fill_keys(array_keys($manifests), ['APP_NAME' => 'fixture']);
					$plan = $builder->build($i === 0 ? 'install' : 'environment', $manifests, $values, ['environment_materialization' => true]);
					$result = $materializer->apply($plan, $environment);
					check($result['ok'] && $state->environment() === $environment, 'Real Composer failed materialization.');
				} finally {
					$lock->release();
				}
				[$exit, $out, $err] = child([PHP_BINARY, '-r', '$loader = require "vendor/autoload.php"; echo json_encode([$loader->isClassMapAuthoritative(), class_exists("TestApp\\\\Known")]);'], $app);
				check($exit === 0 && json_decode($out, true) === [$environment->classmapAuthoritative(), true], 'Actual generated autoloader posture or class loading is wrong. ' . $out . $err);
				check(!is_file($app . '/root-script-ran'), 'Composer executed a root lifecycle script.');
			}
		} finally {
			putenv($oldComposerHome === false ? 'COMPOSER_HOME' : 'COMPOSER_HOME=' . $oldComposerHome);
			putenv($oldSuperuser === false ? 'COMPOSER_ALLOW_SUPERUSER' : 'COMPOSER_ALLOW_SUPERUSER=' . $oldSuperuser);
		}
	};
}

$failed = 0;
try {
	foreach ($tests as $name => $test) {
		try {
			$test();
			echo 'PASS ' . $name . "\n";
		} catch (Throwable $e) {
			$failed++;
			fwrite(STDERR, 'FAIL ' . $name . ': ' . $e->getMessage() . "\n");
		}
	}
} finally {
	foreach ($savedEnvironment as $key => $value) {
		putenv($value === false ? $key : $key . '=' . $value);
	}
	removeTree($base);
}
echo sprintf("\n%d tests, %d failures.\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
