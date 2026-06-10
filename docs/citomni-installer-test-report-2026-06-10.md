# CitOmni Installer Manual Integration Test Report

**Subject:** `citomni/installer` initial release readiness testing  
**Date of test execution:** 10 June 2026  
**Report status:** Evidence-based manual test report  
**Evidence standard:** Strict. This report records only test actions and outcomes explicitly shown in the conversation logs. It does not infer unobserved command output, unshown file contents, or unstated implementation details.
**Author:** CitOmni Core Team

---

## 1. Abstract

This report documents a manual integration and release-readiness test sequence for `citomni/installer`, exercised through a disposable Composer project using local path repositories. The test sequence verified Composer package resolution, installer binary execution, manifest discovery, placeholder rendering, dry-run behaviour, first materialisation, state-based status reporting, idempotent installation, local modification detection, safe `.new` creation, forced overwrite with backup, repair from recorded state, and the later force-confirmation semantics for interactive and JSON modes.

The tested flow reached a successful release-readiness state. One real defect was discovered during testing: scaffold placeholder tokens with whitespace, such as `{{ CITOMNI_ENVIRONMENT }}`, initially failed renderer validation. The renderer was subsequently patched and verified. A later behavioural refinement introduced distinct semantics for `--force` and `--force=yes`; those semantics were also tested successfully.

---

## 2. Scope and limitations

### 2.1 Scope

The following behaviours were covered:

- Composer validation of `citomni/installer`.
- Optimised Composer autoload generation.
- Local Composer path-repository installation into a disposable app.
- Installer execution through `vendor\bin\citomni-installer`.
- `doctor` command behaviour.
- `status` command behaviour before and after installation.
- `install --dry-run` and `install` behaviour.
- Idempotent repeated `install` behaviour.
- Local modification detection for a managed file.
- `sync` behaviour for locally modified files without force.
- `sync --force` overwrite and backup behaviour.
- `repair` recreation of a missing file from recorded installer state.
- Placeholder scan and placeholder-resolution behaviour.
- Force-confirmation behaviour for `--force`, `--force=yes`, cancellation, and JSON mode.

### 2.2 Limitations

The report does not claim coverage of behaviours not evidenced in the logs. In particular, the following were not strictly demonstrated in the provided command output:

- Installation from Packagist or GitHub dist archives.
- Behaviour on Linux or macOS.
- Behaviour under non-interactive terminals other than JSON-mode refusal for `--force`.
- Full test coverage of every individual scaffold file's rendered content.
- Exhaustive testing of invalid manifests, unreadable state files, symlink escape prevention, malformed installed Composer metadata, or IO failure injection.
- Automated test-suite execution, if such a suite exists.

---

## 3. Test environment

### 3.1 Host paths observed

The disposable smoke-test application was created at:

```text
C:\dev\www\citomni\_lab\installer-release-smoke
```

The local package roots used as Composer path repositories were:

```text
C:\dev\www\citomni\kernel
C:\dev\www\citomni\installer
C:\dev\www\citomni\http
C:\dev\www\citomni\cli
```

### 3.2 Composer package versions observed

During the disposable-project installation, Composer reported the following package locks and installations:

```text
citomni/cli       dev-main
citomni/http      dev-main
citomni/installer dev-main
citomni/kernel    dev-main
```

Composer installed these packages by junctioning from the local path repositories.

---

## 4. Pre-release static checks on `citomni/installer`

### 4.1 Composer schema validation

Command:

```bat
cd /d C:\dev\www\citomni\installer
composer validate --strict
```

Observed output:

```text
./composer.json is valid
```

Result: **Pass**.

### 4.2 Optimised autoload generation

Command:

```bat
composer dump-autoload -o
```

Observed output:

```text
Generating optimized autoload files
Generated optimized autoload files containing 19 classes
```

Result: **Pass**.

### 4.3 Working-tree status before commit

Command:

```bat
git status
```

Observed output:

```text
On branch main
Your branch is up to date with 'origin/main'.

Changes not staged for commit:
        modified:   src/Cli/Command/AbstractWriteCommand.php
        modified:   src/Cli/InstallerCli.php
        modified:   src/Support/ScaffoldRenderer.php

no changes added to commit
```

Result: **Informational**. The modified files correspond to the force-confirmation and placeholder-rendering changes discussed and tested in this sequence.

---

## 5. Disposable Composer application setup

### 5.1 Project initialisation

Commands:

```bat
cd /d C:\dev\www\citomni
mkdir _lab
mkdir _lab\installer-release-smoke
cd _lab\installer-release-smoke
composer init --name=citomni/installer-release-smoke --type=project --license=proprietary --no-interaction
```

