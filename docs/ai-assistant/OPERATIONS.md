# Operations

**Lifecycle:** Application operations implemented; external scheduler evidence
pending
**Verified:** 2026-08-21

## Verified release

The verified Phase 1 application SHA observed/deployed on 2026-08-20 was
`e7f230d2ea01dc456aef1a51035f4d88f39542e2`.
[tests 32410960971](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32410960971)
and [deploy 32411415481](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32411415481)
passed for that SHA. Production read-only verification returned `/up -> 200`, found
all four chat routes, and reported `CHAT_SCHEMA_OK`,
`ACTIVE_OWNER_DUPLICATE_GROUPS=0`, and `LOCK_TABLES_OK`.

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

Recurring Hostinger execution is an open external gate. The SSH account has no
`crontab` command, and this task has no hPanel/browser/API credentials. An
authorized operator must open **Websites → Dashboard → Cron Jobs**, add this
custom command, choose the manual `* * * * *` schedule in UTC, and verify its
execution/output in hPanel:

```text
/usr/bin/php /home/u372356793/domains/store.arab-ut.com/current/artisan schedule:run
```

Then, from the active release, run and read:

```bash
php artisan schedule:list
```

The hourly Laravel event is source/test verified; it is not evidence that
Hostinger invokes `schedule:run` every minute. Phase 1 owner acceptance remains
blocked until recurring execution evidence exists.

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
