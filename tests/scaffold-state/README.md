# Scaffold state suite

Isolated checks for `CitOmni\Installer\State\ScaffoldState`, the installer state file under `var/state/citomni/`. No Composer, no database, no environment variables.

```
php tests/scaffold-state/run.php
```

Expected: `19 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

Every check gets an application root in its own run directory, `citomni_installer_scaffold_state_<random>` under `sys_get_temp_dir()`, which is removed after the check. Unsafe states are written as raw PHP files.

## Checks

Reads, writes, and round trips:

- A missing state file reads as no state.
- The first write creates the state directories and the file, stamps the format 2 envelope with the environment, and leaves no temporary files behind.
- Packages round-trip unchanged, and a later write replaces the recorded packages.
- `forAppRoot()` places the state file under the application root.
- `writePackages()` keeps the recorded environment and cannot create the first state; only `writePackagesAndEnvironment()` can.

Unsafe state blocks reads and writes:

- A newer `format_version` blocks reads and writes and leaves the file untouched.
- `format_version` 0, legacy `format_version` 1, a `format_version` that is not an integer, and a missing `format_version` are refused.
- A parse error blocks a write and leaves the file untouched; a file that returns no array is refused.
- A state without packages, or without a valid environment, is refused.
