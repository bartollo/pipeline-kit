# pipeline-kit

Installs/merges the Claude Code + Sloppy + Laravel Boost quality pipeline
(described in [`stubs/PIPELINE.md`](stubs/PIPELINE.md)) into another Laravel
project. Available in two forms:

- **Standalone script** (`install.php`) — no installation needed, run from
  outside the target project.
- **Composer package with an artisan command** (this README) — installed
  into the target project and run from inside it via
  `php artisan pipeline:install`.

## Installing it in another project

This package's code lives at
[`github.com/bartollo/pipeline-kit`](https://github.com/bartollo/pipeline-kit)
(tag `v1.0.0` onward). While it isn't published on Packagist yet, add it as a
`vcs` repository in the **other** project's `composer.json`:

```jsonc
"repositories": [
    { "type": "vcs", "url": "https://github.com/bartollo/pipeline-kit.git" }
]
```

Then:

```bash
composer require --dev bartollo/pipeline-kit:^1.0
```

If the package is already on Packagist, the `repositories` step above isn't
needed — `composer require --dev bartollo/pipeline-kit` resolves it directly.

If both projects sit side by side on disk (as in this monorepo, which uses
`pipeline-kit/` itself as a local dependency for dogfooding), a `path`
repository works too:

```jsonc
"repositories": [
    { "type": "path", "url": "../laravel-ai-pipeline/pipeline-kit" }
]
```
```bash
composer require --dev bartollo/pipeline-kit:@dev
```

The service provider is auto-discovered by Laravel (`extra.laravel.providers`
in the package's `composer.json`) — nothing to register manually.

## Usage

```bash
php artisan pipeline:install              # applies it
php artisan pipeline:install --dry-run    # just shows what it would do
php artisan pipeline:install --force      # overwrites phpstan.neon, boost.json,
                                           # CLAUDE.md and PIPELINE.md if they
                                           # already exist (backs up to .bak first)
```

The command always operates on the project it's run in (`base_path()`) — it
doesn't take a target path, because it's already installed inside the
project you want to configure.

Same additive/idempotent logic as the standalone script: it never overwrites
hooks, `require-dev` packages, or scripts already present in the target,
unless you pass `--force` (and even then, only for the four whole-file
copies, never for `composer.json`).

## Package structure

```
pipeline-kit/
├── composer.json              # package manifest (bartollo/pipeline-kit)
├── src/
│   ├── PipelineKitServiceProvider.php
│   ├── Console/Commands/InstallPipelineCommand.php
│   └── PipelineMerger.php      # merge logic, no Laravel dependency
├── stubs/                      # bundled copies of .mcp.json, CLAUDE.md, etc.
├── bin/sync-stubs.php          # resyncs stubs/ from the source project's real files
└── tests/PipelineMergerTest.php
```

## Maintenance (only relevant to whoever maintains the source repo)

The files under `stubs/` are copies — once installed in another project, the
package no longer has access to the source repo's real files. After editing
`CLAUDE.md`, `PIPELINE.md`, `.mcp.json`, `.claude/settings*.json`,
`phpstan.neon`, `boost.json`, or the pipeline's package/script list, run:

```bash
php pipeline-kit/bin/sync-stubs.php
```

## Tests

```bash
cd pipeline-kit && php ../vendor/bin/phpunit -c phpunit.xml
```

(`PipelineMerger` doesn't depend on Laravel, so the tests run with plain
PHPUnit — no need to install the package or use orchestra/testbench.)
