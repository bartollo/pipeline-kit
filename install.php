#!/usr/bin/env php
<?php

/**
 * Installs/merges this repo's Claude Code + Sloppy + Laravel Boost quality pipeline
 * into another Laravel project, additively and idempotently.
 *
 * Usage:
 *   php pipeline-kit/install.php /path/to/other-project [--dry-run] [--force]
 *
 * --dry-run   Print every planned action without writing anything.
 * --force     Overwrite files that are normally left alone when they already exist
 *             in the target (phpstan.neon, boost.json, CLAUDE.md, PIPELINE.md).
 *             A backup (<file>.bak) is written first.
 *
 * See PIPELINE.md in this repo for background on what this pipeline is for.
 */

declare(strict_types=1);

const PIPELINE_REQUIRE_DEV = [
    'heyosseus/sloppy',
    'larastan/larastan',
    'laravel/boost',
    'laravel/pao',
    'laravel/pint',
    'mockery/mockery',
    'nunomaduro/collision',
    'pestphp/pest',
    'pestphp/pest-plugin-laravel',
    'pestphp/pest-plugin-type-coverage',
    'phpunit/phpunit',
];

const PIPELINE_SCRIPTS = [
    'lint',
    'lint:fix',
    'analyse',
    'pest',
    'types',
    'mutate',
    'sloppy',
    'sloppy:diff',
    'sloppy:review',
    'security',
    'quality',
];

function out(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, 'Error: '.$message.PHP_EOL);
    exit(1);
}

