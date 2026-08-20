# Final fix report — Chat Phase 1 Completion

**Date:** 2026-08-21

**Base:** `46c83c2`

**Implementation commit:** `5da9cf7` (`fix(chat): close final phase one races`)

**Scope:** The binding final-fix wave only. No UI, lifecycle vocabulary,
retention, Phase 2, production configuration, push, SSH, or deployment change
outside the approved fixes.

## Result

The message-write TOCTOU and failed-health schema/code rollback findings are
fixed locally. The Chat action now locks and reloads the conversation, then
revalidates owner and `open` status in the same transaction as canonical
idempotency lookup, customer insertion, `last_message_at`, and optional demo
reply. Stale requests create no row and preserve the existing safe 404/409
contracts.

The release script now detects pending migrations before migration. After a
failed health check, it rolls back the batch introduced by that release before
restoring valid prior code. A failed schema rollback stops the code rollback
and leaves the new release active for operator recovery.

## Strict TDD evidence

### Chat send revocation RED

Command:

```powershell
php artisan test tests/Feature/Chat/ChatMessageTest.php --filter="loses to"
```

Observed before production changes: 2 failed. The send that lost to restart
returned `201` instead of `409`; the guest send that lost to login claim
returned `201` instead of `404`. Both failures proved the stale model could
still write.

### Chat send revocation GREEN

The same command passed 2 tests / 10 assertions after the fix. The broader
message/cache/concurrency command passed 26 tests: 21 passed, five MariaDB-only
tests skipped under SQLite, and 168 assertions completed. The new MariaDB
workers deterministically load a stale conversation, pause, allow the real
restart or guest-claim action to win, then prove the rejected send wrote no
message.

Canonical duplicate recovery remains inside the same locked revalidation
boundary. Only the named client-ID unique violation is recoverable; unrelated
`QueryException` instances still propagate.

### Deployment rollback RED

Command:

```powershell
php artisan test tests/Unit/Deployment/HostingerDeploymentContractTest.php --filter="restores prior code|rolls back the newly|refuses to restore"
```

Observed before the script change: all 3 tests failed. The executed command log
contained neither pending-migration detection nor schema rollback, and the
rollback-failure case still restored prior code.

### Deployment rollback GREEN

The same three behavioral tests passed with 19 assertions. The complete
deployment contract file passed 9 tests / 58 assertions. These tests execute
the real `deploy/hostinger-release.sh` with isolated filesystem state and fake
OS command boundaries; they assert command exit status, side effects, and
ordering rather than grepping for the implementation.

## Rollback reasoning and branches

Laravel 13.24.0's installed `migrate:status` command returns the value supplied
to `--pending` when pending migrations exist. The release script uses
`--pending=10`; this exact exit contract was verified against an isolated real
SQLite migration repository. Exit `0` means no pending migration, exit `10`
means this release will apply a migration batch, and any other status aborts
before migration.

Laravel's rollback command reverses the latest migration operation/batch. The
script is serialized by the production deployment workflow, so migrations
applied by the one release command form the latest batch recovered after that
release fails health.

The implemented branches are:

1. No pending migration + valid prior release + failed health: restore the
   prior `current` and `public_html` symlinks; do not run schema rollback.
2. Pending migrations applied + valid prior release + failed health: run
   `php artisan migrate:rollback --force`; only after success restore prior
   code.
3. Schema rollback failure: exit nonzero, do not restore old code, and leave
   the new release active for operator recovery.
4. No valid prior release: exit nonzero with no automatic schema or code
   rollback, preserving the prior first-deployment/missing-prior behavior.

This ordering avoids running old application code against the new lifecycle
schema. A successful automatic schema rollback can discard migration-only
metadata written during the failed release's short activation window; the
canonical runbooks now state that trade-off.

Installed source and official API contracts checked:

- `vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php` —
  `lockForUpdate()` selects through the write connection.
- `vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/StatusCommand.php`
  — `--pending` exit behavior.
- `vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/RollbackCommand.php`
  and `Illuminate/Database/Migrations/Migrator.php` — latest-batch rollback.
