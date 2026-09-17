# Retiring the legacy tracker (`track.arab-ut.com`)

The standalone PHP tracker at `track.arab-ut.com` (repo `MoMorgan1/ArabUT-Track`) is replaced by
the store's own tracking. This is the cutover procedure: what must be true before the site comes
down, in what order, what to redirect where, how to check afterwards, and how to put a customer
back on a working page if a link breaks.

**Who does what.** The repository half is done (see *What is already done in the store*). The DNS
change, the takedown, and the supplier-side confirmations are the owner's; nothing in this
document happens automatically on deploy.

**Why it is worth doing rather than leaving up.** `admin-links.php` mints the `?t=` tokens that
the tracker's four write endpoints (`mode=update`, `mode=resume`, `mode=sbc-retry`, `mode=sbc-edit`)
trust, and it serves HTTP 200 with no password whenever no credential file exists
(`includes/functions.php:367-380`, `config.php` `adminAuth.requireWhenUnset` is `false`). By owner
instruction the tracker is not patched, so that window closes when the site does. The same host
also holds a supplier API key in plaintext in `config.php`.

---

## What is already done in the store

| Was | Is now |
| --- | --- |
| `support-v6`..`support-v9` told the assistant order tracking is `https://track.arab-ut.com` | They name `https://store.arab-ut.com/my-account/orders` |
| The knowledge topics `order-tracking` and `issue-paused` carried `"url": "/orders"`, which is not a route | `"url": "/my-account/orders"`, which is `account.orders` |
| `track.arab-ut.com` was on the chat linkifier's allowlist (`resources/js/lib/chat-format.ts`) | Removed. Old transcripts still containing the address render it as plain text, not a tap target |

Nothing else in the store sends a customer to the tracker. The remaining occurrences of the name
in this repository are provenance (`app/Enums/HoldTone.php`, `app/Enums/TrackingPresentation.php`,
`resources/js/styles/order-tracking.css`, `CONTEXT.md`, `AGENTS.md`, `docs/`) and are correct as
history.

## The in-store replacement, by route

| Route | Name | Who reaches it |
| --- | --- | --- |
| `GET /my-account/orders` | `account.orders` | A signed-in customer. This is the address the assistant now gives out. |
| `GET /my-account/orders/{order}` | `account.orders.show` | A signed-in customer. Carries the tracking block; opening it performs one supplier read. |
| `GET /orders/{order}` | `store.orders.show` | Redirects to `account.orders.show` — the old shape still works. |
| `GET /orders/track/{token}` | `store.orders.track` | **No session.** The capability link: 48-character token, resolved by SHA-256 digest, never expires, 404s once revoked (`app/Support/PublicHandle/TrackingLinkHandle.php`). |
| `POST /orders/track/{token}/items/{item}/actions/edit-credentials` | `store.orders.track.actions.edit-credentials` | Replaces the tracker's `mode=update` and `mode=sbc-edit`. |
| `POST /orders/track/{token}/items/{item}/actions/resume` | `store.orders.track.actions.resume` | Replaces `mode=resume`. |
| `POST /orders/track/{token}/items/{item}/actions/retry-challenge` | `store.orders.track.actions.retry-challenge` | Replaces `mode=sbc-retry`. |

English is the same set under an `/en` prefix (`localized.*`). The whole `/my-account` tree is
behind `MY_ACCOUNT_ENABLED` (`config/store.php:9`); if that is not `true` in production every
target above answers 404.

## What the store does not replace

Three things the tracker could do have no in-store equivalent. Read these before setting a date.

1. **`https://track.arab-ut.com/?id=<Salla order id>`.** The tracker resolved a Salla order number
   to a supplier order through a Google Sheet. The store has no route that takes a Salla order
   number, so a link of this shape in an old WhatsApp message cannot be deep-linked to the right
   order. Those customers land on `/my-account/orders` and pick the order themselves (the Salla
   archive was imported, so the order is there if the customer signs in with the matching account).
2. **`?t=` links minted by `admin-links.php` that point at a supplier order with no store order.**
   The store cannot represent one: `fulfillment_jobs.order_item_id` is NOT NULL
   (`database/migrations/2026_08_08_000004_create_fulfillment_tables.php:44`). Each outstanding one
   has to be re-created as a manual order first — see the pre-flight below.
3. **Handing a customer a fresh tracking link.** `IssueOrderTrackingLink` exists and works, but as
   of this writing **no admin screen and no notification in the store calls it** — its only callers
   are tests. So the store can mint the signed link but has no button that shows it and no message
   that sends it. Until that is built, or until an n8n workflow sends it, `admin-links.php` is
   still the only way to hand out a link outside checkout, and retiring the site removes that
   capability with nothing in its place. **This is a blocker for the takedown, not for the
   redirect.**

