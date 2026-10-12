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
use CitOmni\Installer\Operation\AuditAppSkeletonMigration;
use CitOmni\Installer\Operation\BuildScaffoldPlan;
use CitOmni\Installer\Operation\BuildMigrationDiagnostics;
use CitOmni\Installer\State\ScaffoldState;
use CitOmni\Installer\Support\AtomicFileWriter;
use CitOmni\Installer\Support\ComposerPackageDiscovery;
use CitOmni\Installer\Support\ComposerRunner;
use CitOmni\Installer\Support\InstallerLock;
use CitOmni\Installer\Support\MigrationReportWriter;
use CitOmni\Installer\Support\PlaceholderResolver;
use CitOmni\Installer\Support\PathGuard;
use CitOmni\Installer\Support\ScaffoldManifestLocator;
use CitOmni\Installer\Support\ScaffoldRenderer;
use CitOmni\Installer\Util\GitignoreBlock;
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
function package(string $app, string $name, array $files, array $stubs, ?array $gitignore = null): void {
	$root = $app . '/vendor/' . $name;
	foreach ($stubs as $path => $bytes) {
		put($root . '/' . $path, $bytes);
	}
	manifest($app, $name, $files, $gitignore);
	$path = $app . '/vendor/composer/installed.json';
	$data = is_file($path) ? json_decode(file_get_contents($path), true) : ['packages' => []];
	$data['packages'][] = ['name' => $name, 'version' => '1.0.0', 'install-path' => '../' . $name];
	put($path, json_encode($data));
}

