# Operations

**Lifecycle:** Runtime deployment, failed public evaluation, and safe AI
containment verified
**Verified:** 2026-08-22

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

The current application release is
`e13ee8bde25263a262788177d0ce78fb4f46f37f`.
[Tests 32578736891](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32578736891)
and [deploy 32578995534](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32578995534)
passed for that SHA. Production read-only checks verified `/up`, all seven chat
routes, and the stale-turn schedule. Phase 2 runtime code is present, but its
public Luna evaluation failed and production is contained in Phase 1 demo mode.

## Maintenance

`chat:maintain-conversations` is scheduled hourly with `withoutOverlapping()` in
`routes/console.php`. It closes open conversations whose nonnull
`last_message_at` is at or before `chat.auto_close_hours` (24 by default), then
deletes closed guest rows at 30 days and closed authenticated rows at 180 days
of last activity. Reopen and retention calculations use `last_message_at`, with
legacy nulls falling back to `closed_at`, then `updated_at`; auto-close currently
does not use that fallback. Conversation deletion cascades its messages. The
per-row recheck protects a guest row claimed during maintenance selection.

`agent:recover-stale-turns` is scheduled every minute with
`withoutOverlapping()`. It terminalizes `waiting`/`running` turns whose
`updated_at` is at least 60 seconds old, after a locked recheck, using the safe
`stale_turn_recovered` code. Configuration requires that threshold to exceed
the 30-second request deadline by at least 15 seconds. A read-only production
`schedule:list` verified the event on the current release.

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
`chat:maintain-conversations` hourly. The scheduler gate is closed. Mohamed's
separate owner-device acceptance is recorded in [STATUS.md](STATUS.md).

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

Phase 2 schema checks also verify `chat_messages.agent_eligible_at`,
`agent_prompt_blocked_at`, `idx_chat_messages_agent_claim`, `agent_turns`,
`agent_runs`, the generated/triggered one-active-turn invariant, and
millisecond `debounce_until`. A partial Phase 2 installation follows the same
forward-repair rule: do not edit an applied migration or use production
rollback.

## Safe disable and recovery

To contain a chat incident, use approved secure server access to set
`CHAT_ENABLED=false` in the shared environment, run `php artisan config:cache`,
then verify the launcher is absent and a route returns no-store `chat_disabled` 404. This does not delete chat data. Do not print environment files, session
records, or secrets.

For an AI-only incident, keep chat and the demo available while setting:

```text
AI_ASSISTANT_ENABLED=false
AI_ASSISTANT_ROLLOUT=disabled
AI_MODEL_PROVIDER=
```

Run `php artisan config:cache`, then verify only these nonsecret resolved values.
Confirm a public message receives the deterministic demo and creates no
`agent_turns`. This exact containment was completed after the 2026-08-22 failed
evaluation. It does not cancel a provider request that already entered the
controller, rewrite previously eligible rows, remove the source credential, or
delete chat data.

Laravel configuration caching resolves the shared environment into the active
release cache. Treat retained release caches as secret-bearing; never print,
archive, or attach them as evidence.

Database migrations are forward-only. `deploy/hostinger-release.sh` migrates
before atomically switching `current` and checks `/up`. On health failure, it
restores code only when a valid prior release directory exists. Do not run
`migrate:rollback`; follow [the Hostinger rollback
runbook](../operations/hostinger-rollback.md).

## Local and CI verification

```powershell
php artisan route:list --path=chat
php artisan schedule:list
php artisan agent:inspect-streaming-http
php artisan test tests/Feature/AI tests/Integration/AI
composer ci:check
npx playwright test tests/Browser/storefront-smoke.spec.ts tests/Browser/agent-stream.spec.ts --project=chromium
```

`composer ci:check` includes PHP validation, formatting, static analysis, tests,
and frontend CI checks. CI uses public rollout with the fake provider and no
OpenAI key; it does not prove Luna quality. Playwright starts the configured
local Laravel server and must not be repointed to production.

## Luna re-entry

The direct-public owner decision remains in force, but acceptance and continued
enablement require every mandatory threshold in [EVALS.md](EVALS.md). After an
approved remediation deploys with AI disabled:

1. correct the inspection gap, then verify release SHA, health, seven routes,
   schedule, the adapter's actual deployed handler, wrappers, and timeout values;
2. enable direct-public Luna only through approved secure access and recache
   configuration without displaying values;
3. require a live incremental canary and run resilience probes outside the eval
   interval;
4. run one fresh ordered 16-case batch at validated rate limits;
5. query only content-free aggregates for its exact half-open UTC interval;
6. disable again on any mandatory miss.

The prior failed batch is recorded in
[the evaluation evidence](evidence/2026-08-22-phase-2-luna-public-eval.md).
