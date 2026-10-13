# Lifecycle suite

End-to-end checks for the installer commands (`install`, `status`, `sync`, `repair`, `environment`, `migrate`, `doctor`) and the operations behind them. No Composer install, no database, no environment variables, and no setup per session.

## Run

```
php tests/lifecycle/run.php
```

Expected result on Linux and macOS:

```
68 passed, 0 failed, 2 skipped
```

Expected result on Windows: `68 passed, 0 failed, 2 skipped` when PHP can create symlinks, otherwise `63 passed, 0 failed, 7 skipped`. PHP on Windows creates symlinks only in an elevated prompt or with the "Create symbolic links" user right; Developer Mode is not enough.

`PASS` and `SKIP` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

The real Composer check runs only when the suite gets the path to a Composer PHAR:

```
php tests/lifecycle/run.php --composer=/absolute/path/to/composer.phar
```

Expected result with `--composer`: `69 passed, 0 failed, 1 skipped` on Linux and macOS, and on Windows when PHP can create symlinks; otherwise `64 passed, 0 failed, 6 skipped` on Windows.

The check isolates `COMPOSER_HOME` and runs only `config` and `dump-autoload` on local fixtures; it resolves and downloads nothing. The PHAR must run with the PHP binary that runs the suite.

## How it works

The checks build disposable applications below one run directory, `citomni_installer_lifecycle_<random>` under `sys_get_temp_dir()`, which is removed after the run. The fixture application installs a package with an environment-aware managed target, a managed entry point, and a create-only config file.

Commands run through the real `InstallerCli` in a fresh child process (`run.php --child <app> <command>`), started with the same php.ini as the suite. One check runs `bin/citomni-installer` itself through the PHP proxy Composer 2 writes to `vendor/bin`, the file that `vendor\bin\citomni-installer.bat` runs on Windows. A Composer substitute placed first on `PATH` answers `config` and `dump-autoload`, records the classmap posture in the fixture's `composer.json`, and fails on demand, so Composer failures are deterministic. Some checks drive the operations directly in the suite process instead.

## Checks

The checks in `run.php` cover, in this order:

- The first install, recovery after Composer failures, adoption, and conflicts.
- Target ownership: competing owners, file/directory overlaps, symlinked aliases, reserved installer paths, vendor containment, and case on Windows.
- Stale plans, root URL placeholders, backups, the application lock, and dry runs.
- Environment switching, including a dev, stage, prod, dev cycle that checks every dry run, the recorded environment, the Composer posture, the rendered target, the backup of the previous environment, and `status` after each switch.
- `bin/citomni-installer` through a Composer proxy: it installs the application it is run from and refuses a directory without `composer.json`.
- Unsafe state paths, Unix modes, discovery that foreign Composer metadata cannot redirect, and IO failures.
- `migrate`: legacy rebuild, reconciliation, dry runs, PHP lint, the app-skeleton audit, source warnings, the migration report, and the state format boundaries.
- The installer block in `.gitignore`.
- Omitting create-only targets through `omit` in `config/citomni_installer.php`.
- The real Composer check.

## Skipped checks

| Check | Runs when |
|---|---|
| targets differing only in case collide on Windows | On Windows |
| atomic replacement preserves restrictive and executable Unix modes | Not on Windows |
| target aliases through a symlinked directory fail before apply | PHP can create symlinks |
| installer metadata stays reserved behind a symlinked alias | PHP can create symlinks |
| a symlinked alias of vendor is rejected as a target | PHP can create symlinks |
| unsafe state paths fail and doctor reports write-access failure | PHP can create symlinks |
| unsupported gitignore files never break commands | PHP can create symlinks |
| a missing gitignore stays absent outside a Git checkout | The system temp directory lies outside every Git checkout |
| real Composer materializes dev/stage/prod/dev and skips root scripts | `--composer=<path>` is given |