## Pre-flight

Every check below must pass on the day of the cutover, not once in advance.

### 1. No unresolved supplier job in the store

Terminal fulfillment statuses are `completed`, `failed`, `cancelled` (`app/Enums/FulfillmentStatus.php`).
Anything else is outstanding.

```sql
SELECT j.status, j.supplier, j.supplier_order_id, j.observed_at, o.order_number
FROM fulfillment_jobs j
JOIN order_items i ON i.id = j.order_item_id
JOIN orders o ON o.id = i.order_id
WHERE j.status NOT IN ('completed', 'failed', 'cancelled')
ORDER BY j.created_at;
```

Expect zero rows. A row means a customer is still waiting on a supplier and will reopen their
link.

### 2. No open silence alarm, no stuck background work

**Screen:** the admin overview at `/admin` (`admin.overview`). Its queue-health panel is built by
`App\Admin\Queries\ReadQueueHealth` and reports failed jobs, failed outbox events, stalled jobs,
and `silentItems` — the count of open rows in `fulfillment_alarms`, which is the only place an
item that was paid for, published, acknowledged and never placed shows up.

All four must read zero. The equivalent query, if the panel is unavailable:

```sql
SELECT COUNT(*) FROM fulfillment_alarms WHERE resolved_at IS NULL;
SELECT COUNT(*) FROM integration_events WHERE status = 'failed';
```

### 3. No outstanding direct-tracking link on the tracker host

The tracker's minted `?t=` links are a JSON file outside the web root. The path is
`$config['directTrackingLinks']['storagePath']` when set, otherwise
`dirname(__DIR__, 3) . '/private/direct-tracking-links.json'` resolved from `includes/`
(`includes/functions.php:520-527`) — two directories above the document root. Each entry is keyed
by its `trk_…` token and records `label`, `createdAt`, `supplier`, `supplierOrderId` and `sbcIDs`.

Read it and, for every entry whose supplier order is not finished:

1. Confirm its state in the FFT or UTT dashboard.
2. If it is still running, create the matching manual order in the Admin — `/admin/orders`, the
   "new order" drawer, posting to `admin.orders.store` — pasting the supplier reference so the job
   is bound at creation rather than dispatched to n8n.
3. Send the customer the store's link for that order. **Today this needs the gap in item 3 of
   *What the store does not replace* to be closed first.**

Do not delete the JSON file before the takedown; it is the only inventory of these links.

### 4. Nothing still messages customers with a tracker URL

Two n8n workflows build `https://track.arab-ut.com/?id=…` into a WhatsApp message:

| Workflow | Node |
| --- | --- |
| `Fulfillment v14` (`automation/n8n/fulfillment-v14/workflow-v14-salla.json`) | `WA: Build Confirmation` |
| `Customer Notifier v2` (`automation/n8n/customer-notifier-v2/workflow-v2-salla.json`) | `Build Customer Message` |

Both committed files are the Salla baselines and are never edited in place, so this check is
against the **live n8n instance**, not the repository. Neither must be active when the site comes
down, or the store will hand out dead links itself. The replacements, `ship-coins-v1` and
`solve-challenge-v1`, send no customer message at all.

### 5. Supplier confirmation

Mohamed confirms from the FFT and UTT dashboards that no unresolved job is outstanding. This is
separate from check 1: the store only knows about jobs it created, and the tracker also served
orders placed by hand.

### 6. The replacement is actually reachable

On production: `MY_ACCOUNT_ENABLED=true`, and `/my-account/orders` returns 200 for a signed-in
customer.

---

## Order of operations

The order matters: each step is reversible until the one after it.

1. **Deploy the store changes.** They are additive; the tracker keeps working. After this the
   assistant no longer names the tracker.
2. **Run the pre-flight.** Every check above, on the day.
3. **Announce nothing.** There is no customer-facing announcement; a redirect is the announcement.
4. **Redirect `track.arab-ut.com` at the store.** Prefer an HTTP 301 from the tracker's own host
   over a DNS change, because the host can answer every old URL shape while DNS can only move the
   name. If the redirect is served by the tracker's own Apache, add this to the `.htaccess` in its
   document root as the **first** rule, above the `Options` line and everything after it:

   ```apache
   RewriteEngine On
   RewriteRule ^ https://store.arab-ut.com/my-account/orders [R=301,L]
   ```

   This deliberately drops `?id=` and `?t=`: neither means anything to the store, and carrying a
   supplier order id into a store URL would only produce a 404 with the customer's order reference
   in it. Everything lands on the account orders list.

   If the redirect is done at DNS instead, point `track` at the store host and make the store's web
   server answer that name with the same 301 — do **not** leave the name resolving to a host that
   serves nothing, which is indistinguishable from an outage.
