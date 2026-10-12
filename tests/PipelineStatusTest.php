<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit\Tests;

use Bartollo\PipelineKit\PipelineMerger;
use Bartollo\PipelineKit\PipelineStatus;
use PHPUnit\Framework\TestCase;

final class PipelineStatusTest extends TestCase
{
    private string $stubs;

    private string $target;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/pipeline-kit-status-test-'.bin2hex(random_bytes(6));
        $this->stubs = $base.'/stubs';
        $this->target = $base.'/target';
        mkdir($this->stubs, 0755, true);
        mkdir($this->target, 0755, true);

        file_put_contents($this->stubs.'/mcp.json', json_encode([
            'mcpServers' => [
                'sloppy' => ['command' => 'php'],
                'laravel-boost' => ['command' => 'php'],
            ],
        ]));
        file_put_contents($this->stubs.'/claude-settings.json', json_encode([
            'hooks' => [
                'PostToolUse' => [
                    ['matcher' => 'Edit|Write', 'hooks' => [['type' => 'command', 'command' => 'sloppy hook post-edit']]],
                ],
                'Stop' => [
                    ['hooks' => [['type' => 'command', 'command' => 'sloppy hook stop']]],
                ],
            ],
        ]));
        file_put_contents($this->stubs.'/claude-settings.local.json', json_encode([
            'enabledMcpjsonServers' => ['sloppy', 'laravel-boost'],
        ]));
        file_put_contents($this->stubs.'/phpstan.neon', "parameters:\n    level: 8\n");
        file_put_contents($this->stubs.'/boost.json', json_encode(['enabled' => true]));
        file_put_contents($this->stubs.'/CLAUDE.md', "# Rules\n");
        file_put_contents($this->stubs.'/PIPELINE.md', "# Pipeline\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(dirname($this->stubs));
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * @return array<string, string>
     */
    private function healthyScripts(): array
    {
        $scripts = array_fill_keys(PipelineMerger::SCRIPT_NAMES, 'echo ok');

        foreach (['sloppy', 'sloppy:diff', 'sloppy:review'] as $sloppyScript) {
            $scripts[$sloppyScript] = 'php -d memory_limit=2G vendor/bin/sloppy scan';
        }

        return $scripts;
    }

    private function statusFor(array $rows, string $item): array
    {
        foreach ($rows as $row) {
            if ($row['item'] === $item) {
                return $row;
            }
        }

        $this->fail("No status row found for item \"{$item}\"");
    }

    public function test_check_reports_missing_when_nothing_is_installed(): void
    {
        $rows = PipelineStatus::check($this->stubs, $this->target);

        foreach ($rows as $row) {
            if (in_array($row['item'], ['Claude Code hook reachability', 'Claude Code config committed to git'], true)) {
                // No enclosing git repo in this fixture, so there's nothing to flag here.
                continue;
            }

            $this->assertSame(PipelineStatus::STATUS_MISSING, $row['status'], $row['item'].' should be missing');
        }

        $this->assertFalse(PipelineStatus::isHealthy($rows));
    }

    public function test_check_reports_ok_when_fully_installed(): void
    {
        mkdir($this->target.'/.claude', 0755, true);
        copy($this->stubs.'/mcp.json', $this->target.'/.mcp.json');
        copy($this->stubs.'/claude-settings.json', $this->target.'/.claude/settings.json');
        copy($this->stubs.'/claude-settings.local.json', $this->target.'/.claude/settings.local.json');
        copy($this->stubs.'/phpstan.neon', $this->target.'/phpstan.neon');
        copy($this->stubs.'/boost.json', $this->target.'/boost.json');
        copy($this->stubs.'/CLAUDE.md', $this->target.'/CLAUDE.md');
        copy($this->stubs.'/PIPELINE.md', $this->target.'/PIPELINE.md');

        $requireDev = array_fill_keys(PipelineMerger::REQUIRE_DEV_PACKAGES, '^1.0');
        file_put_contents($this->target.'/composer.json', json_encode([
            'require-dev' => $requireDev,
            'scripts' => $this->healthyScripts(),
        ]));

        $rows = PipelineStatus::check($this->stubs, $this->target);

        foreach ($rows as $row) {
            $this->assertSame(PipelineStatus::STATUS_OK, $row['status'], $row['item'].' should be ok');
        }

        $this->assertTrue(PipelineStatus::isHealthy($rows));
    }

    public function test_check_reports_partial_when_some_mcp_servers_are_missing(): void
    {
        file_put_contents($this->target.'/.mcp.json', json_encode([
            'mcpServers' => ['sloppy' => ['command' => 'php']],
        ]));

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'MCP servers');

        $this->assertSame(PipelineStatus::STATUS_PARTIAL, $row['status']);
        $this->assertStringContainsString('laravel-boost', $row['detail']);
        $this->assertFalse(PipelineStatus::isHealthy($rows));
    }

