<?php

declare(strict_types=1);

namespace Bartollo\PipelineKit;

/**
 * Decision logic for the `pipeline:ai-review-gate` Claude Code `Stop` hook.
 *
 * PIPELINE.md's step 19 ("AI Code Review") expects an independent review
 * once the deterministic pipeline (lint, static analysis, tests, Sloppy,
 * security audit) is green — but nothing forces that to actually happen; a
 * session can just stop once `composer quality` passes. This gate blocks
 * that stop exactly once per reviewed diff, by returning the `{"decision":
 * "block"}` response Claude Code's Stop hook contract reads as "don't stop,
 * do this first".
 *
 * Framework-free and pure: the command wrapper computes the diff signature,
 * the previously-stored hash and the quality-pipeline result (the last one
 * lazily, via a callable, since it's expensive and most calls don't need
 * it), and this class only makes the decision.
 */
final class PipelineAiReviewGate
{
    public const ACTION_ALLOW = 'allow';

    public const ACTION_BLOCK = 'block';

    /**
     * @param  array<string, mixed>  $payload  decoded Stop hook stdin JSON
     * @param  string  $pendingChangesSignature  raw text representing the pending changes (e.g. `git diff` + untracked file list); empty string means nothing pending
     * @param  callable(): bool  $qualityPasses  lazily runs the quality pipeline; only called when a decision actually depends on it
     * @return array{action: string, reason: string, hash?: string}
     */
    public static function decide(
        array $payload,
        string $pendingChangesSignature,
        ?string $lastReviewedHash,
        callable $qualityPasses
    ): array {
        if (($payload['stop_hook_active'] ?? false) === true) {
            return self::allow('a previous Stop hook already forced a continuation for this turn — not blocking again');
        }

        if ($pendingChangesSignature === '') {
            return self::allow('no pending changes to review');
        }

        $hash = hash('sha256', $pendingChangesSignature);

        if ($hash === $lastReviewedHash) {
            return self::allow('this exact diff was already sent for an AI code review');
        }

        if (! $qualityPasses()) {
            return self::allow('quality pipeline is not green yet — nothing to review until it is');
        }

        return [
            'action' => self::ACTION_BLOCK,
            'hash' => $hash,
            'reason' => self::reviewInstruction(),
        ];
    }

    public static function reviewInstruction(): string
    {
        return <<<'TEXT'
The deterministic quality pipeline (lint, static analysis, tests, Sloppy diff, security audit) just came back green for the pending changes. Per PIPELINE.md step 19 ("AI Code Review"), that is not the finish line.

Before ending this task, run an independent AI code review of the pending changes — with a skeptical, fresh perspective, not an assumption that the implementation is correct just because the deterministic checks passed. Use the `code-review` skill (or a code-reviewer subagent) against the current diff. Fix any real findings it reports, or say explicitly why a finding doesn't apply. Only stop once that review has actually run.
TEXT;
    }

    /**
     * @return array{action: string, reason: string}
     */
    private static function allow(string $reason): array
    {
        return ['action' => self::ACTION_ALLOW, 'reason' => $reason];
    }
}
