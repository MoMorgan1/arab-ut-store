# Operations

**Lifecycle:** Application operations and external scheduler evidence verified
**Verified:** 2026-08-21

## Verified release

The verified final Phase 1 application SHA deployed on 2026-08-21 is
`d77385a44e7ac1413aab419f79d38fc2040be650`.
[tests 32429880313](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32429880313)
and [deploy 32430144972](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32430144972)
passed for that SHA. Production read-only verification returned 200 for the
health and five public routes, found all four chat routes, and listed the
hourly Laravel maintenance event.

The GitHub tests workflow is authoritative for MariaDB lifecycle and release
packaging. Its earlier backend checkpoint was [tests
32398600493](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32398600493)
at `1fd83d37b990833cd451d7c3a7b48314976a9f6f`.

## Maintenance

`chat:maintain-conversations` is scheduled hourly with `withoutOverlapping()` in
`routes/console.php`. It closes open conversations with `last_message_at` at or
before `chat.auto_close_hours` (24 by default), then deletes closed guest rows
at 30 days and closed authenticated rows at 180 days of last activity.
`last_message_at` is authoritative; legacy nulls fall back to `closed_at`, then
`updated_at`. Conversation deletion cascades its messages. The per-row recheck
protects a guest row claimed during maintenance selection.

Owner-provided hPanel evidence on 2026-08-21 shows this exact custom command at
the manual `* * * * *` schedule in UTC:

```text
/usr/bin/php /home/u372356793/domains/store.arab-ut.com/current/artisan schedule:run
```

The hPanel output recorded `orders:publish-paid-events` as `DONE` at
`2026-08-21 10:14:01`, proving recurring `schedule:run` execution. A subsequent
read-only check from the active release ran:

```bash
php artisan schedule:list
```

The output listed `orders:publish-paid-events` every minute and
`chat:maintain-conversations` hourly. The scheduler gate is closed; owner device
acceptance remains separate.

## Partial lifecycle migration detection

The deployed migration
`2026_08_20_000002_add_chat_conversation_lifecycle.php` is immutable. After a
failed migration, compare `php artisan migrate:status` with MariaDB's `SHOW
CREATE TABLE chat_conversations` and `SHOW CREATE TABLE chat_messages`. The
conversation table must contain the lifecycle columns, generated
`active_owner_key`, and named unique index; the message table must contain its
nullable reply link, foreign key, and unique index. If the migration ledger and
schema disagree, stop deployment: do not edit/rerun the deployed migration or
use `migrate:rollback`. Prepare a reviewed compensating forward migration or a
reviewed manual repair, then rerun the invariant/schema checks. Production and
CI applied this migration successfully; this procedure is for detecting a
future partial installation.

## Safe disable and recovery

To contain a chat incident, use approved secure server access to set
`CHAT_ENABLED=false` in the shared environment, run `php artisan config:cache`,
then verify the launcher is absent and a route returns no-store `chat_disabled` 404. This does not delete chat data. Do not print environment files, session
records, or secrets.

Database migrations are forward-only. `deploy/hostinger-release.sh` migrates
before atomically switching `current` and checks `/up`. On health failure, it
restores code only when a valid prior release directory exists. Do not run
`migrate:rollback`; follow [the Hostinger rollback
runbook](../operations/hostinger-rollback.md).

## Local and CI verification

```powershell
php artisan route:list --path=chat
composer ci:check
npm run test:e2e
```

`composer ci:check` includes PHP validation, formatting, static analysis, tests,
and frontend CI checks. `npm run test:e2e` is Playwright Chromium. In CI it
starts the configured Laravel server with chat flags enabled; outside CI it can
reuse port 8010 and must never target production.
