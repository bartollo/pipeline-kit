<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit;

use Bartollo\PipelineKit\Console\Commands\InstallPipelineCommand;
use Illuminate\Support\ServiceProvider;

final class PipelineKitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallPipelineCommand::class,
            ]);
        }
    }
}
