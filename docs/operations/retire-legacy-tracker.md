# Retiring the legacy tracker (`track.arab-ut.com`)

The standalone PHP tracker at `track.arab-ut.com` (repo `MoMorgan1/ArabUT-Track`) is replaced by
the store's own tracking. This is the cutover procedure: what must be true before the site comes
down, in what order, what to redirect where, how to check afterwards, and how to put a customer
back on a working page if a link breaks.

**Who does what.** The repository half is done (see *What is already done in the store*). The DNS
change, the takedown, and the supplier-side confirmations are the owner's; nothing in this
document happens automatically on deploy.

**Read two sections before scheduling anything**: *The imported-customer cohort*, which is the one
part of this change a customer can be hurt by and cannot fix themselves, and *What the store does
not replace*, item 3, which is an open blocker on the takedown.

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

English is the same set under an `/en` prefix (`localized.*`).

`MY_ACCOUNT_ENABLED` (`config/store.php:9`) gates the **first two rows only**. Those are the
`routes/account.php` group, whose middleware stack starts with `EnsureMyAccountEnabled`; with the
flag off they answer 404.

`GET /orders/{order}` is **not** gated. It lives in `routes/web.php` under `['auth', NoStore]` and
does nothing but issue a redirect, so with the flag off it still answers `302` and only the
*followed* location 404s. An operator curling it and seeing a 302 has learned nothing about the
flag — check the flag itself (pre-flight check 6), not this route.

It is **not** a kill switch for public tracking. `/orders/track/{token}` and its three action
routes are registered in `routes/web.php` outside that group, carry neither
`EnsureMyAccountEnabled` nor `auth`, and keep serving whatever the flag says. If the public path
ever has to be closed in a hurry, the lever is revoking the tokens
(`IssueOrderTrackingLink::revoke()`, which stamps `order_tracking_links.revoked_at` and makes the
link 404), not this flag.

## The imported-customer cohort, and the two doors that fail silently

**Read this before the cutover. It is the one part of this change that reaches a customer who can
do nothing about it.**

The tracker was a public link: whoever held it could open it, with no account and no sign-in.
`/my-account/orders` is the opposite, and the assistant now names it. For a customer who placed
their order through the store that is fine — they registered at checkout, which sits behind `auth`
(`routes/web.php:56`), and `orders.user_id` is NOT NULL with a foreign-key constraint
(`database/migrations/2026_08_08_000003_create_commerce_tables.php:85`), so the account exists and
they know how to reach it.

**The Salla-era imported customers are different, and "has an account" is not "can reach it".**
`App\Imports\Salla\ImportSallaCustomers` creates every imported user with `password => null`
(line 274) and stamps `phone_verified_at` **only when the Salla row carried a phone** (line 284).
It never sets `email_verified_at` at all. That leaves three doors in three different states:

| Door | State for an imported customer |
| --- | --- |
| Password reset | **Bolted, and it lies.** `email_verified_at` is null for every imported row, so `EnsureVerifiedPasswordRecoveryEmail` returns the `RESET_LINK_SENT` response without ever calling `$next()` (lines 25-36). The customer sees the "check your email" success screen and **nothing is sent, ever**. There is also no password to reset: the import wrote `null`. |
| WhatsApp OTP | **Open if the Salla row had a phone**, because that is what sets `phone_verified_at`. If it did not — or the account was later deactivated — `SendWhatsAppLoginCode` returns without sending (lines 20-23) while `WhatsAppLoginController::send` still answers `sent: true` (line 32), and `VerifyWhatsAppLoginCode` would reject the code anyway. **Silent again**: the customer waits for a code that was never sent. |
| Google | **Open if the Salla email is a Google address.** `GoogleAuthenticationController` claims the existing account by email and stamps `email_verified_at` (lines 74-104). This is the only door that works for a phoneless imported customer, and only if they happen to use Google. |

So an imported customer with no phone on their Salla row and a non-Google email **cannot sign in at
all**, and the store tells them twice that it has helped. Under the tracker they simply opened their
link.

