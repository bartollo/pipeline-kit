<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit;

/**
 * Framework-free merge logic shared by the `pipeline:install` artisan command.
 * Pure in the sense that every public method only touches the filesystem
 * paths it's given — no Laravel container, no base_path() assumptions — so
 * it can be unit tested without booting an application.
 *
 * This is a straight port of the same rules already proven in
 * pipeline-kit/install.php: additive merges, idempotent re-runs, and never
 * silently overwriting something that already exists.
 */
final class PipelineMerger
{
    public const REQUIRE_DEV_PACKAGES = [
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

    public const SCRIPT_NAMES = [
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

    public static function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($path, $json);
    }

    public static function backup(string $path): void
    {
        if (is_file($path)) {
            copy($path, $path.'.bak');
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    public static function copyWholeFile(string $sourcePath, string $targetPath, bool $dryRun, bool $force): array
    {
        $exists = is_file($targetPath);

        if ($exists && ! $force) {
            return ['status' => 'skipped', 'detail' => 'already exists (use --force to overwrite)'];
        }

        if ($dryRun) {
            return ['status' => $exists ? 'would-overwrite' : 'would-create', 'detail' => ''];
        }

        if ($exists) {
            self::backup($targetPath);
        }

        $targetDir = dirname($targetPath);
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        copy($sourcePath, $targetPath);

        return [
            'status' => $exists ? 'overwritten' : 'created',
            'detail' => $exists ? 'backed up to '.basename($targetPath).'.bak' : '',
        ];
    }

    /**
     * Merges one hook event's entries (e.g. PostToolUse) by matcher, appending
     * only hook commands that aren't already present under a matching matcher.
     */
    public static function mergeHookEvent(array $targetEvent, array $sourceEvent): array
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
    public static function mergeMcpJson(string $sourcePath, string $targetPath, bool $dryRun): array
    {
        $source = self::readJson($sourcePath) ?? [];
        $target = self::readJson($targetPath) ?? [];
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
        self::writeJson($targetPath, $target);

        return [
            'status' => $existed ? 'merged' : 'created',
            'detail' => $added === [] ? '' : 'added: '.implode(', ', $added),
        ];
    }

    /**
     * @return array{status: string, detail: string}
     */
    public static function mergeClaudeSettings(string $sourcePath, string $targetPath, bool $dryRun): array
    {
        $source = self::readJson($sourcePath) ?? [];
        $target = self::readJson($targetPath) ?? [];
        $existed = is_file($targetPath);

        $targetHooks = $target['hooks'] ?? [];
        $before = json_encode($targetHooks);

        foreach ($source['hooks'] ?? [] as $event => $sourceEvent) {
            $targetHooks[$event] = self::mergeHookEvent($targetHooks[$event] ?? [], $sourceEvent);
        }

        $changed = json_encode($targetHooks) !== $before;

        if (! $changed && $existed) {
            return ['status' => 'skipped', 'detail' => 'hooks already present'];
        }

        if ($dryRun) {
            return ['status' => $existed ? 'would-merge' : 'would-create', 'detail' => ''];
        }

        $target['hooks'] = $targetHooks;
        self::writeJson($targetPath, $target);

        return ['status' => $existed ? 'merged' : 'created', 'detail' => ''];
    }

    /**
     * @return array{status: string, detail: string}
     */
    public static function mergeSettingsLocal(string $sourcePath, string $targetPath, bool $dryRun): array
    {
        $source = self::readJson($sourcePath) ?? [];
        $target = self::readJson($targetPath) ?? [];
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
        self::writeJson($targetPath, $target);

        return [
            'status' => $existed ? 'merged' : 'created',
            'detail' => $added === [] ? '' : 'added: '.implode(', ', $added),
        ];
    }

    /**
     * @param  array{require-dev: array<string, string>, scripts: array<string, mixed>}  $fragment
     * @return array{status: string, detail: string}
     */
    public static function mergeComposerJson(array $fragment, string $targetPath, bool $dryRun): array
    {
        $target = self::readJson($targetPath);

        if ($target === null) {
            return ['status' => 'error', 'detail' => "target composer.json not found or unreadable at {$targetPath}"];
        }

        $requireDev = $target['require-dev'] ?? [];
        $addedPackages = [];

        foreach (self::REQUIRE_DEV_PACKAGES as $package) {
            if (! array_key_exists($package, $requireDev) && isset($fragment['require-dev'][$package])) {
                $requireDev[$package] = $fragment['require-dev'][$package];
                $addedPackages[] = $package;
            }
        }

        $scripts = $target['scripts'] ?? [];
        $addedScripts = [];
        $skippedScripts = [];

        foreach (self::SCRIPT_NAMES as $scriptName) {
            if (! isset($fragment['scripts'][$scriptName])) {
                continue;
            }

            if (array_key_exists($scriptName, $scripts)) {
                $skippedScripts[] = $scriptName;

                continue;
            }

            $scripts[$scriptName] = $fragment['scripts'][$scriptName];
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

        self::backup($targetPath);
        $target['require-dev'] = $requireDev;
        $target['scripts'] = $scripts;
        self::writeJson($targetPath, $target);

        return ['status' => 'merged', 'detail' => $detail];
    }
}
