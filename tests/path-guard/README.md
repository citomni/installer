# PathGuard suite

Isolated checks for `CitOmni\Installer\Support\PathGuard`: the application root, target and source resolution, and the rejection of unsafe paths. No Composer, no database, no environment variables.

```
php tests/path-guard/run.php
```

Expected: `14 passed, 0 failed` where PHP can create symlinks, otherwise `13 passed, 0 failed, 1 skipped`. On Windows, PHP creates symlinks only in an elevated prompt or with the "Create symbolic links" user right; Developer Mode is not enough.

`PASS` and `SKIP` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

Every check gets an application root and a package root with one stub in its own run directory, `citomni_installer_path_guard_<random>` under `sys_get_temp_dir()`, which is removed after the check. The unsafe-path cases are string rejections and behave identically on every platform.

## Checks

- A missing application root is rejected; an existing one is canonicalized.
- A target that does not exist yet resolves below the application root, with redundant segments normalized.
- An existing source resolves below the package root, a missing source resolves when an ancestor exists, and a missing package root is rejected.
- Sixteen unsafe paths are rejected as target and as source: drive letters, UNC paths, backslashes, traversal, absolute paths, stream wrappers, and empty or dot-only paths.
- The rejection names the problem: absolute, drive letter, traversal, or backslash.
- A symlinked directory that leaves the application root is rejected.

## Skipped checks

| Check | Runs when |
|---|---|
| a symlinked ancestor that leaves the application root is rejected | PHP can create symlinks |
