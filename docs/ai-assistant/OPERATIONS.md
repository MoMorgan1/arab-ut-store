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

## Focused repository and CI commands

Run these commands only from a repository or CI checkout with the PHP and Node
development dependencies installed:

```powershell
php artisan route:list --path=chat
php artisan test tests/Feature/Chat
npm test -- resources/js/__tests__/chat
npm run test:e2e
```

These are not deployed-release commands. The packaged release excludes
`tests/`, `node_modules/`, and `vendor/`; deployment then installs Composer
production dependencies with `--no-dev` and does not install Node dependencies.

In CI, `npm run test:e2e` always launches the configured Laravel server on port
8010 with `CHAT_ENABLED=true` and `CHAT_DEMO_ASSISTANT=true`, then runs Chromium
only. Outside CI, Playwright may reuse an existing server on port 8010. When it
does, Playwright does not launch the configured process or apply those two flag
values; the existing server's configuration governs the smoke. The command must
not target production.

## Deployed-release health checks

Use read-only HTTP checks against the deployed release. At minimum, verify
`GET /up`, `GET /`, and `GET /en` return successful responses, and confirm the
storefront pages use the expected Arabic RTL and English LTR direction. The
deployment script itself uses `/up` for its activation health gate.

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