    public function test_check_reports_modified_when_whole_file_differs_from_stub_and_stays_healthy(): void
    {
        mkdir($this->target.'/.claude', 0755, true);
        copy($this->stubs.'/mcp.json', $this->target.'/.mcp.json');
        copy($this->stubs.'/claude-settings.json', $this->target.'/.claude/settings.json');
        copy($this->stubs.'/claude-settings.local.json', $this->target.'/.claude/settings.local.json');
        copy($this->stubs.'/phpstan.neon', $this->target.'/phpstan.neon');
        copy($this->stubs.'/boost.json', $this->target.'/boost.json');
        copy($this->stubs.'/PIPELINE.md', $this->target.'/PIPELINE.md');
        file_put_contents($this->target.'/CLAUDE.md', "# Customized rules\n");

        $requireDev = array_fill_keys(PipelineMerger::REQUIRE_DEV_PACKAGES, '^1.0');
        file_put_contents($this->target.'/composer.json', json_encode([
            'require-dev' => $requireDev,
            'scripts' => $this->healthyScripts(),
        ]));

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Project instructions');

        $this->assertSame(PipelineStatus::STATUS_MODIFIED, $row['status']);
        // "modified" doesn't count against overall health — it's expected once Sloppy/Boost append to CLAUDE.md,
        // or once the user edits it further.
        $this->assertTrue(PipelineStatus::isHealthy($rows));
    }

    public function test_composer_packages_status_is_missing_when_composer_json_is_absent(): void
    {
        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'composer.json — require-dev packages');