/** Write (or rewrite) one installed package's schema 2 manifest. */
function manifest(string $app, string $name, array $files, ?array $gitignore = null): void {
	$manifest = ['package' => $name, 'version' => 2, 'files' => $files];
	if ($gitignore !== null) {
		$manifest['gitignore'] = $gitignore;
	}
	put($app . '/vendor/' . $name . '/install/manifest.php', '<?php return ' . var_export($manifest, true) . ';');
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
$tests['environment target contract exposes only environment materialization ownership'] = static function () use ($base): void {
	$app = fixture($base, 'environment-targets');
	package($app, 'test/other', [
		['target' => 'config/environment-switch.php', 'type' => 'config', 'policy' => 'managed', 'environments' => [
			'dev' => ['source' => 'install/dev.stub'],
			'stage' => ['source' => 'install/stage.stub'],
			'prod' => ['source' => 'install/prod.stub'],
		]],
		['target' => 'config/ordinary.php', 'source' => 'install/ordinary.stub', 'type' => 'config', 'policy' => 'managed'],
	], [
		'install/dev.stub' => "dev\n",
		'install/stage.stub' => "stage\n",
		'install/prod.stub' => "prod\n",
		'install/ordinary.stub' => "ordinary\n",
	]);

	$locator = ScaffoldManifestLocator::forAppRoot($app);
	$targets = $locator->environmentTargets($locator->discover());

	check($targets === [
		['package' => 'test/other', 'target' => 'config/environment-switch.php'],
		['package' => 'test/core', 'target' => 'public/environment.txt'],
	], 'Environment target contract was incomplete, included source-only files, or was not deterministic.');
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
$tests['root URL placeholders reject trailing slashes'] = static function () use ($base): void {
	$app = fixture($base, 'root-url-trailing-slash');

	put($app . '/config/citomni_installer.php', "<?php return ['placeholders' => ['PROD_ROOT_URL' => 'https://www.example.test/']];");
	try {
		(new PlaceholderResolver($app))->resolve();
		throw new RuntimeException('Trailing slash in PROD_ROOT_URL was accepted.');
	} catch (InstallerException $e) {
		check(
			$e->getMessage() === 'Placeholder {{PROD_ROOT_URL}} in installer config must not end with "/". Remove the trailing slash and retry.',
			'PROD_ROOT_URL trailing-slash error was not precise.'
		);
	}

	put($app . '/config/citomni_installer.php', "<?php return ['placeholders' => ['STAGE_ROOT_URL' => 'https://stage.example.test/']];");
	try {
		(new PlaceholderResolver($app))->resolve();
		throw new RuntimeException('Trailing slash in STAGE_ROOT_URL was accepted.');
	} catch (InstallerException $e) {
		check(
			$e->getMessage() === 'Placeholder {{STAGE_ROOT_URL}} in installer config must not end with "/". Remove the trailing slash and retry.',
			'STAGE_ROOT_URL trailing-slash error was not precise.'
		);
	}

	put($app . '/config/citomni_installer.php', "<?php return ['placeholders' => ['PROD_ROOT_URL' => 'https://www.example.test', 'STAGE_ROOT_URL' => 'https://stage.example.test']];");
	try {
		(new PlaceholderResolver($app))->resolve(['PROD_ROOT_URL' => 'https://override.example.test/']);
		throw new RuntimeException('Trailing slash in CLI PROD_ROOT_URL override was accepted.');
	} catch (InstallerException $e) {
		check(
			$e->getMessage() === 'Placeholder {{PROD_ROOT_URL}} in CLI overrides must not end with "/". Remove the trailing slash and retry.',
			'CLI root URL trailing-slash error was not precise.'
		);
	}

	$resolved = (new PlaceholderResolver($app))->resolve();
	check(($resolved['PROD_ROOT_URL'] ?? null) === 'https://www.example.test', 'Canonical PROD_ROOT_URL was not preserved.');
	check(($resolved['PROD_ROOT_HOST'] ?? null) === 'www.example.test', 'PROD_ROOT_HOST derivation changed.');
	check(($resolved['STAGE_ROOT_URL'] ?? null) === 'https://stage.example.test', 'Canonical STAGE_ROOT_URL was not preserved.');
	check(($resolved['STAGE_ROOT_HOST'] ?? null) === 'stage.example.test', 'STAGE_ROOT_HOST derivation changed.');
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
	check($exit === 0, 'Create-only app differences must be advisory during migrate. ' . $output);
	check(file_get_contents($app . '/public/environment.txt') === "dev\n", 'Migration did not refresh the environment-aware target.');
	check(file_get_contents($app . '/bin/tool') === "tool version 1\n", 'Migration did not refresh an ordinary managed target.');
	check(file_get_contents($app . '/config/app.php') === $customConfig, 'Migration overwrote an existing create-only target.');
	check(is_file($app . '/config/app.php.new'), 'Migration did not write the create-only review sidecar.');
	check(file_get_contents($app . '/config/app.php.new') === "<?php return ['name' => 'fixture'];\n", 'Migration sidecar does not contain the current rendered scaffold.');
	check(($result['state_written'] ?? false) === true, 'Advisory create-only review prevented v2 state commit.');
	check(($result['composer']['applied'] ?? false) === true, 'Advisory create-only review prevented Composer posture.');
	check(($result['migration']['blocking_manual_review'] ?? ['unexpected']) === [], 'Create-only review was incorrectly classified as blocking.');
	$advisory = $result['migration']['advisory_manual_review'] ?? [];
	check(count($advisory) === 1 && ($advisory[0]['target'] ?? null) === 'config/app.php', 'Create-only review was not classified as advisory.');

	$backupDir = (string)$result['backup_dir'];
	$stateBackup = $backupDir . '/' . ScaffoldState::RELATIVE_PATH;
	check(is_file($stateBackup), 'Migration did not back up legacy state.');
	check(file_get_contents($stateBackup) === $legacyStateBytes, 'Legacy state backup bytes differ from the original.');
	check(is_file($backupDir . '/public/environment.txt'), 'Migration did not back up the replaced environment target.');
	check(is_file($backupDir . '/bin/tool'), 'Migration did not back up the replaced managed target.');

	$state = ScaffoldState::forAppRoot($app);
	check($state->environment() === Environment::DEV, 'Migration did not commit v2 dev state.');
	$validated = $state->read();
	check(($validated['format_version'] ?? null) === ScaffoldState::FORMAT_VERSION, 'Migration did not write the current state format.');
	check(!isset($validated['packages']['obsolete/package']), 'Migration copied obsolete legacy package state into v2.');
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

$tests['migrate reconciles an already current v2 application and defaults to dev'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-current');
	installed($app);

	[$exit, $result, $output] = command($app, ['migrate']);
	check($exit === 0, 'Current v2 migration reconciliation failed. ' . $output);
	check(($result['environment'] ?? null) === 'dev', 'Migrate without --environment did not default to dev.');
	check(($result['migration']['mode'] ?? null) === 'reconcile_v2', 'Current v2 migrate did not enter reconciliation mode.');
	check(ScaffoldState::forAppRoot($app)->environment() === Environment::DEV, 'Current v2 reconciliation lost the recorded environment.');

	put($app . '/bin/tool', "local managed edit\n");
	put($app . '/bin/tool.new', "stale generated sidecar\n");
	[$exit, $result, $output] = command($app, ['migrate']);
	check($exit === 4, 'Locally modified managed v2 target did not require manual review. ' . $output);
	check(file_get_contents($app . '/bin/tool') === "local managed edit\n", 'V2 migrate overwrote a locally modified managed target.');
	check(file_get_contents($app . '/bin/tool.new') === "tool version 1\n", 'V2 migrate did not overwrite the stale managed .new sidecar with current desired bytes.');
};

$tests['blocked v2 migrate can adopt managed bytes already advanced by the previous attempt'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-forward-recovery');
	installed($app);
	put($app . '/vendor/test/core/install/tool.stub', "tool version 2\n");
	put($app . '/public/environment.txt', "local managed edit\n");

	[$exit, $result, $output] = command($app, ['migrate']);
	check($exit === 4, 'Expected locally modified managed target to block state commit. ' . $output);
	check(file_get_contents($app . '/bin/tool') === "tool version 2\n", 'First blocked migrate did not advance the clean managed target.');
	check(file_get_contents($app . '/public/environment.txt') === "local managed edit\n", 'Blocked migrate overwrote the locally modified managed target.');
	check(!($result['state_written'] ?? true), 'Blocked migrate unexpectedly committed v2 state.');

	[$exit, $result, $output] = command($app, ['migrate']);
	check($exit === 4, 'Second migrate should still report the unresolved managed local difference. ' . $output);
	$tool = resultFile($result, 'bin/tool');
	check(($tool['applied'] ?? null) === 'registered', 'Second migrate misclassified already-current managed bytes as a local edit.');
	check(($tool['reason'] ?? null) === 'adopt_current_migration_baseline', 'Second migrate did not use forward-recovery baseline adoption.');
};

$tests['migrate default dev safely switches a current v2 prod environment'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-default-dev');
	installed($app);
	[$exit, , $output] = command($app, ['environment', 'prod']);
	check($exit === 0, 'Fixture could not switch to prod before default-dev migrate. ' . $output);
	check(ScaffoldState::forAppRoot($app)->environment() === Environment::PROD, 'Fixture did not enter prod.');

	[$exit, $result, $output] = command($app, ['migrate']);
	check($exit === 0, 'Migrate without environment did not safely switch current v2 prod to dev. ' . $output);
	check(($result['environment'] ?? null) === 'dev', 'Default migrate environment was not dev.');
	check(ScaffoldState::forAppRoot($app)->environment() === Environment::DEV, 'Default migrate did not persist dev environment.');
	check(file_get_contents($app . '/public/environment.txt') === "dev\n", 'Default migrate did not materialize dev environment bytes.');
	$environmentFile = resultFile($result, 'public/environment.txt');
	check(isset($environmentFile['backup_path']), 'Default prod-to-dev reconciliation did not back up the environment-aware target.');
};

$tests['migrate PHP lint failures are reported and return manual-action exit'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-lint');
	installed($app);
	put($app . '/src/Bad.php', "<?php\nreturn [;\n");

	[$exit, $result, $output] = command($app, ['migrate']);
	check($exit === 4, 'PHP lint failure did not produce manual-action exit. ' . $output);
	$lint = $result['migration']['php_lint'] ?? null;
	check(is_array($lint) && ($lint['failed'] ?? 0) === 1, 'Migration did not report the PHP lint failure.');
	$paths = array_column($lint['files'] ?? [], 'path');
	check(in_array('src/Bad.php', $paths, true), 'Migration PHP lint omitted the invalid app source.');
	$reportPath = (string)($result['migration_report'] ?? '');
	check(is_file($reportPath), 'Migration with lint failure did not write its report.');
	$report = file_get_contents($reportPath);
	check(str_contains($report, '## PHP lint') && str_contains($report, 'src/Bad.php'), 'Migration report omitted PHP lint output.');
};

$tests['app-skeleton audit ignores line endings and one final newline but reports content drift'] = static function () use ($base): void {
	$app = $base . '/apps/app-skeleton-text-compare';
	mkdir($app, 0775, true);
	put($app . '/.gitignore', "vendor\r\nvar/cache");

	$audit = new AuditAppSkeletonMigration(new PathGuard($app));
	$reference = [
		'sha' => str_repeat('a', 40),
		'files' => [
			'.gitignore' => [
				'url' => 'https://example.invalid/.gitignore',
				'bytes' => "vendor\nvar/cache\n",
			],
		],
	];

	$result = $audit->apply($reference, false);
	check(($result['files'][0]['status'] ?? null) === 'identical', 'Line-ending/final-newline-only app-skeleton difference was not ignored.');
	check(($result['manual_review'] ?? ['unexpected']) === [], 'Line-ending/final-newline-only app-skeleton difference became advisory.');
	check(!is_file($app . '/.gitignore.new'), 'Line-ending/final-newline-only app-skeleton difference wrote a sidecar.');

	put($app . '/.gitignore', "vendor\r\nvar/logs\r\n");
	$result = $audit->apply($reference, false);
	check(($result['files'][0]['status'] ?? null) === 'different', 'Real app-skeleton content drift was ignored.');
	check(count($result['manual_review'] ?? []) === 1, 'Real app-skeleton content drift was not advisory.');
	check(file_get_contents($app . '/.gitignore.new') === "vendor\nvar/cache\n", 'App-skeleton content drift sidecar does not contain current reference bytes.');
};

$tests['migrate lists percent-style source files when missing placeholders stop early'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-source-warning-early');
	installed($app);
	put(
		$app . '/vendor/test/core/install/config.stub',
		"<?php\n// %LEGACY_TOKEN%\nreturn ['name' => '{{MISSING_NAME}}'];\n"
	);

	[$exit, $result] = command($app, ['migrate']);
	check($exit === 1, 'Missing placeholder did not stop migration before apply.');
	$error = (string)($result['error'] ?? '');
	check(str_contains($error, 'MISSING_NAME'), 'Early migration error omitted the missing placeholder.');
	check(str_contains($error, 'test/core — install/config.stub → config/app.php'), 'Early migration error omitted the percent-style source and target.');
};

$tests['migrate reports percent-style tokens in current scaffold sources without blocking'] = static function () use ($base): void {
	$app = fixture($base, 'migrate-source-warning');
	installed($app);
	put(
		$app . '/vendor/test/core/install/config.stub',
		"<?php\n// %LEGACY_TOKEN%\nreturn ['name' => '{{APP_NAME}}'];\n"
	);

	[$exit, $result, $output] = command($app, ['migrate']);
	check($exit === 0, 'Advisory percent-style source token changed migrate exit behavior. ' . $output);
	$warnings = $result['migration']['scaffold_source_warnings'] ?? null;
	check(is_array($warnings) && ($warnings['file_count'] ?? 0) === 1, 'Migration did not report the current scaffold source warning.');
	check(($warnings['token_count'] ?? 0) === 1, 'Migration source warning token count is wrong.');
	check(($warnings['files'][0]['tokens'][0] ?? null) === '%LEGACY_TOKEN%', 'Migration source warning omitted the percent-style token.');
	$reportPath = (string)($result['migration_report'] ?? '');
	check(is_file($reportPath), 'Migration source warning did not produce its report.');
	$report = file_get_contents($reportPath);
	check(str_contains($report, '## Current scaffold source warnings') && str_contains($report, '%LEGACY_TOKEN%'), 'Migration report omitted the source warning details.');
};

$tests['migration report sorts create-only review by app path and uses readable unique names'] = static function () use ($base): void {
	$app = $base . '/apps/migration-report-order';
	mkdir($app, 0775, true);

	$result = [
		'environment' => 'dev',
		'ok' => true,
		'state_written' => true,
		'packages' => [],
		'migration' => [
			'mode' => 'rebuild_legacy',
			'blocking_manual_review' => [],
			'advisory_manual_review' => [
				['package' => 'citomni/http', 'target' => 'templates/public/helloworld.html', 'reason' => 'migration_content_difference'],
				['package' => 'citomni/kernel', 'target' => 'config/providers.php', 'reason' => 'migration_php_code_difference'],
				['package' => 'citomni/http', 'target' => 'src/Http/Controller/AppController.php', 'reason' => 'migration_php_code_difference'],
				['package' => 'citomni/http', 'target' => 'config/citomni_http_cfg.php', 'reason' => 'migration_php_code_difference'],
				['package' => 'citomni/cli', 'target' => 'config/citomni_cli_cfg.php', 'reason' => 'migration_php_code_difference'],
			],
			'app_skeleton' => ['available' => false, 'manual_review' => [], 'error' => 'fixture'],
			'installer_config' => [],
			'php_lint' => [],
			'scaffold_source_warnings' => ['file_count' => 0, 'files' => []],
			'scaffold_diffs' => [],
		],
	];

	$writer = new MigrationReportWriter(new PathGuard($app));
	$firstPath = $writer->write($result);
	$secondPath = $writer->write($result);
	check($firstPath !== $secondPath, 'Migration report naming reused an existing path.');
	check(
		preg_match('/^citomni-installer-migration-\\d{8}T\\d{4}Z(?:-\\d+)?\\.md$/', basename($firstPath)) === 1,
		'Migration report filename is not human-readable.'
	);
	check(
		preg_match('/^citomni-installer-migration-\\d{8}T\\d{4}Z(?:-\\d+)?\\.md$/', basename($secondPath)) === 1,
		'Second migration report filename is not human-readable.'
	);

	$report = file_get_contents($firstPath);
	$ordered = [
		'`citomni/cli: config/citomni_cli_cfg.php`',
		'`citomni/http: config/citomni_http_cfg.php`',
		'`citomni/kernel: config/providers.php`',
		'`citomni/http: src/Http/Controller/AppController.php`',
		'`citomni/http: templates/public/helloworld.html`',
	];
	$previous = -1;
	foreach ($ordered as $line) {
		$position = strpos($report, $line);
		check($position !== false, 'Migration report omitted expected advisory item: ' . $line);
		check($position > $previous, 'Migration report advisory items are not sorted by app target path.');
		$previous = $position;
	}
};


$tests['migration diagnostics omit secret and sensitive config diffs while keeping safe diffs'] = static function () use ($base): void {
	$app = $base . '/apps/secret-diff-redaction';
	$pkg = $base . '/secret-diff-package';
	mkdir($app . '/var/secrets', 0775, true);
	mkdir($app . '/config', 0775, true);
	mkdir($pkg, 0775, true);

	$secretValue = 'LOCAL_SUPER_SECRET_123';
	$configSecretValue = 'LOCAL_SMTP_PASSWORD_456';
	put($app . '/var/secrets/app.secret.dev.php', "<?php return ['secret' => '{$secretValue}'];\n");
	put($pkg . '/secret.stub', "<?php return ['secret' => 'REPLACE_ME'];\n");
	put($app . '/var/secrets/example.php.tpl', "<?php return ['example' => 'LOCAL_TEMPLATE'];\n");
	put($pkg . '/template.stub', "<?php return ['example' => 'CURRENT_TEMPLATE'];\n");
	put($app . '/config/mail.php', "<?php return ['smtp' => ['smtp_password' => '{$configSecretValue}']];\n");
	put($pkg . '/mail.stub', "<?php return ['smtp' => ['smtp_password' => 'CURRENT_PLACEHOLDER']];\n");
	put($app . '/config/safe.php', "<?php\n// password is documentation only\nreturn ['label' => 'password', 'mode' => 'LOCAL'];\n");
	put($pkg . '/safe.stub', "<?php return ['label' => 'password', 'mode' => 'CURRENT'];\n");

	$plan = [
		'packages' => [[
			'name' => 'test/secrets',
			'files' => [
				[
					'target' => 'var/secrets/app.secret.dev.php',
					'action' => 'write_new',
					'reason' => 'migration_php_code_difference',
					'_apply' => [
						'target_abs' => $app . '/var/secrets/app.secret.dev.php',
						'source_abs' => $pkg . '/secret.stub',
						'placeholders' => [],
					],
				],
				[
					'target' => 'var/secrets/example.php.tpl',
					'action' => 'update',
					'reason' => 'migration_template_refresh',
					'_apply' => [
						'target_abs' => $app . '/var/secrets/example.php.tpl',
						'source_abs' => $pkg . '/template.stub',
						'placeholders' => [],
					],
				],
				[
					'target' => 'config/mail.php',
					'action' => 'write_new',
					'reason' => 'migration_php_code_difference',
					'_apply' => [
						'target_abs' => $app . '/config/mail.php',
						'source_abs' => $pkg . '/mail.stub',
						'placeholders' => [],
					],
				],
				[
					'target' => 'config/safe.php',
					'action' => 'write_new',
					'reason' => 'migration_php_code_difference',
					'_apply' => [
						'target_abs' => $app . '/config/safe.php',
						'source_abs' => $pkg . '/safe.stub',
						'placeholders' => [],
					],
				],
			],
		]],
	];

	$diagnostics = new BuildMigrationDiagnostics(new ScaffoldRenderer(), new PlaceholderResolver($app));
	$details = $diagnostics->inspectPlan($plan)['scaffold_diffs'];
	$secret = $details[0];
	$template = $details[1];
	$configSecret = $details[2];
	$safeConfig = $details[3];
	check(($secret['diff_omitted_reason'] ?? null) === 'sensitive_target', 'Sensitive secret target was not marked as redacted.');
	check(!isset($secret['diff']), 'Sensitive secret target still contains diff content.');
	check(($configSecret['diff_omitted_reason'] ?? null) === 'sensitive_config_key', 'Sensitive config key was not marked as redacted.');
	check(!isset($configSecret['diff']), 'Sensitive config target still contains diff content.');
	$encodedDetails = json_encode($details);
	check(!str_contains($encodedDetails, $secretValue), 'Sensitive local secret leaked into migration diagnostics JSON.');
	check(!str_contains($encodedDetails, $configSecretValue), 'Sensitive config value leaked into migration diagnostics JSON.');
	check(str_contains((string)($template['diff'] ?? ''), 'LOCAL_TEMPLATE'), 'Secret template target unexpectedly lost its review diff.');
	check(str_contains((string)($safeConfig['diff'] ?? ''), "'mode' => 'LOCAL'"), 'Config comment/value containing a sensitive word incorrectly suppressed the diff.');

	$result = [
		'environment' => 'dev',
		'ok' => false,
		'state_written' => false,
		'packages' => [[
			'name' => 'test/secrets',
			'files' => [
				[
					'target' => 'var/secrets/app.secret.dev.php',
					'applied' => 'wrote_new',
					'reason' => 'migration_php_code_difference',
					'new_path' => $app . '/var/secrets/app.secret.dev.php.new',
				],
				[
					'target' => 'config/mail.php',
					'applied' => 'wrote_new',
					'reason' => 'migration_php_code_difference',
					'new_path' => $app . '/config/mail.php.new',
				],
			],
		]],
		'migration' => [
			'mode' => 'rebuild_legacy',
			'blocking_manual_review' => [],
			'app_skeleton' => ['available' => false, 'manual_review' => [], 'error' => 'fixture'],
			'installer_config' => [],
			'php_lint' => [],
			'scaffold_source_warnings' => ['file_count' => 0, 'files' => []],
			'scaffold_diffs' => [$secret, $configSecret],
		],
	];
	$reportPath = (new MigrationReportWriter(new PathGuard($app)))->write($result);
	$report = file_get_contents($reportPath);
	check(str_contains($report, 'Diff: omitted because the target is sensitive'), 'Migration report omitted the sensitive-target notice.');
	check(str_contains($report, 'Diff: omitted because the config target contains a potentially sensitive key'), 'Migration report omitted the sensitive-config notice.');
	check(!str_contains($report, $secretValue), 'Migration report leaked the local secret value.');
	check(!str_contains($report, $configSecretValue), 'Migration report leaked the sensitive config value.');
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

$tests['install leaves a clean drifted environment baseline to sync'] = static function () use ($base): void {
	$app = fixture($base, 'environment-drift');
	installed($app);
	put($app . '/vendor/test/core/install/dev.stub', "dev version 2\n");

	[$exit, $result, $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0, 'Install treated a clean drifted baseline of the same environment as a conflict. ' . $output);
	$target = resultFile($result, 'public/environment.txt');
	check($target['status'] === 'update_available' && $target['applied'] === 'skipped', 'Install did not leave the drifted environment target to sync.');
	check(file_get_contents($app . '/public/environment.txt') === "dev\n", 'Install rewrote a clean drifted environment target.');

	[$exit] = command($app, ['status']);
	check($exit === 3, 'Status did not report the drifted environment target as an update.');
	[$exit, , $output] = command($app, ['sync']);
	check($exit === 0 && file_get_contents($app . '/public/environment.txt') === "dev version 2\n", 'Sync did not update the drifted environment target. ' . $output);
};

$tests['install names every blocking target before writing'] = static function () use ($base): void {
	$app = fixture($base, 'environment-conflict');
	put($app . '/public/environment.txt', "stale dev\n");
	put($app . '/bin/tool', "local tool\n");

	[$exit, $result] = command($app, ['install', '--environment=dev']);
	$error = (string)($result['error'] ?? '');
	check($exit === 4, 'An unrecognized environment-aware target did not conflict.');
	check(str_contains($error, '- test/core public/environment.txt: unknown_existing'), 'Conflict message did not name the environment-aware target. ' . $error);
	check(str_contains($error, '- test/core bin/tool: unknown_existing'), 'Conflict message omitted an ordinary blocking target. ' . $error);
	check(str_contains($error, 'cannot be told apart'), 'Conflict message did not explain unknown_existing.');
	$files = $result['packages'][0]['files'] ?? [];
	check(array_column($files, 'target') === ['public/environment.txt', 'bin/tool'], 'JSON did not list exactly the blocking targets.');
	check(array_unique(array_column($files, 'action')) === ['conflict'], 'JSON blocking targets lost their conflict action.');
	check(!is_file($app . '/config/app.php') && !ScaffoldState::forAppRoot($app)->exists(), 'A blocked install wrote scaffold or state.');

	[$exit, , $output] = command($app, ['install', '--environment=dev'], false);
	check($exit === 4 && str_contains($output, 'public/environment.txt: unknown_existing'), 'Text output omitted the blocking target.');
};

$tests['install reports a local edit of an environment-aware target'] = static function () use ($base): void {
	$app = fixture($base, 'environment-local-edit');
	installed($app);
	put($app . '/public/environment.txt', "edited\n");

	[$exit, $result] = command($app, ['install', '--environment=dev']);
	$error = (string)($result['error'] ?? '');
	check($exit === 4 && str_contains($error, 'public/environment.txt: local_modified'), 'Local edit was not reported as local_modified. ' . $error);
	check(!str_contains($error, 'different_environment'), 'Local edit was still reported as another environment.');
	check(file_get_contents($app . '/public/environment.txt') === "edited\n", 'Blocked install overwrote a local edit.');
};

$tests['gitignore block follows every installed manifest'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-block');
	$runtime = ['/var/cache/*', '/var/backups/*', '/var/backups/flags/*', '!/var/cache/.gitkeep', '!/var/backups/flags/', '!/var/backups/flags/.gitkeep'];
	package($app, 'test/runtime', [], [], $runtime);
	$appRules = "# Application rules\n/node_modules/";
	put($app . '/.gitignore', $appRules);

	[$exit, $result, $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0, 'Install with a .gitignore failed. ' . $output);
	check(($result['gitignore']['applied'] ?? null) === 'updated' && ($result['gitignore']['reason'] ?? null) === 'block_missing', 'Install did not insert the block.');
	check(($result['gitignore']['newly_ignored'] ?? null) === ['bin/tool', 'public/environment.txt'], 'Newly ignored managed targets were not reported.');

	$bytes = file_get_contents($app . '/.gitignore');
	check(str_starts_with($bytes, GitignoreBlock::BEGIN . "\n"), 'The block was not inserted at the top.');
	check(str_ends_with($bytes, GitignoreBlock::END . "\n\n" . $appRules), 'Application rules after the block changed.');
	$order = ['/var/backups/*', '/var/backups/flags/*', '/var/cache/*', '!/var/backups/flags/', '!/var/backups/flags/.gitkeep', '!/var/cache/.gitkeep', '/bin/tool', '/public/environment.txt'];
	$previous = -1;
	foreach ($order as $line) {
		$position = strpos($bytes, "\n" . $line . "\n");
		check($position !== false && $position > $previous, 'Block line missing or out of order: ' . $line);
		$previous = $position;
	}
	check(!str_contains($bytes, '/config/app.php'), 'A create-only target was ignored.');

	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && ($status['gitignore']['status'] ?? null) === 'up_to_date', 'Status did not accept the fresh block.');
	foreach ([['sync', '--package=test/core'], ['install', '--environment=dev', '--package=test/core']] as $scoped) {
		[$exit, $result] = command($app, $scoped);
		check($exit === 0 && ($result['gitignore']['applied'] ?? null) === 'skipped', 'A package-scoped run changed the block: ' . implode(' ', $scoped));
		check(file_get_contents($app . '/.gitignore') === $bytes, 'A package-scoped run dropped rules of other packages.');
	}

	manifest($app, 'test/runtime', [], [...['/var/logs/*'], ...$runtime]);
	[$exit, $status] = command($app, ['status', '--package=test/core']);
	check($exit === 3 && ($status['gitignore']['reason'] ?? null) === 'block_drift', 'Status did not report a stale block.');
	[$exit, $result] = command($app, ['sync', 'bin/tool']);
	check($exit === 0 && array_key_exists('gitignore', $result) && $result['gitignore'] === null, 'A single-target sync planned the block.');
	[$exit, $result] = command($app, ['sync', '--dry-run']);
	check($exit === 0 && ($result['gitignore']['applied'] ?? null) === 'updated' && file_get_contents($app . '/.gitignore') === $bytes, 'Dry-run sync wrote the block.');
	[$exit, $result] = command($app, ['sync']);
	$updated = file_get_contents($app . '/.gitignore');
	check($exit === 0 && str_contains($updated, "\n/var/logs/*\n") && ($result['gitignore']['newly_ignored'] ?? null) === [], 'Sync did not refresh the block.');
	check(str_ends_with($updated, $appRules), 'Sync changed application rules.');

	put($app . '/.gitignore', str_replace("\n/var/logs/*\n", "\n/var/logs/*\n/local-edit\n", $updated));
	[$exit, $result] = command($app, ['sync']);
	check($exit === 0 && file_get_contents($app . '/.gitignore') === $updated, 'Sync did not restore an edited block.');

	put($app . '/.gitignore', $appRules);
	[$exit, $result, $output] = command($app, ['migrate']);
	check(($result['gitignore']['applied'] ?? null) === 'updated' && file_get_contents($app . '/.gitignore') === $updated, 'Migrate did not restore the block. ' . $output);
};

$tests['gitignore block keeps CRLF and follows other tool blocks'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-crlf');
	$devkit = "###> citomni/devkit ###\r\n/_*/\r\n###< citomni/devkit ###\r\n";
	put($app . '/.gitignore', $devkit . "\r\n# Application rules\r\n/node_modules/\r\n");

	[$exit, , $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0, 'Install with a CRLF .gitignore failed. ' . $output);
	$bytes = file_get_contents($app . '/.gitignore');
	check(substr_count($bytes, "\n") === substr_count($bytes, "\r\n"), 'The block did not keep CRLF line endings.');
	check(str_starts_with($bytes, $devkit . "\r\n" . GitignoreBlock::BEGIN . "\r\n"), 'The block was not placed after the leading tool block.');
	check(str_ends_with($bytes, GitignoreBlock::END . "\r\n\r\n# Application rules\r\n/node_modules/\r\n"), 'Application rules lost their position after the block.');
	check(GitignoreBlock::remove($bytes) === $devkit . "\r\n# Application rules\r\n/node_modules/\r\n", 'Removing the block did not restore the original bytes.');
};

$tests['gitignore block helper round-trips and escapes literals'] = static function (): void {
	$body = GitignoreBlock::body(['/var/cache/*', '/var/cache/*'], ['!/var/cache/.gitkeep'], ['odd[1]*?.txt', 'trail  ']);
	check(in_array('/odd\\[1]\\*\\?.txt', $body, true) && in_array('/trail\\ \\ ', $body, true), 'Managed targets were not escaped as literals.');
	check(count(array_keys($body, '/var/cache/*', true)) === 1, 'Duplicate rules were not removed.');
	check(GitignoreBlock::body([], [], []) === [], 'An empty rule set produced a block.');

	foreach (['', "/vendor/", "\n# app\n", "\xEF\xBB\xBF# app\n", "###> other/tool ###\n/x/\n###< other/tool ###\n# app\n"] as $original) {
		$withBlock = GitignoreBlock::apply($original, $body);
		check(GitignoreBlock::extract($withBlock) === $body, 'Extracted block differs from the applied body.');
		check(GitignoreBlock::apply($withBlock, $body) === $withBlock, 'Applying the same block twice changed the file.');
		check(GitignoreBlock::remove($withBlock) === $original, 'Removing the block did not restore: ' . json_encode($original));
		check(GitignoreBlock::apply($withBlock, []) === $original, 'An empty body did not remove the block: ' . json_encode($original));
	}
	check(str_starts_with(GitignoreBlock::apply("\xEF\xBB\xBF# app\n", $body), "\xEF\xBB\xBF" . GitignoreBlock::BEGIN), 'The UTF-8 BOM did not stay first.');

	foreach ([GitignoreBlock::BEGIN . "\n", GitignoreBlock::END . "\n" . GitignoreBlock::BEGIN . "\n", GitignoreBlock::BEGIN . "\n" . GitignoreBlock::BEGIN . "\n" . GitignoreBlock::END . "\n"] as $malformed) {
		expectFailure(fn() => GitignoreBlock::apply($malformed, $body), 'Malformed block markers were accepted.');
	}
};

$tests['gitignore manifest rules and reserved targets are validated'] = static function () use ($base): void {
	$invalid = [
		'not a list' => ['rule' => '/var/cache/*'],
		'empty' => [''],
		'control' => ["/var/cache/*\n"],
		'whitespace' => [' /var/cache/*'],
		'backslash' => ['var\\cache\\*'],
		'comment' => ['# runtime'],
		'ignore after exception' => ['!/var/cache/.gitkeep', '/var/cache/*'],
		'wildcard exception' => ['/var/secrets/*', '!/var/secrets/*.tpl'],
		'unanchored exception' => ['/var/cache/*', '!var/cache/.gitkeep'],
		'unnormalized exception' => ['/var/cache/*', '!/var//cache/.gitkeep'],
		'directory exception alone' => ['/var/backups/*', '!/var/backups/flags/'],
		'directory exception without slash' => ['/var/backups/*', '/var/backups/flags/*', '!/var/backups/flags', '!/var/backups/flags/.gitkeep'],
		'exception under ignored directory' => ['/data/', '!/data/keep.txt'],
		'traversal' => ['/var/../secrets/*'],
		'no path' => ['/'],
	];
	foreach ($invalid as $name => $rules) {
		$app = fixture($base, 'gitignore-invalid-' . str_replace(' ', '-', $name));
		package($app, 'test/runtime', [], [], $rules);
		expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'Invalid gitignore rule was accepted: ' . $name);
	}

	$app = fixture($base, 'gitignore-valid');
	package($app, 'test/runtime', [], [], ['/var/cache/*', '*.log', '/var/backups/flags/**', '!/var/cache/.gitkeep', '!/var/backups/flags/']);
	$locator = ScaffoldManifestLocator::forAppRoot($app);
	$rules = $locator->gitignoreRules($locator->discover());
	check($rules['exceptions'] === ['!/var/cache/.gitkeep', '!/var/backups/flags/'], 'Valid exceptions were not collected.');
	check($rules['targets'] === ['public/environment.txt', 'bin/tool'], 'Managed targets were not collected or create-only targets leaked in.');

	$crossPackage = [
		'exception beneath another package\'s ignored directory' => [['/data'], ['!/data/keep.txt']],
		'directory kept without slash against a directory rule' => [['/var/secrets/'], ['!/var/secrets']],
		'directory kept without slash against a content rule' => [['/var/secrets/*'], ['!/var/secrets']],
		'directory kept without slash against a kept file' => [['/var/secrets/*', '!/var/secrets/keep.tpl'], ['!/var/secrets']],
		'file kept against the identical ignore rule' => [['/var/secrets'], ['!/var/secrets']],
	];
	foreach ($crossPackage as $name => [$runtime, $keeper]) {
		$app = fixture($base, 'gitignore-cross-' . substr(md5($name), 0, 8));
		package($app, 'test/runtime', [], [], $runtime);
		package($app, 'test/keeper', [], [], $keeper);
		expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'Unsafe cross-package exception was accepted: ' . $name);
	}

	$app = fixture($base, 'gitignore-cross-directory-form');
	package($app, 'test/runtime', [], [], ['/var/secrets/', '/var/cache/*', '!/var/cache/.gitkeep']);
	package($app, 'test/keeper', [], [], ['/var/secrets/*', '!/var/secrets/', '!/var/secrets/keep.tpl', '!/var/cache/keeper.tpl']);
	$locator = ScaffoldManifestLocator::forAppRoot($app);
	$rules = $locator->gitignoreRules($locator->discover());
	check(in_array('!/var/secrets/keep.tpl', $rules['exceptions'], true), 'A kept directory in directory form with its own content rule was rejected.');

	$app = fixture($base, 'gitignore-reserved');
	package($app, 'test/other', [['target' => '.gitignore', 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
	expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'A package claimed .gitignore as a scaffold target.');
	$app = fixture($base, 'gitignore-control-target');
	package($app, 'test/other', [['target' => "bin/to\nol", 'source' => 'install/other.stub', 'type' => 'text', 'policy' => 'managed']], ['install/other.stub' => 'other']);
	expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'A target with a control character was accepted.');
};

$tests['malformed gitignore markers stop install and migrate before writing'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-malformed');
	$malformed = GitignoreBlock::BEGIN . "\n/x\n" . GitignoreBlock::BEGIN . "\n" . GitignoreBlock::END . "\n";
	put($app . '/.gitignore', $malformed);

	[$exit, $status] = command($app, ['status', '--environment=dev']);
	check($exit === 4 && ($status['gitignore']['status'] ?? null) === 'malformed', 'Status did not report malformed markers as a conflict.');
	[$exit, $result] = command($app, ['install', '--environment=dev']);
	check($exit === 4 && ($result['gitignore']['status'] ?? null) === 'malformed' && ($result['gitignore']['action'] ?? null) === 'conflict', 'Install accepted malformed markers.');
	check(str_starts_with((string)($result['error'] ?? ''), 'Install stopped before writing: '), 'Install did not explain the malformed markers.');
	check(!is_file($app . '/bin/tool') && !ScaffoldState::forAppRoot($app)->exists(), 'Install wrote scaffold or state despite malformed markers.');
	check(file_get_contents($app . '/.gitignore') === $malformed, 'Malformed markers were rewritten.');

	put($app . '/.gitignore', "# Application rules\n");
	[$exit, , $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0, 'Install did not recover after the markers were repaired. ' . $output);

	put($app . '/.gitignore', GitignoreBlock::END . "\n" . file_get_contents($app . '/.gitignore'));
	[$exit, $result] = command($app, ['migrate', '--environment=prod']);
	check($exit === 4 && ($result['gitignore']['status'] ?? null) === 'malformed', 'Migrate accepted malformed markers.');
	check(file_get_contents($app . '/public/environment.txt') === "dev\n", 'Migrate switched files despite malformed markers.');
	check(ScaffoldState::forAppRoot($app)->environment() === Environment::DEV, 'Migrate changed the recorded environment despite malformed markers.');
};

$tests['near-miss gitignore markers are recognized or rejected, never duplicated'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-near-miss');
	installed($app);
	put($app . '/.gitignore', GitignoreBlock::BEGIN . " \t\n/stale\n" . GitignoreBlock::END . "  \n\n# Application rules\n");
	[$exit, $result] = command($app, ['sync']);
	$bytes = file_get_contents($app . '/.gitignore');
	check($exit === 0 && ($result['gitignore']['reason'] ?? null) === 'block_drift', 'Markers with trailing whitespace were not recognized.');
	check(substr_count($bytes, GitignoreBlock::BEGIN) === 1 && !str_contains($bytes, '/stale'), 'A second block was inserted next to the old one.');
	check(str_ends_with($bytes, GitignoreBlock::END . "\n\n# Application rules\n"), 'The rewritten markers kept trailing whitespace or moved app rules.');

	put($app . '/.gitignore', GitignoreBlock::BEGIN . " extra\n" . GitignoreBlock::END . "\n");
	[$exit, $status] = command($app, ['status']);
	check($exit === 4 && ($status['gitignore']['status'] ?? null) === 'malformed', 'A garbled marker line was not reported as malformed.');

	$otherTool = "###> citomni/installer-extras ###\n/x/\n###< citomni/installer-extras ###\n";
	put($app . '/.gitignore', $otherTool);
	[$exit] = command($app, ['sync']);
	check($exit === 0 && str_starts_with(file_get_contents($app . '/.gitignore'), $otherTool . GitignoreBlock::BEGIN), 'A similarly named tool block was mistaken for the installer block.');
};

$tests['unsupported gitignore files never break commands'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-unsupported');
	$outside = $base . '/shared-gitignore';
	put($outside, "/shared/\n");
	if (!@symlink($outside, $app . '/.gitignore')) {
		echo "  SKIP Unsupported .gitignore check (symlink creation unavailable).\n";
		return;
	}

	[$exit, $result, $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0 && ($result['gitignore']['status'] ?? null) === 'unsupported' && ($result['gitignore']['reason'] ?? null) === 'gitignore_symlink', 'A .gitignore outside the application broke install. ' . $output);
	foreach ([['status'], ['doctor'], ['repair'], ['sync'], ['environment', 'prod'], ['environment', 'dev']] as $args) {
		[$exit, , $output] = command($app, $args);
		check($exit === 0, 'A .gitignore outside the application broke ' . implode(' ', $args) . '. ' . $output);
	}
	check(file_get_contents($outside) === "/shared/\n", 'The installer wrote through a symlink leaving the application.');

	unlink($app . '/.gitignore');
	symlink($base . '/missing-gitignore', $app . '/.gitignore');
	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && ($status['gitignore']['status'] ?? null) === 'unsupported', 'A dangling .gitignore symlink broke status.');
	unlink($app . '/.gitignore');
	put($app . '/config/gitignore', "# Application rules\n");
	symlink('config/gitignore', $app . '/.gitignore');
	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && ($status['gitignore']['reason'] ?? null) === 'gitignore_symlink', 'A symlinked .gitignore inside the application was not reported as unsupported.');
	[$exit, $result] = command($app, ['sync']);
	check($exit === 0 && ($result['gitignore']['applied'] ?? null) === 'skipped', 'Sync planned a block behind a symlinked .gitignore.');
	check(file_get_contents($app . '/config/gitignore') === "# Application rules\n", 'Sync wrote a block that Git would never read.');

	unlink($app . '/.gitignore');
	mkdir($app . '/.gitignore');
	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && ($status['gitignore']['reason'] ?? null) === 'gitignore_not_regular_file', 'A .gitignore directory was not reported as unsupported.');
};

