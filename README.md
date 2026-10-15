# CitOmni Installer

Official scaffold lifecycle tool for CitOmni applications.

Environment materialization uses explicit `dev`, `stage`, or `prod` selection and strict state format 2. See [environment materialization](docs/environment-materialization.md) for recovery, locking, Composer requirements, and tests.

`citomni/installer` is the package-owned scaffold materializer for the CitOmni ecosystem. It discovers scaffold manifests from installed Composer packages, renders package-owned scaffold files, writes the few files that must physically exist in the application layer, and tracks their state so future repairs and scaffold updates can be handled safely.

The package is deliberately narrow. It is not a framework runtime, not a web updater, not a Composer replacement, not a historical restore tool, and not a merge engine with a heroic cape and a questionable threat model.

In practical terms, `citomni/installer` lets CitOmni keep `citomni/app-skeleton` neutral and almost empty while allowing packages such as `citomni/http` and `citomni/cli` to own the runtime-near entrypoints and scaffold files that belong to them.

---

## Highlights

- **Official scaffold lifecycle tool for CitOmni** with explicit ownership of scaffold discovery, rendering, planning, status, repair, sync, and state.
- **Composer-native scaffold model** where package-owned scaffold comes from the currently installed package versions, never from live GitHub templates.
- **Migration-only app-skeleton audit** that may fetch one pinned current `citomni/app-skeleton` commit to produce advisory `.new` references for app-owned root files.
- **Neutral app skeleton support** so `citomni/app-skeleton` can remain a thin application container instead of a pile of mode-specific runtime files.
- **Conservative write behavior** with no blind overwrites, `.new` files for locally modified managed targets during sync, confirmation before plain forced replacement, and backups before forced overwrites.
- **Stateful managed-file sync** using both raw stub checksums and rendered-file checksums to separate upstream scaffold drift from local changes.
- **Generated `.gitignore` block** that keeps package-managed scaffold targets and package runtime paths out of Git, while every other line stays app-owned.
- **Bootstrap-independent CLI** through `vendor/bin/citomni-installer`, without requiring `citomni/cli`, `citomni/http`, or a running CitOmni application.
- **Stable JSON output** for project tooling, CI, deploy hooks, and other automation that needs machine-readable scaffold status.

---

## What this package is

`citomni/installer` is the scaffold materialization and lifecycle package for CitOmni.

It handles the few package-owned files that cannot stay in `/vendor/` because they must exist in the application tree to function. Typical examples are an HTTP front controller such as `public/index.php` or a CLI entrypoint such as `bin/citomni`.

The package works from installed Composer packages. A package that owns scaffold declares a manifest, and `citomni/installer` uses that manifest to plan and apply safe changes in the application layer.

This keeps the ownership model mechanical and easy to reason about:

- Runtime packages own their scaffold stubs.
- The application owns its local code, root files, and configuration after project creation.
- Composer owns dependency resolution and installed package versions.
- `citomni/installer` owns the mechanics that materialize package-owned scaffold into the app.

No smoke, no mirrors, and preferably no wizard hat in production.

---

## What this package provides

### Scaffold discovery

- Discovery of installed Composer packages.
- Support for scaffold manifests declared through `extra.citomni.scaffold`.
- Convention fallback to `install/manifest.php` when no explicit Composer extra value exists.
- Manifest validation, package-name validation, and manifest version validation.
- Deterministic path normalization and guard checks before any write operation.

### Scaffold materialization

- Creation of missing scaffold files from package-owned stubs.
- Placeholder rendering using deterministic placeholder sources.
- Registration of rendered files in app-local installer state.
- Conservative handling of existing unknown files.
- Targeted package filtering through `--package=vendor/package` after the initial application-wide materialization.
- One delimited block in the application's `.gitignore`, generated from every installed manifest. A Git checkout without a `.gitignore` gets one.

### Scaffold lifecycle commands

- `doctor` for read-only environment validation.
- `status` for read-only scaffold status.
- `install --environment=<dev|stage|prod>` for initial scaffold and Composer materialization.
- `migrate [--environment=<dev|stage|prod>]` for repeatable migration/reconciliation with conservative PHP comparison, refreshed `.new` review sidecars, root-reference audit, recursive PHP lint, and a Markdown report. The environment defaults to `dev`.
- `environment <dev|stage|prod>` for authoritative switching of environment-aware targets.
- `repair` for recreating missing scaffold files from recorded state using the currently installed package stub and the recorded placeholder snapshot.
- `sync` for controlled updates of managed scaffold files.

Migration reports already include rendered diffs for files that need review. A standalone `diff` command may still be added for non-migration workflows.

### Integration output

- Human-readable CLI output by default.
- JSON output for project tooling, CI, deployment tooling, and automation.
- Stable machine-readable status and reason values for scaffold drift, missing files, local changes, and blocked sync.

---

## What this package owns

`citomni/installer` owns the lifecycle mechanics around package-owned scaffold.

That includes:

- The `vendor/bin/citomni-installer` CLI entrypoint.
- Discovery of scaffold manifests from installed Composer packages.
- Validation of scaffold manifests, source paths, target paths, and supported manifest versions.
- Placeholder resolution and simple placeholder rendering.
- Plan-first scaffold decisions.
- Safe write behavior for scaffold targets, `.new` files, backups, and state.
- The delimited `citomni/installer` block in the application's `.gitignore`.
- App-local scaffold state format.
- Checksum semantics for raw stubs and rendered files.
- Read-only status and doctor diagnostics.
- JSON output intended for project tooling and other automation.

This package owns the mechanism, not the application. That distinction is the whole point.

---

## What this package does not own

`citomni/installer` is intentionally not a general application generator or updater.

It does **not** own:

