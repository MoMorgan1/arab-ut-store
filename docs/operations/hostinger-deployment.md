# Hostinger deployment

Arab UT is deployed as one Laravel application on Hostinger PHP/MariaDB
hosting. Node.js builds Vite assets in GitHub Actions; it is not required on
the server.

## Verified chat release

The verified final Phase 1 application SHA deployed on 2026-08-21 is
`d77385a44e7ac1413aab419f79d38fc2040be650`.
[tests 32429880313](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32429880313)
passed CI, MariaDB, seven Chromium checks, and package gates; [deploy
32430144972](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32430144972)
passed for that SHA. Read-only production checks verified the active release,
four chat routes, the hourly Laravel maintenance event, and 200 responses for
`/up`, `/`, `/en`, `/login`, `/en/login`, and `/cart`.

Recurring scheduler execution was verified separately from owner-provided
hPanel evidence, as recorded below.

## Release flow

1. A push to `main` runs the `tests` workflow.
2. `composer ci:check`, the MariaDB migration/integration job, Playwright
   Chromium, deployment-script validation, and SHA-bound packaging must pass.
3. `deploy-production` downloads the artifact for that exact workflow SHA.
4. `deploy/hostinger-release.sh` installs production Composer dependencies,
   migrates forward, caches configuration/routes/views, refreshes display
   exchange rates, atomically switches `current`, and checks `/up`.
5. On failed health, it restores `current` and `public_html` only when the
   remembered prior release resolves to a directory. On success it removes the
   uploaded archive and retains the five newest release directories.

GitHub production secrets contain the SSH identity and pinned host key.
Application/database secrets remain only in `shared/.env`; do not copy them to
logs, artifacts, commits, or chat. After a Hostinger domain/path change, update
the deployment root and production URL variables before another release.

## Server layout and shared storage

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

Every release links `.env` to `shared/.env` and `storage` to `shared/storage`.
The release script removes any packaged `public/storage` path and creates the
direct shell symlink
`public/storage -> shared/storage/app/public`; it does not depend on
`php artisan storage:link`.

## Scheduler and chat maintenance

Laravel schedules `chat:maintain-conversations` hourly with
`withoutOverlapping()` in `routes/console.php`. The command closes at 24 hours
and purges closed guest/authenticated history at 30/180 days of last activity.
`last_message_at` is authoritative; legacy nulls fall back to `closed_at`, then
`updated_at`.

Owner-provided hPanel evidence on 2026-08-21 verifies that an authorized
operator completed these steps:

1. Open **Websites → Dashboard → Cron Jobs** in hPanel.
2. Add this custom command:

    ```text
    /usr/bin/php /home/u372356793/domains/store.arab-ut.com/current/artisan schedule:run
    ```

3. Select the manual `* * * * *` schedule in UTC.
4. Verified recurring output in hPanel: `orders:publish-paid-events` completed
   at `2026-08-21 10:14:01`.
5. A subsequent read-only `php artisan schedule:list` from active release
   `d77385a44e7ac1413aab419f79d38fc2040be650` listed the minute publisher and
   hourly `chat:maintain-conversations` event.

This closes the recurring scheduler gate. Mohamed's separate owner-device
acceptance is recorded in
[`docs/ai-assistant/STATUS.md`](../ai-assistant/STATUS.md).

## Partial lifecycle migration detection

Do not edit deployed migration
`2026_08_20_000002_add_chat_conversation_lifecycle.php`. If a deployment fails
during migration, compare `php artisan migrate:status` with MariaDB's `SHOW
CREATE TABLE chat_conversations` and `SHOW CREATE TABLE chat_messages` through
an approved read-only database path. Verify the migration ledger agrees with
the lifecycle columns, generated `active_owner_key`, active-owner unique index,
and nullable unique reply relationship.

If the ledger and schema disagree, stop deployment. Do not rerun the deployed
migration blindly or use `migrate:rollback`; prepare a reviewed compensating
forward migration or reviewed manual repair, then repeat schema, duplicate-key,
and route checks. Production and CI already applied the migration successfully;
this is a future partial-install recovery procedure.

## Catalog, automation, and reviews

- Coins pricing remains server-authoritative at quote/cart time; automation
  boundaries are documented under `docs/api/`.
- SBC publishing uses the signed
  `POST /api/automation/v1/catalog/sbc/snapshots` boundary and reconciles only
  source `n8n-sbc`; its versioned export and tests live in
  `automation/n8n/sbc-catalog-v1/`.
- Historical Salla reviews use the one-time workflow in
  `docs/operations/storefront-runbook.md`. Customer pages read the local
  archive and render the approved published four- and five-star subset.
- n8n credentials belong only in approved Hostinger/n8n secret storage. Never
  paste them into issues, pull requests, logs, or chat.

The Hostinger maintenance workflow does not activate, delete, or edit n8n
workflows, credentials, or static data.

## Bounded maintenance

Run the manual `hostinger-maintenance` workflow with `mode=audit` first. It
validates the active release path and inventories release directories,
incoming archives, temporary deployment links, legacy public-directory
backups, compiled views, old logs, and disk usage without changing the server.

Use `mode=apply` only after reviewing that inventory. Apply first requires a
successful `/up` check. It preserves `current`, every release directory,
`shared/.env`, and shared application data, then removes only:

- incoming `*.tar.gz` files matched by the script's `-mtime +1` threshold;
- root-level `.current-*` and `.rollback-*` symlinks;
- root-level `public_html.before-laravel-*` directories;
- direct files in `shared/storage/logs` matched by `-mtime +30`.

It then refreshes the compiled Blade view cache and prints a second inventory.
It does not touch MariaDB, customer uploads, carts, orders, credentials, n8n,
or Git history.

## Rollback and post-deploy checks

Do not use `migrate:rollback` as an application rollback. Follow
[hostinger-rollback.md](hostinger-rollback.md) and select a retained release
compatible with the forward-migrated schema. Automatic rollback restores the
prior code symlinks only when a valid prior release exists; it never reverses
database history.

After deployment, verify at minimum:

```text
GET /up -> 200
GET / -> 200, Arabic/RTL
GET /en -> 200, English/LTR
GET /login -> 200
GET /en/login -> 200
GET /cart -> 200
```

Also verify built assets and the browser console, horizontal overflow, auth and
service routes, cart behavior, and footer links. Exercise the Coins
`PS / Xbox → Normal → Amount` path and confirm the displayed server quote
changes without a page refresh. Use synthetic accounts only in the local/CI
browser fixture; do not create production synthetic users. Mohamed separately
accepts the real authenticated account and iPhone/Safari behavior.