$tests['no scaffold manifests means no gitignore block'] = static function () use ($base): void {
	$app = $base . '/apps/gitignore-no-manifests';
	put($app . '/composer.json', json_encode(['name' => 'test/app', 'config' => ['classmap-authoritative' => false]]));
	put($app . '/vendor/composer/installed.json', json_encode(['packages' => []]));
	put($app . '/.gitignore', GitignoreBlock::BEGIN . "\n/orphan\n" . GitignoreBlock::END . "\n");
	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && array_key_exists('gitignore', $status) && $status['gitignore'] === null, 'Status reported a block that no command can maintain.');
	[$exit, $result] = command($app, ['sync']);
	check($exit === 0 && array_key_exists('gitignore', $result) && $result['gitignore'] === null, 'The empty sync result lost its gitignore key.');
	[$exit, $result] = command($app, ['install']);
	check($exit === 2 && array_key_exists('gitignore', $result) && $result['gitignore'] === null, 'A usage failure lost its gitignore key.');
};

$tests['app-skeleton audit ignores the installer block and keeps it in the reference'] = static function () use ($base): void {
	$app = $base . '/apps/app-skeleton-installer-block';
	mkdir($app, 0775, true);
	$body = GitignoreBlock::body(['/var/cache/*'], [], ['public/index.php']);
	put($app . '/.gitignore', GitignoreBlock::apply("vendor\nvar/cache\n", $body));
	$audit = new AuditAppSkeletonMigration(new PathGuard($app));

	$identical = $audit->apply(['sha' => str_repeat('a', 40), 'files' => ['.gitignore' => ['url' => 'https://example.invalid/.gitignore', 'bytes' => "vendor\nvar/cache\n"]]], false);
	check(($identical['files'][0]['status'] ?? null) === 'identical' && !is_file($app . '/.gitignore.new'), 'The installer block counted as app-skeleton drift.');

	$different = $audit->apply(['sha' => str_repeat('a', 40), 'files' => ['.gitignore' => ['url' => 'https://example.invalid/.gitignore', 'bytes' => "vendor\nvar/logs\n"]]], false);
	check(($different['files'][0]['status'] ?? null) === 'different', 'Real app-skeleton drift was hidden.');
	check(!str_contains((string)$different['files'][0]['diff'], GitignoreBlock::BEGIN), 'The diff included the installer block.');
	check(file_get_contents($app . '/.gitignore.new') === GitignoreBlock::apply("vendor\nvar/logs\n", $body), 'The reference sidecar lost the installer block.');
};