**What to do about it, and what not to.**

- **Do not tell such a customer to reset their password or request a WhatsApp code.** Both paths
  report success and do nothing. Repeating the advice wastes the one thing they still have, which
  is patience.
- **Route them to a human**, then use *Rollback*, step 2: mint a signed link for their order and
  send it. That is the same capability the tracker's public link gave them, and it is the correct
  answer rather than a workaround.
- **This is why item 3 of *What the store does not replace* blocks the takedown.** The store has no
  screen that mints that link; today it is a tinker command. Until that gap is closed, every one of
  these customers costs a manual operation, and after the takedown there is no public link to fall
  back on.
- Keep the redirect up long enough that the manual route is exercised at least once before the site
  goes down (*Order of operations*, step 5).

## Why the assistant names the account page and not the signed link

Given the above, the obvious question is whether the prompts should name the sessionless
`/orders/track/{token}` instead, or as well. They should not:

- **The assistant has no tools** (`docs/ai-assistant/TOOLS.md`), cannot look up an order, and the
  token is a 48-character per-order secret. Describing that URL shape in a prompt would invite the
  model to invent a token — and because `store.arab-ut.com` is on the chat linkifier's allowlist,
  an invented one would render as a clickable store link.
- **There is no order without an account to point at**, so the account page is the only address the
  model can correctly name for every customer.
- **A guest reaching it is not sent to a 404.** `redirectGuestsTo` (`bootstrap/app.php:61`) sends
  them to the login page in their own locale. Whether they can get *through* that page is the
  cohort problem above, not a routing problem.

The signed link is delivered by whatever sends it — n8n today, a human using the rollback step
meanwhile — and is never authored by the model.

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

Every check below must pass on the day of the cutover, not once in advance. They run **before** the
deploy (*Order of operations*, step 1), because check 6 is what proves the replacement is reachable
and the deploy is what stops the assistant naming the tracker.

Checks 1, 2 and 6 run against the store over SSH; check 3 runs on the tracker's host; checks 4 and
5 are read off n8n and the supplier dashboards and are Mohamed's.

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

All four must read zero. Prefer the panel: it is the only thing that also reports `monitored`,
which is `false` — and every count therefore meaningless — unless `queue.default` is `database`
(`ReadQueueHealth::read()`). Check that first with `php artisan tinker --execute="echo
config('queue.default');"`.

If the panel is unavailable, these four queries are the same four numbers. They reproduce
`ReadQueueHealth`'s own predicates rather than approximating them; `jobs.available_at` and
`jobs.reserved_at` are unix timestamps, and the two constants are `STALLED_AFTER_SECONDS = 300`
and `queue.connections.database.retry_after` (default 90, `DB_QUEUE_RETRY_AFTER`).

```sql
-- failedJobs: work that was tried and refused.
SELECT COUNT(*) FROM failed_jobs;

-- failedEvents: outbox rows that exhausted every delivery attempt. Drained by
-- their own command, not the queue worker, so they never reach failed_jobs.
SELECT COUNT(*) FROM integration_events WHERE status = 'failed';

-- stalledJobs: rows the queue would hand a worker right now and has not -
-- Laravel's isAvailable OR isReservedButExpired. available_at, not created_at,
-- so a deliberately delayed job is not counted as stalled.
SELECT COUNT(*) AS stalled, MIN(available_at) AS oldest
FROM jobs
WHERE available_at <= UNIX_TIMESTAMP() - 300
  AND (reserved_at IS NULL OR reserved_at <= UNIX_TIMESTAMP() - 90);

-- silentItems: paid, published, acknowledged and never placed. Nothing else
-- knows about these.
SELECT COUNT(*) FROM fulfillment_alarms WHERE resolved_at IS NULL;
```

Substitute the real `retry_after` for `90` if `DB_QUEUE_RETRY_AFTER` is set on production. A
non-zero `stalled` means the scheduler cron is dead or a worker died holding a reservation — the
failure mode that leaves `failed_jobs` empty while every receipt silently stops, and exactly the
state you must not take the tracker down on top of.

