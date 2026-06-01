# CitOmni Installer

Official scaffold lifecycle tool for CitOmni applications.

`citomni/installer` is the package-owned scaffold materializer for the CitOmni ecosystem. It discovers scaffold manifests from installed Composer packages, renders package-owned scaffold files, writes the few files that must physically exist in the application layer, and tracks their state so future repairs and scaffold updates can be handled safely.

The package is deliberately narrow. It is not a framework runtime, not a web updater, not a Composer replacement, not a historical restore tool, and not a merge engine with a heroic cape and a questionable threat model.

In practical terms, `citomni/installer` lets CitOmni keep `citomni/app-skeleton` neutral and almost empty while allowing packages such as `citomni/http` and `citomni/cli` to own the runtime-near entrypoints and scaffold files that belong to them.

---

## Highlights

- **Official scaffold lifecycle tool for CitOmni** with explicit ownership of scaffold discovery, rendering, planning, status, repair, sync, and state.
- **Composer-native model** where scaffold files come from the currently installed package versions, not from live GitHub fetches or mutable remote templates.
- **Neutral app skeleton support** so `citomni/app-skeleton` can remain a thin application container instead of a pile of mode-specific runtime files.
- **Conservative write behavior** with no blind overwrites, `.new` files for blocked upstream updates, and backups before forced replacement.
- **Stateful managed-file sync** using both raw stub checksums and rendered-file checksums to separate upstream scaffold drift from local changes.
- **Bootstrap-independent CLI** through `vendor/bin/citomni-installer`, without requiring `citomni/cli`, `citomni/http`, or a running CitOmni application.
- **Stable JSON output** for DevKit, CI, deploy hooks, and other tooling that needs machine-readable scaffold status.

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
- Convention fallback to `resources/citomni/scaffold.php` when no explicit Composer extra value exists.
- Manifest validation, package-name validation, and manifest version validation.
- Deterministic path normalization and guard checks before any write operation.

### Scaffold materialization

- Creation of missing scaffold files from package-owned stubs.
- Placeholder rendering using deterministic placeholder sources.
- Registration of rendered files in app-local installer state.
- Conservative handling of existing unknown files.
- Targeted package filtering through `--package=vendor/package`.

### Scaffold lifecycle commands

- `doctor` for read-only environment validation.
- `status` for read-only scaffold status.
- `install` for first-time package scaffold materialization.
- `repair` for recreating missing scaffold files from the currently installed package version.
- `sync` for controlled updates of managed scaffold files.
- `diff` for comparing local files against the current rendered upstream version.

### Integration output

- Human-readable CLI output by default.
- JSON output for DevKit, CI, deployment tooling, and automation.
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
- App-local scaffold state format.
- Checksum semantics for raw stubs and rendered files.
- Read-only status and doctor diagnostics.
- JSON output intended for DevKit and other tooling.

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
- `composer.json` or `composer.lock` mutation.
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

CitOmni separates project creation, dependency management, runtime packages, scaffold materialization, and developer workflow into distinct layers.

Within that model:

1. `citomni/app-skeleton` provides the neutral application container.
2. Composer installs and updates packages.
3. Runtime packages such as `citomni/http` and `citomni/cli` own their own scaffold stubs.
4. `citomni/installer` materializes package-owned scaffold into the application layer.
5. `citomni/devkit` may orchestrate a richer developer workflow around project creation, GitHub, secrets, deployment, and registration.

A minimal HTTP application can therefore start from a neutral skeleton, require `citomni/http`, and then materialize only the HTTP-owned files needed by that mode.

A minimal CLI application can do the same with `citomni/cli` without dragging a `public/` directory around like ceremonial luggage.

---

## Scaffold ownership model

Package-owned scaffold should live in the package that owns the runtime function.

Examples:

```text
vendor/citomni/http/resources/scaffold/public/index.php.stub
vendor/citomni/http/resources/scaffold/config/citomni_http_cfg.php.stub
vendor/citomni/http/resources/scaffold/config/citomni_http_routes.php.stub

vendor/citomni/cli/resources/scaffold/bin/citomni.stub
vendor/citomni/cli/resources/scaffold/config/citomni_cli_cfg.php.stub
```