$tests['a manifest with only gitignore rules adds its block without scaffold files or state'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-rules-only');
	package($app, 'test/runtime', [], [], ['/var/state/*', '!/var/state/.gitkeep']);
	put($app . '/.gitignore', "# Application rules\n");

	[$exit, $result, $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0 && ($result['gitignore']['applied'] ?? null) === 'updated', 'Install with a rules-only manifest failed. ' . $output);
	check(in_array(['name' => 'test/runtime', 'installed_version' => '1.0.0', 'files' => []], $result['packages'], true), 'The rules-only package was not reported without files.');
	check(!isset(ScaffoldState::forAppRoot($app)->readPackages()['test/runtime']), 'A package without scaffold files was recorded in state.');
	$bytes = file_get_contents($app . '/.gitignore');
	check(str_contains($bytes, "\n/var/state/*\n") && str_contains($bytes, "\n!/var/state/.gitkeep\n"), 'The rules-only manifest did not reach the block.');

	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && ($status['gitignore']['status'] ?? null) === 'up_to_date', 'Status did not accept the block from a rules-only manifest.');
	[$exit, , $output] = command($app, ['status', '--package=test/runtime'], false);
	check($exit === 0 && str_contains($output, "\ntest/runtime\n") && str_contains($output, '.gitignore block: up_to_date'), 'Status text for a rules-only package is wrong. ' . $output);
	[$exit, $result] = command($app, ['sync', '--package=test/runtime']);
	check($exit === 0 && ($result['packages'] ?? null) === [['name' => 'test/runtime', 'installed_version' => '1.0.0', 'files' => []]], 'A scoped sync of a rules-only package reported files.');
	check(($result['gitignore']['applied'] ?? null) === 'skipped' && file_get_contents($app . '/.gitignore') === $bytes, 'A scoped sync of a rules-only package changed the block.');
};

