# Order tracking inside the store, and the supplier boundary it needs

Date: 2026-09-12
Status: all decisions confirmed by Mohamed on 2026-09-12 in a grilling session. Design canvas
and implementation pending. Plan: `docs/plans/2026-09-12-order-tracking-in-store.md`.
Related: `docs/decisions/2026-09-12-ea-credentials-in-placement-payload.md` (the one decision
that departs from `docs/api/paylink-checkout-v1.md`).
Glossary: `CONTEXT.md` — in particular the Fulfillment/Tracking split and the two-phase
Challenge delivery, both of which this document depends on.

## Discovery

- **Users**: paying customers checking an automated order (Coins, Challenges) from a phone,
  in Arabic; Mohamed watching the same orders from the Admin.
- **Look**: the standalone tracker at `track.arab-ut.com` (repo `MoMorgan1/ArabUT-Track`,
  working copy `C:\xampp\htdocs\track`). Mohamed: "التتبع مبني وجاهز انت بس هتدمجه جوا الستور".
  The design is approved by use and is not reopened here; the canvas pass is for identity only.
- **Technology**: nothing new on the server. Laravel actions, the existing database queue and
  minute cron, Inertia/React pages, the store's own tokens. No Redis, no daemon, no new service.
- **Existing data**: `fulfillment_jobs` and `fulfillment_attempts` already exist with the right
  columns (`supplier`, `supplier_order_id`, unique `idempotency_key`, `attempt_count`,
  `next_poll_at`, `deadline_at`, `last_error_code`, `actual_cost_halalah`, `claimed_at`,
  `completed_at`) and **nothing in `app/` writes them**. Seven `OrderStatus` values and
  seventeen `OrderHoldReason` values exist with Arabic copy pinned by parity tests.
  `order_item_secrets` holds EA credentials encrypted. `notification_deliveries` exists unused.
- **Accounts and access**: FFT and UTT API credentials move into the store's `.env`. The keys in
  use today are burned — committed to the tracker's git history and to an n8n export — and are
  rotated before anything ships.
- **Constraints**: Hostinger shared hosting. One-minute cron, `queue:work --stop-when-empty
  --max-time=55`, no supervisor and no daemon (`routes/console.php:31-41`), session driver
  `database`, Redis forbidden. FC 27 is not yet taking orders and FC 26 is ending, so there is
  no live traffic to migrate and no parallel-running requirement.
- **Success**: a customer whose automated order stops is told so without opening anything, can
  fix it themselves in one screen, and never sees a Google Sheet, a second domain, or a second
  status vocabulary.

## The problem this replaces

Today an Arab UT order and the work happening at a supplier are joined by a row in a Google
Sheet (`'ARAB UT'!C:J` — order number, supplier order id, challenge ids, supplier name), read
positionally by a separate PHP app that also calls FFT and UTT directly from the customer's
page. That contradicts the store's own Decision 41 (`docs/product/discovery-record.md:330`):
"Laravel and MariaDB are the authoritative system for … orders, wallets, fulfillment state …
Google Sheets is optional export/reporting only and must not be required for commerce or
fulfillment to continue."

The Sheet row and a `fulfillment_jobs` row are the same fact in two places. Writing the row into
the store instead of the Sheet costs n8n one node — an HTTP call where a Sheets call stands —
and removes the Sheet from the path permanently.

## Decisions

### Ownership

1. **n8n places, Laravel tracks.** n8n keeps the mature `Fulfillment v14` behaviour: it receives
   a paid order, places it at FFT or UTT, and **chooses which supplier** at placement time
   (it already prices against both). Laravel owns everything after placement.
2. **n8n reports the reference back.** On a successful placement n8n calls an authenticated
   store endpoint with the supplier and the supplier order id. That row lands in
   `fulfillment_jobs`. The Google Sheet leaves the fulfillment path.
3. **Laravel calls FFT and UTT directly** for status reads and for customer self-service
   actions. Supplier credentials live in the store's `.env` and are validated on read the way
   `PublishOrderPaidEvent::configuration()` validates the n8n publisher.

### What the customer sees

4. **Two status layers.** The seven `OrderStatus` values stay exactly as they are and remain the
   spine — they drive lists, receipts, cashback, admin permissions and the legal transition
   matrix. Underneath them the tracking page shows the supplier's own detail, the required
   action, and the progress numbers. One vocabulary with a detail layer, not two vocabularies.
5. **`refunded` is no longer folded into `cancelled` for the customer.** `OrderStatus::forCustomer()`
   keeps folding `Received` into `InProgress` and stops folding `Refunded`. A customer who paid
   and was paid back sees تم الاسترجاع, because ملغي reads as "this never happened".
6. **Progress is not a status.** Coins delivered of coins ordered, challenges solved of
   challenges requested, and the ETA badge are their own concept and are never expressed as an
   `OrderStatus`.
