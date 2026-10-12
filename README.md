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

To check what's configured without changing anything, use the companion
status command:

```bash
php artisan pipeline:status           # table of every item the pipeline manages
php artisan pipeline:status --json    # same data as JSON, for scripting/CI
```

It reports one row per item (`.mcp.json` servers, Claude Code hooks,
`.claude/settings.local.json` enabled servers, whether those two are
actually tracked by git, `phpstan.neon`, `boost.json`, `CLAUDE.md`,
`PIPELINE.md`, the `composer.json` require-dev packages and scripts, and
whether the sloppy scripts carry an explicit memory limit) with one of four
statuses:

| Status     | Meaning                                                                 |
|------------|--------------------------------------------------------------------------|
| `OK`       | fully present and matching what the installer would have written        |
| `PARTIAL`  | some but not all expected entries are present (e.g. one MCP server, one missing script) |
| `MODIFIED` | the file exists but its content differs from the bundled stub — expected once Sloppy/Boost append their own sections, or once you edit it yourself |
| `MISSING`  | not installed at all                                                     |

The command exits `0` when nothing is `MISSING` or `PARTIAL` (so it can gate
a CI step or a pre-deploy check), and `1` otherwise.

One row, **Claude Code hook reachability**, checks something `pipeline:install`
itself can't: Claude Code only reads `.claude/settings.json` (and therefore
its hooks) from the project root where a session was opened — never from a
subdirectory. If this project turns out to be nested inside a bigger git
repository (a monorepo with `site/`, `backoffice/`, `api/`, …) and that
repo's root has no matching hooks, this row comes back `MISSING` even though
every other row is `OK` — that combination means the Sloppy hooks installed
here will silently never fire for a session opened at the repo root. Fix it
by mirroring the hooks into `.claude/settings.json` at the repo root (routing
by the edited file's path if more than one sub-app has Sloppy installed), or
by opening Claude Code sessions inside this project instead of the monorepo
root.

A related row, **Claude Code config committed to git**, catches a quieter
version of the same problem: `.mcp.json` and `.claude/settings.json` can
exist on disk, pass every other check here, and still never reach a
teammate, if `.gitignore` excludes them — directly, or because a blanket
`.claude/` rule swallows `settings.json` along with the genuinely-personal
`settings.local.json` it was meant for. From anyone who hasn't got this
exact working copy, a gitignored file is indistinguishable from one that was
never installed — a fresh `git clone` just won't have it. This row runs
`git check-ignore` against both files and comes back `MISSING`/`PARTIAL` if
either is excluded; only `settings.local.json` is supposed to be gitignored,
so commit the rest.

Another row, **Sloppy memory limit**, flags `sloppy`/`sloppy:diff`/
`sloppy:review` scripts that don't raise PHP's memory limit. `vendor/bin/sloppy`
indexes the whole project for cross-file analysis and can exceed the default
128M on anything past a small codebase — the fragment now runs it the same
way `types` already runs Pest, via `php -d memory_limit=2G vendor/bin/sloppy
...`. Because `pipeline:install` never rewrites a script that's already
present (see below), a project that merged the fragment before this fix
is stuck without it until the scripts are edited by hand.

Same additive/idempotent logic as the standalone script: it never overwrites
hooks, `require-dev` packages, or scripts already present in the target,
unless you pass `--force` (and even then, only for the four whole-file
copies, never for `composer.json`).

Every run that actually merges `composer.json` backs it up to
`composer.json.bak` first, regardless of `--force` — this happens on the
very first install too, not only when re-running against an existing one. It's
redundant with your git history, so it's safe to delete once you've reviewed
the diff.

## After installing

The command prints a "Next steps" hint, but a few of those steps have sharp
edges worth knowing up front:

1. **Scope your `composer update`.** A bare `composer update` resolves the
   *entire* dependency tree, not just the packages the fragment just added —
   on an existing project this can silently upgrade dozens of unrelated
   packages (framework patch bumps, a major version jump in some transitive
   dependency, etc.). Prefer naming the packages `composer.json` just gained:

   ```bash
   composer update bartollo/pipeline-kit heyosseus/sloppy larastan/larastan \
       laravel/boost laravel/pint pestphp/pest-plugin-type-coverage \
       phpunit/phpunit --with-dependencies --no-interaction
   composer install --no-interaction
   ```

   If you already ran a bare `composer update` before noticing, `git checkout
   -- composer.lock` to undo it and re-run scoped — check the lock diff
   afterwards for anything beyond new entries and alphabetical reordering.

2. **`laravel/pao` can conflict with the project's `collision`/`phpunit`/
   `pest`/`framework` versions.** `laravel/pao` declares (in its own
   `composer.json`) explicit `conflict` entries against version ranges of
   those packages — but which ranges depends on *which version of `pao`*
   Composer ends up resolving, and that table has changed across `pao`'s own
   releases (an older `pao` release conflicted with `pestphp/pest <4.6.3`
   directly; the version current at the time of writing instead conflicts
   with `nunomaduro/collision <8.9.3`,
   `phpunit/phpunit <12.5.23 || >=13.0.0 <13.1.7 || >=14.0.0`, and
   `laravel/framework <12.0.0`). So this isn't a fixed, permanent
   incompatibility — it's whichever `pao` version and collision/phpunit/pest
   version the target project happens to pair up. If the target project's
   versions of those packages don't satisfy whatever range the resolved
   `pao` version declares, Composer reports a conflict instead of installing
   anything.

   Don't trust a stale list (including the one above) — check what Composer
   actually resolves for *this* project before assuming it's blocked:

   ```bash
   composer require --dev laravel/pao --dry-run
   ```

   If that resolves cleanly, drop `--dry-run` and you're done. If it still
   conflicts, Composer's own output names the exact package(s) and version
   range at fault — bump those specific packages (not a bare
   `composer update`, per point 1 above) rather than dropping `laravel/pao`;
   removing it should be a last resort for a project that can't move those
   versions yet.

3. **`vendor/bin/sloppy rules --format=claude` writes into `CLAUDE.md`
   directly** — it's not a "copy this output by hand" step. It inserts or
   updates a single block delimited by `<!-- sloppy:begin -->` /
   `<!-- sloppy:end -->` and leaves the rest of the file untouched.

4. **`php artisan boost:install` can take a couple of minutes** (agent
   detection plus MCP server installs); it's safe to run in the background.
   Besides updating `CLAUDE.md`, it may also create an `AGENTS.md` if it
   detects an agent (e.g. GitHub Copilot) that reads guidelines from that
   file instead.

5. **`sloppy:diff`/`sloppy:review` assume the trunk branch is called
   `main`.** The bundled scripts run `sloppy diff main` / `sloppy review
   main` — `pipeline:install` has no way to know what this project's actual
   integration branch is called. If it's `develop`, `master`, or anything
   else, both scripts will fail (or silently compare against the wrong ref)
   until you edit the branch name in `composer.json` to match.

6. **Publish Sloppy's own config.** Until you run this, Sloppy's settings —
   including which paths it excludes, e.g. `tests/` — are only the
   package's implicit defaults: invisible and not adjustable from this
   project.

   ```bash
   php artisan vendor:publish --tag=sloppy-config
   ```

## AI code review gate

PIPELINE.md's step 19 describes an "AI Code Review" stage that's meant to
run, with a fresh/independent perspective, once the deterministic pipeline
(`composer quality`) is green — but nothing made that actually happen. A
session could run `composer quality`, see it pass, and just stop without
ever running that review. `pipeline:install` wires a second `Stop` hook
(alongside Sloppy's own) for exactly this:

```json
{
  "type": "command",
  "command": "php \"${CLAUDE_PROJECT_DIR}/artisan\" pipeline:ai-review-gate",
  "timeout": 600
}
```

Every time Claude Code tries to end a turn, this hook:

1. Allows the stop immediately if Claude Code says `stop_hook_active` (it's
   already mid-way through honoring a previous block for this same turn —
   never re-block here, or the session would loop).
2. Allows the stop if there are no pending changes (`git diff HEAD` plus any
   untracked files), or if the current diff's hash matches the last one this
   hook already forced a review for.
3. Otherwise runs `composer quality`. If it isn't green, allows the stop —
   this hook only ever gates the *review*, not the quality pipeline itself.
4. If it's green **and** this exact diff hasn't been reviewed yet, blocks
   the stop and returns `{"decision": "block", "reason": "..."}`: a reason
   telling Claude, in PIPELINE.md's own terms, to run the `code-review`
   skill (or a code-reviewer subagent) against the pending diff before
   actually finishing — and remembers the diff's hash so it won't ask again
   for the same changes.

If the forced review leads to more edits, the diff's hash changes and the
next stop attempt blocks again — each new slice of changes gets its own
review. `composer quality` on a non-trivial project can take a while, hence
the generous `timeout`; shrink it (or swap in a cheaper check) if your
`quality` script is fast, or extend it if it's slower than 10 minutes.

The hash-based "already reviewed" state is a small file under the system
temp directory (keyed by the project path), not inside the repo — it never
gets committed and resets if that directory is cleared, which just costs one
extra forced review, not a correctness problem.

## Troubleshooting

- **`Could not delete vendor/bin/...` during `composer update`/`install`.**
  `vendor/` has files owned by another user — commonly `root`, left over
  from a `composer install` run inside a Docker container — that your shell
  user can't replace. Fix ownership and retry: `sudo chown -R $USER vendor`.

- **`php artisan pipeline:install` isn't listed after requiring the
  package.** Laravel's package manifest (`bootstrap/cache/packages.php`) may
  still be cached from before the package existed in the project, even
  though the autoloader already knows about it. Clear it and rediscover:

  ```bash
  rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
  php artisan package:discover
  ```

- **Sloppy's hooks never seem to fire, even though `pipeline:status` shows
  everything else `OK`.** This happens when the project is installed inside
  a monorepo subdirectory (e.g. `site/` alongside `backoffice/` and `api/`)
  and Claude Code sessions are opened at the monorepo root rather than
  inside `site/`. Claude Code only reads `.claude/settings.json` from the
  root of the open session, not from subdirectories, so hooks installed a
  level down are configured correctly but unreachable. Run
  `php artisan pipeline:status` — its **Claude Code hook reachability** row
  catches exactly this case — and mirror the hooks into a
  `.claude/settings.json` at the monorepo root (with the hook command
  inspecting the edited file's path to route to the right sub-app's
  `vendor/bin/sloppy`, since a root-level hook can't blindly call one
  sub-app's binary for every edit) or open sessions inside the sub-app
  instead.

- **`vendor/bin/sloppy scan`/`diff`/`review` dies with "Allowed memory size
  of 134217728 bytes exhausted".** That's PHP's default 128M limit —
  Sloppy indexes the whole project for cross-file analysis, which can
  outgrow it on anything past a small codebase. Run it with a raised
  limit, the same way the `types` script already does for Pest:

  ```bash
  php -d memory_limit=2G vendor/bin/sloppy scan
  ```

  `php artisan pipeline:status` flags this under **Sloppy memory limit** if
  the `sloppy`/`sloppy:diff`/`sloppy:review` scripts in `composer.json`
  don't already set it.

- **`vendor/bin/sloppy diff main` reports "0 changed" right after writing new
  files.** `sloppy diff` compares against git, not the working tree, so a
  brand-new file that hasn't been `git add`ed yet is invisible to it — "0
  changed" doesn't mean there's nothing to review, it means Sloppy couldn't
  see the new file at all. Stage it first (`git add`, no need to commit) and
  re-run the diff.

- **`composer quality` fails immediately on an existing codebase, drowned in
  pre-existing errors.** `analyse` (PHPStan/Larastan at `level: 8`) has
  nothing to tell pre-existing debt apart from debt your change introduced —
  the bundled `phpstan.neon` doesn't ship a baseline, because `pipeline-kit`
  has no way to know what's already acceptable in a project it has never
  seen. Generate one once, right after installing:

  ```bash
  vendor/bin/phpstan analyse --generate-baseline
  ```

  then add `- phpstan-baseline.neon` under `includes:` in `phpstan.neon` and
  commit it. From then on, `analyse` only reports new errors.

## Package structure

```
pipeline-kit/
├── composer.json              # package manifest (bartollo/pipeline-kit)
├── src/
│   ├── PipelineKitServiceProvider.php
│   ├── Console/Commands/InstallPipelineCommand.php
│   ├── Console/Commands/PipelineStatusCommand.php
│   ├── Console/Commands/PipelineAiReviewGateCommand.php
│   ├── PipelineMerger.php        # merge logic, no Laravel dependency
│   ├── PipelineStatus.php        # read-only status checks, no Laravel dependency
│   └── PipelineAiReviewGate.php  # Stop-hook decision logic, no Laravel dependency
├── stubs/                      # bundled copies of .mcp.json, CLAUDE.md, etc.
├── bin/sync-stubs.php          # resyncs stubs/ from the source project's real files
└── tests/
    ├── PipelineMergerTest.php
    ├── PipelineStatusTest.php
    └── PipelineAiReviewGateTest.php
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