### 3. No outstanding direct-tracking link on the tracker host

The tracker's minted `?t=` links are a JSON file outside the web root, on the tracker's own host.
SSH in with the same Hostinger identity the deploy uses (`docs/operations/hostinger-deployment.md`;
the store lives at `/home/u372356793/domains/store.arab-ut.com`, so the tracker's account directory
is its sibling). Do not guess the path — find it:

```bash
ssh <hostinger-user>@<host>
ls -d ~/domains/track.arab-ut.com            # the account directory
find ~/domains/track.arab-ut.com -name 'direct-tracking-links.json' -maxdepth 4 2>/dev/null
cat <the path that printed>                  # or: python3 -m json.tool <path>
```

If `find` prints nothing, the file has never been written and **no `?t=` link was ever minted** —
this check passes. Confirm that reading rather than assuming it: `grep -n "storagePath"
~/domains/track.arab-ut.com/public_html/config.php` shows whether an override moved it elsewhere.
The default resolves to `/private/direct-tracking-links.json` two directories above the document
root (`includes/functions.php:520-527`). Each entry is keyed by its `trk_…` token and records
`label`, `createdAt`, `supplier`, `supplierOrderId` and `sbcIDs`.

Read it and, for every entry whose supplier order is not finished:

1. Confirm its state in the FFT or UTT dashboard.
2. If it is still running, create the matching manual order in the Admin — `/admin/orders`, the
   "new order" drawer, posting to `admin.orders.store` — pasting the supplier reference so the job
   is bound at creation rather than dispatched to n8n.
3. Send the customer the store's link for that order. **Today this needs the gap in item 3 of
   *What the store does not replace* to be closed first.**

Do not delete the JSON file before the takedown; it is the only inventory of these links.

### 4. Nothing still messages customers with a tracker URL, or any other dead one

Two n8n workflows write a customer-facing address into a WhatsApp message. The committed exports
below say *what* each writes; the question this check answers is whether either is still running,
and that can only be read off Mohamed's n8n instance. **Its URL is deliberately not in this
repository** — `automation/n8n/ship-coins-v1/README.md:60` explains why instance-specific
identifiers are not committed — so ask him for the instance address, or have him run the check.

**"Active" means either of two things, and both have to be false:**

1. The workflow's **Active** toggle is on in the n8n workflow list (a scheduled or webhook trigger
   can fire it without anyone pressing anything), **or**
2. anything still calls its webhook. For `Fulfillment v14` that is the Salla-era order webhook; for
   `Customer Notifier v2` it is whatever posts status updates to it. Deactivating the workflow
   closes both, which is why deactivation — not deleting the node — is the check.

Confirm by opening each workflow in the instance and reading the toggle, then opening its
**Executions** tab and confirming the newest execution predates the cutover. A workflow toggled off
but showing an execution from this morning means something re-enabled it.

| Workflow | Node | Addresses it writes |
| --- | --- | --- |
| `Fulfillment v14` (`automation/n8n/fulfillment-v14/workflow-v14-salla.json`) | `WA: Build Confirmation` | `https://track.arab-ut.com/?id=…` |
| `Customer Notifier v2` (`automation/n8n/customer-notifier-v2/workflow-v2-salla.json`) | `Build Customer Message` | `https://track.arab-ut.com/?id=…`, `https://arab-ut.com/orders`, `https://arab-ut.com/ea-backup-codes-guide/page-699997932` |

Neither workflow may be active when the tracker comes down, or the store will hand out dead links
itself. The replacements, `ship-coins-v1` and `solve-challenge-v1`, send no customer message at all.