- [Laravel 13 Query Builder API](https://api.laravel.com/docs/13.x/Illuminate/Database/Query/Builder.html)
  and [Laravel 13 Migrator API](https://api.laravel.com/docs/13.x/Illuminate/Database/Migrations/Migrator.html).

## Files

### Chat behavior and regressions

- `app/Actions/Chat/CreateChatMessage.php`
- `app/Exceptions/Chat/ChatConversationWriteRejected.php`
- `app/Http/Controllers/Chat/ChatMessageController.php`
- `tests/Feature/Chat/ChatMessageTest.php`
- `tests/Integration/ChatConversationConcurrencyTest.php`
- `tests/Support/ConcurrentChatMessage.php`
- `tests/Support/ConcurrentChatStaleMessage.php`

### Deployment behavior and regressions

- `deploy/hostinger-release.sh`
- `tests/Unit/Deployment/HostingerDeploymentContractTest.php`

### Documentation

- `docs/ai-assistant/ARCHITECTURE.md`
- `docs/ai-assistant/OPERATIONS.md`
- `docs/ai-assistant/STATUS.md`
- `docs/operations/hostinger-deployment.md`
- `docs/operations/hostinger-rollback.md`
- `.superpowers/sdd/2026-08-20-ai-assistant-phase-1-completion/task-7-report.md`

`STATUS.md` now records 2026-08-21 as the latest local verification date. The
Task 7 E1 recorded label now exactly matches its literal script output:
`STATUS evidence-boundary scan: PASS`.

## Verification

| Gate                                    | Result                                                                                                                                                                                                      |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Focused Chat/lifecycle/deployment suite | Passed: 78 tests; 72 passed, six expected MariaDB-only skips under SQLite; 467 assertions.                                                                                                                  |
| Isolated migration lifecycle            | Passed: `migrate:fresh --force`, `migrate:rollback --force`, then `migrate --force`.                                                                                                                        |
| Laravel pending-status exit contract    | Passed: a real pending repository returned exit `10` for `migrate:status --pending=10 --no-ansi`.                                                                                                           |
| Shell syntax                            | Passed: Git Bash `-n deploy/hostinger-release.sh`.                                                                                                                                                          |
| Pint                                    | Passed: `vendor/bin/pint --parallel --test`.                                                                                                                                                                |
| PHPStan                                 | Passed: `vendor/bin/phpstan analyse --memory-limit=1G`, zero errors.                                                                                                                                        |
| Exact Composer CI                       | Passed: `php C:\temp\arabut-composer\composer.phar ci:check`, exit 0; Pest 856 tests / 33,171 assertions; Vitest 52 files / 369 tests; ESLint, Prettier, TypeScript, E2E TypeScript, and Vite build passed. |
| Full Chromium                           | Passed after the sandbox-blocked first launch was rerun with browser permission: 7 tests in 44.2 seconds; authenticated account case 28.9 seconds.                                                          |
| Docs Guard                              | Passed: changed claims checked against source/CLI, changed relative links resolved, Prettier passed, and `git diff --check` passed.                                                                         |

The Vite build emitted only the existing runtime-resolved image/font notices.

## Self-review

- Clean Code Guard: no broad exception swallowing, speculative abstraction, or
  dead fallback. The action catches only the existing named unique-contention
  condition; the controller catches only the typed stale-write exception.
- Test Guard: regressions assert customer-visible status and persisted rows;
  process tests use real actions/database rows; deployment tests fake only OS
  command boundaries and inspect real script behavior.
- Docs Guard: route/action/command/path/flag claims were verified in this
  session; no deployment or production execution is implied.
- Mutation check: removing the locked reload makes both stale-send tests fail;
  skipping schema rollback breaks ordering; restoring code after rollback
  failure breaks the refusal branch.
- No frontend or approved product behavior changed.

## Remaining concerns and external gates

- The true-process Chat race tests require MariaDB/MySQL row locking. They are
  present in the existing `mariadb-schema` CI job but were skipped locally
  because no approved local MariaDB test service was available. The configured
  non-SQLite database was not accessed.
- GitHub CI, production session settings, push/deployment, production health,
  iPhone/Safari validation, and Mohamed's acceptance remain pending. No SSH,
  push, deploy, production account, or production session inspection occurred.
- The report itself is committed in a follow-up documentation commit; its hash
  is returned in the final handoff because a commit cannot contain its own
  stable hash.

## Post-review evidence correction

The Task 7 E1 Operations matcher was updated after final scoped review so it
asserts the migration-aware failed-health branches documented by the final fix:
direct prior-code restore with no migration batch, schema rollback before old
code when a batch was applied, refusal to restore old code when schema rollback
fails, and the no-valid-prior-release branch. The literal E1 script was rerun
successfully before publication.