$tests['the installer manifest is validated together with package rules'] = static function () use ($base): void {
	$manifest = require __DIR__ . '/../install/manifest.php';
	check(($manifest['package'] ?? null) === 'citomni/installer' && ($manifest['files'] ?? null) === [], 'The installer manifest must declare runtime rules and no scaffold files.');

	$app = fixture($base, 'gitignore-installer-manifest');
	$path = $app . '/vendor/composer/installed.json';
	$data = json_decode(file_get_contents($path), true);
	$data['packages'][] = ['name' => 'citomni/installer', 'version' => '1.0.0', 'install-path' => realpath(__DIR__ . '/..')];
	put($path, json_encode($data));
	package($app, 'test/web', [], [], ['/var/secrets/*', '/var/state/*', '!/var/secrets/.gitkeep', '!/var/secrets/web.secret.php.tpl', '!/var/state/.gitkeep']);

	$locator = ScaffoldManifestLocator::forAppRoot($app);
	$rules = $locator->gitignoreRules($locator->discover());
	$body = GitignoreBlock::body($rules['ignore'], $rules['exceptions'], $rules['targets']);
	$expected = ['/var/backups/*', '/var/logs/*', '/var/secrets/*', '/var/state/*', '!/var/backups/.gitkeep', '!/var/logs/.gitkeep', '!/var/secrets/.gitkeep', '!/var/secrets/web.secret.php.tpl', '!/var/state/.gitkeep'];
	check(array_values(array_filter($body, static fn(string $line): bool => str_starts_with($line, '/var/') || str_starts_with($line, '!/var/'))) === $expected, 'The installer rules did not merge with package rules: ' . implode(' ', $body));

	// Every package keeps its placeholders, so a shared runtime directory must never be ignored literally.
	package($app, 'test/literal', [], [], ['/var/state/']);
	expectFailure(fn() => ScaffoldManifestLocator::forAppRoot($app)->discover(), 'A literal ignore of a shared runtime directory was accepted next to kept placeholders.');
};

