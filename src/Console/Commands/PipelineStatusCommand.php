<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit\Console\Commands;

use Bartollo\PipelineKit\PipelineStatus;
use Illuminate\Console\Command;

final class PipelineStatusCommand extends Command
{
    protected $signature = 'pipeline:status
        {--json : Output the raw status list as JSON instead of a table}';

    protected $description = 'Show the status of every item the Claude Code + Sloppy + Laravel Boost pipeline manages in this project';

    private const STATUS_LABELS = [
        PipelineStatus::STATUS_OK => '<fg=green>OK</>',
        PipelineStatus::STATUS_PARTIAL => '<fg=yellow>PARTIAL</>',
        PipelineStatus::STATUS_MODIFIED => '<fg=cyan>MODIFIED</>',
        PipelineStatus::STATUS_MISSING => '<fg=red>MISSING</>',
    ];

    public function handle(): int
    {
        $stubs = dirname(__DIR__, 3).'/stubs';
        $target = base_path();

        $rows = PipelineStatus::check($stubs, $target);

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return PipelineStatus::isHealthy($rows) ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Item', 'File', 'Status', 'Detail'],
            array_map(
                fn (array $row) => [
                    $row['item'],
                    $row['file'],
                    self::STATUS_LABELS[$row['status']] ?? $row['status'],
                    $row['detail'],
                ],
                $rows
            )
        );

        $this->newLine();

        if (PipelineStatus::isHealthy($rows)) {
            $this->info('Pipeline fully configured.');

            return self::SUCCESS;
        }

        $this->warn('Some pipeline items are missing or only partially configured — run php artisan pipeline:install (or pipeline:install --force) to fix them.');

        return self::FAILURE;
    }
}
