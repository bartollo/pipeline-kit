<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit;

use Bartollo\PipelineKit\Console\Commands\InstallPipelineCommand;
use Bartollo\PipelineKit\Console\Commands\PipelineAiReviewGateCommand;
use Bartollo\PipelineKit\Console\Commands\PipelineStatusCommand;
use Illuminate\Support\ServiceProvider;

final class PipelineKitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallPipelineCommand::class,
                PipelineStatusCommand::class,
                PipelineAiReviewGateCommand::class,
            ]);
        }
    }
}
