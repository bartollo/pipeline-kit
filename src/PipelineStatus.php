<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit;

/**
 * Framework-free status checks shared by the `pipeline:status` artisan
 * command. Reports, for each item `pipeline:install` manages, whether it's
 * missing, partially configured, present but different from the bundled
 * stub, or fully configured — without writing anything.
 */
final class PipelineStatus
{
    public const STATUS_OK = 'ok';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_MODIFIED = 'modified';

    public const STATUS_MISSING = 'missing';

    /**
     * @return list<array{item: string, file: string, status: string, detail: string}>
     */
    public static function check(string $stubsPath, string $targetPath): array
    {
        return [
            self::mcpServersStatus($stubsPath.'/mcp.json', $targetPath.'/.mcp.json'),
            self::claudeHooksStatus($stubsPath.'/claude-settings.json', $targetPath.'/.claude/settings.json'),
            self::enabledMcpServersStatus($stubsPath.'/claude-settings.local.json', $targetPath.'/.claude/settings.local.json'),
            self::hookReachabilityStatus($stubsPath.'/claude-settings.json', $targetPath),
            self::gitTrackingStatus($targetPath),
            self::wholeFileStatus('Static analysis config', 'phpstan.neon', $stubsPath.'/phpstan.neon', $targetPath.'/phpstan.neon'),
            self::wholeFileStatus('Laravel Boost config', 'boost.json', $stubsPath.'/boost.json', $targetPath.'/boost.json'),
            self::wholeFileStatus('Project instructions', 'CLAUDE.md', $stubsPath.'/CLAUDE.md', $targetPath.'/CLAUDE.md'),
            self::wholeFileStatus('Pipeline documentation', 'PIPELINE.md', $stubsPath.'/PIPELINE.md', $targetPath.'/PIPELINE.md'),
            self::composerPackagesStatus($targetPath.'/composer.json'),
            self::composerScriptsStatus($targetPath.'/composer.json'),
            self::sloppyMemoryLimitStatus($targetPath.'/composer.json'),
        ];
    }

