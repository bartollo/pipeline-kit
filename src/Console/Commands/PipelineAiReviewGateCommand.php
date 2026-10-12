<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit\Console\Commands;

use Bartollo\PipelineKit\PipelineAiReviewGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Wired into `.claude/settings.json` as a `Stop` hook. Not meant to be run
 * by hand — Claude Code invokes it with the Stop hook JSON payload on
 * stdin, and reads a `{"decision": "block", "reason": "..."}` line from
 * stdout as an instruction to keep working instead of stopping.
 */
final class PipelineAiReviewGateCommand extends Command
{
    protected $signature = 'pipeline:ai-review-gate';

    protected $description = 'Claude Code Stop-hook gate: forces an independent AI code review once the quality pipeline is green';

    public function handle(): int
    {
        $payload = json_decode((string) file_get_contents('php://stdin'), true);
        $payload = is_array($payload) ? $payload : [];

        $statePath = $this->statePath();
        $lastReviewedHash = is_file($statePath) ? trim((string) file_get_contents($statePath)) : null;

        $decision = PipelineAiReviewGate::decide(
            $payload,
            $this->pendingChangesSignature(),
            $lastReviewedHash,
            fn (): bool => $this->qualityPipelinePasses(),
        );

        if ($decision['action'] === PipelineAiReviewGate::ACTION_BLOCK) {
            $this->rememberReviewedHash($statePath, $decision['hash']);
            $this->output->write(json_encode(['decision' => 'block', 'reason' => $decision['reason']]));
        }

        return self::SUCCESS;
    }

    private function pendingChangesSignature(): string
    {
        return $this->capture(['git', 'diff', 'HEAD']).$this->capture(['git', 'ls-files', '--others', '--exclude-standard']);
    }

    private function qualityPipelinePasses(): bool
    {
        return Process::path(base_path())->timeout(600)->run('composer quality')->successful();
    }

    /**
     * @param  list<string>  $command
     */
    private function capture(array $command): string
    {
        $result = Process::path(base_path())->timeout(30)->run($command);

        return $result->successful() ? $result->output() : '';
    }

    private function rememberReviewedHash(string $statePath, string $hash): void
    {
        $dir = dirname($statePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($statePath, $hash);
    }

    private function statePath(): string
    {
        return sys_get_temp_dir().'/pipeline-kit-ai-review/'.md5(base_path()).'.hash';
    }
}
