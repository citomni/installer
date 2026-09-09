# Environment materialization and failure recovery

This describes the explicit-environment implementation and its hardening changes.
Normal lifecycle commands use strict state format 2 without fallback. The explicit
`migrate` command can replace known v1 materialization from current manifests.

## Commands

Run commands from the application root after Composer has installed its packages:

```sh
vendor/bin/citomni-installer install --environment=dev
vendor/bin/citomni-installer migrate --environment=dev --dry-run
vendor/bin/citomni-installer migrate --environment=dev
vendor/bin/citomni-installer environment stage --dry-run
vendor/bin/citomni-installer environment stage
vendor/bin/citomni-installer environment prod
vendor/bin/citomni-installer environment dev
```

The initial install must include all discovered scaffold packages. A package filter
cannot establish a global environment. Later installs may use `--package` with the
recorded environment. `install --force` cannot switch an existing environment.


The `migrate` command is an explicit recovery path for legacy materialization. It
ignores legacy package/file baselines and uses the currently installed manifests and
current placeholder resolution as the authority. Existing create-only targets are
preserved. Managed targets are adopted when current or backed up and replaced when
they differ. A known v1 state is backed up and removed before current v2 state is
committed. Missing state is accepted so an interrupted migration can be resumed.

The `environment` command selects only environment-aware manifest entries. It
replaces differing bytes with the selected environment's rendering and backs up
existing targets before replacement. Ordinary source-only entries are unaffected.
Repair and sync use the recorded environment and reject `--environment`.

## Recovery after a failed command

Scaffold writes and Composer operations are not one transaction. If Composer fails,
files already written remain in place, and composer.json or generated autoload files
may also have changed. Environment state is committed only after all scaffold actions
and Composer steps succeed. There is no rollback command or automatic rollback.

Correct the reported cause and rerun the same command with the same environment and
placeholder values. A normal managed file without a committed baseline can be adopted
when its bytes exactly match the current rendering. A differing normal managed file
still conflicts unless a forced replacement is explicitly requested.

An existing create-only file can acquire a missing repair record when every required
token resolves and its bytes exactly match the rendering. Local edits and existing
create-only files whose tokens cannot be resolved are left untouched. No record is
invented for bytes that the installer cannot reproduce.

When two environment sources render identical bytes, state still records the current
source, type, policy, stub checksum, and placeholder snapshot. This allows a later
repair to use the correct inputs even when no target replacement was needed.

## Concurrent commands, paths, and backups

All real CLI write commands acquire the same nonblocking advisory lock before reading
state or manifests and hold it through confirmation, file writes, Composer, and state
persistence. A competing writer exits with code 4. The lock file is:

```text
var/state/citomni/installer.lock
```

The file is intentionally retained after release. Removing a live lock file would
allow different processes to lock different filesystem objects. A command rejected
during preflight can therefore leave its lock file and parent directories behind;
no scaffold or environment state is materialized by acquiring the lock. Dry runs do
not acquire the lock or write files.

The applier also verifies the target path, existence, and content observed during
planning before replacing/adopting a target. Environment no-ops receive the same
check. Changed targets produce conflicts. This protects against edits between plan
and apply, but is not an atomic compare-and-swap against unrelated programs: An
external editor can still race the final check. Direct engine callers must acquire
InstallerLock around discovery, planning, apply, and finalization themselves.

Discovery validates ownership across all manifests before applying any package or
environment filter. Duplicate resolved targets, file/directory overlaps, installer
metadata targets, and targets inside vendor are rejected. Checks include symlink
aliases and conservative ASCII case folding on Windows. Other application state
under var/state/citomni remains available to packages.

ScaffoldState resolves its fixed state path through PathGuard for reads and writes.
Unsafe state paths fail, and doctor reports unsafe write paths instead of silently
omitting them.

Each backup run uses a UTC timestamp with microseconds and a random suffix, then
reserves its directory with exclusive creation. Existing backup files are never
replaced. Use `backup_dir` in the command JSON result to identify that run's directory;
it may be empty if the command failed after reservation. Do not infer the directory
from modification times. Atomic replacements preserve existing Unix rwx permission
bits, including executable and restrictive modes; ownership, ACLs, and special mode
bits are not copied. New files retain the existing creation/umask behavior.

## Composer context and diagnostics

Materialization first verifies Composer availability, the selected project file,
and the effective vendor directory. COMPOSER must resolve to the application's
composer.json, and Composer's effective vendor-dir must resolve to its vendor tree.
Overrides that select other locations fail before scaffold writes.

Composer sets classmap-authoritative to false for dev and true for stage/prod, then
runs dump-autoload with --no-scripts and --no-interaction. Composer plugins are not
disabled for these materialization commands. Root package scripts are not run.
Dry-run text and JSON include the planned Composer setting.

Package discovery reads paths, versions, and extra metadata from the selected vendor
tree's installed.json. It does not consult process-global Composer InstalledVersions.

Per-file failures may include `error_type` (`io`, `conflict`, or `general`) in JSON.
Filesystem failures map to exit 6, conflicts and stale plans to 4, and general failures
to 1. Unknown status package filters fail rather than returning a successful empty
result. Unsafe recorded state uses exit 5; invalid command usage uses exit 2.

Constructor changes for direct callers: ScaffoldState now takes PathGuard;
AbstractWriteCommand and its concrete install/environment constructors also require
InstallerLock. Prefer ScaffoldState::forAppRoot() and InstallerCli for normal usage.

## Regression tests

The standalone suite requires PHP 8.5 or newer. It autoloads this checkout directly
and creates disposable application fixtures; no Composer install is needed:

```sh
php tests/installer-regression-test.php
```

Most cases use a controlled Composer substitute to exercise failures deterministically.
An optional integration case uses an actual Composer PHAR, verifies the generated
autoloader's authoritative setting in a fresh PHP process for dev/stage/prod/dev, and
checks that a root post-autoload-dump script did not run:

```sh
php tests/installer-regression-test.php --composer=/absolute/path/to/composer.phar
```

The optional case isolates COMPOSER_HOME and runs only config and dump-autoload on
local fixtures; it does not resolve or download dependencies. The selected PHAR must
be runnable by the PHP binary executing the tests. Windows-specific and symlink
checks report a skip when their platform/capability is unavailable.

The application-level Windows test still runs from the disposable lab application:

```bat
cd /d C:\dev\www\citomni\_lab\environment-switch-e2e
composer dump-autoload --no-scripts
..\..\installer\tests\citomni-environment-end2end-test.bat
```

Refresh the application's autoload files after updating installer sources so newly
added classes are available even if its prior autoloader was authoritative. The batch
test reads each apply result's backup_dir and needs no sleep between switches.