        $this->assertSame(PipelineStatus::STATUS_MISSING, $row['status']);
    }

    public function test_sloppy_memory_limit_is_missing_when_sloppy_scripts_are_absent(): void
    {
        file_put_contents($this->target.'/composer.json', json_encode([
            'require-dev' => [],
            'scripts' => ['lint' => 'pint --test'],
        ]));

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Sloppy memory limit');

        $this->assertSame(PipelineStatus::STATUS_MISSING, $row['status']);
    }

    public function test_sloppy_memory_limit_is_partial_when_scripts_have_no_explicit_limit(): void
    {
        file_put_contents($this->target.'/composer.json', json_encode([
            'require-dev' => [],
            'scripts' => [
                'sloppy' => 'sloppy scan',
                'sloppy:diff' => 'sloppy diff main --fail-on=high',
                'sloppy:review' => 'sloppy review main',
            ],
        ]));

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Sloppy memory limit');

        $this->assertSame(PipelineStatus::STATUS_PARTIAL, $row['status']);
        $this->assertStringContainsString('sloppy, sloppy:diff, sloppy:review', $row['detail']);
        $this->assertFalse(PipelineStatus::isHealthy($rows));
    }

    public function test_sloppy_memory_limit_is_ok_when_scripts_already_raise_it(): void
    {
        file_put_contents($this->target.'/composer.json', json_encode([
            'require-dev' => [],
            'scripts' => [
                'sloppy' => 'php -d memory_limit=2G vendor/bin/sloppy scan',
                'sloppy:diff' => 'php -d memory_limit=2G vendor/bin/sloppy diff main --fail-on=high',
                'sloppy:review' => 'php -d memory_limit=2G vendor/bin/sloppy review main',
            ],
        ]));

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Sloppy memory limit');

        $this->assertSame(PipelineStatus::STATUS_OK, $row['status']);
    }

    public function test_hook_reachability_is_ok_when_project_itself_is_the_git_root(): void
    {
        mkdir($this->target.'/.git', 0755, true);

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code hook reachability');

        $this->assertSame(PipelineStatus::STATUS_OK, $row['status']);
        $this->assertStringContainsString('is the git root', $row['detail']);
    }

    public function test_hook_reachability_is_missing_when_enclosing_repo_has_no_claude_settings(): void
    {
        $repoRoot = dirname($this->target).'/repo';
        mkdir($repoRoot.'/.git', 0755, true);
        rename($this->target, $repoRoot.'/app');
        $this->target = $repoRoot.'/app';

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code hook reachability');

        $this->assertSame(PipelineStatus::STATUS_MISSING, $row['status']);
        $this->assertStringContainsString($repoRoot, $row['detail']);
        $this->assertFalse(PipelineStatus::isHealthy($rows));
    }

    public function test_hook_reachability_is_ok_when_hooks_are_mirrored_at_the_git_root(): void
    {
        $repoRoot = dirname($this->target).'/repo';
        mkdir($repoRoot.'/.git', 0755, true);
        mkdir($repoRoot.'/.claude', 0755, true);
        copy($this->stubs.'/claude-settings.json', $repoRoot.'/.claude/settings.json');
        rename($this->target, $repoRoot.'/app');
        $this->target = $repoRoot.'/app';

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code hook reachability');

        $this->assertSame(PipelineStatus::STATUS_OK, $row['status']);
        $this->assertStringContainsString('mirrored at the git root', $row['detail']);
    }

    public function test_hook_reachability_is_partial_when_git_root_is_missing_some_hooks(): void
    {
        $repoRoot = dirname($this->target).'/repo';
        mkdir($repoRoot.'/.git', 0755, true);
        mkdir($repoRoot.'/.claude', 0755, true);
        file_put_contents($repoRoot.'/.claude/settings.json', json_encode([
            'hooks' => [
                'Stop' => [
                    ['hooks' => [['type' => 'command', 'command' => 'sloppy hook stop']]],
                ],
            ],
        ]));
        rename($this->target, $repoRoot.'/app');
        $this->target = $repoRoot.'/app';

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code hook reachability');

        $this->assertSame(PipelineStatus::STATUS_PARTIAL, $row['status']);
    }

    private function initGitRepo(string $dir): void
    {
        exec('git init -q '.escapeshellarg($dir).' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->markTestSkipped('git is not available in this environment: '.implode("\n", $output));
        }
    }

    public function test_git_tracking_is_ok_when_no_git_repository_is_present(): void
    {
        file_put_contents($this->target.'/.mcp.json', '{}');

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code config committed to git');

        $this->assertSame(PipelineStatus::STATUS_OK, $row['status']);
        $this->assertStringContainsString('no git repository detected', $row['detail']);
    }

    public function test_git_tracking_is_ok_when_neither_file_exists_yet(): void
    {
        $this->initGitRepo($this->target);

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code config committed to git');

        $this->assertSame(PipelineStatus::STATUS_OK, $row['status']);
        $this->assertStringContainsString('nothing to check', $row['detail']);
    }

    public function test_git_tracking_is_ok_when_files_are_not_gitignored(): void
    {
        $this->initGitRepo($this->target);
        mkdir($this->target.'/.claude', 0755, true);
        file_put_contents($this->target.'/.mcp.json', '{}');
        file_put_contents($this->target.'/.claude/settings.json', '{}');

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code config committed to git');

        $this->assertSame(PipelineStatus::STATUS_OK, $row['status']);
    }

    public function test_git_tracking_is_partial_when_only_claude_settings_is_gitignored(): void
    {
        $this->initGitRepo($this->target);
        mkdir($this->target.'/.claude', 0755, true);
        file_put_contents($this->target.'/.gitignore', ".claude/\n");
        file_put_contents($this->target.'/.mcp.json', '{}');
        file_put_contents($this->target.'/.claude/settings.json', '{}');

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code config committed to git');

        $this->assertSame(PipelineStatus::STATUS_PARTIAL, $row['status']);
        $this->assertStringContainsString('.claude/settings.json', $row['detail']);
        $this->assertFalse(PipelineStatus::isHealthy($rows));
    }

    public function test_git_tracking_is_missing_when_both_files_are_gitignored(): void
    {
        $this->initGitRepo($this->target);
        mkdir($this->target.'/.claude', 0755, true);
        file_put_contents($this->target.'/.gitignore', ".mcp.json\n.claude/\n");
        file_put_contents($this->target.'/.mcp.json', '{}');
        file_put_contents($this->target.'/.claude/settings.json', '{}');

        $rows = PipelineStatus::check($this->stubs, $this->target);
        $row = $this->statusFor($rows, 'Claude Code config committed to git');

        $this->assertSame(PipelineStatus::STATUS_MISSING, $row['status']);
        $this->assertFalse(PipelineStatus::isHealthy($rows));
    }
}
