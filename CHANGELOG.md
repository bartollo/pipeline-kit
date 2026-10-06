# Changelog

All notable changes to `pipeline-kit` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/bartollo/pipeline-kit/releases/tag/v1.0.0
