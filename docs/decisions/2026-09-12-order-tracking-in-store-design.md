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
- **Existing data**: `fulfillment_jobs` already exists with the placement columns this needs
  (`supplier`, `supplier_order_id`, unique `idempotency_key`, `attempt_count`, `next_poll_at`,
  `deadline_at`, `last_error_code`, `actual_cost_halalah`, `claimed_at`, `completed_at`), and
  `fulfillment_attempts` beside it with its own schema. **No code creates or updates either**;
  the only write path that exists is a delete, in
  `app/Actions/Orders/PurgeDeadCancelledOrders.php:167`. What the table does *not* have is
  anywhere to keep a supplier observation — no progress counters, no observation timestamp, no
  phase, no structured hold or action fields — so a migration is part of this work, not an
  assumption it can be skipped. Seven `OrderStatus` values and seventeen `OrderHoldReason` values
  exist in both locales; the parity tests assert membership and non-empty translations, they do
  not pin the exact wording. `order_item_secrets` holds EA credentials encrypted.
  `notification_deliveries` exists with no sender.
- **Accounts and access**: FFT and UTT API credentials move into the store's `.env`. The keys in
  use today are burned — committed to the tracker's git history and to an n8n export — and are
  rotated before anything ships.
- **Constraints**: Hostinger shared hosting. One-minute cron, `queue:work --stop-when-empty
  --max-time=55`, no supervisor and no daemon (`routes/console.php:31-41`), session driver
  `database`, Redis forbidden. FC 27 is not yet taking orders and FC 26 is ending, so by the
  owner's account there is no live traffic to migrate and no parallel-running requirement. That
  is an operational statement, not something this repo can prove: before the Sheet writer is
  removed, confirm against the supplier dashboards and the Sheet that no unresolved job and no
  paid order without a reference is outstanding, and keep a manual recovery path for any that is.
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
2. **n8n reports the reference back.** On a successful placement n8n calls an authenticated store
   endpoint that binds the supplier and the supplier order id to the order item, landing a
   `fulfillment_jobs` row. The Google Sheet leaves the fulfillment path.

   **Retrying a failed placement and alerting Mohamed stay inside n8n**, where they already work
   (owner, 2026-09-12: "الـ n8n كده كده لما بيفشل بيعيد التحقق وبينبهني وكل ده موجود"). The store
   does not rebuild that. Two store-side obligations remain, and they are not the same thing:

   - **The callback is idempotent**, keyed so that n8n retrying it is a no-op and a reference
     already bound to a different item is rejected. n8n retrying is normal, not exceptional.
   - **The store alarms on silence.** An automated, paid order item with no placement row after a
     bounded wait is surfaced to Mohamed. This covers the case n8n cannot see: it placed
     successfully and its callback never arrived. Without it that order is paid for and invisible
     to everyone, which is precisely the failure the Sheet used to make loud.
3. **n8n polls the suppliers; Laravel calls them only when a customer presses something.**
   Revised 2026-09-12 after measuring both machines. The two jobs have opposite shapes:

   - **Polling** is high-volume, continuous, and entirely network wait. It runs on the VPS that
     already hosts n8n — Hostinger KVM 2, 2 vCPU and 8 GB, idling at 1.2–2.6% CPU and 25% memory
     over the last twenty-nine hours, up 220 days. A schedule-triggered workflow asks the store
     what is open, reads the suppliers, and posts observations back.
   - **Customer actions** — correct credentials, resume, retry — are rare, user-triggered, and
     need an answer while the customer is looking at the screen. Laravel calls the supplier
     directly for these.

   The store remains the source of truth either way: n8n reads suppliers and reports, it does not
   hold state. What this avoids is the real bottleneck: the store is on shared hosting with a
   one-minute cron, where every supplier call holds a PHP process for up to twelve seconds. Fifty
   live orders checked every two minutes is about twenty-five calls a minute, which does not fit
   one sequential cron tick at poor supplier latency — while a nearly idle VPS sits beside it.

   Supplier credentials therefore live in **both** n8n and the store's `.env`, validated on read
   the way `PublishOrderPaidEvent::configuration()` validates the n8n publisher.

### What the customer sees

4. **Two status layers.** The seven `OrderStatus` values stay exactly as they are and remain the
   spine — they drive lists, receipts, cashback, admin permissions and the legal transition
   matrix. Underneath them the tracking page shows the supplier's own detail, the required
   action, and the progress numbers. One vocabulary with a detail layer, not two vocabularies.
5. **`refunded` is no longer folded into `cancelled` for the customer.** A customer who paid and
   was paid back sees تم الاسترجاع, because ملغي reads as "this never happened". Both enums fold
   it today — `OrderStatus::forCustomer()` and `OrderItemStatus::forCustomer():16` — and the live
   order page reads both (`app/Account/Queries/ReadLiveOrder.php:92`, `:143`), so both change.
   The cancellation wording in `lang/{ar,en}/orders.php:8` needs revising with it, and the raw
   status must keep driving the financial logic untouched. The frontend already supports the
   value: the TypeScript union, both locales' labels, the closed-page branch and the CSS all
   carry `refunded` already.
