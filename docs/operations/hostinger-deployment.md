# Hostinger deployment

Arab UT is deployed as one Laravel application on Hostinger PHP/MariaDB hosting.
Node.js is used in GitHub Actions to build Vite assets, not on the server.

## Verified chat release

The deployed candidate is `e7f230d2ea01dc456aef1a51035f4d88f39542e2`.
[tests 32410960971](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32410960971)
passed CI, MariaDB, Chromium, and package gates; [deploy
32411415481](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32411415481)
passed for the same SHA. Read-only production checks verified `/up -> 200`,
the current release path, four chat routes, `CHAT_SCHEMA_OK`,
`ACTIVE_OWNER_DUPLICATE_GROUPS=0`, and `LOCK_TABLES_OK`.

## Release flow

1. A push to `main` runs the `tests` workflow.
2. `composer ci:check`, MariaDB migration lifecycle, Playwright Chromium,
   deployment-script validation, and SHA-bound package creation must pass.
3. `deploy-production` downloads the artifact for that exact workflow SHA.
4. `deploy/hostinger-release.sh` installs production Composer dependencies,
   migrates forward, caches configuration/routes/views, atomically switches
   `current`, and checks `/up`.
5. On health failure it restores the previous release symlink and retains the
   five newest release directories.

Application/database secrets remain only in `shared/.env`; do not copy them to
logs, artifacts, commits, or chat.

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

## Scheduler and chat maintenance

Hostinger Cron Jobs invokes `artisan schedule:run` every minute. Laravel
schedules `chat:maintain-conversations` hourly with overlap prevention. It
closes stale open conversations after the configured 24-hour default and purges
closed guest/authenticated conversations at the configured 30/180-day defaults.

Verify scheduling from the active release after a domain or directory change:

```bash
php artisan schedule:list
php artisan schedule:run
```

## Rollback and post-deploy checks

Do not use `migrate:rollback` as an application rollback. Database migrations
are forward-only; follow [hostinger-rollback.md](hostinger-rollback.md) and use
a retained compatible release.

After deployment, verify `GET /up`, `GET /`, `GET /en`, `GET /login`,
`GET /en/login`, and `GET /cart` read-only. Use the synthetic/local browser
only for its fixture; do not create production synthetic users. Mohamed
separately accepts authenticated account and iPhone/Safari behavior.
