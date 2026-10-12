# Changelog

All notable changes to `pipeline-kit` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-10-12

### Added

- Artisan command `pipeline:status` that reports, for each item
  `pipeline:install` manages, whether it's missing, partially configured,
  present but drifted from the bundled stub, or fully configured — without
  writing anything.
- Artisan command `pipeline:ai-review-gate`, wired into the Claude Code
  `Stop` hook, so a session can't end once `composer quality` passes
  without an independent AI Code Review having run first, once per
  reviewed diff.

### Changed

- Translated `stubs/PIPELINE.md` from Portuguese to English.
- Documented the `sloppy diff`/staging caveat (new files must be `git add`ed
  before `sloppy diff` sees them) in `stubs/CLAUDE.md`.

### Fixed

- Ignore `.env` in `.gitignore`.

## [1.0.0] - 2026-10-06

### Added

- Artisan command `pipeline:install` (`--dry-run`, `--force`), auto-discovered
  via `Bartollo\PipelineKit\PipelineKitServiceProvider`, that
  installs/merges a Claude Code + Sloppy + Laravel Boost quality pipeline
  into the Laravel project it's installed in.
- Standalone, dependency-free `install.php` script for installing the same
  pipeline into another project without adding a Composer dependency.
- `PipelineMerger`: framework-free merge logic shared by both entry points —
  additive and idempotent for every file it touches:
  - `.mcp.json` — merges `mcpServers` entries.
  - `.claude/settings.json` — merges hook entries by matching command,
    without duplicating on repeated runs.
  - `.claude/settings.local.json` — unions `enabledMcpjsonServers` without
    removing existing entries.
  - `phpstan.neon`, `boost.json`, `CLAUDE.md`, `PIPELINE.md` — copied only if
    missing in the target (`--force` overwrites with a `.bak` backup first).
  - `composer.json` — adds only the missing pipeline-related `require-dev`
    packages and `scripts` entries; anything already present in the target
    is left untouched.
- Bundled `stubs/` with the pipeline's config/doc files, plus
  `bin/sync-stubs.php` to resync them from the source project.
- PHPUnit test suite for `PipelineMerger` (no Laravel bootstrap required).

[1.0.1]: https://github.com/bartollo/pipeline-kit/releases/tag/v1.0.1
[1.0.0]: https://github.com/bartollo/pipeline-kit/releases/tag/v1.0.0