Observed output included:

```text
Writing ./composer.json
```

A directory listing showed only `composer.json` in the newly created test application at that point.

Result: **Pass**.

### 5.2 Composer configuration for local path repositories

Commands:

```bat
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.citomni-kernel path ../../kernel
composer config repositories.citomni-installer path ../../installer
composer config repositories.citomni-http path ../../http
composer config repositories.citomni-cli path ../../cli
```

Result: **Pass**, as evidenced by the subsequent successful `composer require` operation.

### 5.3 Composer installation of CitOmni packages

Command:

```bat
composer require citomni/kernel:*@dev citomni/installer:*@dev citomni/http:*@dev citomni/cli:*@dev -W
```

Observed outcome:

```text
Lock file operations: 4 installs, 0 updates, 0 removals
  - Locking citomni/cli (dev-main)
  - Locking citomni/http (dev-main)
  - Locking citomni/installer (dev-main)
  - Locking citomni/kernel (dev-main)
Package operations: 4 installs, 0 updates, 0 removals
  - Installing citomni/kernel (dev-main): Junctioning from ../../kernel
  - Installing citomni/cli (dev-main): Junctioning from ../../cli
  - Installing citomni/http (dev-main): Junctioning from ../../http
  - Installing citomni/installer (dev-main): Junctioning from ../../installer
Generating autoload files
No security vulnerability advisories found.
```

Result: **Pass**.

---

## 6. Installer discovery and environment checks

### 6.1 Initial `doctor` run without installer config

Command:

```bat
vendor\bin\citomni-installer doctor
```

Observed checks:

```text
[ OK ] app_root             C:\dev\www\citomni\_lab\installer-release-smoke
[ OK ] vendor_dir           C:\dev\www\citomni\_lab\installer-release-smoke/vendor
[ OK ] composer_metadata    C:\dev\www\citomni\_lab\installer-release-smoke/vendor/composer/installed.json
[ OK ] scaffold_manifests   citomni/cli (11 files), citomni/http (38 files)
[ OK ] installer_config     No installer config present (config + CLI only): C:\dev\www\citomni\_lab\installer-release-smoke/config/citomni_installer.php
[ OK ] write_access         App-root and var/ tree are writable.
[ OK ] state_file           No state file yet (created on first install): C:\dev\www\citomni\_lab\installer-release-smoke/var/state/citomni/installer-scaffold.php

Result: OK (exit 0)
```

Result: **Pass**.

Interpretation: Composer metadata was readable, the installer binary executed from the disposable project, and both scaffold manifests were discovered with the expected file counts: 11 files for `citomni/cli` and 38 files for `citomni/http`.

---

## 7. Initial status before materialisation

### 7.1 `status` on an empty app root

Command:

```bat
vendor\bin\citomni-installer status
```

Observed outcome:

- `citomni/cli` reported all 11 manifest entries as `missing` with reason `target_missing`.
- `citomni/http` reported all 38 manifest entries as `missing` with reason `target_missing`.
- The command ended with:

```text
Result: updates available (exit 3)
```

Result: **Pass**.

Interpretation: The status command correctly treated an empty application root as requiring scaffold materialisation and returned exit code 3 for drift or missing files.

---

## 8. Placeholder defect discovery and correction

### 8.1 Initial `install --dry-run` failure

Command:

```bat
vendor\bin\citomni-installer install --dry-run
```

Observed failure:

```text
Malformed placeholder token: {{ CITOMNI_ENVIRONMENT }}
Hint: rendering managed stubs requires every token to resolve. Provide the missing value(s) via config/citomni_installer.php or --placeholder=KEY=VALUE.
```

Result: **Fail, defect identified**.

Interpretation: The smoke test identified that the scaffold renderer rejected a whitespace-padded placeholder token. This was a genuine pre-release defect in the renderer/token contract.

### 8.2 Placeholder inventory scan

Command:

```powershell
cd C:\dev\www\citomni
Get-ChildItem http\install\scaffold,cli\install\scaffold -Recurse -File |
Select-String -Pattern '\{\{\s*([A-Z][A-Z0-9_]*)\s*\}\}' -AllMatches |
ForEach-Object { $_.Matches.Groups[1].Value } |
Sort-Object -Unique
```

Observed output:

```text
CITOMNI_ENVIRONMENT
```

Result: **Pass**.

Interpretation: Only one scaffold placeholder was observed in the tested HTTP and CLI scaffold trees.

### 8.3 Renderer-class resolution check

Command:

```bat
php -r "require 'vendor/autoload.php'; $r = new ReflectionClass(\CitOmni\Installer\Support\ScaffoldRenderer::class); echo $r->getFileName(), PHP_EOL;"
```

Observed output:

```text
C:\dev\www\citomni\installer\src\Support\ScaffoldRenderer.php
```

Result: **Pass**.

Interpretation: The disposable application loaded the `ScaffoldRenderer` class from the local path-repository checkout of `citomni/installer`.

### 8.4 Renderer unit-level smoke check

Command:

```bat
php -r "require 'vendor/autoload.php'; $r = new \CitOmni\Installer\Support\ScaffoldRenderer(); echo $r->render('{{ CITOMNI_ENVIRONMENT }}', ['CITOMNI_ENVIRONMENT' => 'dev']), PHP_EOL;"
```

Observed output:

```text
dev
```

Result: **Pass**.

Interpretation: After the renderer patch, the renderer accepted a whitespace-padded placeholder token and resolved it correctly.

### 8.5 Installer config recognition

After creating `config` and adding an installer config file, `doctor` was rerun.

Command:

```bat
vendor\bin\citomni-installer doctor
```

Observed relevant output:

```text
[ OK ] installer_config     Loaded 1 placeholder(s) from C:\dev\www\citomni\_lab\installer-release-smoke/config/citomni_installer.php
```

The remaining `doctor` checks were also reported as `OK`, and the command ended with:

```text
Result: OK (exit 0)
```

Result: **Pass**.

Interpretation: The installer config was loaded and supplied one placeholder. The content of the config file was not shown verbatim in the logs; however, subsequent successful scaffold rendering demonstrates that the required placeholder was available to the installer.

---

## 9. Dry-run materialisation after placeholder correction

### 9.1 `install --dry-run`

Command:

```bat
vendor\bin\citomni-installer install --dry-run
```

Observed outcome:

- All 11 `citomni/cli` entries were reported as `created` with reason `target_missing`.
- All 38 `citomni/http` entries were reported as `created` with reason `target_missing`.
- The command ended with:

```text
Result: ok (no changes written) (exit 0)
```

Result: **Pass**.

Interpretation: The installer could build and apply a dry-run plan for all scaffold files without writing to disk.

---

## 10. First materialisation

### 10.1 `install`

Command:

```bat
vendor\bin\citomni-installer install
```

Observed outcome:

- All 11 `citomni/cli` entries were reported as `created`.
- All 38 `citomni/http` entries were reported as `created`.
- The command ended with:

```text
Result: ok (exit 0)
```

A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 0
```

Result: **Pass**.

Interpretation: The installer successfully materialised all scaffold files and returned success.

---

## 11. Post-install status and state-based baseline tracking

### 11.1 `status` after first install

Command:

```bat
vendor\bin\citomni-installer status
```

Observed outcome:

- Managed entries, such as `bin/citomni`, `public/index.php`, `public/.htaccess`, `public/uploads/.htaccess`, `templates/.htaccess`, and `config/tpl/...`, were reported as `up_to_date` with reason `matches_baseline`.
- Create-only entries, such as configuration files, starter code, `.gitkeep` placeholders, and starter templates, were reported as `create_only_present`.
- The command ended with:

```text
Result: up to date (exit 0)
```

A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 0
```

Result: **Pass**.

Interpretation: The installer state and written files were mutually consistent after first materialisation.

---

## 12. Idempotent installation

### 12.1 Repeated `install`

Command:

```bat
vendor\bin\citomni-installer install
```

Observed outcome:

- Previously created managed files were reported as `skipped` with reason `matches_baseline`.
- Previously created create-only files were reported as `skipped` with reason `create_only_present`.
- The command ended with:

```text
Result: ok (exit 0)
```

A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 0
```

Result: **Pass**.

Interpretation: Re-running `install` against an already materialised app did not rewrite files unnecessarily and returned success.

---

## 13. Local modification detection

### 13.1 Manual local edit

Command:

```bat
echo // local edit>> public\index.php
```

Result: **Setup step**.

### 13.2 Package-scoped status after local edit

Command:

```bat
vendor\bin\citomni-installer status --package=citomni/http
```

Observed relevant output:

```text
public/index.php                 local_modified       rendered_checksum_mismatch
Result: conflicts / local changes (exit 4)
```

In one earlier command form using `& echo Exit: %ERRORLEVEL%` on the same line, CMD printed a stale exit value because `%ERRORLEVEL%` was expanded before the installer command executed. This was identified as a shell-observation issue rather than an installer issue. Later two-line checks were used where necessary.

Result: **Pass**.

Interpretation: The installer correctly detected that a managed file had diverged from its recorded baseline and reported a conflict/local-change state.

---

## 14. Safe sync behaviour without force

### 14.1 `sync` on locally modified managed file

Command:

```bat
vendor\bin\citomni-installer sync public/index.php --package=citomni/http
```

Observed output:

```text
public/index.php                 wrote_new    rendered_checksum_mismatch
      wrote:  C:\dev\www\citomni\_lab\installer-release-smoke\public\index.php.new

