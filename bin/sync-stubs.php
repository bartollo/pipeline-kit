#!/usr/bin/env php
<?php

/**
 * Maintenance helper: regenerates pipeline-kit/stubs/* from this repo's own
 * live config/doc files, so the bundled Laravel package (which can't read
 * this repo's files once installed elsewhere) doesn't silently drift from
 * what pipeline-kit/install.php already reads live.
 *
 * Run this after editing CLAUDE.md, PIPELINE.md, .mcp.json, .claude/settings*.json,
 * phpstan.neon, boost.json, or the PIPELINE_REQUIRE_DEV/PIPELINE_SCRIPTS package
 * lists, before tagging/publishing the package.
 *
 * Usage: php pipeline-kit/bin/sync-stubs.php
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

$root = dirname(__DIR__, 2);
$stubs = $root.'/pipeline-kit/stubs';

$fileCopies = [
    '.mcp.json' => 'mcp.json',
    '.claude/settings.json' => 'claude-settings.json',
    '.claude/settings.local.json' => 'claude-settings.local.json',
    'phpstan.neon' => 'phpstan.neon',
    'boost.json' => 'boost.json',
    'CLAUDE.md' => 'CLAUDE.md',
    'PIPELINE.md' => 'PIPELINE.md',
];

foreach ($fileCopies as $source => $stubName) {
    copy($root.'/'.$source, $stubs.'/'.$stubName);
    echo "synced {$stubName}\n";
}

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

$fragment = ['require-dev' => [], 'scripts' => []];

foreach (PIPELINE_REQUIRE_DEV as $package) {
    if (! isset($composer['require-dev'][$package])) {
        fwrite(STDERR, "warning: {$package} no longer in root composer.json require-dev\n");

        continue;
    }
    $fragment['require-dev'][$package] = $composer['require-dev'][$package];
}

foreach (PIPELINE_SCRIPTS as $scriptName) {
    if (! isset($composer['scripts'][$scriptName])) {
        fwrite(STDERR, "warning: script '{$scriptName}' no longer in root composer.json\n");

        continue;
    }
    $fragment['scripts'][$scriptName] = $composer['scripts'][$scriptName];
}

file_put_contents(
    $stubs.'/composer-fragment.json',
    json_encode($fragment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
);

echo "synced composer-fragment.json\n";
