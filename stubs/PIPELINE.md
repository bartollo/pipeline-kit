# Laravel + Claude Code --- Quality, Sloppy, Code Review and AI Pipeline

This document defines a recommended pipeline for developing
**PHP/Laravel applications with Claude Code**, combining deterministic
tools, analysis specific to agent-produced code, tests, code review
and CI.

> **The AI implements. Sloppy watches the agent. The tools verify.
> Another context reviews. CI validates.**

------------------------------------------------------------------------

## 1. Goal

Claude should not decide on its own whether a task is complete.

Code is only considered ready after passing through multiple
independent layers:

``` text
Claude Code
    │
    ├── Laravel Boost / MCP
    │
    ▼
Implementation
    │
    ├── Sloppy hook after edit
    │       └── new finding → Claude fixes it immediately
    │
    ▼
Laravel Pint
    │
    ▼
Larastan / PHPStan
    │
    ▼
Pest
    │
    ├── Feature / Unit
    ├── Architecture Tests
    └── Type Coverage
    │
    ▼
Composer Audit
    │
    ▼
Sloppy Diff / Review
    │
    ▼
composer quality
    │
    ▼
Independent AI Code Review
    │
    ▼
GitHub Actions
    │
    ├── quality
    └── Sloppy CI / annotations
    │
    ▼
Human Review
    │
    ▼
Merge
```

------------------------------------------------------------------------

## 2. Recommended stack

### Framework

-   PHP 8.3+
-   Laravel
-   Composer

### AI

-   Claude Code
-   Laravel Boost
-   MCP
-   Sloppy

### Quality

-   Laravel Pint
-   Larastan / PHPStan
-   Sloppy
-   Composer Audit

### Tests

-   Pest
-   Feature Tests
-   Unit Tests
-   Architecture Tests
-   Type Coverage
-   Mutation Testing

### Review

-   Sloppy Diff
-   Sloppy Review
-   AI Code Review
-   Human Review

### Automation

-   Composer Scripts
-   Claude Code Hooks
-   GitHub Actions

### Optional

-   Rector
-   Deptrac

------------------------------------------------------------------------

## 3. Installation

Install the core tools:

``` bash
composer require --dev \
    laravel/pint \
    larastan/larastan \
    pestphp/pest \
    pestphp/pest-plugin-laravel \
    pestphp/pest-plugin-type-coverage \
    heyosseus/sloppy
```

Then configure Sloppy's integration with Claude Code:

``` bash
vendor/bin/sloppy agents install
```

In Laravel, Sloppy can also be run via:

``` bash
php artisan sloppy
```

Also configure **Laravel Boost** to provide the agent with framework
context, documentation and information compatible with the versions
used in the project.

------------------------------------------------------------------------

## 4. Why Sloppy is part of the pipeline

Sloppy is a layer that complements Pint, PHPStan and Pest.

Each tool answers a different question:

``` text
Pint
└── Is the code formatted correctly?

PHPStan / Larastan
└── Are the static types and contracts correct?

Pest
└── Does the expected behavior work?

Architecture Tests
└── Do the checkable architectural rules still hold?

Sloppy
└── Did the agent introduce debt, bad patterns or regressions
    typical of code produced quickly by AI?

AI Code Review
└── Is there a semantic/contextual problem that the
    deterministic tools didn't catch?
```

Sloppy is particularly useful because it works on **new problems
introduced by the change**, keeping the agent from wandering off to
fix historical debt that doesn't belong to the current task.

------------------------------------------------------------------------

## 5. Sloppy inside Claude's loop

Sloppy should run during development, not only in CI.

After installing the hooks:

``` bash
vendor/bin/sloppy agents install
```

the expected flow is:

``` text
Claude changes a PHP file
        │
        ▼
Sloppy analyses the change
        │
        ├── new finding
        │       │
        │       ▼
        │   Claude fixes it
        │       │
        │       └──────┐
        │              │
        ▼              │
      clean ◄───────────┘
        │
        ▼
Claude continues
```

When the agent tries to finish a task, new findings above the
configured threshold should send it back to fix them.

This creates a much shorter feedback loop:

``` text
generate → analyse → fix → continue
```

instead of:

``` text
generate everything → open PR → find problems during review
```

------------------------------------------------------------------------

## 6. What Sloppy looks for

Among the categories analysed:

-   excessive complexity;
-   god methods;
-   god classes;
-   excessive nesting;
-   duplicate logic;
-   copy/paste drift;
-   dead private methods;
-   unused constructor dependencies;
-   placeholder implementations;
-   swallowed exceptions;
-   redundant conditions;
-   narrative comments;
-   unnecessary defensive programming;
-   business logic in controllers;
-   inline validation;
-   direct external calls;
-   models taking on too many responsibilities;
-   possible N+1s;
-   queries inside loops;
-   using a Collection when the query should resolve it;
-   suspicious `Model::all()`;
-   excessive dependencies in controllers/services;
-   unnecessary abstractions;
-   empty wrappers;
-   single-use abstractions;
-   unexplained suppressions;
-   PHPStan/Psalm baseline growth;
-   tests weakened just to make the pipeline pass.

This is especially relevant in agent workflows, since several of
these problems are not necessarily syntax or type errors.

------------------------------------------------------------------------

## 7. Laravel Pint

Check:

``` bash
vendor/bin/pint --test
```

Fix:

``` bash
vendor/bin/pint
```

Pint is responsible for mechanical code standardization.

Don't waste the AI Code Review discussing formatting that Pint can
resolve deterministically.

------------------------------------------------------------------------

## 8. Larastan + PHPStan

Example `phpstan.neon`:

``` neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app

    level: 8

    treatPhpDocTypesAsCertain: false
```

For new projects, gradually evolve to:

``` neon
level: 10
```

### Legacy projects

In existing projects:

``` bash
vendor/bin/phpstan analyse --generate-baseline
```

The baseline should represent historical debt.

Never regenerate the baseline just to hide new errors.

Sloppy can also detect improper baseline growth, adding another
safeguard against that kind of shortcut.

------------------------------------------------------------------------

## 9. Pest

Run:

``` bash
vendor/bin/pest --parallel
```

Every behavioral change must have tests.

Prioritize:

1.  Feature Tests;
2.  Unit Tests for isolated logic;
3.  Regression Tests.

Test at least:

-   happy path;
-   validation;
-   authorization;
-   failure paths;
-   important edge cases;
-   regressions.

------------------------------------------------------------------------

## 10. Architecture Tests

Example:

``` php
<?php

arch('debug functions')
    ->expect('App')
    ->not->toUse([
        'dd',
        'dump',
        'var_dump',
    ]);

arch('controllers')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('models')
    ->expect('App\Models')
    ->toExtend(\Illuminate\Database\Eloquent\Model::class);

arch()->preset()->php();
arch()->preset()->security();
arch()->preset()->laravel();
```

Architecture Tests turn architectural decisions into executable
rules.

------------------------------------------------------------------------

## 11. Sloppy integrated into Pest

Sloppy can participate directly in the Pest suite.

Example:

``` php
it('has no new slop on this branch', function (): void {
    expectCleanSloppyDiff('main');
});
```

This means new debt detected by Sloppy can break the test suite.

The benefit is significant:

``` text
project rule
      ↓
executable test
      ↓
CI
      ↓
merge blocked
```

------------------------------------------------------------------------

## 12. Type Coverage

``` bash
vendor/bin/pest --type-coverage --min=95
```

For new projects, consider evolving to:

``` bash
vendor/bin/pest --type-coverage --min=100
```

Avoid:

``` php
function calculate($value)
```

Prefer, when appropriate:

``` php
public function calculate(Money $value): Money
```

------------------------------------------------------------------------

## 13. Mutation Testing

To check the real quality of the tests:

``` bash
vendor/bin/pest \
    --mutate \
    --parallel \
    --covered-only \
    --min=80
```

Since mutation testing is more expensive, a possible strategy is to
run it:

-   nightly;
-   on critical PRs;
-   before releases;
-   on sensitive modules.

------------------------------------------------------------------------

## 14. Composer Audit

Include:

``` bash
composer audit
```

Checking for known vulnerabilities should be part of CI.

------------------------------------------------------------------------

## 15. Sloppy Diff

During a feature branch:

``` bash
vendor/bin/sloppy diff main
```

The goal is to analyse only the problems introduced by the branch.

This is especially important in legacy projects:

``` text
historical debt
      │
      ├── doesn't belong to the task
      │
      └── baseline

current change
      │
      └── cannot introduce new debt
```

------------------------------------------------------------------------

## 16. Sloppy Review

Before the AI Code Review:

``` bash
vendor/bin/sloppy review main
```

`review` prioritizes findings by risk, helping the reviewer decide
where to focus attention.