Those stubs are the package-owned reference versions. The materialized files in the app are app-local runtime artifacts derived from those stubs.

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
.gitignore
README.md
bin/
config/
config/citomni_installer.php
docs/
language/
src/
templates/
tests/
var/
```

It should not need to include complete HTTP or CLI runtime entrypoints as permanent root-owned files.

Examples:

- `public/index.php` comes from `citomni/http`.
- `bin/citomni` comes from `citomni/cli`.
- `config/citomni_installer.php` belongs to the app and provides versioned placeholder configuration.

After `composer create-project`, root-level files are app-owned. `citomni/installer` must not rewrite the app README, `.gitignore`, `composer.json`, or `composer.lock` during normal operation.

---

## Requirements

- PHP **8.2+**
- Composer autoloading
- `ext-json` for JSON output

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
vendor/bin/citomni-installer install --package=citomni/http
```

### Add CLI support

```bash
composer require citomni/cli
vendor/bin/citomni-installer install --package=citomni/cli
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

`repair` is not historical restore. It recreates missing scaffold files from the currently installed package version.

---

## Composer scripts

Applications may expose shorter commands through root-level Composer scripts.

Example:

```json
{
	"scripts": {
		"citomni:status": "vendor/bin/citomni-installer status",
		"citomni:install": "vendor/bin/citomni-installer install",
		"citomni:sync": "vendor/bin/citomni-installer sync",
		"citomni:repair": "vendor/bin/citomni-installer repair",
		"citomni:diff": "vendor/bin/citomni-installer diff",
		"citomni:doctor": "vendor/bin/citomni-installer doctor"
	}
}
```

Usage:

```bash
composer citomni:status
composer citomni:sync
```

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
- Write access diagnostics for relevant app paths.
- State readability and format validation where relevant.

Examples:

```bash
vendor/bin/citomni-installer doctor
vendor/bin/citomni-installer doctor --format=json
```

### `status`

Shows scaffold status without changing files.

It reports whether known scaffold targets are missing, clean, locally modified, affected by upstream stub changes, affected by placeholder changes, or blocked from automatic sync.

Examples:

```bash
vendor/bin/citomni-installer status
vendor/bin/citomni-installer status --package=citomni/http
vendor/bin/citomni-installer status --format=json
```

### `install`

Materializes scaffold for a newly installed package or runtime mode.

Default behavior:

- Creates missing files.
- Does not overwrite existing files.
- Registers created files in installer state.
- Stores checksum and placeholder metadata where relevant.
- Fails clearly on conflicts.

Example:

```bash
vendor/bin/citomni-installer install --package=citomni/http
```

### `repair`

Recreates missing scaffold files from the currently installed package stubs.

Default behavior:

- Writes only files that are missing.
- Does not overwrite existing files.
- Uses previously registered placeholder values when state exists.
- Updates the baseline for files it recreates.
- Warns when state points to an older stub checksum than the currently installed package provides.

Example:

```bash
vendor/bin/citomni-installer repair --package=citomni/cli
```

### `sync`

Updates managed scaffold files safely.

Default behavior:

- Creates missing managed files.
- Updates files that still match the previous rendered baseline.
- Does not overwrite locally modified files.
- Writes the new upstream version to `.new` when a local modification blocks automatic sync.
- Does not automatically update `create-only` files.

Example:

```bash
vendor/bin/citomni-installer sync --package=citomni/http
```

Conflict example:

```text
public/index.php changed locally and was not overwritten.
New upstream version written to public/index.php.new
Run vendor/bin/citomni-installer diff public/index.php
```

### `diff`

Shows differences between a local app file and the current rendered upstream scaffold.

Examples:

```bash
vendor/bin/citomni-installer diff public/index.php
vendor/bin/citomni-installer diff --package=citomni/http
```

A first implementation may provide a two-way diff. A future version may add three-way comparison between previous rendered baseline, local file, and current rendered upstream.

---

## Package manifest convention

Packages that provide scaffold should declare a scaffold manifest.

Composer `extra` is the primary discovery mechanism:

```json
{
	"extra": {
		"citomni": {
			"scaffold": "resources/citomni/scaffold.php"
		}
	}
}
```

If no explicit value exists, the installer may fall back to:

```text
resources/citomni/scaffold.php
```

If both exist and point to different paths, the Composer `extra` value wins.

Example manifest:

```php
<?php

declare(strict_types=1);