Result: conflicts / manual action required (exit 4)
```

A subsequent same-line `echo Exit: %ERRORLEVEL%` reported exit 4 in this case.

Result: **Pass**.

Interpretation: The installer did not overwrite the locally modified `public/index.php`. Instead, it wrote a sibling `.new` file and returned exit code 4 for manual action.

---

## 15. Forced overwrite and backup behaviour

### 15.1 Pre-confirmation-force implementation behaviour

Command:

```bat
vendor\bin\citomni-installer sync public/index.php --package=citomni/http --force
```

Observed relevant output:

```text
public/index.php                 updated      forced_overwrite
      backup: C:\dev\www\citomni\_lab\installer-release-smoke\var/backups/citomni-installer/20260610T104531Z/public/index.php

Backups: C:\dev\www\citomni\_lab\installer-release-smoke/var/backups/citomni-installer/20260610T104531Z

Result: ok (exit 0)
```

The same-line `echo Exit: %ERRORLEVEL%` printed a stale value due to CMD expansion; the installer output itself reported exit 0.

Result: **Pass**.

Interpretation: Forced overwrite replaced the locally modified file and created a backup.

---

## 16. Repair from recorded state

### 16.1 Delete managed file

Command:

```bat
del public\index.php
```

Result: **Setup step**.

### 16.2 Status after deletion

Command:

```bat
vendor\bin\citomni-installer status --package=citomni/http
```

Observed relevant output:

```text
public/index.php                 missing              target_missing
Result: updates available (exit 3)
```

A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 3
```

Result: **Pass**.

### 16.3 Repair command

Command:

```bat
vendor\bin\citomni-installer repair --package=citomni/http
```

Observed relevant output:

```text
public/index.php                 created      recreated_from_recorded
Result: ok (exit 0)
```

All existing files in the HTTP package scope were reported as `skipped` with reason `exists_untouched`. A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 0
```

Result: **Pass**.

### 16.4 Status after repair

Command:

```bat
vendor\bin\citomni-installer status --package=citomni/http
```

Observed relevant output:

```text
public/index.php                 up_to_date           matches_baseline
Result: up to date (exit 0)
```

A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 0
```

Result: **Pass**.

Interpretation: `repair` recreated the missing managed file from recorded installer state and left existing files untouched.

---

## 17. Force-confirmation semantics

After the force-confirmation implementation was added, the following tests were performed.

### 17.1 `--force` prompts and proceeds on `yes`

Setup:

```bat
echo // local force prompt test>> public\index.php
```

Command:

```bat
vendor\bin\citomni-installer sync public/index.php --package=citomni/http --force
```

Observed prompt and answer:

```text
Force will overwrite 1 existing file and create backup.
Type 'yes' to continue: yes
```

Observed result:

```text
public/index.php                 updated      forced_overwrite
      backup: C:\dev\www\citomni\_lab\installer-release-smoke\var\backups\citomni-installer/20260610T111434Z/public/index.php

Backups: C:\dev\www\citomni\_lab\installer-release-smoke/var/backups/citomni-installer/20260610T111434Z

Result: ok (exit 0)
```

Result: **Pass**.

### 17.2 `--force` prompts and cancels on `no`

Setup:

```bat
echo // local force prompt test>> public\index.php
```

Command:

```bat
vendor\bin\citomni-installer sync public/index.php --package=citomni/http --force
```

Observed prompt and answer:

```text
Force will overwrite 1 existing file and create backup.
Type 'yes' to continue: no
Cancelled by user.
```

A later explicit cancellation-exit test observed:

```text
Exit: 1
```

Result: **Pass**.

Interpretation: Plain `--force` requires interactive confirmation and cancellation returns exit code 1.

### 17.3 `--force=yes` proceeds without prompt

Setup:

```bat
echo // local force prompt test>> public\index.php
```

Command:

```bat
vendor\bin\citomni-installer sync public/index.php --package=citomni/http --force=yes
```

Observed result:

```text
public/index.php                 updated      forced_overwrite
      backup: C:\dev\www\citomni\_lab\installer-release-smoke\var\backups\citomni-installer/20260610T111520Z/public/index.php

Backups: C:\dev\www\citomni\_lab\installer-release-smoke/var/backups/citomni-installer/20260610T111520Z

Result: ok (exit 0)
```