6. **Progress is not a status.** Coins delivered of coins ordered, challenges solved of
   challenges requested, and the ETA badge are their own concept and are never expressed as an
   `OrderStatus`.
7. **The hold-reason copy is split from the action copy.** The reason text says what happened;
   the action copy says what to press. Today several reasons end in "ثم أخبرنا" — right when a
   human had to intervene, wrong once the customer has a button — but not all of them do:
   `credentials` already asks for an update, and `ea_servers`, `store_stock`, `maintenance` and
   `connection` correctly describe automatic recovery with nothing for the customer to do
   (`lang/ar/orders.php:20`, `:21`, `:26`, `:32`, `:35`). Rewriting all seventeen around a button
   would be wrong: it would attach an action to states that have none, and to manual-service
   orders that have no supplier at all. So the action copy becomes contextual and the reason text
   changes only where it actually says "tell us". Mohamed reviews the changed ones.

   Note that existing hold messages are frozen into `order_status_history` at transition time by
   design (`app/Admin/Actions/TransitionAdminOrder.php:149`), so editing a translation does not
   rewrite what a customer was already told. That is intended and stays.
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

13. **The page renders stored state and refreshes asynchronously.** It never blocks on a
    supplier. The page already reloads itself every thirty seconds
    (`resources/js/pages/account/live-order.tsx:34`), so a supplier call inside the render is not
    a call "on open" — it is a call every thirty seconds per viewer, at up to twelve seconds
    each, inside `LiveOrderController`'s synchronous path. One slow supplier would then hold the
    page hostage and one outage would queue every viewer behind it.

    So: render the last stored observation immediately, with its age. The observations arrive
    from the n8n sweep (decision 3), not from the render path, so the page's thirty-second reload
    is a database read and costs nothing outside.

    The sweep is a **schedule-triggered** workflow, not the wait-in-execution loop v14 uses today.
    That matters twice over: it keeps each n8n execution seconds long instead of hours — the whole
    reason the current workflow is hard to debug — and it is what lets a customer be told their
    order stopped without opening anything.

    Stall detection stays in the store, because it is a query over observation age, not an HTTP
    call: an open job whose last observation is older than its expected cadence is stalled, and
    the store can see that without talking to anyone.
14. **Notifications are sent by the store**, not by n8n: the store is what noticed the change,
    it already has Whapi wired for OTP, and `notification_deliveries` exists for exactly this
    de-duplication. The message catalogue is ported from Mohamed's existing n8n order-status
    workflow rather than written fresh.

### Failure

15. **A failed placement is n8n's to retry and escalate** (see decision 2). What the store owns is
    the customer's view of it: قيد التنفيذ throughout, because a supplier being down is not
    something a customer can act on. Only failures the customer can actually fix move the item to
    `waiting_for_customer`.

16. **A supplier observation never overrides a human.** The translation layer produces a proposed
    status; applying it is a separate, transactional reconciliation that locks the order and its
    items the way `TransitionAdminOrder` does, respects an admin's manual hold, refuses to move a
    terminal order, and aggregates item states to the order conservatively. Completion side
    effects — cashback, the review invitation — must fire exactly once whichever path completes
    the order. And a supplier reporting a cancelled job never means the customer's payment was
    refunded; `refunded` still comes only from a verified refund.

### Operations

17. **An Admin fulfillment screen comes after the base works** — every item currently at a
    supplier in one place, with age, stall, failure, actual cost and a retry control. Built in
    the existing Admin with its permissions and audit, not as a separate surface. A queue screen
    over an empty table is worth nothing, so it follows the two slices above it.

## What happens to the two n8n workflows

Both exports are committed under `automation/n8n/` as the Salla-era baseline:
`fulfillment-v14/workflow-v14-salla.json` (117 nodes, ~64KB of JavaScript) and
`customer-notifier-v2/workflow-v2-salla.json`.