The notifier's other two addresses are **already dead** and are not waiting on this cutover: both
are WordPress pages on the retired `arab-ut.com` storefront, which the Laravel store replaced.
`https://arab-ut.com/orders` has the same in-store answer as the tracker — `/my-account/orders`;
the backup-codes guide is the knowledge topic `issue-backup-codes`, which points at
`/ea-backup-codes`. Whoever rebuilds or disables that node fixes all three in the same edit; none
of it is a change to the committed baseline.

### 5. Supplier confirmation

Mohamed confirms from the FFT and UTT dashboards that no unresolved job is outstanding. This is
separate from check 1: the store only knows about jobs it created, and the tracker also served
orders placed by hand.

### 6. The replacement is actually reachable

Two things, in this order. Run them from the current release directory over SSH, the way
`docs/operations/hostinger-deployment.md` runs its operator commands
(`/home/u372356793/domains/store.arab-ut.com/current`).

**The flag is on.** A `curl` cannot tell you this, because an unauthenticated request redirects to
login either way:

```bash
php artisan tinker --execute="var_dump(config('store.features.my_account_enabled'));"
```

It must print `bool(true)`. If it prints `false`, `/my-account/orders` answers **404** for every
signed-in customer and the cutover must not proceed.

**The page actually renders.** This needs a session, so do not try to curl it — a `302` to `/login`
is what a correct server returns to an anonymous request and proves nothing. Sign in as a real
customer account in a browser and open `https://store.arab-ut.com/my-account/orders`. Use an
account that has at least one order, so you are checking the list renders rather than the empty
state. If no such account is at hand, Mohamed's own customer account is the one to use; do not
create a throwaway account on production to satisfy a check.

A scripted equivalent, if a browser is not available, is one Playwright run of the account specs
against production — but the browser check is faster and is what this step is for.

---

## Order of operations

Steps 1 to 5 can be undone: the branch can be reverted and the redirect rule deleted. **Steps 6 and
7 cannot.** Step 6 removes the document root and the three `private/` JSON files — including the
only inventory of the minted tracking links — and step 7 rotates live credentials. Treat the
boundary between 5 and 6 as the point of no return, and read *Rollback* before crossing it.

1. **Run the pre-flight.** Every check above, on the day. It comes before the deploy because
   check 6 is what proves the replacement is reachable, and step 2 stops the assistant naming the
   tracker; doing them the other way round means the assistant could be sending customers at a
   404 for as long as it takes to notice.
2. **Deploy the store changes.** They are additive; the tracker keeps working and every old link
   still resolves. After this the assistant names `/my-account/orders`, and `track.arab-ut.com`
   stops being a one-tap link in chat (see *The linkifier window* below).
3. **Announce nothing.** There is no customer-facing announcement; a redirect is the announcement.
4. **Redirect `track.arab-ut.com` at the store.** Prefer an HTTP 301 from the tracker's own host
   over a DNS change, because the host can answer every old URL shape while DNS can only move the
   name. If the redirect is served by the tracker's own Apache, add this to the `.htaccess` in its
   document root as the **first** rule, above the `Options` line and everything after it:

   ```apache
   RewriteEngine On
   RewriteRule ^ https://store.arab-ut.com/my-account/orders [R=301,L,QSD]
   ```

   **`QSD` is not optional.** When the substitution URL carries no query string of its own,
   mod_rewrite appends the incoming one, so without `QSD` the rule turns
   `track.arab-ut.com/?t=<token>` into `store.arab-ut.com/my-account/orders?t=<token>` — a live
   capability token copied into a store URL, the store's access log, the `Referer` of everything
   the page loads, and any analytics running on it. `QSD` is Apache 2.4+; on 2.2 the same effect
   comes from appending a bare `?` to the substitution
   (`… /my-account/orders? [R=301,L]`). Read the version off the server before choosing — over SSH,
   `httpd -v` or `apache2 -v`; if neither binary is on the path (shared hosting usually hides it),
   `curl -sSI https://track.arab-ut.com/ | grep -i '^server:'`, and if the header is suppressed,
   use the bare `?` form, which is valid on both versions.

   Dropping the query string is also right on its own terms: `?id=` is a Salla order number and
   `?t=` is a tracker token, and neither means anything to the store. Everything lands on the
   account orders list.

   **If the redirect is done at DNS instead**, point `track` at the store host, give that host a
   vhost for the name, and have it answer with the same 301. Do **not** leave the name resolving to
   a host that serves nothing — that is indistinguishable from an outage. In Apache:

   ```apache
   <VirtualHost *:443>
       ServerName track.arab-ut.com
       # TLS for this name must exist here too - see "The redirect window's
       # certificate" below.
       Redirect 301 / https://store.arab-ut.com/my-account/orders
   </VirtualHost>
   ```

   `Redirect` discards the query string by itself, so there is no `QSD` equivalent to remember. If
   the name has to be answered by Laravel rather than the web server, the equivalent is one route,
   registered before the locale groups in `routes/web.php`:

   ```php
   Route::domain('track.arab-ut.com')->any('{any?}', fn () => redirect()
       ->away('https://store.arab-ut.com/my-account/orders', 301))
       ->where('any', '.*');
   ```

   `redirect()->away()` takes the URL as given and adds no query string. Prefer the web server:
   a route means the redirect depends on the application booting.
