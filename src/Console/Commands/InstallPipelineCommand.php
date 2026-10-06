<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit\Console\Commands;

use Bartollo\PipelineKit\PipelineMerger;
use Illuminate\Console\Command;

final class InstallPipelineCommand extends Command
{
    protected $signature = 'pipeline:install
        {--dry-run : Print every planned action without writing anything}
        {--force : Overwrite phpstan.neon, boost.json, CLAUDE.md and PIPELINE.md if they already exist (backs up first)}';

    protected $description = 'Install/merge the Claude Code + Sloppy + Laravel Boost quality pipeline into this project';

    public function handle(): int
    {
        $stubs = dirname(__DIR__, 3).'/stubs';
        $target = base_path();
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->line(($dryRun ? '[dry-run] ' : '')."Installing pipeline into {$target}");
        $this->newLine();

        $this->comment('Claude / MCP configuration:');
        $this->report('.mcp.json', PipelineMerger::mergeMcpJson($stubs.'/mcp.json', $target.'/.mcp.json', $dryRun));
        $this->report('.claude/settings.json', PipelineMerger::mergeClaudeSettings($stubs.'/claude-settings.json', $target.'/.claude/settings.json', $dryRun));
        $this->report('.claude/settings.local.json', PipelineMerger::mergeSettingsLocal($stubs.'/claude-settings.local.json', $target.'/.claude/settings.local.json', $dryRun));

        $this->newLine();
        $this->comment('Quality tooling configuration:');
        $this->report('phpstan.neon', PipelineMerger::copyWholeFile($stubs.'/phpstan.neon', $target.'/phpstan.neon', $dryRun, $force));
        $this->report('boost.json', PipelineMerger::copyWholeFile($stubs.'/boost.json', $target.'/boost.json', $dryRun, $force));

        $this->newLine();
        $this->comment('Documentation:');
        $this->report('CLAUDE.md', PipelineMerger::copyWholeFile($stubs.'/CLAUDE.md', $target.'/CLAUDE.md', $dryRun, $force));
        $this->report('PIPELINE.md', PipelineMerger::copyWholeFile($stubs.'/PIPELINE.md', $target.'/PIPELINE.md', $dryRun, $force));

        $this->newLine();
        $this->comment('composer.json:');
        $fragment = PipelineMerger::readJson($stubs.'/composer-fragment.json') ?? ['require-dev' => [], 'scripts' => []];
        $composerResult = PipelineMerger::mergeComposerJson($fragment, $target.'/composer.json', $dryRun);
        $this->report('composer.json', $composerResult);

        $this->newLine();

        if ($composerResult['status'] === 'error') {
            $this->error($composerResult['detail']);

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line('Dry run only — nothing was written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $this->info('Done. Next steps:');
        $this->line('  1. composer update (installs any newly added require-dev packages)');
        $this->line('  2. php artisan boost:install (if laravel/boost was just added)');
        $this->line('  3. vendor/bin/sloppy rules --format=claude');
        $this->line('     then replace the "Sloppy: code rules for this repository" section at the');
        $this->line('     bottom of CLAUDE.md with that output — it was copied from the source');
        $this->line('     project and describes its paths/config, not this one.');
        $this->line('  4. Review git diff before committing.');

        return self::SUCCESS;
    }

    /**
     * @param  array{status: string, detail: string}  $result
     */
    private function report(string $file, array $result): void
    {
        $label = str_pad($result['status'], 16);
        $line = "  [{$label}] {$file}";
        if ($result['detail'] !== '') {
            $line .= ' — '.$result['detail'];
        }
        $this->line($line);
    }
}