Flow:

``` text
git diff
    │
    ▼
sloppy diff
    │
    ▼
sloppy review
    │
    ▼
AI Code Review
    │
    ▼
Human Review
```

Sloppy does not replace Code Review.

It reduces noise and points to regions of the diff that deserve
attention.

------------------------------------------------------------------------

## 17. Sloppy Fix

For findings that can be fixed mechanically:

``` bash
vendor/bin/sloppy fix
```

Sloppy can delegate mechanical fixes to Rector, limit the changes to
the relevant files, and run Pint.

The rule should be:

``` text
mechanical fix
      ↓
Sloppy / Rector / Pint

semantic fix
      ↓
Claude / developer
      ↓
tests
      ↓
review
```

Never let an automated tool silently change business rules just to
eliminate a finding.

------------------------------------------------------------------------

## 18. Composer Scripts

Example `composer.json`:

``` json
{
    "scripts": {
        "lint": "pint --test",
        "lint:fix": "pint",
        "analyse": "phpstan analyse --memory-limit=2G",
        "test": "pest --parallel",
        "types": "pest --type-coverage --min=95",
        "sloppy": "sloppy",
        "sloppy:diff": "sloppy diff main",
        "sloppy:review": "sloppy review main",
        "security": "composer audit",
        "quality": [
            "@lint",
            "@analyse",
            "@test",
            "@types",
            "@sloppy:diff",
            "@security"
        ]
    }
}
```

Main contract:

``` bash
composer quality
```

If it returns a code other than `0`, the task is not finished.

------------------------------------------------------------------------

## 19. AI Code Review

After the deterministic checks pass, run an independent Code
Review.

`bartollo/pipeline-kit` automates triggering this step: it installs a
`Stop` hook (`pipeline:ai-review-gate`) that runs `composer quality`
whenever the session tries to end and, if it passes, blocks the task
from finishing by explicitly asking for an independent review before
stopping — once per diff, not repeatedly. Nothing replaces Human
Review, but this ensures the step is always at least triggered,
instead of depending on someone remembering to run it manually.

Ideally:

``` text
Claude / context A
       │
       ▼
  implementation
       │
       ▼
Sloppy + composer quality
       │
       ▼
Claude / context B
       │
       ▼
   CODE REVIEW
```

The reviewer should receive:

``` text
REQUIREMENT
+
GIT DIFF
+
ARCHITECTURAL CONTEXT
+
TEST RESULTS
+
SLOPPY FINDINGS
```

The second context should not assume the implementation is correct
just because another agent produced it.

------------------------------------------------------------------------

## 20. Code Review checklist

### Correctness

-   bugs;
-   inverted logic;
-   incomplete conditions;
-   incorrect `null` handling;
-   invalid states;
-   edge cases;
-   concurrency.

### Laravel

-   N+1;
-   missing eager loading;
-   unnecessary queries;
-   business logic in controllers;
-   validation outside Form Requests;
-   missing authorization;
-   missing transactions;
-   jobs that should be asynchronous.

### Database

-   missing indexes;
-   problematic migrations;
-   foreign keys;
-   unique constraints;
-   race conditions;
-   queries in loops;
-   non-atomic operations.

### Security

-   mass assignment;
-   authorization bypass;
-   IDOR;
-   SQL injection;
-   XSS;
-   sensitive data exposure;
-   secrets;
-   insecure uploads;
-   insufficient validation.

### Performance

-   N+1;
-   duplicated queries;
-   huge collections in memory;
-   lack of pagination;
-   slow synchronous operations;
-   unnecessary processing.

### Tests

-   new behavior without a test;
-   weak assertions;
-   tests that don't prove the behavior;
-   missing failure paths;
-   untested authorization;
-   untested validation;
-   uncovered regressions.

### Architecture

-   unnecessary abstractions;
-   duplication;
-   mixed responsibilities;
-   inadequate dependencies;
-   domain rules in controllers;
-   unnecessary coupling.

------------------------------------------------------------------------

## 21. Finding severity

Use:

``` text
BLOCKER
Serious error that blocks the merge.

HIGH
Bug, vulnerability or important problem.

MEDIUM
Real problem that should be fixed.

LOW
Valid improvement, but not critical.

INFO
Optional observation or suggestion.
```

Example:

