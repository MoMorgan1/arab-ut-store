# Hostinger deployment

Arab UT is deployed as one Laravel application on Hostinger PHP/MariaDB hosting. Node.js is used only in GitHub Actions to compile Vite assets; it is not required on the server.

## Release flow

1. Open a pull request and wait for both `ci` and `mariadb-schema` to pass.
2. Merge the reviewed commit to `main`.
3. The `tests` workflow runs the complete test, static-analysis, and production-build gate.
4. Only a successful `main` push produces the SHA-bound `hostinger-release-<sha>` artifact.
5. `deploy-production` downloads that exact artifact, connects with the dedicated Hostinger deployment key, and runs `deploy/hostinger-release.sh`.
6. The script installs production Composer packages, runs forward migrations, caches Laravel configuration/routes/views, atomically switches `current`, verifies `/up`, and retains the five newest releases.

GitHub environment secrets contain the SSH identity and pinned host key. The environment variables contain only the deploy root and public health-check URL. Application and database secrets stay in Hostinger's `shared/.env`; they are never copied into GitHub artifacts or the repository.

The production environment points at `https://store.arab-ut.com` and deploys under the matching Hostinger domain directory. After changing a Hostinger domain, update both `HOSTINGER_DEPLOY_ROOT` and `PRODUCTION_URL` before the next release.

## Server layout

```text
<deploy-root>/
  current -> releases/<commit-sha>
  public_html -> current/public
  releases/
  shared/
    .env
    storage/
  incoming/
```

The deployment script creates `public/storage` with a direct shell symlink because Hostinger disables the PHP `exec()` fallback used by `php artisan storage:link`.

## Scheduler

Hostinger Cron Jobs runs this PHP command every minute:

```text
/usr/bin/php /home/<account>/domains/<website>/current/artisan schedule:run
```

Laravel then schedules exchange-rate refresh, paid-order event publication,
guest-claim cleanup, and the hourly
`chat:maintain-conversations` lifecycle/retention job. Confirm that the command
is registered after a domain or directory change with:

```bash
cd <deploy-root>/current
php artisan schedule:list
php artisan schedule:run
```

`php artisan schedule:list` establishes hourly registration, not overlap
locking. The `withoutOverlapping()` contract is defined in `routes/console.php`
and asserted by `tests/Feature/Console/MaintainChatConversationsTest.php`.

## Catalog and reviews

- Coins pricing is updated through the signed production pricing workflow and remains server-authoritative at quote and cart time.
- SBC products use the dedicated signed `/api/automation/v1/catalog/sbc/snapshots` boundary and isolated `n8n-sbc` source documented in `docs/api/n8n-catalog-v1.md`. Other automated services use the generic catalog boundary only when their own workflow is approved.
- The production SBC snapshot was verified on 2026-08-15 with 29 products and 58 variants. A complete accepted SBC snapshot can reconcile only `n8n-sbc` rows.
- Historical Salla reviews are imported once with the production workflow documented in `docs/operations/storefront-runbook.md`. Customer pages read only the local database and render the approved public four- and five-star subset.
- n8n credentials must be supplied through the approved secure access channel and placed only in Hostinger/n8n secret storage. Never paste them into issues, pull requests, logs, or chat.

The Hostinger maintenance workflow never changes n8n. Workflow activation, deletion, credentials, and static data remain separate operator-controlled concerns.

## Bounded maintenance

Run the manual `hostinger-maintenance` GitHub workflow with `mode=audit` first. It reports the active release, retained release count, stale incoming archives, temporary deployment links, pre-Laravel public-directory backups, old logs, and storage size without changing the server.

Use `mode=apply` only after the audit output is reviewed. The script verifies `/up`, preserves `current`, `shared/.env`, all shared application data, and the five retained Laravel releases, then removes only:

- release archives in `incoming/` older than one day;
- abandoned root-level `.current-*` and `.rollback-*` symlinks;
- obsolete `public_html.before-laravel-*` directories from the retired Next.js cutover;
- application log files older than 30 days.

It refreshes the compiled Blade view cache and prints a second inventory. It does not touch MariaDB, customer uploads, carts, orders, credentials, n8n, or GitHub history.

## Post-deploy verification

Verify at minimum:

```text
GET /up -> 200
GET / -> 200, Arabic/RTL
GET /en -> 200, English/LTR
```

Then exercise PS/Xbox -> Normal -> Amount and confirm the displayed price changes synchronously without a quote request or refreshing message. Check the browser console, asset responses, overflow, auth pages, cart, service routes, and footer links before completing the release.

## Chat Phase 1 Completion release checklist

This checklist applies when the Phase 1 Completion changes are authorized for release. It is intentionally not evidence that those steps have already run.

1. Wait for the release SHA's `ci` and `mariadb-schema` workflow jobs. The MariaDB job must complete the fresh, rollback, migrate lifecycle and chat lifecycle/concurrency integration tests.
2. Deploy the SHA-bound artifact through the normal workflow. Do not run a schema rollback as part of application rollback; the release process uses forward migrations. If `/up` fails, the script restores a prior symlink only when a valid prior release exists. On a first deployment or missing/invalid prior release, it exits nonzero without automatic rollback and leaves the failed release as `current` for operator recovery.
3. Confirm `php artisan schedule:list` includes the hourly `chat:maintain-conversations` registration. Verify overlap prevention from `routes/console.php` and its focused feature test, not from `schedule:list`.
4. Read-only check `/`, `/en`, `/login`, `/en/login`, `/cart`, and the authenticated account route. Do not create a production synthetic account.
5. Inspect production `SESSION_DRIVER` and `SESSION_ENCRYPT` only through the approved secure read-only path. Never copy `.env`, session records, or secrets into logs, commits, or chat. Treat an encryption change as a separate approval because active sessions may be invalidated.
6. Hand the deployed release to Mohamed for the Arabic/English, iPhone safe area, navigation/lifecycle, focus, touch-target, and browser-console manual acceptance gate.
