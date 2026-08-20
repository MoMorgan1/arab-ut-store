# Operations

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Verified release

The current deployed SHA is `e7f230d2ea01dc456aef1a51035f4d88f39542e2`.
[tests 32410960971](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32410960971)
and [deploy 32411415481](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32411415481)
passed for it. Production read-only verification returned `/up -> 200`, found
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
at 30 days and closed authenticated rows at 180 days. Conversation deletion
cascades its messages. The per-row recheck protects a guest row claimed during
maintenance selection.

Hostinger runs Laravel scheduling every minute. Investigate only from the active
release:

```bash
php artisan schedule:list
php artisan chat:maintain-conversations
```

## Safe disable and recovery

To contain a chat incident, use approved secure server access to set
`CHAT_ENABLED=false` in the shared environment, run `php artisan config:cache`,
then verify the launcher is absent and a route returns no-store `chat_disabled` 404. This does not delete chat data. Do not print environment files, session
records, or secrets.

Deploys are forward-only. `deploy/hostinger-release.sh` migrates before atomically
switching `current`, checks `/up`, and restores the previous release path when
health fails. Do not run `migrate:rollback`; follow [the Hostinger rollback
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