$tests['a missing gitignore is created in a Git checkout'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-checkout');
	mkdir($app . '/.git');
	$block = GitignoreBlock::apply('', GitignoreBlock::body([], [], ['bin/tool', 'public/environment.txt']));

	[$exit, $status] = command($app, ['status', '--environment=dev']);
	check($exit === 3 && ($status['gitignore']['status'] ?? null) === 'update_available' && ($status['gitignore']['reason'] ?? null) === 'gitignore_missing', 'Status did not report a .gitignore missing in a Git checkout.');
	check(($status['gitignore']['newly_ignored'] ?? null) === ['bin/tool', 'public/environment.txt'], 'Status did not list what a new .gitignore would ignore.');
	[$exit, $result] = command($app, ['install', '--environment=dev', '--dry-run']);
	check($exit === 0 && ($result['gitignore']['applied'] ?? null) === 'created' && !file_exists($app . '/.gitignore'), 'Dry-run install wrote a .gitignore.');

	[$exit, $result, $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0 && ($result['gitignore']['action'] ?? null) === 'create' && ($result['gitignore']['applied'] ?? null) === 'created', 'Install did not create the .gitignore. ' . $output);
	check(file_get_contents($app . '/.gitignore') === $block, 'The created .gitignore does not hold exactly the block.');
	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && ($status['gitignore']['status'] ?? null) === 'up_to_date', 'Status did not accept the created .gitignore.');

	foreach ([['sync'], ['migrate']] as $args) {
		unlink($app . '/.gitignore');
		[$exit, $status] = command($app, ['status']);
		check($exit === 3 && ($status['gitignore']['reason'] ?? null) === 'gitignore_missing', 'Status did not report a deleted .gitignore as drift.');
		[, $result, $output] = command($app, $args);
		check(($result['gitignore']['applied'] ?? null) === 'created' && file_get_contents($app . '/.gitignore') === $block, implode(' ', $args) . ' did not recreate the .gitignore. ' . $output);
	}
	unlink($app . '/.gitignore');
	[$exit, $result] = command($app, ['sync', 'bin/tool']);
	check($exit === 0 && $result['gitignore'] === null && !file_exists($app . '/.gitignore'), 'A single-target sync created the .gitignore.');
	[$exit, $result] = command($app, ['repair']);
	check($exit === 0 && !file_exists($app . '/.gitignore'), 'Repair created the .gitignore.');

	// Linked worktrees and submodules use a .git file; a checkout may also start above the app root.
	$worktree = fixture($base, 'gitignore-worktree');
	put($worktree . '/.git', "gitdir: ../elsewhere/.git/worktrees/app\n");
	$nested = fixture($base, 'gitignore-parent-checkout/app');
	mkdir(dirname($nested) . '/.git');
	foreach ([$worktree, $nested] as $root) {
		[$exit, $result, $output] = command($root, ['install', '--environment=dev']);
		check($exit === 0 && ($result['gitignore']['applied'] ?? null) === 'created' && file_get_contents($root . '/.gitignore') === $block, 'Install did not create the .gitignore in ' . $root . '. ' . $output);
	}

	// An empty block never creates a file.
	$empty = $base . '/apps/gitignore-checkout-empty';
	put($empty . '/composer.json', json_encode(['name' => 'test/app', 'config' => ['classmap-authoritative' => false]]));
	package($empty, 'test/owned', [['target' => 'config/owned.php', 'source' => 'install/owned.stub', 'type' => 'config', 'policy' => 'create-only']], ['install/owned.stub' => "<?php return [];\n"]);
	mkdir($empty . '/.git');
	[$exit, $result, $output] = command($empty, ['install', '--environment=dev']);
	check($exit === 0 && ($result['gitignore']['status'] ?? null) === 'absent' && !file_exists($empty . '/.gitignore'), 'An empty block created a .gitignore. ' . $output);
};

