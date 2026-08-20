# Operations

**Lifecycle:** Local implementation verified; release operations pending

**Verified:** 2026-08-21

## Configuration

The repository defaults all chat flags to disabled. The lifecycle defaults are documented in [ARCHITECTURE.md](ARCHITECTURE.md): 24-hour auto-close, seven-day inactivity reopen, 30-day guest retention, and 180-day authenticated retention. Production flag values are not known from local evidence.

Do not change `SESSION_DRIVER` or `SESSION_ENCRYPT` as part of this release. The production values must first be inspected through the separately approved secure path. Enabling session encryption can invalidate active customer sessions.

## Scheduled maintenance

The release contains `php artisan chat:maintain-conversations`, scheduled hourly with `withoutOverlapping()`. It closes inactive open conversations and purges expired closed guest/authenticated history according to the four chat configuration keys. It reports counts only; it does not print message content or owner secrets.

After a deployment, an authorized operator must confirm that the existing Hostinger per-minute `schedule:run` cron reaches the new release and that `php artisan schedule:list` shows the hourly maintenance command. `schedule:list` proves that hourly registration only; `routes/console.php` and `tests/Feature/Console/MaintainChatConversationsTest.php` establish `withoutOverlapping()`. This has not been checked in production.

## Local verification commands

Run from a development checkout with dependencies installed:

```powershell
php artisan route:list --path=chat
php artisan schedule:list
php artisan test tests/Feature/Chat
php artisan test tests/Feature/Console/MaintainChatConversationsTest.php
php artisan test tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php
php artisan test tests/Integration/ChatConversationConcurrencyTest.php
npm test -- resources/js/__tests__/chat
npm run test:e2e
```

`composer ci:check` is the complete local PHP/frontend gate. On 2026-08-21, `php C:\temp\arabut-composer\composer.phar ci:check` exited 0: Composer validation, configuration-cache clear, Pint, PHPStan (0 errors), Pest (856 tests, 33,171 assertions), Vitest (52 files, 369 tests), ESLint, Prettier, TypeScript, E2E TypeScript, and the Vite production build completed. The build emitted only existing runtime-resolved asset/font notices. `npm run test:e2e` also exited 0 with 7 Chromium tests passed in 44.2 seconds; the authenticated account test took 28.9 seconds.

The GitHub `mariadb-schema` job separately runs a fresh/rollback/migrate lifecycle and the chat invariant/concurrency integration tests against MariaDB. That CI result is pending for this handoff.

## Deployment and rollback boundary

The deployment workflow packages only a successful `main` SHA, then `deploy/hostinger-release.sh` installs production dependencies, checks whether migrations are pending, runs the forward migration command, caches Laravel configuration/routes/views, atomically switches `current`, and health-checks `/up`.

If `/up` fails and `current` previously resolved to an existing release directory, rollback depends on what this release applied:

- When the pending-migration check found nothing to apply, the script restores the previous `current` and `public_html` symlinks and exits nonzero.
- When the release applied a migration batch, the script first runs `php artisan migrate:rollback --force` from the failed release. It restores the previous symlinks only after that command succeeds. This can remove migration-only metadata written during the failed release's short activation window.
- If that schema rollback fails, the script refuses to reactivate older code against the newer schema. It exits nonzero and leaves the failed release active for operator recovery.

On a first deployment or when no valid prior release exists, the script performs neither automatic schema rollback nor symlink rollback; it exits nonzero and leaves the failed release as `current`. Manual application rollback remains forward-only and must use schema-compatible code; the targeted migration rollback above is limited to automatic recovery of the batch just applied by a release that then failed health.

No push, deploy, production health check, or production route check was run for this handoff. Once an authorized deployment is complete, verify `/up`, `/`, `/en`, `/login`, `/en/login`, `/cart`, and the authenticated account route without creating a production synthetic account. Then complete Mohamed's manual acceptance gate in [UX.md](UX.md).

The broader server procedure is in the [Hostinger deployment runbook](../operations/hostinger-deployment.md).