``` text
[HIGH] Race condition when creating the payment

PaymentService.php:82

The code checks whether a payment exists and then creates
a new record in two separate operations.

Two concurrent requests can pass the check
before either one performs the INSERT.

Recommendation:
Add a unique constraint at the database level and handle
the collision, or make the operation atomically safe.
```

------------------------------------------------------------------------

## 22. Code Review should look for bugs, not style

Avoid:

``` text
"I would write this method differently."

"I'd prefer a repository here."

"Maybe this name could be different."
```

Prioritize:

``` text
BUG
SECURITY
DATA INTEGRITY
RACE CONDITION
PERFORMANCE
MISSING AUTHORIZATION
MISSING VALIDATION
MISSING TEST
ARCHITECTURE VIOLATION
```

Pint already takes care of mechanical style.

Sloppy already covers several recurring structural patterns.

The reviewer should use their context to look for problems that
still slipped past those layers.

------------------------------------------------------------------------

## 23. CLAUDE.md

Add rules similar to these to `CLAUDE.md`:

``` md
# Laravel Development Rules

## General

This is a Laravel application.

Before implementing anything:

1. Understand the existing architecture.
2. Search for existing implementations before creating abstractions.
3. Prefer Laravel conventions over custom solutions.
4. Use Laravel Boost documentation when unsure about framework APIs.
5. Never assume an API exists.

## PHP

- Use strict typing where appropriate.
- Prefer typed properties.
- Always declare parameter and return types.
- Avoid mixed whenever possible.
- Prefer enums over magic strings.
- Do not create unnecessary abstractions.

## Laravel

- Validation belongs in Form Requests.
- Authorization belongs in Policies/Gates.
- Business logic should not live in controllers.
- Avoid N+1 queries.
- Use transactions when multiple writes must be atomic.
- Use queues for slow/out-of-band operations.
- Never call env() outside config files.

## Database

- Migrations must be reversible.
- Add appropriate indexes and constraints.
- Avoid queries inside loops.
- Consider race conditions.
- Protect invariants at database level when appropriate.

## Testing

Every behavioral change must include tests.

Test:
- happy path
- validation
- authorization
- failure paths
- important edge cases
- regressions

## Sloppy

Sloppy is part of the development loop.

- Fix new Sloppy findings introduced by your changes.
- Do not modify unrelated legacy code merely to improve the Sloppy score.
- Do not grow a baseline to hide a new problem.
- Do not weaken tests to satisfy the pipeline.
- Use sloppy diff/review before declaring work complete.
- Mechanical fixes may use sloppy fix, but semantic changes require review.

## Definition of Done

Before saying a task is complete:

1. Run the relevant tests.
2. Run composer quality.
3. Run sloppy review main.
4. Review git diff.
5. Fix valid findings.
6. Run composer quality again.

Never disable PHPStan, Sloppy rules or tests merely to make CI pass.
Never regenerate a baseline to hide a newly introduced problem.
```

------------------------------------------------------------------------

## 24. GitHub Actions + Sloppy CI

CI should repeat the checks independently of the agent.

Sloppy has a specific mode for CI:

``` bash
vendor/bin/sloppy ci
```

It can also be integrated via a GitHub Action:

``` yaml
- uses: heyosseus/sloppy-action@v1
  with:
    diff-branch: main
```

Pipeline:

``` text
                   Pull Request
                        │
          ┌─────────────┴─────────────┐
          │                           │
          ▼                           ▼
      Quality CI                  Sloppy CI
          │                           │
     ┌────┼─────┐                     │
     ▼    ▼     ▼                     │
   Pint PHPStan Pest              diff findings
          │                           │
     Types + Audit                annotations
          │                           │
          └─────────────┬─────────────┘
                        ▼
                  AI Code Review
                        │
                        ▼
                   Human Review
                        │
                        ▼
                      Merge
```

Even if Claude runs everything locally, CI should repeat the checks
in a clean environment.

------------------------------------------------------------------------

## 25. Sloppy Baseline for legacy projects

For an existing codebase:

``` bash
vendor/bin/sloppy baseline
```

The idea is to explicitly accept the current debt and prevent new
debt.

``` text
LEGACY
────────────────────────────
existing problems
        │
        ▼
Sloppy baseline
        │
        ▼
known state

NEW CODE
────────────────────────────
Claude changes code
        │
        ▼
Sloppy diff
        │
        ▼
new debt?
   │         │
  YES        NO
   │         │
  fix     continue
```

A baseline is not a license to increase debt.

------------------------------------------------------------------------

## 26. Sloppy Watch

