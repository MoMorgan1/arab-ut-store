# Operations

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Production flags

| Flag                  | Production state | Effect                                                                                                           |
| --------------------- | ---------------- | ---------------------------------------------------------------------------------------------------------------- |
| `CHAT_ENABLED`        | `true`           | Shares `chat.enabled=true`, renders the launcher, and permits chat routes.                                       |
| `CHAT_DEMO_ASSISTANT` | `true`           | Shares `chat.demoAssistant=true` and stores the deterministic demo reply for each newly stored customer message. |

These states were observed through read-only production HTML after application
release `fdba471af2fef38905581a309bf8b0e9119ab41b`; no secret value is recorded.
Both flags default to `false` in `config/chat.php` and `.env.example`.

## Safe disable

1. Through the approved secure server-access path, set `CHAT_ENABLED=false` in
   the existing shared production environment. Set `CHAT_DEMO_ASSISTANT=false`
   as well when disabling the demo behavior. Do not copy the environment file or
   any secret into chat, logs, commits, or workflow output.
2. From the active release directory, run `php artisan config:cache` so the
   cached configuration uses the changed flags.
3. Verify the storefront HTML exposes `chat.enabled=false`, the launcher is
   absent, and `POST /chat/conversations` returns a no-store 404 without creating
   chat state.
4. Record the operational change and the evidence. Re-enable only after the
   incident owner approves it and the same checks pass with the intended state.

Disabling chat does not delete conversations or messages.

## Focused health commands

Run from a configured development or release checkout:

```powershell
php artisan route:list --path=chat
php artisan test tests/Feature/Chat
npm test -- resources/js/__tests__/chat
npm run test:e2e
```

`npm run test:e2e` starts the configured local Laravel server, forces both chat
flags on for that disposable process, and runs Chromium only. It must not target
production. The minimum read-only production health checks are `GET /up`,
`GET /`, and `GET /en`, each returning successful responses with the expected
locale direction.

## CI and deployment dependency

The `tests` workflow runs `composer ci:check`, installs Playwright Chromium, and
runs `npm run test:e2e` before validating deployment scripts and packaging a
SHA-bound release artifact. On smoke failure, its GitHub Actions run exposes the
log and uploads `playwright-report-<sha>` from `playwright-report/` for seven
days.

`deploy-production` runs only after a successful `tests` workflow for a push to
`main`. It downloads the artifact for that exact SHA and invokes
`deploy/hostinger-release.sh`, which migrates forward, caches configuration,
routes, and views, atomically switches the active release, checks `/up`, and
restores the prior release automatically if health fails.

Verified release evidence is recorded in [STATUS.md](STATUS.md). General
deployment details live in the [Hostinger deployment
runbook](../operations/hostinger-deployment.md).

## Incident and rollback routing

Record assistant incidents in [INCIDENTS.md](INCIDENTS.md), preserve the active
and last known-good application SHAs, and disable chat first when that safely
contains the issue. For an application rollback, follow the [Hostinger rollback
runbook](../operations/hostinger-rollback.md). Do not run `migrate:rollback` as
part of an application rollback.