$tests['a missing gitignore stays absent outside a Git checkout'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-no-checkout');
	for ($directory = (string)realpath($app); ; $directory = dirname($directory)) {
		if (file_exists($directory . '/.git')) {
			echo "  SKIP Missing .gitignore outside a Git checkout (the temp directory is inside one).\n";
			return;
		}
		if (dirname($directory) === $directory) {
			break;
		}
	}

	[$exit, $result, $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0 && ($result['gitignore']['status'] ?? null) === 'absent' && ($result['gitignore']['applied'] ?? null) === 'skipped', 'Install did not leave a missing .gitignore absent. ' . $output);
	[$exit, $status] = command($app, ['status']);
	check($exit === 0 && ($status['gitignore']['status'] ?? null) === 'absent' && ($status['gitignore']['reason'] ?? null) === 'gitignore_missing', 'Status reported a missing .gitignore outside a Git checkout as a finding.');
	[$exit] = command($app, ['sync']);
	check($exit === 0 && !file_exists($app . '/.gitignore'), 'Sync created a .gitignore outside a Git checkout.');
};

$tests['the installer block follows a leading app-skeleton block'] = static function () use ($base): void {
	$app = fixture($base, 'gitignore-three-tiers');
	package($app, 'test/runtime', [], [], ['/var/cache/*', '!/var/cache/.gitkeep']);
	$skeleton = "###> citomni/app-skeleton ###\n# Defaults from citomni/app-skeleton.\n/vendor/\n/var/cache/*\n!/var/cache/.gitkeep\n###< citomni/app-skeleton ###\n";
	$appRules = "# Application rules: everything below takes precedence over both blocks above.\n!/var/cache/fixture.json\n";
	put($app . '/.gitignore', $skeleton . "\n" . $appRules);

	[$exit, , $output] = command($app, ['install', '--environment=dev']);
	check($exit === 0, 'Install with a skeleton block failed. ' . $output);
	$bytes = file_get_contents($app . '/.gitignore');
	$block = GitignoreBlock::apply('', GitignoreBlock::body(['/var/cache/*'], ['!/var/cache/.gitkeep'], ['bin/tool', 'public/environment.txt']));
	check($bytes === $skeleton . "\n" . $block . "\n" . $appRules, 'The block did not land between the skeleton block and the application rules.');

	[$exit, $result] = command($app, ['sync']);
	check($exit === 0 && ($result['gitignore']['applied'] ?? null) === 'skipped' && file_get_contents($app . '/.gitignore') === $bytes, 'A second run moved or rewrote the block.');
	check(GitignoreBlock::remove($bytes) === $skeleton . "\n" . $appRules, 'Removing the block did not restore the skeleton and application tiers.');
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
