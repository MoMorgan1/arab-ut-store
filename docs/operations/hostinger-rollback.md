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

## Domain cutover rollback

The legacy Next.js repository remains in GitHub, and its Hostinger Web App is retained on a temporary Hostinger domain during the rollback window. If the Laravel domain verification fails:

1. Reassign `store.arab-ut.com` to the prior Web App in hPanel.
2. Confirm HTTPS and the old homepage before ending the incident.
3. Keep the Laravel PHP site and database intact for diagnosis; do not erase user or cart data.

Before any future public launch, verify a current Hostinger backup, record the active release SHA, and rehearse this domain reassignment while no checkout/payment traffic is enabled.
