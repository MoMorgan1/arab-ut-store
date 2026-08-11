# Hostinger deployment

Arab UT is deployed as one Laravel application on Hostinger PHP/MariaDB hosting. Node.js is used only in GitHub Actions to compile Vite assets; it is not required on the server.

## Release flow

1. Open a pull request and wait for both `ci` and `mariadb-schema` to pass.
2. Merge the reviewed commit to `main`.
3. The `tests` workflow runs the complete test, static-analysis, and production-build gate.
4. Only a successful `main` push produces the SHA-bound `hostinger-release-<sha>` artifact.
5. `deploy-staging` downloads that exact artifact, connects with the dedicated Hostinger deployment key, and runs `deploy/hostinger-release.sh`.
6. The script installs production Composer packages, runs forward migrations, caches Laravel configuration/routes/views, atomically switches `current`, verifies `/up`, and retains the five newest releases.

GitHub environment secrets contain the SSH identity and pinned host key. The environment variables contain only the deploy root and public health-check URL. Application and database secrets stay in Hostinger's `shared/.env`; they are never copied into GitHub artifacts or the repository.

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

Laravel then schedules exchange-rate refresh, review refresh, cart-secret cleanup, and guest-claim cleanup. Confirm it after a domain or directory change with:

```bash
cd <deploy-root>/current
php artisan schedule:list
php artisan schedule:run
```

## Catalog and reviews

- Coins pricing was initialized from the verified live Next.js production configuration so the replacement preserves the current formula.
- SBC, Objectives, FUT Champions, and Rivals remain automation-owned and arrive through the signed n8n catalog snapshot endpoint documented in `docs/api/n8n-catalog-v1.md`.
- Reviews are pulled by the scheduled `reviews:refresh` command.
- n8n credentials must be supplied through the approved secure access channel and placed only in Hostinger/n8n secret storage. Never paste them into issues, pull requests, logs, or chat.

Until the n8n handoff is configured, category pages honestly show an empty catalog and reviews show their existing empty state; the storefront never fabricates products or testimonials.

## Post-deploy verification

Verify at minimum:

```text
GET /up -> 200
GET / -> 200, Arabic/RTL
GET /en -> 200, English/LTR
```

Then exercise PS/Xbox -> Normal -> Amount and confirm the displayed price changes synchronously without a quote request or refreshing message. Check the browser console, asset responses, overflow, auth pages, cart, service routes, and footer links before promoting the domain.
