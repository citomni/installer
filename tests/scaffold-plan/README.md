# Scaffold plan suite

Isolated checks for the scaffold decision graph (`BuildScaffoldPlan`) and the only write layer (`ApplyScaffoldPlan`). No Composer, no database, no environment variables.

```
php tests/scaffold-plan/run.php
```

Expected: `18 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

Every check gets an application root and a package with one managed and one create-only stub in its own run directory, `citomni_installer_scaffold_plan_<random>` under `sys_get_temp_dir()`, which is removed after the check. The plan checks assert the planned status, reason, action, and the internal `_apply` snapshot. The apply checks drive real plans through `ApplyScaffoldPlan` and assert files, `.new` sidecars, backups, and recorded state; they first record an environment, as a completed materialization does, because only materialization creates state.

## Checks

Managed targets:

- `install` plans a missing target as `create` with its rendered checksum.
- `sync` leaves an up-to-date target alone, updates a clean target whose stub changed upstream without a backup, and refreshes the baseline of a target that already matches a changed stub.
- `sync` plans `.new` for a local modification; forced, it overwrites with a backup.

Create-only targets:

- `sync` creates a missing target and never touches an existing one; forced, it overwrites with a backup.

No recorded state:

- `sync` adopts an existing file that matches the render, plans `.new` for one that differs, and creates a missing target.

Apply:

- `install` creates the files and records a managed baseline with both checksums and a create-only baseline with the stub checksum only.
- `sync` writes `.new` for a local modification and leaves the recorded baseline alone.
- A forced overwrite backs up the previous bytes under `var/backups/citomni-installer/`, also for a clean file, then writes and records the render.
- A dry run writes nothing and still reports the outcome.
- A plan that went stale is a conflict and leaves the stale target alone.