    /**
     * @param  list<array{item: string, file: string, status: string, detail: string}>  $rows
     */
    public static function isHealthy(array $rows): bool
    {
        foreach ($rows as $row) {
            if (in_array($row['status'], [self::STATUS_MISSING, self::STATUS_PARTIAL], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function wholeFileStatus(string $item, string $file, string $sourcePath, string $targetPath): array
    {
        if (! is_file($targetPath)) {
            return self::row($item, $file, self::STATUS_MISSING, 'not installed — run php artisan pipeline:install');
        }

        $source = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
        $target = file_get_contents($targetPath);

        if ($source !== false && $source === $target) {
            return self::row($item, $file, self::STATUS_OK, 'matches the bundled stub');
        }

        return self::row($item, $file, self::STATUS_MODIFIED, 'present, differs from the bundled stub (expected once Sloppy/Boost or you have edited it)');
    }

    /**
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function mcpServersStatus(string $sourcePath, string $targetPath): array
    {
        $source = PipelineMerger::readJson($sourcePath) ?? [];
        $expected = array_keys($source['mcpServers'] ?? []);

        if (! is_file($targetPath)) {
            return self::row('MCP servers', '.mcp.json', self::STATUS_MISSING, 'expected: '.implode(', ', $expected));
        }

        $target = PipelineMerger::readJson($targetPath) ?? [];
        $present = array_keys($target['mcpServers'] ?? []);
        $missing = array_values(array_diff($expected, $present));

        return self::presenceRow('MCP servers', '.mcp.json', $expected, $missing, 'configured');
    }

    /**
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function claudeHooksStatus(string $sourcePath, string $targetPath): array
    {
        $source = PipelineMerger::readJson($sourcePath) ?? [];
        $expected = self::flattenHooks($source['hooks'] ?? []);

        if (! is_file($targetPath)) {
            return self::row('Claude Code hooks', '.claude/settings.json', self::STATUS_MISSING, count($expected).' hook(s) expected');
        }

        $target = PipelineMerger::readJson($targetPath) ?? [];
        $present = self::flattenHooks($target['hooks'] ?? []);
        $missing = array_values(array_diff($expected, $present));

        return self::presenceRow('Claude Code hooks', '.claude/settings.json', $expected, $missing, 'hook(s) configured', countOnly: true);
    }

    /**
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function enabledMcpServersStatus(string $sourcePath, string $targetPath): array
    {
        $source = PipelineMerger::readJson($sourcePath) ?? [];
        $expected = $source['enabledMcpjsonServers'] ?? [];

        if (! is_file($targetPath)) {
            return self::row('Enabled MCP servers', '.claude/settings.local.json', self::STATUS_MISSING, 'expected: '.implode(', ', $expected));
        }

        $target = PipelineMerger::readJson($targetPath) ?? [];
        $present = $target['enabledMcpjsonServers'] ?? [];
        $missing = array_values(array_diff($expected, $present));

        return self::presenceRow('Enabled MCP servers', '.claude/settings.local.json', $expected, $missing, 'enabled');
    }

    /**
     * Claude Code only reads `.claude/settings.json` (and its hooks) from the
     * project root where a session was opened — not from subdirectories. If
     * this project is nested inside a larger git repository (a monorepo with
     * `site/`, `backoffice/`, `api/`, …), a session opened at the repo root
     * will never trigger the hooks installed here, even though everything
     * below looks correctly configured.
     *
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function hookReachabilityStatus(string $sourcePath, string $targetPath): array
    {
        $item = 'Claude Code hook reachability';
        $gitRoot = self::findGitRoot($targetPath);
        $normalizedTarget = realpath($targetPath) ?: $targetPath;

        if ($gitRoot === null) {
            return self::row($item, '.claude/settings.json (repo root)', self::STATUS_OK, 'no enclosing git repository found — nothing to check');
        }

        if ($gitRoot === $normalizedTarget) {
            return self::row($item, '.claude/settings.json (repo root)', self::STATUS_OK, 'this project is the git root — a Claude Code session opened here reads its hooks directly');
        }

        $expected = self::flattenHooks((PipelineMerger::readJson($sourcePath) ?? [])['hooks'] ?? []);
        $rootSettingsPath = $gitRoot.'/.claude/settings.json';

        if (! is_file($rootSettingsPath)) {
            return self::row(
                $item,
                '.claude/settings.json (repo root)',
                self::STATUS_MISSING,
                "this project sits inside the git repo at {$gitRoot}, which has no .claude/settings.json — a session opened at that root won't trigger the hooks installed here"
            );
        }

        $present = self::flattenHooks((PipelineMerger::readJson($rootSettingsPath) ?? [])['hooks'] ?? []);
        $missing = array_values(array_diff($expected, $present));

        if ($missing === []) {
            return self::row($item, '.claude/settings.json (repo root)', self::STATUS_OK, "hooks are mirrored at the git root ({$gitRoot}) — a session opened there will trigger them");
        }

        if ($missing === $expected) {
            return self::row(
                $item,
                '.claude/settings.json (repo root)',
                self::STATUS_MISSING,
                "the git root's .claude/settings.json ({$gitRoot}) has none of this project's hooks — mirror them there, or open Claude Code sessions inside this directory instead"
            );
        }

        return self::row(
            $item,
            '.claude/settings.json (repo root)',
            self::STATUS_PARTIAL,
            'missing '.count($missing).' of '.count($expected)." hook(s) at the git root ({$gitRoot})"
        );
    }

    private static function findGitRoot(string $startPath): ?string
    {
        $dir = realpath($startPath) ?: $startPath;

        while (true) {
            if (is_dir($dir.'/.git') || is_file($dir.'/.git')) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

    /**
     * A file can exist on disk, pass every other check here, and still never
     * reach a teammate: if `.gitignore` excludes it — directly, or because a
     * blanket `.claude/` rule swallows `settings.json` along with the local,
     * genuinely-personal `settings.local.json` — it's invisible to `git
     * status`/`git add` and a fresh clone simply won't have it. From the
     * point of view of anyone who hasn't got this exact working copy, that's
     * indistinguishable from the file never having been installed at all.
     *
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function gitTrackingStatus(string $targetPath): array
    {
        $item = 'Claude Code config committed to git';
        $candidates = ['.mcp.json', '.claude/settings.json'];

        $gitRoot = self::findGitRoot($targetPath);

        if ($gitRoot === null) {
            return self::row($item, implode(', ', $candidates), self::STATUS_OK, 'no git repository detected — nothing to check');
        }

        $existing = array_values(array_filter($candidates, fn (string $relative) => is_file($targetPath.'/'.$relative)));

        if ($existing === []) {
            return self::row($item, implode(', ', $candidates), self::STATUS_OK, 'neither file exists yet — nothing to check');
        }

        $ignored = array_values(array_filter(
            $existing,
            fn (string $relative) => self::isGitIgnored($gitRoot, $targetPath.'/'.$relative)
        ));

        if ($ignored === []) {
            return self::row($item, implode(', ', $existing), self::STATUS_OK, 'not excluded by .gitignore');
        }

        $status = $ignored === $existing ? self::STATUS_MISSING : self::STATUS_PARTIAL;

        return self::row(
            $item,
            implode(', ', $ignored),
            $status,
            implode(', ', $ignored).' exist locally but .gitignore excludes '.
                (count($ignored) === 1 ? 'it' : 'them').
                ' (directly, or via a blanket .claude/ rule) — a teammate cloning this repo won\'t get '.
                (count($ignored) === 1 ? 'it' : 'them').
                '. Only settings.local.json is meant to be gitignored; commit the rest.'
        );
    }

    private static function isGitIgnored(string $gitRoot, string $absolutePath): bool
    {
        $command = sprintf(
            'git -C %s check-ignore -q -- %s 2>/dev/null',
            escapeshellarg($gitRoot),
            escapeshellarg($absolutePath)
        );

        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function composerPackagesStatus(string $targetPath): array
    {
        $target = PipelineMerger::readJson($targetPath);

        if ($target === null) {
            return self::row('composer.json — require-dev packages', 'composer.json', self::STATUS_MISSING, 'composer.json not found or unreadable');
        }

        $requireDev = array_keys($target['require-dev'] ?? []);
        $missing = array_values(array_diff(PipelineMerger::REQUIRE_DEV_PACKAGES, $requireDev));

        return self::presenceRow('composer.json — require-dev packages', 'composer.json', PipelineMerger::REQUIRE_DEV_PACKAGES, $missing, 'package(s) present', countOnly: true);
    }

    /**
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function composerScriptsStatus(string $targetPath): array
    {
        $target = PipelineMerger::readJson($targetPath);

        if ($target === null) {
            return self::row('composer.json — quality scripts', 'composer.json', self::STATUS_MISSING, 'composer.json not found or unreadable');
        }

        $scripts = array_keys($target['scripts'] ?? []);
        $missing = array_values(array_diff(PipelineMerger::SCRIPT_NAMES, $scripts));

        return self::presenceRow('composer.json — quality scripts', 'composer.json', PipelineMerger::SCRIPT_NAMES, $missing, 'script(s) present', countOnly: true);
    }

    /**
     * `sloppy scan`/`diff`/`review` index the whole project for cross-file
     * analysis and can exceed PHP's default 128M memory_limit on anything
     * past a small codebase — the same reason the `types` script already
     * runs Pest through `php -d memory_limit=1G`. Composer's merge never
     * rewrites a script that's already present, so an older install (or a
     * manual edit) can be stuck without this.
     *
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function sloppyMemoryLimitStatus(string $targetPath): array
    {
        $item = 'Sloppy memory limit';
        $file = 'composer.json';
        $sloppyScripts = ['sloppy', 'sloppy:diff', 'sloppy:review'];

        $target = PipelineMerger::readJson($targetPath);

        if ($target === null) {
            return self::row($item, $file, self::STATUS_MISSING, 'composer.json not found or unreadable');
        }

        $scripts = $target['scripts'] ?? [];
        $present = array_intersect_key($scripts, array_flip($sloppyScripts));

        if ($present === []) {
            return self::row($item, $file, self::STATUS_MISSING, 'none of the sloppy scripts are defined yet');
        }

        $withoutLimit = [];
        foreach ($present as $name => $command) {
            $command = is_array($command) ? implode(' ', $command) : (string) $command;

            if (! preg_match('/memory[-_]limit/i', $command)) {
                $withoutLimit[] = $name;
            }
        }

        if ($withoutLimit === []) {
            return self::row($item, $file, self::STATUS_OK, 'memory_limit already raised for: '.implode(', ', array_keys($present)));
        }

        return self::row(
            $item,
            $file,
            self::STATUS_PARTIAL,
            'no explicit memory limit for: '.implode(', ', $withoutLimit).
                " — Sloppy can exhaust PHP's default 128M indexing a whole project for cross-file analysis; ".
                'raise it the same way `types` already does for Pest, e.g. `php -d memory_limit=2G vendor/bin/sloppy scan`'
        );
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $missing
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function presenceRow(string $item, string $file, array $expected, array $missing, string $okWord, bool $countOnly = false): array
    {
        if ($expected === []) {
            return self::row($item, $file, self::STATUS_OK, 'nothing expected');
        }

        if ($missing === []) {
            $detail = $countOnly ? count($expected)." {$okWord}" : implode(', ', $expected).' '.$okWord;

            return self::row($item, $file, self::STATUS_OK, $detail);
        }

        if ($missing === $expected) {
            return self::row($item, $file, self::STATUS_MISSING, 'none configured — expected: '.implode(', ', $expected));
        }

        $detail = $countOnly
            ? 'missing '.count($missing).' of '.count($expected)
            : 'missing: '.implode(', ', $missing);

        return self::row($item, $file, self::STATUS_PARTIAL, $detail);
    }

    /**
     * @return list<string>
     */
    private static function flattenHooks(array $hooks): array
    {
        $flat = [];

        foreach ($hooks as $event => $entries) {
            foreach ($entries as $entry) {
                $matcher = $entry['matcher'] ?? '';

                foreach ($entry['hooks'] ?? [] as $hook) {
                    $flat[] = $event.'|'.$matcher.'|'.($hook['command'] ?? '');
                }
            }
        }

        return $flat;
    }

    /**
     * @return array{item: string, file: string, status: string, detail: string}
     */
    private static function row(string $item, string $file, string $status, string $detail): array
    {
        return ['item' => $item, 'file' => $file, 'status' => $status, 'detail' => $detail];
    }
}
