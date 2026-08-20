# Hostinger rollback

Application releases are recoverable without exposing secrets. Automatic failed-health recovery may reverse only the migration batch just applied by that failed release; manual rollback remains schema-forward.

## Automatic rollback

`deploy/hostinger-release.sh` remembers the previous `current` target and checks for pending migrations before it runs `php artisan migrate --force`. If the new release does not return a successful `/up` response and a valid prior release exists:

- with no migrations applied by the failed release, it restores the prior `current` and `public_html` symlinks;
- with a new migration batch applied, it runs `php artisan migrate:rollback --force` from the failed release and restores the prior symlinks only after that rollback succeeds;
- when migration rollback fails, it refuses to activate the older code and leaves the failed release active for operator recovery.

Every failed-health branch exits nonzero so GitHub marks the deployment failed. With no valid prior release, the script leaves both the new schema and failed release active. A successful automatic schema rollback can discard migration-only metadata written during the failed release's activation window.

## Manual rollback

1. Identify the last known-good directory under `<deploy-root>/releases`.
2. Point a temporary symlink at that release.
3. Atomically replace `current` with the temporary symlink.
4. Reassert `public_html -> current/public`.
5. Run `php artisan config:cache`, then verify `/up`, `/`, `/en`, and one Coins price.

Do not run `migrate:rollback` during this manual procedure. Manual code rollback must use a release compatible with the current schema. The automatic failed-health branch above is narrower: it knows the current release introduced a new batch and reverses that batch before it restores prior code.

## Recovery beyond the retained releases

The retired Next.js Hostinger Web App is not a production rollback target. Normal rollback uses one of the five retained Laravel releases. If the required commit is older than those releases:

1. Identify the known-good commit in the private GitHub repository.
2. Rebuild it through the normal verified `tests` and `deploy-production` workflows.
3. Confirm that its schema compatibility is safe before activating it; never reverse production migrations as part of an application rollback.
4. Keep the current Laravel application and database intact for diagnosis; do not erase customer, cart, order, or credential data.

Before enabling production payment traffic, verify a current Hostinger backup, record the active release SHA, and rehearse the retained-release rollback procedure.