- HTTP runtime behavior.
- CLI runtime behavior.
- The contents of HTTP scaffold stubs.
- The contents of CLI scaffold stubs.
- Application business code.
- Application root-level files after `composer create-project`.
- General `composer.json` editing or `composer.lock` mutation. Environment materialization sets only `config.classmap-authoritative` through Composer and regenerates autoload files.
- Composer dependency resolution.
- Composer `require` or `update` execution.
- GitHub repository creation.
- Deployment profiles.
- Secrets management.
- Historical restoration of previous scaffold bytes.
- Automatic merging of local modifications.
- HTTP write access to the codebase.

Those responsibilities belong elsewhere. The installer stays small because that is where most of its safety comes from.

---

## Relationship to other CitOmni packages

CitOmni separates project creation, dependency management, runtime packages, scaffold materialization, and project tooling into distinct layers.

Within that model:

1. `citomni/app-skeleton` provides the neutral application container.
2. Composer installs and updates packages.
3. Runtime packages such as `citomni/http` and `citomni/cli` own their own scaffold stubs.
4. `citomni/installer` materializes package-owned scaffold into the application layer.
5. External project tooling may orchestrate richer workflows around project creation, repository setup, secrets, deployment, and registration.

A minimal HTTP application can therefore start from a neutral skeleton, require `citomni/http`, and then materialize only the HTTP-owned files needed by that mode.

A minimal CLI application can do the same with `citomni/cli` without dragging a `public/` directory around like ceremonial luggage.

---

## Scaffold ownership model

Package-owned scaffold should live in the package that owns the runtime function.

Examples:

```text
vendor/citomni/http/install/manifest.php
vendor/citomni/http/install/scaffold/public/index.php.dev.stub
vendor/citomni/http/install/scaffold/public/index.php.stage.stub
vendor/citomni/http/install/scaffold/public/index.php.prod.stub
vendor/citomni/http/install/scaffold/config/citomni_http_cfg.php.stub
vendor/citomni/http/install/scaffold/config/citomni_http_routes.php.stub

vendor/citomni/cli/install/manifest.php
vendor/citomni/cli/install/scaffold/bin/citomni.dev.stub
vendor/citomni/cli/install/scaffold/bin/citomni.stage.stub
vendor/citomni/cli/install/scaffold/bin/citomni.prod.stub
vendor/citomni/cli/install/scaffold/config/citomni_cli_cfg.php.stub
```

Those stubs are the package-owned reference versions. The materialized files in the app are app-local runtime artifacts derived from those stubs. Environment-aware targets such as `public/index.php` and `bin/citomni` are materialized from the stub for the selected environment.

Examples:

```text
public/index.php
bin/citomni
config/citomni_http_cfg.php
config/citomni_cli_cfg.php
```

This duplication is intentional. The files have different lifecycles:

- The stub in `/vendor/` follows the installed package version.
- The materialized file in the app may be present, missing, clean, locally modified, or ready for sync.

Composer remains the source of truth for which upstream scaffold version is available.

---

## Application skeleton model

`citomni/app-skeleton` should stay neutral and mode-free.

It may provide the application container and base structure, such as:

```text
composer.json
.gitattributes
.gitignore
.htaccess
LICENSE
NOTICE
README.md
TRADEMARKS.md
bin/
config/
language/
src/
tests/
var/
```

It should not need to include complete HTTP or CLI runtime entrypoints as permanent root-owned files.

Examples:

- `public/index.php` comes from `citomni/http`.
- `bin/citomni` comes from `citomni/cli`.
- `config/citomni_installer.php` may be added by the app to provide versioned placeholder configuration when scaffold placeholders are needed, and to [omit create-only targets](#omitting-create-only-targets) it does not want.

After `composer create-project`, root-level files are app-owned. Outside the explicit legacy `migrate` workflow, `citomni/installer` writes only targets declared by discovered scaffold manifests, plus its own delimited block in `.gitignore`, which it creates in a Git checkout that has none (see [.gitignore block](#gitignore-block)); every line outside that block stays app-owned. App-owned files such as `README.md`, `.gitignore`, `composer.json`, and `composer.lock` should therefore not be declared as package-owned scaffold targets. `.gitignore` is reserved and rejected as a target.

During legacy migration only, the installer may compare `.gitignore`, `.gitattributes`, and `composer.json` with one pinned current `citomni/app-skeleton` commit. A difference produces an advisory sibling `<target>.new`; the live app-owned root file is never replaced by this audit. The installer block is left out of the `.gitignore` comparison and carried into `.gitignore.new`.

---

## Requirements

- PHP **8.5+**
- Composer autoloading
- `ext-json` for JSON output

`ext-curl` is optional. Legacy `migrate` uses it only for the advisory `citomni/app-skeleton` reference audit; an unavailable extension or failed GitHub request is reported without blocking package-owned scaffold migration.

`citomni/installer` is intentionally independent of `citomni/cli` and `citomni/http`. It must be able to run before either runtime package has been installed in the application.

`citomni/kernel` is not required for the installer CLI bootstrap unless a future implementation explicitly chooses that dependency.

---

## Installation

In a new CitOmni application, `citomni/installer` is normally installed through `citomni/app-skeleton`.

```bash
composer create-project citomni/app-skeleton my-app
cd my-app
```

For an existing application, install the package explicitly:

```bash
composer require citomni/installer
composer dump-autoload -o
```

The package provides its own CLI entrypoint:

```bash
vendor/bin/citomni-installer
```

No CitOmni provider registration is required for MVP usage. The installer is a CLI lifecycle tool, not a request-time runtime dependency.

---

## Typical workflows

### Add HTTP support

```bash
composer require citomni/http
vendor/bin/citomni-installer install --environment=dev
```

Environment-aware manifests select their source through `--environment`; the environment is not a placeholder override. Supply other required tokens through `config/citomni_installer.php` or repeated `--placeholder=KEY=VALUE` options.

After the initial application-wide install, package filtering is allowed with the recorded environment:

```bash
vendor/bin/citomni-installer install --environment=dev --package=citomni/http
```

### Add CLI support

```bash
composer require citomni/cli
vendor/bin/citomni-installer install --environment=dev
```

### Check scaffold status

```bash
vendor/bin/citomni-installer status
vendor/bin/citomni-installer status --package=citomni/http
vendor/bin/citomni-installer status --format=json
```

### Update framework packages and sync scaffold

```bash
composer update citomni/http citomni/installer
vendor/bin/citomni-installer status --package=citomni/http
vendor/bin/citomni-installer sync --package=citomni/http
```

### Repair missing files

```bash
vendor/bin/citomni-installer repair --package=citomni/http
```

`repair` is not historical restore. It recreates only missing targets that already have recorded installer state, using the currently installed package stub together with the placeholder snapshot stored in that state.

### Stop versioning package-managed scaffold

Package-managed targets are rendered per installation, like `vendor/`. In an application that still commits them, write the `.gitignore` block once and untrack the targets it lists as newly ignored:

```bash
vendor/bin/citomni-installer sync
git rm --cached --ignore-unmatch -- .htaccess bin/citomni public/index.php
git commit -m "Stop versioning package-managed scaffold"
```

Use the exact paths from the `sync` output; `--ignore-unmatch` skips listed targets that were never tracked. After that, a fresh checkout, a new server or a build workspace materializes these files with `install --environment=<env>`.

Every other checkout deletes these files when it pulls that commit, because Git removes files that a pulled commit stops tracking. Run `citomni-installer sync` (or `install --environment=<env>` without recorded state) right after pulling. A server that deploys with `git pull` must do so before it serves traffic, or it loses its front controller and the protecting `.htaccess` files.

---

## Composer scripts

Applications may expose shorter commands through root-level Composer scripts.

Example:

```json
{
	"scripts": {
		"citomni:doctor": "vendor/bin/citomni-installer doctor",
		"citomni:status": "vendor/bin/citomni-installer status",
		"citomni:install": "vendor/bin/citomni-installer install",
		"citomni:migrate": "vendor/bin/citomni-installer migrate",
		"citomni:environment": "vendor/bin/citomni-installer environment",
		"citomni:repair": "vendor/bin/citomni-installer repair",
		"citomni:sync": "vendor/bin/citomni-installer sync"
	}
}
```

Usage:

```bash
composer citomni:status
composer citomni:install -- --environment=dev
composer citomni:environment -- prod
composer citomni:sync
```

Keep `--environment` out of the `citomni:install` script: without it, install fails instead of materializing dev files in a stage or production checkout.

These scripts belong to the application. `citomni/installer` must not rewrite `composer.json` to add or modify them.

Avoid wiring write-capable commands such as `sync` into automatic Composer hooks. Read-only hints are friendly. Surprise file mutation after dependency update is how a tool earns side-eye.

---

## Commands

### `doctor`

Runs read-only validation of the application environment.

Typical checks include:

- Application root detection.
- Presence of `vendor/`.
- Readability of Composer metadata.
- Discovery of scaffold manifests.
- The installer config, including its [omit list](#omitting-create-only-targets): a managed target or an invalid entry fails, and an entry no installed package declares is a warning.
- Write access diagnostics for relevant app paths.
- State readability and format validation where relevant.

Examples:

```bash
vendor/bin/citomni-installer doctor
vendor/bin/citomni-installer doctor --format=json
```

### `status`

Shows scaffold status without changing files.

It reports whether known scaffold targets are missing, clean, locally modified, affected by upstream stub changes, affected by placeholder changes, or blocked from automatic sync. A create-only target the application [omits](#omitting-create-only-targets) is reported as `omitted`, which does not affect the exit code.

It also compares the [.gitignore block](#gitignore-block) with every installed package, including with `--package`: `up_to_date`, `update_available` (exit `3`, also for a Git checkout without a `.gitignore`), `malformed` (exit `4`), `absent` when the application has no `.gitignore` and lies outside a Git checkout, or `unsupported` when the installer cannot maintain it, such as a symlinked `.gitignore`.

Examples:

```bash
vendor/bin/citomni-installer status
vendor/bin/citomni-installer status --package=citomni/http
vendor/bin/citomni-installer status --format=json
```

### `install`

Materializes scaffold for an explicitly selected environment. The first install must omit `--package`; later installs may filter packages while using the recorded environment. Use `environment <env>` to switch environments, including when `--force` is supplied.

Default behavior:

- Creates missing files, except create-only targets the application [omits](#omitting-create-only-targets).
- Does not overwrite existing files.
- Registers created files and adopts existing exact rendered matches in installer state.
- Leaves a clean recorded baseline that lags behind the installed package to `sync`.
- Stores checksum and placeholder metadata where relevant.
- Writes the [.gitignore block](#gitignore-block) from every installed package, also with `--package`, and creates a missing `.gitignore` in a Git checkout. Malformed block markers stop install before it writes anything.
- Fails clearly on conflicts.

An existing environment-aware target that differs from the requested rendering stops install before anything is written. The error names every blocking target with its reason, and JSON output lists them under `packages`:

- `unknown_existing`: there is no recorded baseline, so another environment, an older package revision, and a local edit cannot be told apart. Delete a stale package-managed file so install recreates it, or install the environment the file belongs to and switch with `environment <env>`.
- `local_modified`: the file differs from its recorded baseline. `sync` writes the current rendering as `.new` for review; `environment <env>` replaces it after a backup.

Initial materialization:

```bash
vendor/bin/citomni-installer install --environment=dev
```

Forced replacement:

```bash
vendor/bin/citomni-installer install --environment=dev --package=citomni/http --force
vendor/bin/citomni-installer install --environment=dev --package=citomni/http --force=yes
```

`--force` allows existing scaffold targets to be overwritten, but asks for interactive confirmation before writing. `--force=yes` confirms the overwrite without prompting and is intended for scripts or explicitly confirmed manual runs. Forced replacements create backups before writing.

### `migrate`

Reconciles an application with the currently installed scaffold manifests and current
migration reference sources. The environment defaults to `dev` when omitted.

The command is deliberately repeatable:

- Missing state or known v1 state uses rebuild mode from current manifests.
- Current v2 state uses reconciliation mode and keeps its managed-file baselines, so
  clean upstream drift can move forward while locally modified managed files receive
  `.new` instead of being overwritten.
- Every create-only target that is not [omitted](#omitting-create-only-targets) is
  compared again against the current rendered source on every run. Legitimate
  app-specific differences therefore keep appearing as advisory review items and
  receive a freshly generated `.new` reference each time without blocking Composer
  posture or v2 state completion.
- Existing `.new` files are generated artifacts and are overwritten on every real run.
  Do not use them as a workspace for manual merge edits.
- Existing `.tpl` targets are authoritative package templates and are refreshed when
  they differ.
- `config/citomni_installer.php` is audited for missing and currently unused values.
- Current package scaffold sources are scanned for `%UPPER_SNAKE_CASE%`-style tokens and reported as advisory source-hygiene warnings without changing migrate exit behavior.
- Stubs of omitted targets are left out of the config audit and the source scan.
- The [.gitignore block](#gitignore-block) is refreshed from every installed package, and
  a missing `.gitignore` is created in a Git checkout; malformed block markers stop
  migration before it writes anything.
- `.gitignore`, `.gitattributes`, and `composer.json` are compared with one pinned
  current `citomni/app-skeleton` commit using text semantics that ignore CRLF/LF and
  one final-newline difference; real content differences are written as `.new`. The
  installer block is left out of the `.gitignore` comparison and kept in `.gitignore.new`.
- App-owned `.php` and `.php.tpl` files are recursively checked with `php -l`; vendor,
  caches, backups, uploads, Git metadata, node_modules, and root `_...` workspaces are
  excluded. Full lint output is stored in the migration report.
- Local diff content is omitted for non-template targets below `var/secrets`; `.tpl`
  targets remain diffable because they are explicit templates and must not contain populated secrets.
- Targets below `config/` also omit diff content when a PHP array key contains a known
  credential fragment such as `password`, `secret`, `access_token`, or `api_key`.
- A Markdown audit report is written under `var/logs/citomni-installer/migrations` on
  real runs.

Preview using the default development environment:

```bash
vendor/bin/citomni-installer migrate --dry-run
```

Apply using the default development environment:

```bash
vendor/bin/citomni-installer migrate
```

Select another environment explicitly when needed:

```bash
vendor/bin/citomni-installer migrate --environment=stage
vendor/bin/citomni-installer migrate --environment=prod
```

A blocking managed-file `.new`, conflict, or PHP lint failure results in exit 4 so
required corrective action cannot be missed. Create-only `.new` references and app-skeleton
root-file differences are advisory.

### `repair`

Recreates missing scaffold files from recorded installer state and the currently installed package stubs.

Default behavior:

- Writes only files that are missing.
- Does not overwrite existing files.
- Requires recorded installer state with a placeholder snapshot for the missing target; without it, the target is left untouched.
- Never recreates a create-only target the application [omits](#omitting-create-only-targets), also when state records it.
- Uses the recorded placeholder snapshot rather than current `config/citomni_installer.php` or CLI placeholder overrides for the recreated file.
- Updates the baseline for files it recreates.
- Reports `recreated_stub_drift` when the recorded stub checksum differs from the currently installed package stub.
- Ignores `--force`; existing files are never touched by `repair`.
- Does not touch `.gitignore`.

Example:

```bash
vendor/bin/citomni-installer repair --package=citomni/cli
```

### `sync`

Updates managed scaffold files safely.

Default behavior:

- Creates missing scaffold files, including missing `managed` and `create-only` targets, except create-only targets the application [omits](#omitting-create-only-targets).
- Updates files that still match the previous rendered baseline.
- Does not overwrite locally modified files.
- Writes the current rendered scaffold to `.new` when a local modification blocks automatic sync.
- Adopts an unknown existing managed file into state without rewriting it when its bytes exactly match the current rendered scaffold.
- Does not update existing `create-only` files by default; forced replacement requires `--force` or `--force=yes`.
- Refreshes the [.gitignore block](#gitignore-block) from every installed package, also with `--package`, and creates a missing `.gitignore` in a Git checkout. A positional target leaves `.gitignore` alone.

Example:

```bash
vendor/bin/citomni-installer sync --package=citomni/http
```

Forced sync:

```bash
vendor/bin/citomni-installer sync public/index.php --package=citomni/http --force
vendor/bin/citomni-installer sync public/index.php --package=citomni/http --force=yes
```

`--force` replaces the existing target after interactive confirmation and writes a backup first. `--force=yes` performs the same forced replacement without prompting. A positional target limits the run to that app-relative file; without a target, the command applies to the selected package scope.

Conflict example:

```text
public/index.php changed locally and was not overwritten.
Current rendered scaffold written to public/index.php.new
Review public/index.php and public/index.php.new before merging manually.
```

Post-MVP may add a `diff` command for comparing local app files against the current rendered upstream scaffold.

---

## Package manifest convention

Packages that provide scaffold should declare a scaffold manifest. So should packages that write runtime paths in the application, even without scaffold files, so the [.gitignore block](#gitignore-block) covers those paths (see [`gitignore`](#gitignore)).

Composer `extra` is the primary discovery mechanism:

```json
{
	"extra": {
		"citomni": {
			"scaffold": "install/manifest.php"
		}
	}
}
```

If no explicit value exists, the installer may fall back to:

```text
install/manifest.php
```

If both exist and point to different paths, the Composer `extra` value wins.

The manifest's scaffold source base is normally `install/scaffold/`.

Example manifest:

```php
<?php

declare(strict_types=1);

return [
	'package' => 'citomni/http',
	'version' => 2,
	'files' => [
		[
			'target' => 'public/index.php',
			'type' => 'entrypoint',
			'policy' => 'managed',
			'environments' => [
				'dev' => ['source' => 'install/scaffold/public/index.php.dev.stub'],
				'stage' => ['source' => 'install/scaffold/public/index.php.stage.stub'],
				'prod' => ['source' => 'install/scaffold/public/index.php.prod.stub'],
			],
		],
		[
			'target' => 'config/citomni_http_cfg.php',
			'source' => 'install/scaffold/config/citomni_http_cfg.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_routes.php',
			'source' => 'install/scaffold/config/citomni_http_routes.php.stub',
			'type' => 'routes',
			'policy' => 'create-only',
		],
	],
];
```

`version` is the manifest schema version, not the package semver. Supported versions are `1` and `2`. `environments` requires version `2`, which also accepts entries with a plain `source`.

---

## Manifest fields

### `target`

Relative path in the application where the scaffold file should be materialized.

Targets must be safe relative app paths. Absolute paths, parent traversal, invalid separators, and paths outside the application root must be rejected.

### `source`

Relative path inside the package root pointing to the scaffold stub.

Sources must stay inside the package root. The installer must not follow a manifest into writing or reading arbitrary system files. Manifests are configuration, not a treasure map.

An entry declares either `source` or `environments`, never both.

### `environments`

Per-environment sources for a target whose content differs between environments, such as an entrypoint that defines `CITOMNI_ENVIRONMENT`.

Rules:

- Requires manifest schema version `2` and policy `managed`.
- Must define exactly `dev`, `stage`, and `prod`.
- Each environment must contain exactly one non-empty `source`, which follows the same rules as `source` above.

`install --environment=<env>` materializes the selected environment's source. `sync` and `repair` use the recorded environment, and `environment <env>` switches environment-aware targets. See [environment materialization](docs/environment-materialization.md).

### `type`

Diagnostic metadata describing what the file is.

Examples:

- `entrypoint`
- `config`
- `routes`
- `server-config`

`type` may be used for output, grouping, logging, JSON responses, and future read-only UI.

### `policy`

The write behavior contract.

Supported MVP policies:

- `managed`
- `create-only`

Write behavior is controlled by `policy`, not by `type`.

### `gitignore`

Optional top-level list of `.gitignore` lines for the runtime paths the package's code writes in the application, such as caches, logs, state and secrets. Accepted in manifest versions `1` and `2`. A manifest may declare `gitignore` with an empty `files` list; `citomni/installer` declares its own runtime paths that way.

```php
'gitignore' => [
	'/var/logs/*',
	'/var/secrets/*',
	'!/var/logs/.gitkeep',
	'!/var/secrets/.gitkeep',
	'!/var/secrets/webhooks.secret.php.tpl',
],
```

Conventions for package authors:

- Ignore the contents of every runtime directory the package's code writes to under its default cfg paths, as `/var/<dir>/*` anchored at the application root. A file the package writes through another package's service is covered by that package's rule: a log written through the `citomni/infrastructure` log service falls under its `/var/logs/*`. Never name another package's files.
- Keep the directory's placeholder with `!/var/<dir>/.gitkeep`. Packages that share a runtime directory declare the same pair, and the block deduplicates it. Without the keep, the package rule would ignore the placeholder again after the application skeleton kept it.
- Never ignore a shared runtime directory literally (`/var/<dir>/` or `/var/<dir>`). Git cannot re-include a file inside an excluded directory, so discovery rejects that rule as soon as another installed package keeps a file in the directory, and every installer command then fails in applications that install both packages.
- Never use broad rules such as `/var/*`; declare only the directories the package writes.
- Keep a file the package ships and wants versioned with its own exception, such as `!/var/secrets/webhooks.secret.php.tpl`. Nothing is kept implicitly: a `create-only` target inside an ignored directory stays ignored, which is what generated secrets and runtime state need.
- The rules cover the package's default paths. An application that moves a runtime path out of its directory owns the ignore rule for the new location and writes it after the block.

The installer merges every package's lines into one [.gitignore block](#gitignore-block), with all ignore rules before all exceptions. Discovery therefore enforces:

- One rule per entry, without comments, control characters, backslash escapes, or leading or trailing whitespace.
- Ignore rules before exceptions. The merge keeps that order, so each package's own meaning is unchanged.
- An exception (`!`) is an anchored literal path without wildcards, so it cannot re-include paths it does not name. Because every exception follows every ignore rule, it does override any package's ignore rule for exactly that path.
- A directory is kept only in directory form (`!/var/data/imports/`), and the same package must ignore its contents with `/var/data/imports/*` or `/var/data/imports/**`. The directory's contents then stay ignored unless their own exceptions name them. An exception counts as a directory when it ends with `/` or when any package declares `/var/data/imports/` or a rule beneath it; any other exception names a file.
- A kept file must not be ignored by the identical rule in any package; the packages would disagree about that path.
- No exception may lie beneath a directory that any package ignores with an anchored literal rule (`/data/` or `/data`), unless that directory is itself kept in directory form; Git cannot re-include a file inside an excluded directory. Ignore the contents (`/data/*`) instead.

These checks span all installed packages and look only at the declared rules, never at the application's files. Wildcard rules are not evaluated as directory signals, so an exception for a directory must always use the directory form.

Managed targets are added to the block automatically and do not belong in this list.

---

## File policies

### `managed`

Used for small framework-near files where the package owner may continue to ship updates.

Examples:

- `public/index.php`
- `bin/citomni`

Rules:

- May be updated automatically when the local file still matches the previous rendered baseline.
- Must not be overwritten when locally modified.
- Stores both `stub_checksum` and `rendered_checksum` in state.
- Produces `.new` during `sync` when local changes block automatic replacement.

### `create-only`

Used for files that should normally be created once and then left to the application.

Examples:

- `config/citomni_http_cfg.php`
- `config/citomni_http_routes.php`
- `config/citomni_cli_cfg.php`

Rules:

- Create if missing, unless the application [omits](#omitting-create-only-targets) the target.
- Register newly created files in state.
- Do not update automatically.
- Do not treat upstream drift as an automatic update signal.
- Require explicit force or manual action for replacement.

---

## Omitting create-only targets

A create-only file belongs to the application once it exists, and that includes deleting it. The installer cannot tell a target that a package has just added from one the application removed on purpose: both are missing. Without an explicit choice, every `sync`, and every `install` in a fresh checkout or a build, creates the file again.

The application lists the create-only targets it does not want under `omit` in `config/citomni_installer.php`:

```php
<?php

declare(strict_types=1);

return [
	'placeholders' => [
		// ...
	],
	'omit' => [
		'templates/member/.gitkeep',
		'public/assets/.gitkeep',
	],
];
```

Because the file is versioned, the choice applies on every machine, in fresh checkouts and in builds. Installer state lives under `var/state` and stays on one machine, so it cannot carry the choice.

Rules:

- `install`, `sync`, `repair` and `migrate` never create, update or back up an omitted target, never write its `.new`, and never add or refresh its state record, also not with `--force`. An existing file keeps its bytes.
- `status` reports the target as `omitted` with reason `listed_in_omit`, and it does not count as drift.
- The target's stub is never read or rendered, so it requires no placeholders, and migration diagnostics ignore it.
- Only create-only targets can be omitted. A managed target is owned by its package, and the application cannot run without it, so `status`, `install`, `sync`, `repair` and `migrate` fail until the entry is removed.
- Entries are app-relative paths with forward slashes and without `..`. They are normalized like manifest targets, so `./public//assets/.gitkeep` names `public/assets/.gitkeep`, and then compared with the targets exactly; on Windows, case-insensitively.
- An entry that no installed package declares has no effect, so an application keeps working when a package stops shipping a file. `doctor` warns about it.
- Removing an entry restores ordinary create-only behavior: the next `sync` creates the file if it is missing.

Older installer versions read only `placeholders` and ignore `omit`.

---

## .gitignore block

Managed targets are rendered per installation from the installed packages, like `vendor/`, so they should not be committed. A committed managed file goes stale as soon as its package changes, and an install without recorded state cannot tell a stale rendering from another environment or a local edit. The runtime paths that packages write, such as caches, logs, sessions and generated secrets, do not belong in Git either. The installer therefore keeps one delimited block in the application's `.gitignore`.

The file has three tiers. Git uses the last matching pattern, so each tier takes precedence over the ones above it, in the same order as configuration merging: baseline, packages, application.

```gitignore
###> citomni/app-skeleton ###
# Defaults from citomni/app-skeleton. citomni/installer inserts its block below
# this one; package rules there take precedence over these defaults.
# Put application rules at the end of the file; they take precedence over both.
/vendor/
/var/state/*
!/var/state/.gitkeep
###< citomni/app-skeleton ###

###> citomni/installer ###
# Generated by citomni/installer from the installed package manifests.
# Rewritten by install, sync and migrate; edits inside this block are lost.
# Application rules belong after the block.

# Package runtime paths
/var/state/*

# Package runtime files kept in Git
!/var/state/.gitkeep

# Package-managed scaffold targets
/.htaccess
/bin/citomni
/public/index.php
###< citomni/installer ###

# Application rules: everything below takes precedence over both blocks above.
!/var/state/fixture.json
```

1. Defaults from `citomni/app-skeleton` for the runtime directories it creates, plus general conventions. They arrive with the project and belong to the application from then on; no tool rewrites them.
2. The installer block with the rules of the installed packages.
3. Application rules at the end of the file.

Rules:

- The block is generated from every installed manifest: each package's [`gitignore`](#gitignore) lines plus every `managed` target. `create-only` targets are app-owned after creation and are not added; they are ignored only when a runtime rule covers their directory.
- Lines are grouped as ignore rules, kept files and managed targets, and each group is sorted and deduplicated, so the bytes do not depend on package order. Kept files win over every ignore rule in the block, and managed targets stay ignored even when a kept file names them.
- `install`, `migrate` and `sync` without a positional target rewrite the block, also with `--package`. `repair` and `environment` never touch `.gitignore`. `status` reports a stale block as an update (exit `3`). Without any scaffold manifest, no command maintains or reports the block; `citomni/installer` ships a manifest for its own runtime paths, so an application that installs it through Composer always has one.
- The block contains no environment or timestamp, so it is identical on every machine with the same packages.
- Everything outside the markers is preserved byte for byte, including line endings and a UTF-8 BOM. A new block is inserted at the top, after marker blocks of other tools such as the `citomni/app-skeleton` block, followed by a blank line.
- A missing `.gitignore` is created with the block alone when the application lies in a Git checkout, that is when a `.git` directory or file (a linked worktree or a submodule) exists in the application root or a parent directory. Until then `status` reports `update_available` with reason `gitignore_missing` (exit `3`). Outside a Git checkout, such as an exported build or a deploy tree, a missing `.gitignore` stays missing and is reported as `absent`. Rules kept only in `.git/info/exclude` do not prevent the creation.
- A `.gitignore` the installer cannot maintain is reported as `unsupported` and left alone; it never fails a command. That covers a symlink, even one pointing inside the application, because Git does not read a symlinked `.gitignore` from the working tree, as well as a directory or an unreadable file.
- Marker lines tolerate trailing spaces and tabs and are rewritten exactly. Malformed markers (missing, repeated, out of order, or another line starting like a marker) are a conflict and leave the file untouched: `install` and `migrate` stop before writing anything, `sync` reports the conflict (exit `4`), and `status` exits `4`.
- Managed targets are written as anchored literal paths; wildcard characters and trailing spaces are escaped.

Git keeps tracking files it already tracks. When the block starts ignoring a managed target, the output lists it as newly ignored (`newly_ignored` in JSON). Untrack it once with `git rm --cached --ignore-unmatch -- <path>` and commit the result; see [Stop versioning package-managed scaffold](#stop-versioning-package-managed-scaffold) for what that means for other checkouts. Runtime files that were committed before a package rule covered them are not listed; `git ls-files -ci --exclude-standard` shows every tracked file that the current rules ignore.

---

## Placeholder rendering

Scaffold stubs may use simple placeholders.

Examples:

```text
{{APP_NAMESPACE}}
{{ APP_NAME }}
{{CITOMNI_ENVIRONMENT}}
{{ CITOMNI_ENVIRONMENT }}
```

Whitespace immediately inside the braces is ignored, but placeholder names themselves must still use uppercase keys matching `[A-Z][A-Z0-9_]*`.

MVP placeholder rules:

- Keep placeholders few and explicit.
- Do not use a general template engine.
- Do not use `eval`.
- Do not allow arbitrary PHP execution.
- Fail on unknown placeholders.
- Resolve placeholders from deterministic sources.
- Store the actual placeholder snapshot used for each rendered file in installer state.

The installer reads application-level placeholder configuration from this fixed path when the file exists:

```text
config/citomni_installer.php
```

Example:

```php
<?php

declare(strict_types=1);

return [
	'placeholders' => [
		'APP_NAMESPACE' => 'App',
		'APP_NAME' => 'My App',
		'CITOMNI_ENVIRONMENT' => 'dev',
	],
];
```

The same file can also list create-only targets to [omit](#omitting-create-only-targets).

Placeholder precedence, from highest to lowest:

1. CLI `--placeholder=KEY=VALUE` options.
2. `config/citomni_installer.php`.

`PROD_ROOT_URL` and `STAGE_ROOT_URL` must be canonical base URLs without a trailing slash. The installer rejects trailing slashes from both application config and CLI overrides before scaffold rendering or materialization continues.

Future versions may add Composer metadata, existing CitOmni config, or explicit package defaults.

Example:

```bash
vendor/bin/citomni-installer install --environment=dev --package=citomni/http --placeholder=APP_NAMESPACE=App
```

`APP_NAMESPACE` should be explicit when Composer autoload configuration is ambiguous.

---

## State and checksums

Installer state is stored in the application, not in the installer package, runtime package, or `/vendor/`.

Only state format 2 is accepted; there is no state format 1 migration or fallback. State records the application environment in addition to package/file metadata.

The state path is fixed:

```text
var/state/citomni/installer-scaffold.php
```

The state file stores enough information to determine whether a managed file is clean, locally modified, affected by upstream stub changes, or affected by placeholder changes.

For managed files, the installer uses two checksum concepts.

### `stub_checksum`

Checksum of the raw scaffold stub before placeholder rendering.

Purpose:

- Identifies the upstream stub.
- Detects package-owned scaffold changes.
- Stays independent of application placeholder values.

### `rendered_checksum`

Checksum of the rendered file that was written to the application.

Purpose:

- Detects local modifications.
- Allows safe automatic updates when the local file still matches the previous rendered baseline.

Package semver is useful metadata, but it must not drive sync decisions alone. A package can change without scaffold drift, and a dev checkout can change scaffold without a meaningful semver change. Bytes beat vibes.

---

## Write safety model

The table below covers ordinary scaffold lifecycle commands. `environment <env>` authoritatively replaces differing environment-aware targets with backups, and does not touch source-only entries.

| Situation | Default behavior |
|---|---|
| Target is missing | Create file |
| Managed target exists but has no state | `install` and `sync` adopt an exact current rendered match; otherwise `install` conflicts and `sync` writes `.new` unless forced |
| Managed target matches previous `rendered_checksum` | Leave unchanged if it also matches the current render; otherwise update to the current render |
| Target is locally modified | Do not overwrite |
| Target is `create-only` and exists | Do not touch unless explicitly forced |
| Target is `create-only` and listed under `omit` | Leave it alone, also when forced: no create, update, backup or `.new`, and no new or refreshed state record; `status` reports `omitted` |
| Environment-aware target differs during `install` | Stop before writing and list every blocking target |
| `.gitignore` installer block differs | Rewrite the block only; malformed markers conflict, and `install`/`migrate` stop before writing |
| `.gitignore` is missing in a Git checkout | Create it with the block alone; a `.gitignore` that appears after planning is a conflict |
| Target is outside app root | Fail |
| Source is missing in package | Fail, unless the target is omitted |
| Manifest is invalid | Fail |
| State is unknown or unsafe | Fail without materializing scaffold or committing state; a real write command may leave its administrative lock file |

Plain `--force` asks for confirmation before destructive writes. `--force=yes` confirms the same destructive write without prompting. Both modes create backups before replacing existing files.

When forced replacement overwrites a file, the installer must create a backup first.

Forced-overwrite backups are stored under:

```text
var/backups/citomni-installer/<utc-microseconds>-<random-suffix>/path/to/file
```

Scaffold files, `.new` files, backups, and state are written through temporary files and renamed into place. Existing Unix rwx permission bits are preserved.

Real write commands share the persistent advisory lock `var/state/citomni/installer.lock`. Dry runs create no lock or scaffold files. Planned target bytes are checked again during apply. Read `backup_dir` from the command JSON result instead of guessing the newest backup by directory modification time.

---

## Exit codes

CLI exit codes are defined explicitly so project tooling, CI, and deploy scripts can make deterministic decisions.

Exit codes:

| Code | Meaning |
|---:|---|
| `0` | OK, no problems |
| `1` | General error |
| `2` | Invalid usage or invalid arguments |
| `3` | Drift found, no conflict |
| `4` | Conflict or local changes requiring manual action |
| `5` | Unsafe state or migration required |
| `6` | IO or permission error |

Examples:

- `status` may return `3` when an update is available but no local conflict exists, including a stale `.gitignore` block or a Git checkout without a `.gitignore`.
- `status` may return `4` when local changes block sync.
- `install` returns `4` without writing when an environment-aware target conflicts.
- `sync` may return `4` when it writes `.new` because a local file was modified.
- `doctor` may return `5` when the state format is unknown.

---

## JSON output

All MVP commands support JSON output.

Example:

```bash
vendor/bin/citomni-installer status --format=json
```

JSON output should be stable enough for project tooling and automation. It includes package name, type, policy, target, status, reason, and action values that tooling can use to infer whether a file can be synced without conflict.

`status` and the write commands also return a top-level `gitignore` key: an object with `target`, `status`, `reason`, `newly_ignored`, and `error`, where write commands add `action` (`none`, `create`, `update` or `conflict`), `applied` (`created`, `updated`, `skipped`, `conflict` or `failed`; `null` when the command stopped before applying), and `error_type`. It is `null` when the command does not maintain the block, including early failures. When `install` stops before writing, `error` explains the conflict, and `packages` lists the blocking targets or `gitignore` carries the malformed block.

Interactive confirmation is not available in JSON mode. When a forced overwrite is required, automation must use `--force=yes`; plain `--force --format=json` returns an invalid-usage response instead of prompting.

Status, reason, and error values should distinguish at least:

- Missing target.
- Omitted target (`omitted`, reason `listed_in_omit`).
- Local modification.
- Upstream stub drift.
- Placeholder drift.
- Invalid manifest, usually as a top-level command error or `doctor` check.
- Unsafe state, usually as a top-level command error or `doctor` check.
- Permission issue, usually as a top-level command error or `doctor` check.

Human output can be friendly. JSON output should be boring. Boring is a feature when another program has to parse it.

---

## Tooling integration

External project tooling can use `citomni/installer` as the scaffold engine in a larger app-creation workflow.

A typical project-tooling flow may look like this:

```text
Create application workspace
	composer create-project citomni/app-skeleton <path>
	composer require citomni/http and/or citomni/cli
	vendor/bin/citomni-installer install --environment=dev
	Create project repository
	Push initial commit
	Register the application in external tooling
	Create deployment and secret placeholders
```

Project tooling should not need to know the internal contents of `public/index.php`, `bin/citomni`, HTTP config stubs, or CLI config stubs. It should ask the installer for status or ask the installer to materialize the package-owned scaffold.

---

## Legacy applications

Legacy migration is not an MVP responsibility, but the state model should allow a future `adopt` command.

A possible future flow:

```bash
composer require citomni/installer
vendor/bin/citomni-installer doctor
vendor/bin/citomni-installer status
vendor/bin/citomni-installer adopt
```

Important principles for legacy adoption:

- Do not assume an existing file is framework-owned.
- Render stubs before comparing templated files.
- Register clean matches differently from local modifications.
- Do not compare raw stub bytes directly against rendered app files.

Existing applications deserve caution. They may contain history, intentional edits, and the occasional fossil from a heroic Friday deploy.

---

## HTTP UI

HTTP UI is not part of the MVP.

A future UI may be read-only and show status, diffs, and recommended commands. It should not write to the codebase from a web request, run Composer update, mutate `/vendor/`, or act as a web updater.

If remote write behavior is ever needed, it should be handled through an out-of-band runner, deploy hook, cron job, or explicit CLI process rather than direct HTTP request execution.

---

## Runtime and architectural model

`citomni/installer` follows CitOmni's architectural discipline without requiring a full CitOmni runtime.

Recommended internal structure:

- CLI classes own argument parsing and output.
- Operations own the decision graph and return arrays.
- State classes own reading and writing installer state.
- Support classes may handle IO-oriented helper behavior.
- Utilities remain pure and side-effect free.

There is no repository layer in MVP because there is no SQL.

There is no HTTP controller in MVP because HTTP UI is not part of the first version.

There is no service-map singleton dependency in MVP because the installer must work before a CitOmni app runtime is bootable.

---

## Performance notes

- The installer is not loaded in the HTTP request boot pipeline.
- The installer should keep its dependency footprint small.
- Scaffold discovery and status checks should be deterministic and file-based.
- Runtime packages should not need the installer during normal request handling.
- Production applications should still use optimized Composer autoloading and OPcache for runtime code.

Composer example:

```json
{
	"config": {
		"optimize-autoloader": true,
		"classmap-authoritative": true,
		"apcu-autoloader": true
	}
}
```

Then run:

```bash
composer dump-autoload -o
```

---

## Error handling philosophy

Fail fast.

`citomni/installer` should reject unsafe paths, unknown manifest versions, invalid state, missing sources, unknown placeholders, and ambiguous writes before touching the application tree.

When a file is locally modified, the correct default is not to be clever. The correct default is to stop, write `.new` where appropriate, and make the human decision obvious.

Silent fallback behavior feels nice until it quietly edits the wrong file. That is not the kind of magic CitOmni is trying to collect.

---

## Contributing

- PHP 8.5+
- PSR-4
- Tabs for indentation
- K&R brace style
- Keep ownership boundaries sharp
- Keep write behavior conservative
- Keep installer independent of `citomni/cli` and `citomni/http`
- Validate paths before IO
- Build plans before writing files
- Prefer explicit failure over hidden fallback behavior
- Do not introduce HTTP write paths in MVP

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Installer** is open-source under the **MIT License**.
See [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Usage of the name or logo must follow the policy in [NOTICE](NOTICE). Do not imply endorsement or affiliation without prior written permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.
Do not register or use "citomni" or confusingly similar terms in company names, domains, social handles, or top-level vendor/package names.
For details, see [NOTICE](NOTICE).

---

## Author

Developed by Lars Grove Mortensen (c) 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.
