<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit\Tests;

use Bartollo\PipelineKit\PipelineAiReviewGate;
use PHPUnit\Framework\TestCase;

final class PipelineAiReviewGateTest extends TestCase
{
    private function neverCalled(): callable
    {
        return function (): bool {
            $this->fail('the quality check should not have been called');
        };
    }

    public function test_allows_stop_when_stop_hook_is_already_active(): void
    {
        $decision = PipelineAiReviewGate::decide(
            ['stop_hook_active' => true],
            'diff --git a/foo.php b/foo.php',
            null,
            $this->neverCalled()
        );

        $this->assertSame(PipelineAiReviewGate::ACTION_ALLOW, $decision['action']);
    }

    public function test_allows_stop_when_there_are_no_pending_changes(): void
    {
        $decision = PipelineAiReviewGate::decide(
            ['stop_hook_active' => false],
            '',
            null,
            $this->neverCalled()
        );

        $this->assertSame(PipelineAiReviewGate::ACTION_ALLOW, $decision['action']);
        $this->assertStringContainsString('no pending changes', $decision['reason']);
    }

    public function test_allows_stop_without_running_quality_when_diff_already_reviewed(): void
    {
        $diff = 'diff --git a/foo.php b/foo.php';
        $hash = hash('sha256', $diff);

        $decision = PipelineAiReviewGate::decide(
            ['stop_hook_active' => false],
            $diff,
            $hash,
            $this->neverCalled()
        );

        $this->assertSame(PipelineAiReviewGate::ACTION_ALLOW, $decision['action']);
        $this->assertStringContainsString('already sent for an AI code review', $decision['reason']);
    }

    public function test_allows_stop_when_quality_pipeline_is_not_green(): void
    {
        $decision = PipelineAiReviewGate::decide(
            ['stop_hook_active' => false],
            'diff --git a/foo.php b/foo.php',
            null,
            fn (): bool => false
        );

        $this->assertSame(PipelineAiReviewGate::ACTION_ALLOW, $decision['action']);
        $this->assertStringContainsString('not green yet', $decision['reason']);
    }

    public function test_blocks_stop_and_returns_the_diff_hash_when_quality_is_green_on_a_new_diff(): void
    {
        $diff = 'diff --git a/foo.php b/foo.php';

        $decision = PipelineAiReviewGate::decide(
            ['stop_hook_active' => false],
            $diff,
            'some-previously-reviewed-hash',
            fn (): bool => true
        );

        $this->assertSame(PipelineAiReviewGate::ACTION_BLOCK, $decision['action']);
        $this->assertSame(hash('sha256', $diff), $decision['hash']);
        $this->assertStringContainsString('code-review', $decision['reason']);
    }

    public function test_blocks_again_once_the_diff_changes_after_a_previous_review(): void
    {
        $firstDiff = 'diff --git a/foo.php b/foo.php';
        $firstHash = hash('sha256', $firstDiff);

        // Same diff: already reviewed, allow.
        $still = PipelineAiReviewGate::decide(['stop_hook_active' => false], $firstDiff, $firstHash, $this->neverCalled());
        $this->assertSame(PipelineAiReviewGate::ACTION_ALLOW, $still['action']);

        // New changes on top: must be reviewed again.
        $secondDiff = $firstDiff."\n+fixed something";
        $again = PipelineAiReviewGate::decide(['stop_hook_active' => false], $secondDiff, $firstHash, fn (): bool => true);

        $this->assertSame(PipelineAiReviewGate::ACTION_BLOCK, $again['action']);
        $this->assertSame(hash('sha256', $secondDiff), $again['hash']);
    }

    public function test_stop_hook_active_short_circuits_before_checking_the_diff_hash(): void
    {
        $diff = 'diff --git a/foo.php b/foo.php';

        $decision = PipelineAiReviewGate::decide(
            ['stop_hook_active' => true],
            $diff,
            null,
            $this->neverCalled()
        );

        $this->assertSame(PipelineAiReviewGate::ACTION_ALLOW, $decision['action']);
    }
}
