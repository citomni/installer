# Manifest discovery suite

Isolated checks for scaffold manifest discovery: `ScaffoldManifestLocator` on top of `ComposerPackageDiscovery`. No Composer, no database, no environment variables.

```
php tests/manifest-discovery/run.php
```

Expected: `17 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

Every check builds a fresh application in its own run directory, `citomni_installer_manifest_discovery_<random>` under `sys_get_temp_dir()`, and writes `vendor/composer/installed.json` by hand, so discovery reads exactly the packages the check declares. The run directory is removed after the check.

## Checks

Discovery:

- A convention manifest (`install/manifest.php`) is discovered and normalized with package, schema version, installed version, manifest path, and source paths.
- A manifest declared in `extra.citomni.scaffold` is discovered, and it wins over a convention manifest in the same package.
- A package without a manifest is skipped.
- Composer 1's top-level `installed.json` list is read like Composer 2's `packages` object.
- `discoverPackage()` returns one manifest, and null for a package that is not installed or has no manifest.

Manifest validation:

- A manifest naming another package, an unknown schema version, a source that leaves the package root, an unsafe target (absolute, backslash, drive letter, traversal, stream wrapper), a duplicate target after normalization, an unknown policy, and a file entry without a source each fail discovery.
- A declared `extra.citomni.scaffold` manifest that is missing, or a path that leaves the package, fails.

`installed.json`:

- A missing file and a file that is not JSON fail with their own messages.