function readJson(string $path): ?array
{
    if (! is_file($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

function writeJson(string $path, array $data): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($path, $json);
}

function backup(string $path): void
{
    if (is_file($path)) {
        copy($path, $path.'.bak');
    }
}

/**
 * Copies a whole file into the target only if it's missing there, or (with --force)
 * overwrites it after backing the existing copy up. Never silently clobbers otherwise.
 *
 * @return array{status: string, detail: string}
 */
function copyWholeFile(string $sourcePath, string $targetPath, bool $dryRun, bool $force): array
{
    $exists = is_file($targetPath);

    if ($exists && ! $force) {
        return ['status' => 'skipped', 'detail' => 'already exists (use --force to overwrite)'];
    }

    if ($dryRun) {
        return ['status' => $exists ? 'would-overwrite' : 'would-create', 'detail' => ''];
    }

    if ($exists) {
        backup($targetPath);
    }

    $targetDir = dirname($targetPath);
    if (! is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    copy($sourcePath, $targetPath);

    return ['status' => $exists ? 'overwritten' : 'created', 'detail' => $exists ? 'backed up to '.basename($targetPath).'.bak' : ''];
}

/**
 * Merges one hook event's entries (e.g. PostToolUse) by matcher, appending only
 * hook commands that aren't already present under a matching matcher.
 */
function mergeHookEvent(array $targetEvent, array $sourceEvent): array
{
    foreach ($sourceEvent as $sourceEntry) {
        $matcher = $sourceEntry['matcher'] ?? null;
        $foundIndex = null;

        foreach ($targetEvent as $index => $targetEntry) {
            if (($targetEntry['matcher'] ?? null) === $matcher) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex === null) {
            $targetEvent[] = $sourceEntry;

            continue;
        }

        $targetHooks = $targetEvent[$foundIndex]['hooks'] ?? [];

        foreach ($sourceEntry['hooks'] ?? [] as $sourceHook) {
            $alreadyPresent = false;

            foreach ($targetHooks as $targetHook) {
                if (($targetHook['command'] ?? null) === ($sourceHook['command'] ?? null)) {
                    $alreadyPresent = true;
                    break;
                }
            }

            if (! $alreadyPresent) {
                $targetHooks[] = $sourceHook;
            }
        }

        $targetEvent[$foundIndex]['hooks'] = $targetHooks;
    }

    return $targetEvent;
}

/**
 * @return array{status: string, detail: string}
 */
function mergeMcpJson(string $sourcePath, string $targetPath, bool $dryRun): array
{
    $source = readJson($sourcePath) ?? [];
    $target = readJson($targetPath) ?? [];
    $existed = is_file($targetPath);

    $sourceServers = $source['mcpServers'] ?? [];
    $targetServers = $target['mcpServers'] ?? [];

    $added = [];
    foreach ($sourceServers as $name => $config) {
        if (! array_key_exists($name, $targetServers)) {
            $targetServers[$name] = $config;
            $added[] = $name;
        }
    }

    if ($added === [] && $existed) {
        return ['status' => 'skipped', 'detail' => 'servers already configured'];
    }

    if ($dryRun) {
        return [
            'status' => $existed ? 'would-merge' : 'would-create',
            'detail' => $added === [] ? '' : 'would add: '.implode(', ', $added),
        ];
    }

    $target['mcpServers'] = $targetServers;
    writeJson($targetPath, $target);

    return [
        'status' => $existed ? 'merged' : 'created',
        'detail' => $added === [] ? '' : 'added: '.implode(', ', $added),
    ];
}

/**
 * @return array{status: string, detail: string}
 */
function mergeClaudeSettings(string $sourcePath, string $targetPath, bool $dryRun): array
{
    $source = readJson($sourcePath) ?? [];
    $target = readJson($targetPath) ?? [];
    $existed = is_file($targetPath);

    $targetHooks = $target['hooks'] ?? [];
    $before = json_encode($targetHooks);

    foreach ($source['hooks'] ?? [] as $event => $sourceEvent) {
        $targetHooks[$event] = mergeHookEvent($targetHooks[$event] ?? [], $sourceEvent);
    }

    $changed = json_encode($targetHooks) !== $before;

    if (! $changed && $existed) {
        return ['status' => 'skipped', 'detail' => 'hooks already present'];
    }

    if ($dryRun) {
        return ['status' => $existed ? 'would-merge' : 'would-create', 'detail' => ''];
    }

    $target['hooks'] = $targetHooks;
    writeJson($targetPath, $target);

    return ['status' => $existed ? 'merged' : 'created', 'detail' => ''];
}

/**
 * @return array{status: string, detail: string}
 */
function mergeSettingsLocal(string $sourcePath, string $targetPath, bool $dryRun): array
{
    $source = readJson($sourcePath) ?? [];
    $target = readJson($targetPath) ?? [];
    $existed = is_file($targetPath);

    $sourceList = $source['enabledMcpjsonServers'] ?? [];
    $targetList = $target['enabledMcpjsonServers'] ?? [];

    $merged = array_values(array_unique(array_merge($targetList, $sourceList)));
    $added = array_values(array_diff($merged, $targetList));

    if ($added === [] && $existed) {
        return ['status' => 'skipped', 'detail' => 'servers already enabled'];
    }

    if ($dryRun) {
        return [
            'status' => $existed ? 'would-merge' : 'would-create',
            'detail' => $added === [] ? '' : 'would add: '.implode(', ', $added),
        ];
    }

    $target['enabledMcpjsonServers'] = $merged;
    writeJson($targetPath, $target);

    return [
        'status' => $existed ? 'merged' : 'created',
        'detail' => $added === [] ? '' : 'added: '.implode(', ', $added),
    ];
}

/**
 * @return array{status: string, detail: string}
 */
function mergeComposerJson(string $sourcePath, string $targetPath, bool $dryRun): array
{
    $source = readJson($sourcePath);
    $target = readJson($targetPath);

    if ($source === null) {
        fail("could not read source composer.json at {$sourcePath}");
    }

    if ($target === null) {
        fail("target composer.json not found or unreadable at {$targetPath}");
    }

    $requireDev = $target['require-dev'] ?? [];
    $addedPackages = [];

    foreach (PIPELINE_REQUIRE_DEV as $package) {
        if (! array_key_exists($package, $requireDev) && isset($source['require-dev'][$package])) {
            $requireDev[$package] = $source['require-dev'][$package];
            $addedPackages[] = $package;
        }
    }

    $scripts = $target['scripts'] ?? [];
    $addedScripts = [];
    $skippedScripts = [];

    foreach (PIPELINE_SCRIPTS as $scriptName) {
        if (! isset($source['scripts'][$scriptName])) {
            continue;
        }

        if (array_key_exists($scriptName, $scripts)) {
            $skippedScripts[] = $scriptName;

            continue;
        }

        $scripts[$scriptName] = $source['scripts'][$scriptName];
        $addedScripts[] = $scriptName;
    }

    if ($addedPackages === [] && $addedScripts === []) {
        $detail = $skippedScripts === [] ? 'nothing to add' : 'scripts already present: '.implode(', ', $skippedScripts);

        return ['status' => 'skipped', 'detail' => $detail];
    }

    $detailParts = [];
    if ($addedPackages !== []) {
        $detailParts[] = 'require-dev: '.implode(', ', $addedPackages);
    }
    if ($addedScripts !== []) {
        $detailParts[] = 'scripts: '.implode(', ', $addedScripts);
    }
    if ($skippedScripts !== []) {
        $detailParts[] = 'skipped existing scripts: '.implode(', ', $skippedScripts);
    }
    $detail = implode(' | ', $detailParts);

    if ($dryRun) {
        return ['status' => 'would-merge', 'detail' => $detail];
    }

    backup($targetPath);
    $target['require-dev'] = $requireDev;
    $target['scripts'] = $scripts;
    writeJson($targetPath, $target);

    return ['status' => 'merged', 'detail' => $detail];
}

function assertLaravelProject(string $targetDir): void
{
    if (! is_dir($targetDir)) {
        fail("target directory does not exist: {$targetDir}");
    }

    if (! is_file($targetDir.'/composer.json')) {
        fail("not a Laravel project (no composer.json found in {$targetDir})");
    }

    if (! is_file($targetDir.'/artisan')) {
        fail("not a Laravel project (no artisan file found in {$targetDir})");
    }
}

function report(string $file, array $result): void
{
    $label = str_pad($result['status'], 16);
    $line = "  [{$label}] {$file}";
    if ($result['detail'] !== '') {
        $line .= ' — '.$result['detail'];
    }
    out($line);
}

// --- entry point -----------------------------------------------------------

$args = array_slice($argv, 1);
$dryRun = false;
$force = false;
$targetArg = null;

foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        out('Usage: php pipeline-kit/install.php /path/to/other-project [--dry-run] [--force]');
        exit(0);
    } elseif (str_starts_with($arg, '--')) {
        fail("unknown option: {$arg}");
    } else {
        $targetArg = $arg;
    }
}

if ($targetArg === null) {
    fail('missing target project path. Usage: php pipeline-kit/install.php /path/to/other-project [--dry-run] [--force]');
}

$sourceDir = dirname(__DIR__);
$targetDir = rtrim($targetArg, '/');

assertLaravelProject($targetDir);

out(($dryRun ? '[dry-run] ' : '')."Installing pipeline from {$sourceDir} into {$targetDir}");
out('');

out('Claude / MCP configuration:');
report('.mcp.json', mergeMcpJson($sourceDir.'/.mcp.json', $targetDir.'/.mcp.json', $dryRun));
report('.claude/settings.json', mergeClaudeSettings($sourceDir.'/.claude/settings.json', $targetDir.'/.claude/settings.json', $dryRun));
report('.claude/settings.local.json', mergeSettingsLocal($sourceDir.'/.claude/settings.local.json', $targetDir.'/.claude/settings.local.json', $dryRun));

out('');
out('Quality tooling configuration:');
report('phpstan.neon', copyWholeFile($sourceDir.'/phpstan.neon', $targetDir.'/phpstan.neon', $dryRun, $force));
report('boost.json', copyWholeFile($sourceDir.'/boost.json', $targetDir.'/boost.json', $dryRun, $force));

out('');
out('Documentation:');
report('CLAUDE.md', copyWholeFile($sourceDir.'/CLAUDE.md', $targetDir.'/CLAUDE.md', $dryRun, $force));
report('PIPELINE.md', copyWholeFile($sourceDir.'/PIPELINE.md', $targetDir.'/PIPELINE.md', $dryRun, $force));

out('');
out('composer.json:');
report('composer.json', mergeComposerJson($sourceDir.'/composer.json', $targetDir.'/composer.json', $dryRun));

out('');
if ($dryRun) {
    out('Dry run only — nothing was written. Re-run without --dry-run to apply.');
} else {
    out('Done. Next steps in the target project:');
    out('  1. composer update (installs any newly added require-dev packages)');
    out('  2. php artisan boost:install (if laravel/boost was just added)');
    out('  3. vendor/bin/sloppy rules --format=claude');
    out('     then replace the "Sloppy: code rules for this repository" section at the');
    out('     bottom of CLAUDE.md with that output — it was copied from the source');
    out('     project and describes its paths/config, not this one.');
    out('  4. Review git diff before committing.');
}