5. **Leave the redirect up for at least one warranty period (8 days) plus a margin.** Old WhatsApp
   messages keep arriving; the warranty window is how long a customer still has a reason to open
   one. Four weeks is the recommended figure.
6. **Take the site down.** Only after steps 2-5 and after the gap in *What the store does not
   replace* item 3 is closed. Taking it down means: remove the document root's contents, then
   remove `direct-tracking-links.json`, `finished-stamps.json` and `admin-auth.json` from the
   `private/` directory named in pre-flight check 3. This is what closes the `admin-links.php`
   exposure and removes the plaintext supplier key in `config.php` from that host.
7. **Rotate the FFT and UTT keys if that has not already happened** (task A2 in the tracking plan).
   The keys in the tracker's `config.php` and in its git history must be treated as disclosed
   whether or not the site is up. Distribute the new keys to **both** the store's `shared/.env` and
   n8n; n8n still places orders and runs pricing and catalog against the same suppliers.
8. **Archive the repository.** `MoMorgan1/ArabUT-Track` becomes read-only rather than deleted: it
   is the behavioural specification the store's tracking was ported from, and `CONTEXT.md` and
   `AGENTS.md` both cite it.

## Verification after the redirect

Run all of these; the first three are the ones that catch a wrong redirect.

1. `curl -sSI 'https://track.arab-ut.com/?id=12345'` → `301` with
   `Location: https://store.arab-ut.com/my-account/orders`.
2. `curl -sSI 'https://track.arab-ut.com/?t=trk_anything'` → the same 301. A `200` here means the
   tracker is still serving and the redirect rule is below something that matched first.
3. `curl -sSI 'https://track.arab-ut.com/admin-links.php'` → the same 301, **not** a 200.
4. `curl -sSi 'https://track.arab-ut.com/?mode=json&id=12345'` → the same 301 and no JSON body. A
   JSON body means the read and write endpoints are still reachable and the exposure is still open.
5. In a browser, signed in: `/my-account/orders` lists orders, and opening one shows the tracking
   block with a fresh observation age.
6. A real signed link — `/orders/track/{token}` — opens in a private window with no session, shows
   the order, and its buttons work.
7. Ask the assistant in chat where to track an order. The reply must name
   `store.arab-ut.com/my-account/orders` and must not name the tracker.

## Rollback

**If a customer link breaks**, the order of attempts is:

1. **The customer has an account.** Send them to `https://store.arab-ut.com/my-account/orders` and
   have them sign in. This is the answer for every `?id=` link and almost every `?t=` one, and it
   needs nothing from us.
2. **The order exists in the store but the customer cannot sign in.** Mint a fresh signed link for
   that order and send it. There is no screen for this yet (see *What the store does not replace*,
   item 3), so today it is `php artisan tinker` on the production release:

   ```php
   app(App\Actions\Orders\IssueOrderTrackingLink::class)
       ->execute(App\Models\Order::where('order_number', 'AUT-…')->firstOrFail());
   ```

   It returns the same URL on every call while the link is live, so a resend never orphans a link
   already in flight. If the old link leaked, call `->revoke($order)` first; the next `execute`
   issues a fresh token in the same row and the old one 404s on its own.
3. **The order does not exist in the store.** Create it as a manual order with the supplier
   reference pasted (`/admin/orders`, the new-order drawer), then do step 2. This is the intended
   replacement for `admin-links.php` and it produces a real `AUT-` order rather than a floating
   link.

**If the redirect itself is wrong** — wrong target, loop, or a 200 where a 301 was expected —
remove the `.htaccess` rule. The tracker is unchanged underneath it and resumes serving. This is
why the redirect comes before the takedown and by a wide margin: it is the only step in this
procedure that can be undone in one edit.

**Once the site is down there is no rollback to it.** Everything after step 6 is one-way, which is
why steps 2-5 exist.

## Related

- `docs/plans/2026-09-12-order-tracking-in-store.md`, slice G — why this is on the critical path.
- `docs/decisions/2026-09-12-order-tracking-in-store-design.md`, *Security items that are not
  optional* — the exposure and the key rotation.
- `docs/operations/hostinger-rollback.md` — application rollback, which this is not.