**`Fulfillment v14` shrinks by roughly eighty per cent, and the shrinking is a consequence of the
decisions above rather than a separate refactor.** What leaves: the three polling loops (five wait
nodes, three evaluate code nodes, three prepare-poll nodes, and the IF branches around them — the
loops are replaced by decision 13's schedule trigger); ten Google Sheets nodes; six Supabase
nodes; eight Salla `updateStatus` nodes; and twenty-three of its forty HTTP calls, which are
WhatsApp sends the store now owns. What stays is the part that is genuinely about suppliers —
stock, cooldown, prediction, the two supplier-decision engines and `SBC: Match & Validate`, about
21KB of the 64KB.

**It is restructured by phase, not by product.** Splitting it into a coins workflow and a
challenge workflow would duplicate the coins logic, because a Challenge order ships coins too
(owner, 2026-09-12; see the Challenges section of `CONTEXT.md`). So:

| Workflow | Trigger | Job |
| --- | --- | --- |
| `ship-coins` | the store, on a paid automated item | pick a supplier, place the shipment, report the reference |
| `solve-challenge` | the store, once the shipment has landed | submit the challenge, report the reference |
| `poll-open-jobs` | schedule | ask the store what is open, read the suppliers, post observations back |

The store therefore exposes three endpoints to n8n: one to receive a placement reference, one to
receive observations, and one read for "what is open". All on the existing HMAC scheme.

**The purchase budget comes from the store, and `ArabUT Price Settings` goes with the rest of the
Sheets.** v14 reads that sheet to decide the maximum it will pay a supplier, with a stale-sheet
alert bolted on (`Read Price Settings`, `Price Fallback?`, `WA: Price Fallback`). The store
already owns pricing — the `coins-pricing-v2` workflow feeds it and `ApplyCoinsPricingRun` stores
it — so the budget travels in the placement request and the second price source disappears.

**`Customer Notifier` is absorbed entirely.** Its status detection matches Salla's Arabic status
names by substring, which has nothing to map onto after the migration; its de-duplication lives
in `$getWorkflowStaticData` and so does not survive an n8n restart; and it logs to Supabase. All
three are replaced by the canonical status, `notification_deliveries`, and our own database. What
is worth keeping is its message catalogue, which is good and already speaks in terms of buttons
("افتح رابط طلبك واضغط زر تشغيل الطلب") rather than "reply to us" — consistent with decision 7.
The `Forward Status Update` node that chains v14 into it goes too.

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

**An unknown supplier code is not "in progress".** The tracker's tables are evidence of what the
suppliers did, not a guarantee of what they can still return, and suppliers add codes without
telling us. Mapping an unrecognised code to `in_progress` would silently clear a real customer
hold or walk a finished order backwards. An unknown code instead **keeps the last known canonical
state**, marks the observation unsupported, disables every action it cannot vouch for, and raises
an alert so the map gets extended. The same applies to a supplier response that arrives
malformed.

**`actionable` is a boolean; the set of offered actions is not.** Which buttons appear depends on
canonical state, the supplier's own capability for that state, the delivery phase, and
authorisation — the tracker itself keeps edit and resume permissions separate and gates SBC edits
on a narrower list still. The layer therefore yields an explicit set of allowed actions, and every
one of them is re-authorised server-side when pressed. A single boolean deciding what a customer
may do to their EA account is not enough.

## Explicitly out of scope

- Rebuilding or redesigning the tracking UI. It is ported.
- Rewriting `Fulfillment v14`. It is adapted, and only where it spoke to Salla.
- Adding any `OrderStatus` value.
- Changing `OrderHoldReason` membership (the copy changes; the cases do not).
- Editing the tracker repo. Owner instruction, 2026-09-12: leave it alone and retire it soon
  instead. The consequence is that the `admin-links.php` exposure below stays open until the site
  comes down, which is why the manual-order work that makes retirement possible is on the critical
  path and not deferred.

## Manual orders, and why they are one feature and not two

Mohamed needs to hand a customer a tracking link when payment happened outside checkout — a bank
transfer, a gift, "any circumstance" — and today `admin-links.php` is the only way to do it.

That capability moves into the store's Admin, where it inherits MFA, a permission and staff audit
instead of a page whose authentication is opt-in. And it arrives as **one screen, with the money
optional**, not as a manual-order feature plus a separate link minter. The reason is structural:
`fulfillment_jobs.order_item_id` is NOT NULL and bound to an order item, so a link pointing at a
supplier order with no store order has nowhere to live. Making that column nullable to allow it
would create jobs belonging to nothing — which is exactly the shape that let the tracker drift out
of the store's world in the first place.

So a bank transfer is an amount plus a manual payment record, a gift is neither, and an order
Mohamed placed at a supplier by hand is one where he pastes the reference instead of dispatching
to n8n. All three are the same order, with the same `AUT-` number, the same fulfillment path, the
same tracking page and the same signed link.

Because it touches money, the form's rules need owner approval before it is built: which services
it may create, whether it can create a customer or only pick one, and whether a manual order earns
cashback and loyalty spend. The `salla_import` channel is already excluded from both, and the same
reasoning applies here.

## Security items that are not optional

These are live today and are not gated on any decision above.

- Rotate the FFT and UTT API keys. Both are in the tracker's committed git history
  (`REVIEW_2026-07-03.md:78-90`) and one is in an n8n export
  (`automation/n8n/sbc-catalog-v1/README.md`). The new keys go to **every** authorised consumer,
  not only the store: n8n still places orders and still runs the coins-pricing and SBC-catalog
  workflows against these suppliers. Rotating into the store alone would break the placement path
  this design depends on.
- The tracker's write endpoints (`mode=update`, `mode=resume`, `mode=sbc-retry`, `mode=sbc-edit`)
  and `admin-links.php` are the open exposure: that page serves HTTP 200 unauthenticated whenever
  no credential file exists, and it issues the tokens those endpoints trust. **By owner
  instruction the tracker is not edited**, so this is closed by retiring the site rather than by
  patching it, and the window stays open until then. The mechanism to close it sooner already
  exists on that host if Mohamed chooses to: run `tools/set-admin-password.php` from the CLI and
  set `adminAuth.requireWhenUnset` to `true` (`config.php:111`).
- Neither n8n workflow is under version control. Both exports are committed under
  `automation/n8n/` before either is modified.