During interactive development:

``` bash
vendor/bin/sloppy watch
```

Can be useful in long agent sessions, keeping continuous feedback
while files are changed.

------------------------------------------------------------------------

## 27. MCP

Sloppy also offers an MCP server for agents that need to request
scans on demand.

In a setup with Laravel Boost + Sloppy, the conceptual split is:

``` text
Laravel Boost / MCP
└── Laravel context
    documentation
    framework knowledge

Sloppy / MCP
└── quality of the change
    introduced debt
    structural risks
```

The two are complementary.

------------------------------------------------------------------------

## 28. Human Review

AI Code Review and Sloppy do not eliminate Human Review.

The tools are good at systematically detecting:

-   problematic patterns;
-   potential bugs;
-   new debt;
-   regressions;
-   repetitive problems;
-   missing tests.

The human remains responsible for questions like:

-   is the requirement correct?
-   does this solution solve the product problem?
-   is the complexity justified?
-   does this architecture make sense long term?
-   are we solving the right problem?

------------------------------------------------------------------------

## 29. Definition of Done

A task is only considered complete when:

-   the implementation is complete;
-   Sloppy hooks don't flag relevant new debt;
-   Pint passes;
-   PHPStan/Larastan passes;
-   Pest passes;
-   new behaviors have tests;
-   Architecture Tests pass;
-   Type Coverage meets the minimum;
-   Composer Audit passes;
-   `sloppy diff main` is acceptable;
-   `sloppy review main` has been reviewed;
-   `composer quality` passes;
-   the Git Diff has been reviewed;
-   AI Code Review has run;
-   relevant findings have been resolved;
-   `composer quality` has passed again;
-   GitHub Actions passed;
-   Sloppy CI passed;
-   Human Review was performed when necessary.

------------------------------------------------------------------------

## 30. Final pipeline

``` text
                        TASK
                          │
                          ▼
                  ┌───────────────┐
                  │   PLANNING    │
                  │ Claude / human│
                  └───────┬───────┘
                          │
                          ▼
                  ┌───────────────┐
                  │ Claude Code   │
                  │ implementer   │
                  └───────┬───────┘
                          │
                          ▼
                     PHP change
                          │
                          ▼
                  ┌───────────────┐
                  │ Sloppy Hook   │
                  └───────┬───────┘
                          │
                    new finding?
                     /         \
                   YES          NO
                    │           │
                    ▼           ▼
              Claude fixes it  continue
                    │           │
                    └─────┬─────┘
                          ▼
                        tests
                          │
                          ▼
                 composer quality
                          │
                    ┌─────┴─────┐
                    │           │
                  FAIL         PASS
                    │           │
                    ▼           ▼
                   fix     sloppy review
                    ▲           │
                    │           ▼
                    │     AI CODE REVIEW
                    │           │
                    │      ┌────┴────┐
                    │      │         │
                    └── findings     OK
                                   │
                                   ▼
                            composer quality
                                   │
                                   ▼
                            GitHub Actions
                              │         │
                              ▼         ▼
                         Quality CI  Sloppy CI
                              │         │
                              └────┬────┘
                                   ▼
                              Human Review
                                   │
                                   ▼
                                  MERGE
```

------------------------------------------------------------------------

## 31. Fundamental rule

We don't want:

``` text
Claude implements
       ↓
Claude looks at its own code
       ↓
"looks correct"
       ↓
Merge
```

We also don't want to rely exclusively on another LLM to police the
first one.

We want:

``` text
Claude implements
       ↓
Sloppy watches every change
       ↓
Pint
       ↓
Larastan / PHPStan
       ↓
Pest
       ↓
Architecture Tests
       ↓
Type Coverage
       ↓
Security Audit
       ↓
Sloppy Diff / Review
       ↓
Independent AI Reviewer
       ↓
Independent CI
       ↓
Sloppy CI
       ↓
Human Review
       ↓
Merge
```

The central point is to create **deterministic feedback around the
AI**.

> **Claude implements. Laravel Boost provides context. Sloppy detects
> the agent's typical debt while it works. Pint standardizes. PHPStan
> analyses types. Pest verifies behavior. Architecture Tests protect
> structural rules. Sloppy Review prioritizes risks in the diff. A
> second context performs Code Review. CI validates everything again.
> Humans remain responsible for engineering decisions.**

------------------------------------------------------------------------

## Reference

Sloppy: https://github.com/Heyosseus/sloppy