5. **Leave the redirect up for at least one warranty period (8 days) plus a margin.** Old WhatsApp
   messages keep arriving; the warranty window is how long a customer still has a reason to open
   one. Four weeks is the recommended figure. Use the window to exercise the manual path in
   *Rollback* step 2 at least once on a real order, so the first time it is needed is not the first
   time it is tried.
6. **Take the site down.** Only after steps 1-5 and after the gap in *What the store does not
   replace* item 3 is closed. Taking it down means: remove the document root's contents, then
   remove `direct-tracking-links.json`, `finished-stamps.json` and `admin-auth.json` from the
   `private/` directory found in pre-flight check 3. Keep a copy of
   `direct-tracking-links.json` somewhere private first — it is the only inventory of the minted
   links, and nothing else can reconstruct it. This is what closes the `admin-links.php` exposure
   and removes the plaintext supplier key in `config.php` from that host.
7. **Rotate the FFT and UTT keys if that has not already happened** (task A2 in the tracking plan).
   The keys in the tracker's `config.php` and in its git history must be treated as disclosed
   whether or not the site is up. Distribute the new keys to **both** the store's `shared/.env` and
   n8n; n8n still places orders and runs pricing and catalog against the same suppliers.
8. **Archive the repository.** `MoMorgan1/ArabUT-Track` becomes read-only rather than deleted: it
   is the behavioural specification the store's tracking was ported from, and `CONTEXT.md` and
   `AGENTS.md` both cite it.

## Two windows this procedure accepts

Both are consequences of the ordering above, both are known, and neither is a defect to chase on
the day. They are written down so that whoever hits one recognises it instead of debugging it.

### The linkifier window

The delinking ships at step 2 and the redirect lands at step 4. In between, `track.arab-ut.com` is
still live and still correct, but a customer scrolling back through an old chat transcript sees the
address as plain text rather than a tap target. They can select and paste it; it still works.

**Accepted, not fixed.** Timing the delink to the redirect would mean either holding the whole
branch until the day of the cutover — which delays the prompt fix, the part that actually stops new
customers being sent at a site that is going away — or shipping the delink as a second deploy in
the middle of the window, which puts a production release inside the one part of the day that has
to be simple. Neither buys enough to be worth it: the transcripts affected are old, the address
remains readable, and the window is however long the owner takes between steps 2 and 4.

Keep it short by choice: there is no reason for step 4 not to follow step 2 the same day.

### The redirect window's certificate

Step 5 keeps the redirect up for about four weeks. **`track.arab-ut.com` needs a valid TLS
certificate for the whole of that window**, and it is a name nobody is looking at any more — which
is exactly how a certificate lapses unnoticed.

If it expires mid-window, every check in *Verification* fails before it prints a status line, and
every customer following an old link gets a browser interstitial. Both read as "the site is down",
which is the outcome this entire procedure exists to avoid, and neither points at the cause.

