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

## A rollback does not roll the outside world back

The release is the only thing that moves. Everything the store has already told an outside service
stays told, and rolling Laravel back is not an undo for any of it. This matters most where the
Google Sheet used to be the shared record: once the Sheet writer is gone, the store's own tables
are the only place a supplier placement is written down, and a release is not those tables.

What survives a rollback, and what to check afterwards:

- **Supplier orders stay placed.** A coins shipment or a challenge solve already accepted by FFT or
  UTT keeps running, keeps costing money, and has no cancel path from the store. Rolling back
  cannot unplace one, and re-publishing a paid order after a rollback can place it a second time —
  see `fulfillment-recovery.md`, section 2, before anything is re-sent.
- **n8n keeps the workflow versions it has published.** `ship-coins` and `solve-challenge` run the
  published version on Mohamed's instance, not a version this repository controls. A store release
  that rolls back to an older payload shape is then talking to a newer workflow; check
  `docs/api/n8n-fulfillment-v1.md` for the schema version the rolled-back release sends.
- **Captured payments stay captured.** Paylink has its own record; a rollback changes nothing there.
- **The outbox is forward-only in practice.** Rows in `integration_events` written by the newer
  release are still pending after the rollback. An event type the older release does not publish
  sits there untouched until the release that knows it is back. List them before deciding to wait
  or to place by hand:
  `php artisan tinker` → `App\Models\IntegrationEvent::whereIn('status', ['pending', 'processing', 'failed'])->get(['event_type', 'aggregate_id', 'status', 'attempts', 'last_error']);`
- **`fulfillment_jobs` and `fulfillment_placements` are unaffected**, including references recorded
  by the newer release. They are schema, not release state.
- **`fulfillment_alarms` re-derives itself.** It is a state table the sweep rebuilds from the world,
  so it needs no attention beyond running `php artisan fulfillment:alarms` once and reading the
  count.

Verify after any rollback that the background loops came back with the release: one
`Fulfillment poll completed.` line per minute in the log, and one `fulfillment:alarms` run whose
open count matches what the admin overview shows. A rolled-back release with a dead scheduler looks
healthy on `/up` and delivers nothing.

## Recovery beyond the retained releases

The retired Next.js Hostinger Web App is not a production rollback target. Normal rollback uses one of the five retained Laravel releases. If the required commit is older than those releases:

1. Identify the known-good commit in the private GitHub repository.
2. Rebuild it through the normal verified `tests` and `deploy-production` workflows.
3. Confirm that its schema compatibility is safe before activating it; never reverse production migrations as part of an application rollback.
4. Keep the current Laravel application and database intact for diagnosis; do not erase customer, cart, order, or credential data.

Before enabling production payment traffic, verify a current Hostinger backup, record the active release SHA, and rehearse the retained-release rollback procedure.
