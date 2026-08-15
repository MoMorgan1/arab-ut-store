# Hostinger rollback

Application releases are recoverable without changing database history or exposing secrets.

## Automatic rollback

`deploy/hostinger-release.sh` remembers the previous `current` target. If the new release does not return a successful `/up` response, it restores the prior `current` and `public_html` symlinks and exits nonzero so GitHub marks the deployment failed.

## Manual rollback

1. Identify the last known-good directory under `<deploy-root>/releases`.
2. Point a temporary symlink at that release.
3. Atomically replace `current` with the temporary symlink.
4. Reassert `public_html -> current/public`.
5. Run `php artisan config:cache`, then verify `/up`, `/`, `/en`, and one Coins price.

Do not run `migrate:rollback` as part of an application rollback. Database migrations are forward-only during deployment; rolling back code must use a release that remains compatible with the migrated schema.

## Recovery beyond the retained releases

The retired Next.js Hostinger Web App is not a production rollback target. Normal rollback uses one of the five retained Laravel releases. If the required commit is older than those releases:

1. Identify the known-good commit in the private GitHub repository.
2. Rebuild it through the normal verified `tests` and `deploy-production` workflows.
3. Confirm that its schema compatibility is safe before activating it; never reverse production migrations as part of an application rollback.
4. Keep the current Laravel application and database intact for diagnosis; do not erase customer, cart, order, or credential data.

Before enabling production payment traffic, verify a current Hostinger backup, record the active release SHA, and rehearse the retained-release rollback procedure.