**Whoever owns the redirect owns the certificate until the site comes down.** Before step 4:

```bash
curl -sSvI https://track.arab-ut.com/ 2>&1 | grep -i 'expire date'
```

Confirm the expiry is beyond the planned takedown date. If it is not, renew it first, or shorten
the window to fit — do not start a four-week redirect on a certificate with three weeks left. If
the host auto-renews (Hostinger does for its own domains), confirm renewal is still enabled for
this name specifically; removing a site from a panel has been known to remove it from renewal too.

## Verification after the redirect

Run all of these; the first four are the ones that catch a wrong redirect. In 1, 2 and 4 the
`Location` header must be **exactly** `https://store.arab-ut.com/my-account/orders`, with no query
string on the end.

1. `curl -sSI 'https://track.arab-ut.com/?id=12345'` → `301`, `Location:
   https://store.arab-ut.com/my-account/orders`. A `Location` ending `?id=12345` means `QSD` is
   missing from the rule.
2. `curl -sSI 'https://track.arab-ut.com/?t=trk_anything'` → the same 301 and the same bare
   `Location`. **A `Location` ending `?t=trk_anything` is the failure that matters**: without `QSD`
   the rule carries a real customer's capability token into the store's URLs and logs. Stop and fix
   the rule before anything else. A `200` instead of a 301 means the tracker is still serving and
   the rule sits below something that matched first.
3. `curl -sSI 'https://track.arab-ut.com/admin-links.php'` → the same 301, **not** a 200.
4. `curl -sSi 'https://track.arab-ut.com/?mode=json&id=12345'` → the same 301, the same bare
   `Location`, and no JSON body. A JSON body means the read and write endpoints are still reachable
   and the exposure is still open.
5. In a browser, signed in: `/my-account/orders` lists orders, and opening one shows the tracking
   block with a fresh observation age.
6. A real signed link opens with no session. Mint one for a real order with the tinker snippet in
   *Rollback* step 2 — that is the only way to obtain one today — then paste the URL it returns
   into a private window. It must show the order and its buttons must work. This is the check that
   proves the manual route in *Rollback* actually functions before you need it in anger.
7. Ask the assistant in chat where to track an order. The reply must name
   `store.arab-ut.com/my-account/orders` and must not name the tracker.
8. **TLS on both names.** `curl -sSI https://track.arab-ut.com/` must not fail before it prints a
   status line. See *The redirect window's certificate* below — a TLS error here looks exactly like
   the outage this whole procedure is trying to avoid.

## Rollback

**If a customer link breaks**, the order of attempts is:

1. **The customer can sign in.** Send them to `https://store.arab-ut.com/my-account/orders`. This
   needs nothing from us, and it is the answer whenever it works.

   "Can sign in" is the question, not "has an account" — every order has an account. If they say a
   password reset or a WhatsApp code never arrived, **believe them and go to step 2**: for an
   imported Salla customer both of those report success and send nothing (see *The
   imported-customer cohort*). Do not ask them to try again.
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

**If the redirect itself is wrong** — wrong target, a loop, a `Location` carrying a query string,
or a 200 where a 301 was expected — remove the `.htaccess` rule. The tracker is unchanged
underneath it and resumes serving immediately. This is why the redirect comes well before the
takedown: it is the only step in this procedure that can be undone in one edit.

**Once the site is down there is no rollback to it.** Steps 6 and 7 are one-way — the document root
and the link inventory are gone, and the old supplier keys no longer work — which is why steps 1
to 5 exist and why the link inventory is copied somewhere private before step 6.

## Related

- `docs/plans/2026-09-12-order-tracking-in-store.md`, slice G — why this is on the critical path.
- `docs/decisions/2026-09-12-order-tracking-in-store-design.md`, *Security items that are not
  optional* — the exposure and the key rotation.
- `docs/operations/hostinger-rollback.md` — application rollback, which this is not.