7. **The seventeen hold-reason texts get a copy pass.** They all end in "ثم أخبرنا", which was
   right when a human had to intervene. With self-service they must end in the button the
   customer is about to press. Mohamed reviews the seventeen before they ship.
8. **Manual services show status only.** Objectives, Rivals and FUT Champions have no supplier
   and no progress feed; they show their `OrderStatus` and nothing resembling a step timeline.

### Access

9. **Two doors to one page.** The tracking view is the existing
   `/my-account/orders/{order_number}` page, behind `auth` and scoped to the owner by
   `OrderHandle::resolveForCustomer`. A signed per-order link opens the same page without the
   account shell, for the WhatsApp message.
10. **The signed link does not expire.** Owner decision. It stays readable for the life of the
    order; its *actions* stop once the order reaches a terminal state, because there is nothing
    left to act on. It grants that one order and nothing else — not the account, not other
    orders, not the wallet.
11. **Session lifetime 120 minutes → 30 days**, and the Admin MFA gate
    (`app/Http/Middleware/EnsureAdminMfa.php:25`) accepts a valid trusted device instead of the
    session flag alone. The trusted-device registry already lasts 30 days
    (`app/Auth/TrustedDeviceRegistry.php:25`); only the Admin gate ignored it, which is why a
    two-hour-old session re-prompted for TOTP.
12. **Self-service survives and is the point.** Correcting account credentials, resuming a
    stopped delivery and retrying a failed challenge all stay, and go through Laravel to the
    supplier. The new credentials are written back into `order_item_secrets` and the access is
    logged, which the current tracker does not do.

### Freshness

13. **Read on open and sweep on cron.** Opening the page triggers a live supplier read cached
    about sixty seconds. Separately a minute-cron sweep walks non-terminal jobs, detects stalls,
    and drives the customer notification. The first keeps the page honest; the second is what
    lets a customer be told their order stopped without looking.
14. **Notifications are sent by the store**, not by n8n: the store is what noticed the change,
    it already has Whapi wired for OTP, and `notification_deliveries` exists for exactly this
    de-duplication. The message catalogue is ported from Mohamed's existing n8n order-status
    workflow rather than written fresh.

### Failure

15. **A failed placement retries, then escalates to Mohamed.** Bounded attempts with backoff on
    the `fulfillment_jobs` row, then an operational alert. The customer keeps seeing قيد التنفيذ
    throughout, because a supplier being down is not something they can act on. Only failures
    the customer can actually fix move the item to `waiting_for_customer`.

### Operations

16. **An Admin fulfillment screen comes after the base works** — every item currently at a
    supplier in one place, with age, stall, failure, actual cost and a retry control. Built in
    the existing Admin with its permissions and audit, not as a separate surface. A queue screen
    over an empty table is worth nothing, so it follows the two slices above it.

## The translation layer

The single most load-bearing new component. It maps one supplier observation to three things:

| Out | Meaning |
| --- | --- |
| `OrderStatus` | one of the seven |
| `OrderHoldReason` or none | why it stopped, in the customer's language |
| actionable | whether the customer can do something about it |

The `actionable` flag lives **here**, not on `OrderHoldReason`. The enum is untouched. This is
what decides whether the page renders an action box with buttons or an informational notice
with none — the distinction the current tracker draws correctly from supplier codes and the
store currently cannot draw at all.

Inputs it must cover, all present in the tracker today: FFT `status`, `accountCheck` and
`economyState`; UTT `statusOrder` (already normalised into the FFT shape by
`mapUTTtoFFTFormat`); and the SBC `sbcStatus` set. Supplier codes are stored on the job for
diagnosis and are never rendered to a customer.

## Explicitly out of scope

- Rebuilding or redesigning the tracking UI. It is ported.
- Rewriting `Fulfillment v14`. It is adapted, and only where it spoke to Salla.
- Adding any `OrderStatus` value.
- Changing `OrderHoldReason` membership (the copy changes; the cases do not).
- Running the old tracker in parallel. Its write endpoints and `admin-links.php` are closed
  before this work lands, independently of it.

## Security items that are not optional

These are live today and are not gated on any decision above.

- Rotate the FFT and UTT API keys. Both are in the tracker's committed git history
  (`REVIEW_2026-07-03.md:78-90`) and one is in an n8n export
  (`automation/n8n/sbc-catalog-v1/README.md`).
- Close the tracker's write endpoints (`mode=update`, `mode=resume`, `mode=sbc-retry`,
  `mode=sbc-edit`) and `admin-links.php`, which serves HTTP 200 unauthenticated when no
  credential file exists and is the issuer of the tokens those endpoints trust.
- Neither n8n workflow is under version control. Both exports are committed under
  `automation/n8n/` before either is modified.
