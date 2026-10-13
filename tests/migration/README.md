# Migration suite

Isolated checks for the building blocks of `migrate`: how create-only targets are planned, generated `.new` sidecars, installer-config and source diagnostics, the app-skeleton audit, and the recursive PHP lint. No Composer, no database, no environment variables.

```
php tests/migration/run.php
```

Expected: `14 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

Every check gets an application root and a package root in its own run directory, `citomni_installer_migration_<random>` under `sys_get_temp_dir()`, which is removed after the check. The operations run unchanged on real files. The lint check starts `php -l` processes with the running PHP binary. The end-to-end `migrate` command is covered by the lifecycle suite.

## Checks

Create-only targets:

- PHP that differs only in comments, PHPDoc, or whitespace is refreshed with a backup.
- PHP with a code difference, and any other file with a content difference, gets a `.new` reference.
- A `.tpl` target is refreshed authoritatively with a backup.

Generated sidecars:

- An existing, different `.new` is overwritten with the current source.
- A directory where the `.new` belongs is reported as an IO failure.

Diagnostics:

- A missing derived placeholder (`PROD_ROOT_HOST`) is reported as its configurable source key (`PROD_ROOT_URL`), and a configured source of a required derived host is neither missing nor unused.
- Percent-style source tokens are advisory and listed once per source.
- Blocking review lists `.new` writes, conflicts, and failures, but not updates.

App-skeleton audit:

- A difference writes the reference as `.gitignore.new` and leaves the local file alone; an existing reference sidecar is replaced.
- Line endings and one final newline are no difference.

PHP lint:

- Application PHP and PHP templates are linted; `vendor/` and `var/backups/` are skipped.
