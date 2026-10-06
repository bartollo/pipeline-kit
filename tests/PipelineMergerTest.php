<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit\Tests;

use Bartollo\PipelineKit\PipelineMerger;
use PHPUnit\Framework\TestCase;

final class PipelineMergerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pipeline-kit-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
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

    private function writeJsonFixture(string $path, array $data): void
    {
        $full = $this->dir.'/'.$path;
        $parent = dirname($full);
        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        file_put_contents($full, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function test_copy_whole_file_creates_when_missing(): void
    {
        $source = $this->dir.'/source.txt';
        file_put_contents($source, 'hello');
        $target = $this->dir.'/target.txt';

        $result = PipelineMerger::copyWholeFile($source, $target, dryRun: false, force: false);

        $this->assertSame('created', $result['status']);
        $this->assertSame('hello', file_get_contents($target));
    }

    public function test_copy_whole_file_skips_when_already_exists(): void
    {
        $source = $this->dir.'/source.txt';
        file_put_contents($source, 'new content');
        $target = $this->dir.'/target.txt';
        file_put_contents($target, 'existing content');

        $result = PipelineMerger::copyWholeFile($source, $target, dryRun: false, force: false);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('existing content', file_get_contents($target));
    }

    public function test_copy_whole_file_force_overwrites_with_backup(): void
    {
        $source = $this->dir.'/source.txt';
        file_put_contents($source, 'new content');
        $target = $this->dir.'/target.txt';
        file_put_contents($target, 'existing content');

        $result = PipelineMerger::copyWholeFile($source, $target, dryRun: false, force: true);

        $this->assertSame('overwritten', $result['status']);
        $this->assertSame('new content', file_get_contents($target));
        $this->assertSame('existing content', file_get_contents($target.'.bak'));
    }

    public function test_copy_whole_file_dry_run_writes_nothing(): void
    {
        $source = $this->dir.'/source.txt';
        file_put_contents($source, 'hello');
        $target = $this->dir.'/target.txt';

        $result = PipelineMerger::copyWholeFile($source, $target, dryRun: true, force: false);

        $this->assertSame('would-create', $result['status']);
        $this->assertFileDoesNotExist($target);
    }

    public function test_merge_mcp_json_creates_when_target_missing(): void
    {
        $this->writeJsonFixture('source.json', [
            'mcpServers' => ['sloppy' => ['command' => 'php']],
        ]);

        $result = PipelineMerger::mergeMcpJson($this->dir.'/source.json', $this->dir.'/target.json', dryRun: false);

        $this->assertSame('created', $result['status']);
        $written = PipelineMerger::readJson($this->dir.'/target.json');
        $this->assertSame(['sloppy' => ['command' => 'php']], $written['mcpServers']);
    }

    public function test_merge_mcp_json_adds_only_missing_servers(): void
    {
        $this->writeJsonFixture('source.json', [
            'mcpServers' => ['sloppy' => ['command' => 'php'], 'laravel-boost' => ['command' => 'php']],
        ]);
        $this->writeJsonFixture('target.json', [
            'mcpServers' => ['sloppy' => ['command' => 'custom-already-here']],
        ]);

        $result = PipelineMerger::mergeMcpJson($this->dir.'/source.json', $this->dir.'/target.json', dryRun: false);

        $this->assertSame('merged', $result['status']);
        $this->assertStringContainsString('laravel-boost', $result['detail']);
        $written = PipelineMerger::readJson($this->dir.'/target.json');
        $this->assertSame('custom-already-here', $written['mcpServers']['sloppy']['command']);
        $this->assertArrayHasKey('laravel-boost', $written['mcpServers']);
    }

    public function test_merge_mcp_json_is_idempotent(): void
    {
        $this->writeJsonFixture('source.json', [
            'mcpServers' => ['sloppy' => ['command' => 'php']],
        ]);

        PipelineMerger::mergeMcpJson($this->dir.'/source.json', $this->dir.'/target.json', dryRun: false);
        $result = PipelineMerger::mergeMcpJson($this->dir.'/source.json', $this->dir.'/target.json', dryRun: false);

        $this->assertSame('skipped', $result['status']);
    }

    public function test_merge_claude_settings_appends_hook_without_duplicating(): void
    {
        $sourceHooks = [
            'hooks' => [
                'PostToolUse' => [
                    ['matcher' => 'Edit|Write', 'hooks' => [['type' => 'command', 'command' => 'sloppy hook post-edit']]],
                ],
            ],
        ];
        $this->writeJsonFixture('source.json', $sourceHooks);

        $first = PipelineMerger::mergeClaudeSettings($this->dir.'/source.json', $this->dir.'/target.json', dryRun: false);
        $this->assertSame('created', $first['status']);

        $second = PipelineMerger::mergeClaudeSettings($this->dir.'/source.json', $this->dir.'/target.json', dryRun: false);
        $this->assertSame('skipped', $second['status']);

        $written = PipelineMerger::readJson($this->dir.'/target.json');
        $this->assertCount(1, $written['hooks']['PostToolUse'][0]['hooks']);
    }

    public function test_merge_settings_local_unions_without_removing_existing(): void
    {
        $this->writeJsonFixture('source.json', ['enabledMcpjsonServers' => ['sloppy', 'laravel-boost']]);
        $this->writeJsonFixture('target.json', ['enabledMcpjsonServers' => ['my-own-server']]);

        $result = PipelineMerger::mergeSettingsLocal($this->dir.'/source.json', $this->dir.'/target.json', dryRun: false);

        $this->assertSame('merged', $result['status']);
        $written = PipelineMerger::readJson($this->dir.'/target.json');
        $this->assertSame(['my-own-server', 'sloppy', 'laravel-boost'], array_values($written['enabledMcpjsonServers']));
    }

    public function test_merge_composer_json_adds_missing_packages_and_scripts(): void
    {
        $this->writeJsonFixture('target.json', [
            'require-dev' => ['laravel/pint' => '^1.0'],
            'scripts' => ['lint' => 'echo custom-lint'],
        ]);

        $fragment = [
            'require-dev' => [
                'laravel/pint' => '^9.9',
                'heyosseus/sloppy' => '^1.2',
            ],
            'scripts' => [
                'lint' => 'pint --test',
                'analyse' => 'phpstan analyse',
            ],
        ];

        $result = PipelineMerger::mergeComposerJson($fragment, $this->dir.'/target.json', dryRun: false);

        $this->assertSame('merged', $result['status']);
        $this->assertStringContainsString('heyosseus/sloppy', $result['detail']);
        $this->assertStringContainsString('analyse', $result['detail']);
        $this->assertStringContainsString('skipped existing scripts: lint', $result['detail']);

        $written = PipelineMerger::readJson($this->dir.'/target.json');
        // existing package constraint untouched
        $this->assertSame('^1.0', $written['require-dev']['laravel/pint']);
        $this->assertSame('^1.2', $written['require-dev']['heyosseus/sloppy']);
        // existing custom script untouched
        $this->assertSame('echo custom-lint', $written['scripts']['lint']);
        $this->assertSame('phpstan analyse', $written['scripts']['analyse']);
        $this->assertFileExists($this->dir.'/target.json.bak');
    }

    public function test_merge_composer_json_is_idempotent(): void
    {
        $this->writeJsonFixture('target.json', ['require-dev' => [], 'scripts' => []]);
        $fragment = ['require-dev' => ['heyosseus/sloppy' => '^1.2'], 'scripts' => ['analyse' => 'phpstan analyse']];

        PipelineMerger::mergeComposerJson($fragment, $this->dir.'/target.json', dryRun: false);
        $result = PipelineMerger::mergeComposerJson($fragment, $this->dir.'/target.json', dryRun: false);

        $this->assertSame('skipped', $result['status']);
    }

    public function test_merge_composer_json_errors_when_target_missing(): void
    {
        $result = PipelineMerger::mergeComposerJson(
            ['require-dev' => [], 'scripts' => []],
            $this->dir.'/does-not-exist.json',
            dryRun: false
        );

        $this->assertSame('error', $result['status']);
    }
}