return [
	'package' => 'citomni/http',
	'version' => 1,
	'files' => [
		[
			'target' => 'public/index.php',
			'source' => 'resources/scaffold/public/index.php.stub',
			'type' => 'entrypoint',
			'policy' => 'managed',
		],
		[
			'target' => 'config/citomni_http_cfg.php',
			'source' => 'resources/scaffold/config/citomni_http_cfg.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_routes.php',
			'source' => 'resources/scaffold/config/citomni_http_routes.php.stub',
			'type' => 'routes',
			'policy' => 'create-only',
		],
	],
];
```

`version` is the manifest schema version, not the package semver.

---

## Manifest fields

### `target`

Relative path in the application where the scaffold file should be materialized.

Targets must be safe relative app paths. Absolute paths, parent traversal, invalid separators, and paths outside the application root must be rejected.

### `source`

Relative path inside the package root pointing to the scaffold stub.

Sources must stay inside the package root. The installer must not follow a manifest into writing or reading arbitrary system files. Manifests are configuration, not a treasure map.

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
- Should store both `stub_checksum` and `rendered_checksum` in state.
- Should produce `.new` when an upstream update is available but local changes block automatic sync.

### `create-only`

Used for files that should normally be created once and then left to the application.

Examples:

- `config/citomni_http_cfg.php`
- `config/citomni_http_routes.php`
- `config/citomni_cli_cfg.php`

Rules:

- Create if missing.
- Register in state.
- Do not update automatically.
- Do not treat upstream drift as an automatic update signal.
- Require explicit force or manual action for replacement.

---

## Placeholder rendering

Scaffold stubs may use simple placeholders.

Examples:

```text
{{APP_NAMESPACE}}
{{APP_NAME}}
{{CITOMNI_ENVIRONMENT}}
{{PACKAGE_VERSION}}
```

MVP placeholder rules:

- Keep placeholders few and explicit.
- Do not use a general template engine.
- Do not use `eval`.
- Do not allow arbitrary PHP execution.
- Fail on unknown placeholders.
- Resolve placeholders from deterministic sources.
- Store the actual placeholder snapshot used for each rendered file in installer state.

The application-level placeholder config should live here:

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

Recommended placeholder priority:

1. CLI options.
2. `config/citomni_installer.php`.
3. `composer.json`.
4. Existing CitOmni config.
5. Explicit package defaults.

Example:

```bash
vendor/bin/citomni-installer install --package=citomni/http --placeholder=APP_NAMESPACE=App
```

`APP_NAMESPACE` should be explicit when Composer autoload configuration is ambiguous.

---

## State and checksums

Installer state is stored in the application, not in the installer package, runtime package, or `/vendor/`.

Recommended location:

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

Default behavior is conservative.

| Situation | Default behavior |
|---|---|
| Target is missing | Create file |
| Target exists and is unknown | Stop or write `.new` |
| Target matches previous `rendered_checksum` | Update if policy is `managed` |
| Target is locally modified | Do not overwrite |
| Target is `create-only` and exists | Do not touch |
| Target is outside app root | Fail |
| Source is missing in package | Fail |
| Manifest is invalid | Fail |
| State is unknown or unsafe | Fail without writing |

When `--force` is used to overwrite a file, the installer must create a backup first.

Recommended backup location:

```text
var/backups/citomni-installer/YYYY-MM-DD-HHMMSS/path/to/file
```

Writes should be atomic where practical. Scaffold files, `.new` files, backups, and state should be written through a temporary file in the same directory and then renamed into place.

---

## Exit codes

CLI exit codes should be stable so DevKit, CI, and deploy scripts can make deterministic decisions.

Recommended codes:

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

- `status` may return `3` when an update is available but no local conflict exists.
- `status` may return `4` when local changes block sync.
- `sync` may return `4` when it writes `.new` because a local file was modified.
- `doctor` may return `5` when the state format is unknown.

---

## JSON output

Most commands should support JSON output.

Example:

```bash
vendor/bin/citomni-installer status --format=json
```

JSON output should be stable enough for DevKit and automation. It should include package name, type, policy, target, status, reason, and whether sync is possible without conflict.

Reason values should distinguish at least:

- Missing target.
- Local modification.
- Upstream stub drift.
- Placeholder drift.
- Invalid manifest.
- Unsafe state.
- Permission issue.

Human output can be friendly. JSON output should be boring. Boring is a feature when another program has to parse it.

---

## DevKit integration

`citomni/devkit` can use `citomni/installer` as the scaffold engine in a larger app-creation workflow.

A typical DevKit flow may look like this:

```text
Create app in DevKit
	composer create-project citomni/app-skeleton <path>
	composer require citomni/http and/or citomni/cli
	vendor/bin/citomni-installer install --package=citomni/http
	vendor/bin/citomni-installer install --package=citomni/cli
	Create GitHub repository
	Push initial commit
	Register app in DevKit DB
	Create deployment and secret placeholders
```

DevKit should not need to know the internal contents of `public/index.php`, `bin/citomni`, HTTP config stubs, or CLI config stubs. It should ask the installer for status or ask the installer to materialize the package-owned scaffold.

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

- PHP 8.2+
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
