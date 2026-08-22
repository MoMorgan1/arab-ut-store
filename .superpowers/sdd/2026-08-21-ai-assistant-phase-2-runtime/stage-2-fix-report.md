# Stage 2 correction report

## Scope

- Fix base: `1c5a0d1b52e1e61ae6d8fc714b65c12d954e09c9`.
- One correction wave across Stage 2 Tasks 1-3 only.
- No push, deployment, Hostinger access, provider request, key request, dependency change, `.env.example` change, production enablement, schema change, or unrelated plan-note edit.

## Implementation

- `config/ai-assistant.php` now preserves Laravel `env()` results instead of casting, trimming, filtering, coercing, or deduplicating them. Defaults and approved values are unchanged.
- `AgentRuntimeConfig` remains the sole application reader of `ai-assistant.*`. It strictly accepts canonical integer strings or integers, rejects malformed or out-of-range values with the generic `ConfigurationInvalid` exception, parses an empty tester allowlist as `[]`, and rejects malformed, zero, negative, non-list, or duplicate tester IDs without sanitizing them.
- `FakeAgentModel` checks the shared deadline immediately before yielding the completion event.
- The runtime-config suite now crosses the real config-file/env-loading boundary for canonical and malformed enabled, tester, and numeric values. It also data-drives minimum-minus-one and maximum-plus-one for every bounded accessor and both 2 and 4 for fixed `maxAttempts=3`.
- The deterministic fake-clock suite covers expiry after all three deltas and before completion, asserting three deltas, `AgentDeadlineExceeded`, and no completion.
- The real-migration schema suite now reuses `trace_id` while changing both attempt number and provider response ID, independently proving the unique trace constraint.

## Correction files

- `config/ai-assistant.php`
- `app/Support/AI/AgentRuntimeConfig.php`
- `app/Services/AI/FakeAgentModel.php`
- `tests/Unit/AI/AgentRuntimeConfigTest.php`
- `tests/Unit/AI/FakeAgentModelTest.php`
- `tests/Feature/AI/AgentRuntimeSchemaTest.php`
- `.superpowers/sdd/2026-08-21-ai-assistant-phase-2-runtime/stage-2-fix-report.md`

## RED and mutation evidence

Before production fixes:

```text
php artisan test tests/Unit/AI/AgentRuntimeConfigTest.php tests/Unit/AI/FakeAgentModelTest.php --filter="environment config|after all deltas before completion"
12 tests: 5 passed, 7 failed, 12 assertions.
```

The six malformed config cases (`tru`, `7oops`, `0`, `-7`, `7,7`, and `100oops`) all failed because the current code incorrectly accepted them. The final-deadline case failed because no `AgentDeadlineExceeded` was raised after the third delta.

The new trace scenario passed against the real migration. Temporarily mutating only `trace_id` from `->unique()` to a plain ULID produced the expected RED:

```text
php artisan test tests/Feature/AI/AgentRuntimeSchemaTest.php --filter="trace boundary"
1 test failed: Illuminate\Database\QueryException was not thrown.
```

The migration mutation was immediately restored; there is no migration diff.

## GREEN and verification evidence

- Focused config/fake/schema: 68 passed, 141 assertions.
- Relevant Tasks 1-3 runtime, mode, chat, eligibility, contract, resolver, schema, and migration-safe set: 101 passed, 274 assertions, 1 environment-gated skip.
- Targeted PHPStan: `php -d memory_limit=2G vendor/bin/phpstan analyse app/Support/AI/AgentRuntimeConfig.php app/Services/AI/FakeAgentModel.php --no-progress` passed with 0 errors.
- Targeted Pint: all six changed PHP source/test files passed `pint --test`.
- Full PHP suite, run once with `php -d memory_limit=2G artisan test`: 945 passed, 33,318 assertions.
- `git diff --check` passed.
- Dependency and `.env.example` diff was empty.
- Source scan found `config("ai-assistant.{$key}")` only in `AgentRuntimeConfig`.

## Guard review and concerns

- TDD and writing-good-tests: regressions exercised real behavior with hand-derived expectations; config and deadline fixes were preceded by observed RED, and existing schema behavior received a demonstrated mutation failure.
- Test guard: variants are data-driven; no internal class, state/model, database, or migration is mocked; the fake clock is the deterministic clock boundary; the trace assertion uses real migrations and persistence.
- Clean-code guard: parsing and tester-token responsibilities are small and named, no malformed value enters an error message, no broad catch or swallowed failure was introduced, and no speculative dependency or abstraction was added.
- Preserved behavior: exact defaults/domains, lazy mode/provider resolution, duplicate eligibility, schema and migrations, prompt/fake event contract, disabled/keyless production, and existing untracked plan notes.
- Environmental limitation: the local MariaDB upgrade test remains gated because the local PHP CLI lacks `pdo_mysql`; the real SQLite migration/schema coverage passed. No unresolved implementation concern was found.