A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 0
```

Result: **Pass**.

Interpretation: `--force=yes` acts as non-interactive confirmation and performs the forced overwrite with backup.

### 17.4 JSON mode refuses interactive force

Setup:

```bat
echo // local json force test>> public\index.php
```

Command:

```bat
vendor\bin\citomni-installer sync public/index.php --package=citomni/http --force --format=json
```

Observed JSON output:

```json
{
    "ok": false,
    "exit_code": 2,
    "command": "sync",
    "error": "Interactive confirmation is not available with --format=json. Use --force=yes to confirm forced overwrites.",
    "packages": []
}
```

A subsequent `echo Exit: %ERRORLEVEL%` reported:

```text
Exit: 2
```

Result: **Pass**.

Interpretation: JSON mode does not attempt interactive confirmation and requires `--force=yes` for forced overwrites.

---

## 18. Behavioural conclusions

The following behaviours were empirically established by the manual integration tests:

1. The installer can be consumed as a Composer dependency via local path repositories.
2. The installer binary executes correctly through `vendor\bin` from an application root.
3. The installer discovers `citomni/cli` and `citomni/http` scaffold manifests through Composer-installed package metadata.
4. Initial `status` correctly reports missing scaffold targets and exits with code 3.
5. `install --dry-run` correctly computes a complete creation plan without writing.
6. `install` creates the expected scaffold files and exits with code 0.
7. Post-install `status` correctly distinguishes managed `up_to_date` files from `create_only_present` files.
8. Repeated `install` is idempotent and returns success without rewriting files.
9. Local modification of a managed file is detected as `local_modified` with `rendered_checksum_mismatch`.
10. `sync` without force writes a `.new` file rather than overwriting a locally modified managed file.
11. Forced overwrite creates a backup and replaces the target file.
12. `repair` recreates a missing managed file from recorded state and leaves existing files untouched.
13. Plain `--force` now requires interactive confirmation for destructive forced overwrites.
14. `--force=yes` confirms destructive forced overwrites without an interactive prompt.
15. `--format=json` rejects interactive force and requires `--force=yes`.

---

## 19. Defects and observations

### 19.1 Defect: Whitespace-padded placeholder rejected

Initial dry-run failed on:

```text
{{ CITOMNI_ENVIRONMENT }}
```

The renderer was patched to trim placeholder token keys before validation and lookup. The renderer-level smoke check subsequently rendered the token to `dev`, and the full `install --dry-run` passed.

Status: **Resolved in tested working tree**.

### 19.2 Observation: CMD `%ERRORLEVEL%` on same line can be stale

When commands were run as:

```bat
vendor\bin\citomni-installer ... & echo Exit: %ERRORLEVEL%
```

CMD sometimes printed a stale exit value because `%ERRORLEVEL%` was expanded before command execution. Later checks used separate lines where strict exit-code observation was necessary.

Status: **Shell observation issue, not an installer defect**.

### 19.3 Observation: Mixed slash styles in backup-path output

Backup paths on Windows were displayed with mixed slash styles, for example a backslash-prefixed Windows path followed by slash-separated suffixes.

Status: **Cosmetic observation, not demonstrated as a functional defect**.

---

## 20. Release-readiness assessment

Based strictly on the observed tests, `citomni/installer` reached a release-ready manual integration state for the tested scope. The core release-critical behaviours of discovery, planning, writing, state tracking, idempotence, conflict avoidance, forced overwrite with backup, repair, and force confirmation were all exercised successfully.

The evidence supports proceeding toward an initial release, subject to ordinary repository hygiene: committing the modified files, validating Composer metadata, and tagging the release. This report does not substitute for Packagist/dist verification or automated regression tests, both of which remain advisable as subsequent controls.

---

## 21. Recommended final pre-tag checklist

The following checklist is grounded in the observed state at the end of testing:

```bat
cd /d C:\dev\www\citomni\installer
composer validate --strict
composer dump-autoload -o
git status
```

The modified files observed before commit were:

```text
src/Cli/Command/AbstractWriteCommand.php
src/Cli/InstallerCli.php
src/Support/ScaffoldRenderer.php
```

After review and staging, an appropriate commit may be created before tagging `1.0.0`.

---

## 22. Overall result

**Result:** Pass for the tested manual integration scope.  
**Release confidence from observed evidence:** High.  
**Known unresolved release blocker from these tests:** None.  
**Not covered:** Packagist/dist install, cross-platform execution, malformed manifest cases, explicit IO failure injection, and automated regression coverage.
