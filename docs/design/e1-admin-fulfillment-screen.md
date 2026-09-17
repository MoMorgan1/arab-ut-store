# E1 — the admin fulfillment screen

Research and design decisions for slice **E1** of
[`docs/plans/2026-09-12-order-tracking-in-store.md`](../plans/2026-09-12-order-tracking-in-store.md)
(`## Slice E — operations (later)`, lines 997–1001).

No code has been written. This note and the canvas beside it
(`docs/design/e1-admin-fulfillment-screen.canvas.html`) are the whole of E1 up to the owner
gate in `CLAUDE.md`: inspect, canvas, approve, *then* build.

**Owner decisions, 2026-09-17.** Six questions were asked; all six are now answered and folded in.
Q1 — the admin gets a working re-send button (§6). Q5 — Staff may see the screen but not the cost,
so it is two views (§4). Q2 — the bold age is time since paid. Q3 — `last_error` is left alone in
this slice and recorded as owed work (§1.4). Q4 — no `failed` filter. Q6 — no manual alarm resolve.

---

## 1. What the plan asks for, and what the database can answer

The plan names six things: **supplier, age, stall, failure, actual cost, retry**.

| Plan's column | Verdict | Where it comes from |
| --- | --- | --- |
| **supplier** | **Real** | `fulfillment_jobs.supplier` and `fulfillment_placements.supplier` |
| **age** | **Real ×3** — owner picked time-since-paid for the bold number | `orders.paid_at`, `fulfillment_placements.placed_at`, `fulfillment_jobs.observed_at` |
| **stall** | **Real** | `fulfillment_jobs.hold_reason` / `hold_tone` / `presentation`, plus open `fulfillment_alarms` |
| **failure** | **Not as the plan words it.** `last_error`/`last_error_code` are dead columns | diagnosis comes from `observed_state` + `observation.status` + `poll_failure_count` |
| **actual cost** | **Real writer, conditional data** — and now Admin-only | `fulfillment_jobs.actual_cost_halalah` |
| **retry** | **Built in E1 (Q1)**, but it is two different writes, not one | §6 |

### 1.1 supplier — real

`fulfillment_jobs.supplier` is set the moment a placement is recorded
(`app/Actions/Fulfillment/RecordSupplierPlacement.php:224`, `:239`) and is a `Supplier` enum
cast (`app/Models/FulfillmentJob.php:52`) with exactly two cases, `fft` and `utt`
(`app/Enums/Supplier.php:14-15`).

One caveat the screen must respect. The job column is **a mirror of the first placement only** —
`database/migrations/2026_09_12_000003_create_fulfillment_placements.php:30-35` dropped the
unique index on the job precisely because "the job mirror only advertises the first placement".
A challenge item has two placements, and the second one's supplier lives in
`fulfillment_placements`, not on the job. `ObserveFulfillmentJob` already reads it that way
(`app/Actions/Fulfillment/ObserveFulfillmentJob.php:66-71`). **A list column that reads only
`fulfillment_jobs.supplier` will show the wrong supplier for the challenge phase of an SBC item.**

The asymmetry is also load-bearing and worth showing: FFT serves coins *and* challenges, UTT
serves coins only (`app/Enums/Supplier.php:5-11`, `:23-26`).

### 1.2 age — three real clocks; the bold one is time since paid

| Clock | Column | Question it answers |
| --- | --- | --- |
| **Waiting** (bold) | `orders.paid_at` | how long has this person been waiting |
| At the supplier | `fulfillment_placements.placed_at` (`…000003_create_fulfillment_placements.php:22`) | how long has the supplier had it |
| Reading age | `fulfillment_jobs.observed_at` (`…000001_add_supplier_observations_to_fulfillment_jobs.php:15`) | how stale is what this row is telling me |

**Owner decision (Q2):** the bold number is time since paid — "the one a customer feels and the
one the unplaced alarm is measured from". That second half is literally true of the code: the
`unplaced` sweep selects on `orders.paid_at` and nothing else
(`app/Support/Orders/AwaitingPlacement.php:94-98`), so for the rows that matter most it is the
*only* clock that exists.

The other two stay on the row as a secondary line, because `AGENTS.md` **Failures** rule 1
requires a screen standing on stored state to carry "the last good value **and its age on it**".
A row whose `observed_at` is two hours old is reporting two-hour-old news however fresh its
placement.

### 1.3 stall — real, and it is two different things

Two unrelated conditions both read as "stalled" to an operator, and conflating them is a design
error the alarm table already went out of its way to avoid:

**(a) The supplier told us it is stuck.** `ApplySupplierObservation` writes `hold_reason`,
`hold_tone`, `presentation` and `allowed_actions` together, as one fact
(`app/Actions/Fulfillment/ApplySupplierObservation.php:322-341` — the comment there records
why: writing some and preserving others "put an amber ring around the word Transferring").
`OrderHoldReason` has 21 cases (`app/Enums/OrderHoldReason.php:21-49`); eleven of them ask the
customer for something and seven say we are handling it (`:8-13`). That split is the one an
operator actually needs — *is this waiting on me or on them?*

**(b) Nobody has heard anything.** `fulfillment_alarms` (`…2026_09_16_000001_create_fulfillment_alarms.php`)
holds one open row per item per kind, with exactly two kinds
(`app/Enums/FulfillmentAlarmKind.php`):

- `unplaced` — paid, automated, and **no fulfillment job at all**. Invisible to n8n, invisible
  to the poller (`SweepFulfillmentAlarms.php:56-66`, predicate in
  `app/Support/Orders/AwaitingPlacement.php:79-104`). Raised after
  `services.suppliers.alarm.unplaced_after_minutes` (default 15) and only inside a 48-hour
  window (`SweepFulfillmentAlarms.php:200-213`).
- `silent` — placed, but `poll_failure_count >= services.suppliers.alarm.silent_after_failures`
  (default 6) (`SweepFulfillmentAlarms.php:118-150`).

**Owner decision (Q6): no manual resolve control.** `fulfillment_alarms.resolved_at` is written
only by the sweep, when the condition itself ends (`SweepFulfillmentAlarms.php:191-198`). An
alarm a human can dismiss without the item changing is an alarm nobody trusts. An item fixed
outside the store therefore stays listed until the sweep agrees, which is the correct behaviour
and not a gap.

### 1.4 failure — the plan's column has no data behind it, and stays that way in E1

`fulfillment_jobs` carries `last_error_code` and `last_error`
(`…2026_08_08_000004_create_fulfillment_tables.php:52-53`). **Nothing in the application ever
writes a non-null value to either.** The only writers set them to `null`:
`RecordSupplierPlacement.php:227-228` and `:242-243`. A grep across `app/` finds no other
assignment.

Three more dead surfaces sit beside it:

- **`fulfillment_attempts`** (`…000004_create_fulfillment_tables.php:62-76`) has a model
  (`app/Models/FulfillmentAttempt.php`) and a `HasMany` on the job
  (`app/Models/FulfillmentJob.php:121-125`) and **no writer anywhere in `app/`**. Its `error`,
  `request_metadata`, `response_metadata` and per-attempt `actual_cost_halalah` columns are all
  unreachable.
- **`fulfillment_jobs.attempt_count`** is cast (`app/Models/FulfillmentJob.php:64`) and never
  incremented. Every `attempt_count` write in `app/` belongs to `AgentTurn`, a different model.
- **`FulfillmentStatus::Failed`** is **never assigned**, anywhere — not in `app/`, not in
  `database/`, not in `tests/`. It appears only as a read: an exclusion in the poll selection
  (`app/Console/Commands/PollFulfillmentJobs.php:251`), an exclusion in the alarm sweep
  (`SweepFulfillmentAlarms.php:124`), and a terminal check (`PollFulfillmentJobs.php:408`).

**Owner decision (Q3): leave `last_error` alone in this slice.** Filling it is a change to the
fulfillment write path, not to a screen, and E1 must not smuggle one in. It is recorded here as
**owed work**: the read-failure reason already exists in memory as
`ObservationOutcome->reason` (`app/Actions/Fulfillment/ObservationOutcome.php:20`) and is
discarded after the log line; persisting it, and deciding whether
`FulfillmentStatus::Failed` should ever be set, belongs to a later fulfillment slice.

**Owner decision (Q4): no `failed` filter chip.** It could never match a row. An absent filter is
better than one that silently always returns nothing.

What the screen shows instead — the **Signal** column:

| Signal | Column | Written by |
| --- | --- | --- |
| The supplier's own last status code | `fulfillment_jobs.observed_state` | `ApplySupplierObservation.php:335` — and `TranslatedState` says it plainly: "Supplier codes are carried in `$observedState` **for diagnosis only**" (`app/Suppliers/Translation/TranslatedState.php:14`) |
| The masked supplier payload | `fulfillment_jobs.observation` (JSON) | `ApplySupplierObservation.php:369`, allowlisted to 25 keys at `:440-472` |
| Consecutive fruitless reads | `fulfillment_jobs.poll_failure_count` | `PollFulfillmentJobs.php:350` (reset) and `:360-362` (increment) |
| The classified stall | `fulfillment_jobs.hold_reason` | `ApplySupplierObservation.php:334` |

### 1.5 actual cost — a real writer with two preconditions, now Admin-only

`fulfillment_jobs.actual_cost_halalah` is written at
`app/Actions/Fulfillment/ApplySupplierObservation.php:307`, from
`app/Fulfillment/SupplierCostInHalalah.php:27-44`. It requires **both**:

1. The supplier payload carries a positive cost — `toPay` for FFT, `_costEur` for UTT
   (`SupplierCostInHalalah.php:29-35`); and
2. there is an **applied** `PriceRun` whose payload holds a positive
   `observations.ratioEuroUsd` (`SupplierCostInHalalah.php:46-56`). That key survives the
   apply-time allowlist (`app/Actions/Pricing/ApplyCoinsPricingRun.php:132`).

With no applied run, **nothing is written** — deliberately: "a cost we cannot convert is not a
cost we should invent" (`SupplierCostInHalalah.php:16-17`). Pricing runs are currently failing
and carrying no `tierCosts`, so expect this column to be **null on most rows right now**. It
renders as "not reported", never as `0.00`.

The cost is read from the **raw** payload before masking and kept on its own column; the stored
`observation` JSON deliberately never carries it (`ApplySupplierObservation.php:52-54`, and
`toPay`/`_costEur` are absent from `STORABLE_OBSERVATION_KEYS` at `:440-472`). That separation is
what makes §4's Staff view cheap to enforce: the cost lives in one column, not scattered through
a JSON blob.

### 1.6 retry — built in E1, but it is two different writes

See §6. The short version: "re-send" means one thing for an item that was never placed and a
completely different thing for an item that already sits at a supplier, and offering the wrong
one is how you pay for the same shipment twice.

---

## 2. Which existing screen this extends

**`/admin/orders` is the parent, and the pattern to copy exactly.**

| Piece | File |
| --- | --- |
| Page shell, toolbar/table/pagination composition, query-failure alert | `resources/js/pages/admin/orders/index.tsx` |
| Desktop semantic table + mobile card list + shared empty state | `resources/js/components/admin/orders/admin-orders-table.tsx` |
| Compact record summary for phone | `resources/js/components/admin/orders/admin-orders-mobile-card.tsx` |
| Status pill | `resources/js/components/admin/admin-badge.tsx`, `admin-order-status.ts` |
| Money rendering | `resources/js/components/admin/admin-money.ts` |
| Server query shape, allowlisted sort, projection | `app/Admin/Queries/ListAdminOrders.php` |
| **Confirm dialog for a money action** | `resources/js/components/admin/orders/admin-order-refund-control.tsx` |

That last one is the precedent for §6's confirm step: a shadcn `Dialog`, a reason control, the
mutation button disabled while in flight with a `Spinner`, and an `Alert` carrying the outcome.

The screen also has an **entry point already in production**. The overview's queue-health banner
counts open alarms — `silentItems` and `oldestSilenceAt` in
`app/Admin/Queries/ReadQueueHealth.php:151-170`, rendered at
`resources/js/components/admin/admin-queue-health.tsx:155-180`. It is a number with nowhere to
go. E1 is where it should link, which satisfies `architecture.md`'s "do not add an empty
navigation destination for a deferred feature".

**Placement:** a top-level `Fulfillment` entry in `AdminShell` navigation
(`app/Admin/Presenters/AdminShell.php:29-141`), between `Orders` and `Customers`, plus the
banner link.

---

## 3. Scope: this is a list of *items*, not a list of jobs

Confirmed by the owner, 2026-09-17.

"Every item currently at a supplier" sounds like `SELECT * FROM fulfillment_jobs`. But an
`unplaced` alarm — the one kind n8n **cannot** report, the whole reason `fulfillment_alarms`
exists (`app/Admin/Queries/ReadQueueHealth.php:27-30`) — is by definition an item with **no
fulfillment job row** (`AwaitingPlacement.php:91`, `whereDoesntHave('fulfillmentJob')`).

A jobs-only screen would be blind to exactly the failure the slice exists to surface, and blind
in the same shape as the failure it is replacing: "no failed job, no failed event, nothing
waiting anywhere".

So the screen lists **order items in fulfillment**, left-joined to their job. An unplaced item
renders as a row with an em dash in every supplier column and an `Unplaced` alarm pill. The row
is not a hole in the table; the hole *is* the finding. It is also the row with the most
consequential action on it (§6.2), so it is shown on the canvas both as a list row and as an
opened detail.

---

## 4. Permissions — one screen, two views

**Owner decision (Q5):** Staff may see the screen; Staff may not see the cost. That is two views
of one screen, so it is three permissions, not one.

| Permission | Admin | Staff | Gates |
| --- | --- | --- | --- |
| `fulfillment.view` | yes | **yes** | the screen: order, service, supplier, both ages, state, stall, signal |
| `fulfillment.view_cost` | yes | **no** | the Cost column, and the cost sort key |
| `fulfillment.act` | yes | **no** | the re-send / resume / retry-challenge control (§6) |

### 4.1 The cost column is withheld by the server, not hidden by the browser

`SKILL.md:32` — "Laravel is the authorization and business-logic boundary. React never decides
permission." So:

- the Query does **not select** `fulfillment_jobs.actual_cost_halalah` when the actor lacks
  `fulfillment.view_cost`;
- the Presenter does not emit the `cost` key at all — not `null`, **absent**, so a Staff
  payload inspected in the network tab contains no trace of it;
- `cost` is removed from the sort allowlist for that actor, and a Staff request naming
  `sort=actual_cost` **fails validation** rather than silently falling back. A silent fallback
  is an oracle: it tells the caller the column exists.

§1.5's separation makes this cheap — the cost is one integer column, and the `observation` JSON
beside it is already guaranteed never to carry a cost figure.

The Staff view shows the column's absence honestly: the table simply has six columns instead of
seven, and the Staff artboard on the canvas is drawn that way so the difference is reviewed
rather than discovered. A column that silently disappears is the thing that gets rebuilt twice.

### 4.2 Why `fulfillment.act` is Admin-only

Staff can see the screen but not act on it. Three reasons:

1. **It spends money at a supplier.** `permissions.md` already draws the Admin/Staff line exactly
   here: Staff hold `orders.cancel` but not `orders.refund`, not `payments.refund`, not
   `wallet.adjust`. A re-send is a purchase, and it belongs on the Admin side of a line the
   matrix has already drawn.
2. **The actor cannot see what it costs.** The owner's own answer to Q5 bars Staff from the cost
   column. Handing someone a button that spends an amount they are forbidden to see is
   incoherent — they could not tell a 60-riyal re-send from a 600-riyal one.
3. **It causes a credential decryption.** See §6.5. `permissions.md:51-53` fixes the Staff set
   and says any expansion is an explicit owner decision. The owner expanded it to *seeing* the
   screen; reading that as also granting a spending action would be inventing a decision he did
   not make.

If Staff should act, it is a one-line matrix change — but it should be said, not assumed.

### 4.3 Enforcement

Route admission (`can:fulfillment.view`), request/policy authorization, and an Action-level
recheck are three separate layers, per `permissions.md:40-42`. `fulfillment.act` is rechecked
inside the Action as well as at the route, because it is the high-risk one.

---

## 5. The server contract

Per `.agents/skills/arab-ut-admin/references/tables.md`: one Request validator, one Query object,
explicit allowlists, unknown keys fail validation, database-side filter/sort/paginate.

### 5.1 Filter allowlist

| Key | Values | Column it filters |
| --- | --- | --- |
| `search` | string | `orders.order_number` exact, or `fulfillment_placements.supplier_order_id` exact. **Not** a `LIKE` — `ListAdminOrders::applySearch` uses exact matches (`ListAdminOrders.php:139-152`) and a wildcard over a supplier reference has no index to stand on |
| `supplier` | `fft`, `utt` — from `Supplier::values()` | `fulfillment_placements.supplier` |
| `phase` | `coins`, `challenge` — from `DeliveryPhase` | `fulfillment_placements.delivery_phase` |
| `status` | `pending`, `ready`, `in_progress`, `waiting_for_customer`, `completed`, `cancelled` — from `FulfillmentStatus`, **less `failed`** (Q4) | `fulfillment_jobs.status` |
| `alarm` | `unplaced`, `silent`, `any`, `none` — from `FulfillmentAlarmKind` plus two aggregates | open `fulfillment_alarms` |
| `hold` | the 21 `OrderHoldReason` values, plus `held` / `clear` | `fulfillment_jobs.hold_reason` |
| `service` | `coins`, `sbc` — the two automated services (`RecordSupplierPlacement.php:29`) | `order_items.service_type` |
| `paid_from`, `paid_to` | `Y-m-d` | `orders.paid_at`, parsed exactly as `ListAdminOrders::applyDateFilters` does (`:194-208`) |

No cost filter, for either role. Filtering by cost is a margin question, not an operations one,
and adding it for Admin alone would put a cost range in a Staff-visible URL.

### 5.2 Sort allowlist

| `sort` | Column | Default | Staff |
| --- | --- | --- | --- |
| `paid_at` | `orders.paid_at` | ✅ `asc` | yes |
| `placed_at` | `fulfillment_placements.placed_at` | | yes |
| `observed_at` | `fulfillment_jobs.observed_at` | | yes |
| `poll_failures` | `fulfillment_jobs.poll_failure_count` | | yes |
| `actual_cost` | `fulfillment_jobs.actual_cost_halalah` | | **no — 422** |

`direction` ∈ `asc`, `desc`. `per_page` from the existing per-page option list. A tiebreak on
`order_items.id` in the same direction, mirroring `ListAdminOrders.php:125-126`.

**Why `paid_at asc`.** Two reasons, and the first is not a preference. An `unplaced` row (§3) has
no placement at all, so `placed_at` is null for exactly the rows that matter most — sorting on it
either buries them or needs a `COALESCE` the allowlist then has to defend. `orders.paid_at` is
the one clock every row has, because being paid is what puts an item here. Second: ascending puts
the longest-waiting customer at the top, which is the order an operator works in.
`/admin/orders` defaults to `placed_at desc` because it is a ledger; this screen is a queue, and
a queue is read from the front.

### 5.3 Indexes

`fulfillment_jobs` already indexes `status`, `supplier`, `['status','next_poll_at']`,
`['status','last_viewed_at']` and `leased_until`
(`…000004_create_fulfillment_tables.php:45-58`, `…000001_add_supplier_observations…:27-28`).
`fulfillment_alarms` indexes `['resolved_at','notified_at']`
(`…2026_09_16_000001_create_fulfillment_alarms.php:31`). None serves the default
`ORDER BY orders.paid_at`. `tables.md` says to add indexes "only for verified query shapes", so
that decision belongs to implementation with an `EXPLAIN` behind it.

### 5.4 What must never reach the props

`security.md` and `audit-logging.md` apply unchanged. The `observation` JSON is already
allowlisted and email-masked server-side (`ApplySupplierObservation.php:440-472`, `:516-532`) —
the presenter passes the stored column through and adds nothing. No credential, no
`lease_token`, no raw provider metadata. Cost, when the actor may see it, is a nonnegative
minor-unit integer rendered through `formatAdminMoney`.

---

## 6. The re-send action (owner decision Q1)

> "The admin can re-send an item to the supplier."

### 6.1 It is two different writes, and the row's state decides which

This is the whole design. "Re-send" means one thing for an item nobody ever placed and something
else entirely for an item already sitting at a supplier.

| Row state | Control | What it actually does |
| --- | --- | --- |
| **No fulfillment job** (`unplaced`) | **Send to supplier** | re-opens the order's `order.paid` outbox row so the publisher delivers it again |
| Job exists, `allowed_actions` contains `resume` | **Resume at supplier** | `ResumeItemDelivery` → the supplier's own resume endpoint |
| Job exists, `allowed_actions` contains `retry_challenge` | **Retry challenge** | `RetryItemChallenge`, one challenge position |
| Job exists, no allowed action | **no control**, and the detail says why | — |

**An item that already has a placement is never "sent" again.** That is the double-spend, and the
defence is that the button does not exist, not that the server returns a 409. (It does also
return one: `RecordSupplierPlacement` refuses a second placement for the same
`(job, phase)` as `item_conflict` and a reused supplier reference as
`supplier_reference_conflict`, `RecordSupplierPlacement.php:155-217` — but a UI that relies on a
409 to stay correct is a UI that will one day meet a race it does not.)

### 6.2 "Send to supplier", precisely

The unplaced case is the valuable one, and the store already has an idempotent mechanism for it.

`EnqueueOrderPlacement` writes one outbox row per paid order with
`'idempotency_key' => 'order-paid:'.$order->id` (`EnqueueOrderPlacement.php:49`), and
`integration_events.idempotency_key` is **unique**
(`…000004_create_fulfillment_tables.php:89`). **A second `order.paid` event for the same order
cannot be inserted.** So a re-send is not a new event — it is re-opening the existing row, the
way `RequeueFailedPaidEvent` already does:

```
UPDATE integration_events
   SET status = 'pending', attempts = 0, available_at = now(), last_error = null
 WHERE id = ? AND status = ?          -- guarded, returns an affected-row count
```

(`app/Console/Commands/RequeueFailedPaidEvent.php:45-58`.)

**Why re-delivering is safe.** The stored row carries identifiers only. Everything the suppliers
need is composed *at send time* by `ComposePlacementRequest`, which re-reads `AwaitingPlacement`
and therefore **drops any item that gained a fulfillment job meanwhile**
(`ComposePlacementRequest.php:22-41`, `:62`). A re-delivered request contains only items still
unplaced; if nothing is left, `PublishOrderPaidEvent` marks the event processed and sends
nothing at all (`PublishOrderPaidEvent.php:66-72`). The duplicate request the operator fears
cannot carry a duplicate item.

**One thing the existing command cannot do.** `RequeueFailedPaidEvent` guards on
`status === 'failed'` (`:31-39`). The `unplaced` alarm's own docblock describes the opposite
case — "from its side the placement succeeded and the callback simply never arrived"
(`app/Enums/FulfillmentAlarmKind.php:19-22`) — which leaves the event `processed`, not `failed`.
So the admin action must be able to re-open a `processed` row, which the console command
deliberately refuses to do.

That is safe for the reason above, but it is a real widening and it must be written as such:
the Action takes the row's **current** status as the expected value, writes the guarded update
with it, and records the previous status in the audit metadata, so the transition
`processed → pending` is visible afterwards rather than erased.

### 6.3 The confirm step

`forms.md:17-18` — "Destructive confirmations name the record and consequence. Buttons say the
actual action, not 'Yes' or 'Submit'." The dialog therefore names the order, the item, the money
it may spend, and what will actually happen:

- **Title** — `Send AUT-1042 to a supplier`
- **Body** — how long it has waited, what the request will contain, and the honest consequence:
  a supplier will be asked to deliver this item and we will be charged for it.
- **Reason** — an **allowlisted select**, not free text. `forms.md:37-41` requires a reason code,
  and `audit-logging.md:14-16` forbids copying free-text reasons into audit metadata. Proposed
  v1 codes: `never_placed`, `callback_lost`, `supplier_stalled`, `customer_report`.
- **Confirm button** — `Send to supplier`. Not "Confirm".

### 6.4 Designing out the double press

Four layers, of which only the fourth is load-bearing:

1. **Client** — the button disables on submit and shows a `Spinner`, per `forms.md:68`. A
   convenience, not a guarantee.
2. **A per-item lock** — `Cache::lock("fulfillment-retry:item:{$item->id}")`, **refused rather
   than queued**, exactly as `ObserveFulfillmentJob.php:51-55` does it and as `AGENTS.md`
   Failures rule 5 requires. A second press while the first is in flight is told "already
   running"; it does not wait and then send again.
3. **The guarded update** — §6.2's conditional `UPDATE … WHERE status = ?` returns an
   affected-row count. Two concurrent re-opens: one affects a row, the other affects none and is
   told so truthfully.
4. **`ComposePlacementRequest` re-reads `AwaitingPlacement` at send time** — so even a genuinely
   duplicated delivery carries only items with no job, and `RecordSupplierPlacement` is
   idempotent per `(job, phase)` anyway. This is the layer that makes a double placement
   impossible rather than merely unlikely.

### 6.5 Recent password confirmation, and a non-obvious consequence

**Pressing "Send to supplier" causes the customer's EA credentials to be decrypted.**
`ComposePlacementRequest` reads `order_item_secrets`, decrypts for the request only, and writes a
`SecretAccessLog` row for each read (`ComposePlacementRequest.php:22-31`, purpose
`ACCESS_PURPOSE = 'fulfillment_placement'` at `:46`). The operator never sees the credentials,
but the press is what triggers the read.

`SKILL.md:30-31` and `forms.md:10-14` require recent password confirmation for credential
reveals and for money actions. This is both. So the route carries `EnsureAdminPassword`, the
middleware already wrapping the settings and MFA routes (`routes/admin.php:91`).

It is also the third argument for `fulfillment.act` being Admin-only (§4.2).

### 6.6 Rate limit

A new named limiter, `staff-fulfillment-action`, registered beside the others in
`app/Providers/AppServiceProvider.php`:

```php
RateLimiter::for('staff-fulfillment-action', function (Request $request): array {
    $caller = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

    return [
        Limit::perMinute(6)->by('staff-fulfillment-action-user:'.$caller),
        Limit::perMinute(2)->by('staff-fulfillment-action-item:'.$caller.':'.(string) $request->route('item')),
    ];
});
```

Two buckets, **both carrying the caller**. `AGENTS.md` Failures rule 6 and the comment at
`AppServiceProvider.php:96-101` say why the second one cannot be keyed on the item alone:
throttling runs before the controller checks who is asking, so an item-only bucket is one a
stranger can empty on someone else's behalf.

Not `staff-writes` (20/min, `AppServiceProvider.php:206`): that budget is for rows in our own
database. This one reaches an external supplier and can spend money, so it gets its own, the way
`staff-payments` does at 10/min.

### 6.7 Audit

Two events, per `audit-logging.md:33-35` — "record truthful reservation/result events; never log
completion before verification":

| Event | When |
| --- | --- |
| `fulfillment.resend_requested` | after authorization and the lock, before the write |
| `fulfillment.resend_dispatched` | the guarded update affected a row / the supplier accepted |
| `fulfillment.resend_refused` | nothing was requeued, or the supplier refused |

Subject: the `OrderItem`. Allowlisted, secret-free metadata: `order_number`,
`order_item_public_id`, `action` (`send` | `resume` | `retry_challenge`), `reason_code` (from the
allowlist, never the operator's prose), `supplier` (null for an unplaced send),
`previous_event_status`, `outcome`.

`StaffAuditEvent` enforces this at construction: any metadata key containing `credential`,
`secret`, `token`, `password`, `recovery_code`, `encrypted_payload` or `provider_metadata` throws
(`app/Admin/Audit/StaffAuditEvent.php:10-18`, `:59-75`). The supplier's own refusal string is a
free-text provider reason and does **not** go in.

### 6.8 What the row shows afterwards

`AGENTS.md` Failures rule 3 — "say what was written, not what was attempted" — governs this, and
it is the part most likely to be got wrong.

- **Send accepted** → the outbox row is `pending` again. **Nothing has been placed yet.** The row
  shows `Queued` — never "Placed" — with the time it was queued. The `unplaced` alarm **stays
  open**, because the item still has no job and the sweep has not seen one appear. Claiming the
  alarm is resolved at the moment of pressing would be the screen lying about the database.
- **Resume accepted** → the supplier took the instruction. The row keeps its current status and
  carries `Resume sent 10:42` until the next reading lands. It does not jump to "In progress":
  only `ApplySupplierObservation` may move that.
- **Refused** → the row is **unchanged**, and the message says what was not done:
  *"Not sent. Nothing changed."* `ResumeItemDelivery` already returns `'refused'` for both
  `SupplierUnavailable` and `SupplierNotConfigured` and logs the configuration fault at error
  level (`ResumeItemDelivery.php:31-43`) — the operator gets the same word for both, because the
  difference is ours to fix and not theirs to act on.
- **Rate-limited or lock held** → *"Already running."* Not an error state; the row is untouched.

---

## 7. Questions — all six answered

| | Question | Answer, 2026-09-17 |
| --- | --- | --- |
| Q1 | Admin resume/retry, or read-only? | **A working button.** Designed in §6. |
| Q2 | Which age is the primary column? | **Time since paid.** §1.2. |
| Q3 | Kill or fill `last_error`? | **Neither, in this slice.** Left alone; recorded as owed work. §1.4. |
| Q4 | Offer a `failed` status filter? | **No.** §1.4. |
| Q5 | Staff access? | **Staff see the screen, not the cost.** Three permissions, two views. §4. |
| Q6 | Manual alarm resolve? | **No.** It resolves when the item does. §1.3. |

Two decisions were mine to make and are recorded rather than asked: the screen lists order items
rather than jobs (§3, since confirmed by the owner), and `fulfillment.act` is Admin-only (§4.2,
with the reasoning, because it narrows what the owner granted in Q5 and he may disagree).

---

## 8. What the code contradicts in the plan

1. **"failure"** — `last_error`, `last_error_code`, `attempt_count`, `fulfillment_attempts` and
   `FulfillmentStatus::Failed` are all dead (§1.4). Diagnosis is assembled from
   `observed_state`, `poll_failure_count` and `hold_reason` instead. Owed work, not E1 work.
2. **"retry"** — the plan lists it as a column, as though it were one thing. It is two different
   writes with opposite risk profiles (§6.1), and `challenge_retries` counts an *automatic*
   retry, not an operator's (`app/Fulfillment/ChallengeAutoRetry.php:70`, `:95`).
3. **"every item currently at a supplier"** — taken literally this excludes the `unplaced`
   alarm, which is the one silence nothing else can report (§3).
4. **"actual cost"** — real, but null unless an applied pricing run carries `ratioEuroUsd`
   (§1.5). The plan does not flag that dependency.
5. **supplier is per-placement, not per-job** — a challenge item's second phase can sit at a
   different placement row than the job mirror advertises (§1.1).
6. **The re-send cannot create a second event.** `'order-paid:'.$order->id` is a unique
   idempotency key, so the natural reading of "send it again" is impossible and the action has to
   re-open the existing row instead (§6.2).

---

## 9. Canvas build spec

Everything needed to lay out the `/design` canvas without re-deriving anything. Values here are
lifted from the repository, not approximated; each one cites its source. Where I could not pin a
value down, §9.10 says so rather than guessing.

A working reference build of these artboards exists in the branch at
`docs/design/e1-admin-fulfillment-screen.canvas.html` (the repo's older `canvas:serve`
convention). It is kept for reference only — the `/design` canvas is the one the owner approves.

### 9.1 Artboard inventory

| # | Artboard | Size | Shows |
| --- | --- | --- | --- |
| 1 | **The list at rest — Admin** | 1440 × 800 | Full shell: sidebar, page header, toolbar, 8-column table with the six fixture rows, pagination |
| 2 | **The list on a phone** | 390 × 1180 | Mobile header, search + Filters button, five compact record cards |
| 3 | **The same screen, as Staff sees it** | 1440 × 720 | Full shell with a Staff sidebar and footer; 6-column table, same six rows, no Cost, no action column |
| 4a | **Empty — nothing owed** | 1440 × 520 | Full shell, table with one full-width empty cell |
| 4b | **Empty — nothing matching** | 390 × 640 | Phone, active-filter chips above a dashed empty box with a Reset button |
| 4c | **Empty — nothing owed, phone** | 390 × 560 | Phone, dashed empty box, no reset |
| 5 | **Row detail — an item that failed to place** | 1440 × 1100 | Right-hand Sheet (576 wide) over a dimmed list, three panels, two footer buttons |
| 6 | **The confirm step** | 1440 × 620 | Centred Dialog (512 wide) over a dimmed list |
| 7 | **What the row does next** | 1440 × 660 | Four stacked states, each a single table row 1104 wide, with its alert |
| 8 | **What is deliberately not here** | full width | A reference table, not a screen — the absent columns and why |

All artboards are **English LTR**. The Admin surface is English-only (owner decision 2026-08-21,
`.agents/skills/arab-ut-admin/references/design-system.md:36`). No Arabic artboard is needed for
this screen.

### 9.2 Design tokens — lift verbatim

The admin palette does **not** come from `.dark`. It comes from `html.admin-document` in
`resources/css/app.css:103-139`. The `<html>` element carries both `dark` and `admin-document`,
and the admin block wins on specificity (0,1,1 against 0,1,0).

| Token | Value | Used for |
| --- | --- | --- |
| `--background` | `#080705` | page, sheet, dialog, inset panels |
| `--foreground` | `#ede4d0` | body text, headings |
| `--card` | `#14110e` | table shell, mobile cards, mobile top bar |
| `--card-foreground` | `#ede4d0` | |
| `--popover` | `#1c1814` | dropdowns, loading pill |
| `--popover-foreground` | `#ede4d0` | |
| `--primary` | `#d4a843` | primary button fill, active sort arrow, focus ring |
| `--primary-foreground` | `#080705` | text on the primary button |
| `--secondary` | `#241e18` | filled tag pills |
| `--secondary-foreground` | `#ede4d0` | |
| `--muted` | `#241e18` | row hover at 40% |
| `--muted-foreground` | `#a89880` | sub-lines, table headers, placeholders |
| `--accent` | `#241e18` | avatar background |
| `--destructive` | `#ffb4a9` | |
| `--border` | `rgb(212 168 67 / 16%)` | every hairline — **translucent gold, not grey** |
| `--input` | `rgb(212 168 67 / 20%)` | field and outline-button borders |
| `--ring` | `#d4a843` | focus ring, 3px at 50% |
| `--sidebar` | `#0d0b08` | sidebar background — distinct from the page |
| `--sidebar-foreground` | `#ede4d0` | |
| `--sidebar-accent` | `#1c1814` | active nav item |
| `--sidebar-border` | `rgb(212 168 67 / 16%)` | |
| `--status-success` | `#67e89a` | |
| `--status-warning` | `#e4b756` | |
| `--status-info` | `#d4a843` | |
| `--status-danger` | `#ffb4a9` | |
| `--status-neutral` | `#a89880` | |

**Five distinct near-blacks, easy to conflate:** page `#080705`, card/table `#14110e`, sidebar
`#0d0b08`, popover `#1c1814`, secondary/muted/accent `#241e18`.

**`--border` and `--input` are translucent gold**, so they composite differently over `#080705`
than over `#14110e`. A hardcoded hex border will visibly differ between the page and inside a
card. Keep them as rgba.

**Radii.** The admin block does **not** define `--radius`; it falls through to `:root`
(`app.css:258`) at `0.625rem`. Derived at `app.css:148-150`:

| Class | Value | Used for |
| --- | --- | --- |
| `rounded-lg` = `--radius-lg` | **10px** | table shell, mobile card, dialog, empty box |
| `rounded-md` = `--radius-md` | **8px** | badges, buttons, fields, inset panels, nav items |
| `rounded-sm` = `--radius-sm` | **6px** | tag pills |
| `rounded-xl` | **12px** | Tailwind's own literal, not token-derived — bottom sheet top corners only |

**Shadows.** `shadow-xs` = `0 1px 2px 0 rgb(0 0 0 / 0.05)` on the table shell, buttons and inset
panels. `shadow-lg` = `0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)` on the
sheet and dialog. Sheet/dialog scrim: `rgb(0 0 0 / 80%)` (`ui/sheet.tsx`, `ui/dialog.tsx`).

### 9.3 Typography

Two families, five weights each (300/400/500/700/900), `@font-face` at `app.css:19-99`, files in
`public/fonts/thmanyah/`.

The admin sets its stack inline on the layout root (`resources/js/layouts/admin-layout.tsx:102`):

```
[font-family:'Thmanyah_Sans',Tahoma,Arial,sans-serif]
```

`font-display` = `'Thmanyah Serif Display', ui-serif, Georgia, serif` (`app.css:146`), used in the
admin **only** on the sidebar wordmark and two page titles. On this screen: the sidebar wordmark
only. The page `h1` is Thmanyah Sans.

| Element | Size / weight / colour | Source |
| --- | --- | --- |
| Page `h1` | `1.25rem` bold (700), `-0.025em` tracking, `#ede4d0`; `1.5rem` at `md` | `orders/index.tsx:170` |
| Page description | `0.875rem` / 400, line-height `1.625`, `#a89880`, `max-w-prose` | `orders/index.tsx:173` |
| Sidebar wordmark | `1.125rem` bold, Serif Display, `-0.01em` | `admin-sidebar.tsx:229` |
| Table `th` | `0.875rem` / **500**, `#a89880`, height **44px**, padding `0 12px`, `text-start` | `ui/table.tsx` |
| Table `td` | padding **12px**, `vertical-align: middle` | `ui/table.tsx` |
| Table base | `0.875rem`, `caption-side: bottom` | `ui/table.tsx` |
| Order-number link | `0.875rem` / 600, `#ede4d0`, tabular-nums, underline in `--border`, offset 4px | `admin-orders-columns.tsx:36` |
| Cell sub-line | `0.75rem`, `#a89880`, tabular-nums | `admin-orders-columns.tsx:263` |
| Money cell | `0.875rem` / 600, `#ede4d0`, tabular-nums | `admin-orders-columns.tsx:253` |
| Badge | `0.75rem` / **600** | `admin-badge.tsx` |
| Tag pill | `0.75rem`, `rounded-sm`, padding `2px 6px` | `admin-orders-columns.tsx:279-308` |
| Mobile micro-text | `11px` | `admin-orders-mobile-card.tsx:108` |
| Sheet title | `1.125rem` bold | coupon/manual-order drawers |
| Dialog title | `1.125rem` / 600, `line-height: 1` | `ui/dialog.tsx:106` |
| Dialog description | `0.875rem`, `#a89880` | `ui/dialog.tsx:119` |
| Section `h2` in a panel | `0.75rem` / 700 (inset panel) or `1rem` / 600 (page section) | `admin-order-item-secret.tsx:224` |

**`tabular-nums` is mandatory** on money, counts, IDs, dates and page numbers
(`design-system.md:27`). Every number is wrapped in `<bdi>`.

### 9.4 Geometry

| Thing | Value | Source |
| --- | --- | --- |
| Shell grid | `16rem` sidebar + `minmax(0,1fr)` | `admin-layout.tsx:127` |
| Sidebar width | **256px**, padding `20px 16px 20px` | `admin-sidebar.tsx:228` |
| Main padding (desktop) | **32px 40px 48px 40px** (`md:pt-8 md:pr-10 md:pb-12 md:pl-10`) | `admin-layout.tsx:130` |
| Main padding (phone) | `24px 20px … 20px` | `admin-layout.tsx:130` |
| Content max-width | **none** — the admin is fluid; only `max-w-prose` on descriptions | 19 admin pages |
| Page vertical rhythm | `space-y-6` = **24px** between page blocks | 17 of 19 admin pages |
| Page header | `border-b border-border`, `padding-bottom: 20px` (`pb-5`) | `orders/index.tsx:168` |
| Table shell | `rounded-lg` + `1px solid --border` + `bg-card` + `shadow-xs` | `admin-orders-table.tsx:70` |
| Table width at 1440 | **1104px** (1440 − 256 sidebar − 80 padding) | derived |
| Row hover | `background: rgb(36 30 24 / 40%)` (`bg-muted/40`) | `ui/table.tsx` |
| Row separator | `border-bottom: 1px solid --border`, last row none | `ui/table.tsx` |
| Buttons | min-height **44px**, `rounded-md` (8px), `0.875rem` / 500 | admin override of `h-9` |
| Row-level action button | height **36px**, `0.75rem` / 500 — see §9.10 | proposed |
| Fields / selects | min-height 44px, `rounded-md`, `1px solid --input`, `0.75rem` desktop | `admin-orders-toolbar.tsx:260` |
| Phone fields | `1rem` — never below, so iOS does not zoom | owner rule 2026-09-03 |
| Filter select width | `9rem` (`min-[480px]:w-36`) | `admin-orders-toolbar.tsx:621` |
| Pager buttons | 44 × 44, `rounded-md`, outline | `admin-orders-pagination.tsx:158` |
| Sheet (right) | **576px** (`sm:max-w-xl`), `border-inline-start`, padding 16px | coupon drawer |
| Dialog | **512px** (`sm:max-w-lg`), `rounded-lg`, padding 24px | `ui/dialog.tsx` |
| Mobile card | `rounded-lg`, `1px solid --border`, `bg-card`, padding 12px, gap 8px | `admin-orders-mobile-card.tsx:38` |
| Inset panel (in a sheet) | `rounded-md`, `1px solid rgb(212 168 67 / 20%)`, `bg-background`, padding 12px | `admin-order-item-secret.tsx:220` |
| Empty cell (desktop) | height **144px** (`h-36`), centred | `admin-orders-table.tsx:123` |
| Empty box (phone) | `rounded-lg` **dashed** `--border`, padding `40px 20px` | `admin-orders-table.tsx:156` |
| Minimum touch target | **44px** everywhere | `design-system.md:31` |

### 9.5 Badges — the component and the state map

`AdminBadge` (`resources/js/components/admin/admin-badge.tsx`) is the only status pill in the
admin. Geometry: `inline-flex`, gap 6px, `rounded-md` (8px), padding `4px 8px`, `0.75rem` / 600,
optional leading icon at 14 × 14.

Each variant is **border at 30%, background at 10%, text at 100%** of one status colour:

| Variant | Colour | Border | Background |
| --- | --- | --- | --- |
| `success` | `#67e89a` | `rgb(103 232 154 / 30%)` | `rgb(103 232 154 / 10%)` |
| `warning` | `#e4b756` | `rgb(228 183 86 / 30%)` | `rgb(228 183 86 / 10%)` |
| `info` | `#d4a843` | `rgb(212 168 67 / 30%)` | `rgb(212 168 67 / 10%)` |
| `danger` | `#ffb4a9` | `rgb(255 180 169 / 30%)` | `rgb(255 180 169 / 10%)` |
| `neutral` | `#a89880` | `rgb(168 152 128 / 30%)` | `rgb(168 152 128 / 10%)` |

**The State column's rule**, which decides which single badge a row shows:

1. an **open alarm**, if there is one → `danger`;
2. else a **hold reason**, if `hold_reason` is set → `warning` when the reason asks the customer
   for something, `info` when it says we are handling it;
3. else the **presentation** (`TrackingPresentation`).

The seven "we are handling it" reasons are named in the enum's own docblock
(`app/Enums/OrderHoldReason.php:8-13`): `ea_servers`, `store_stock`, `connection`, `no_player`,
`maintenance`, `paused`, `below_minimum`. The other **fourteen** ask the customer for something.
(The docblock says "eleven"; it was written before `two_factor_off`, `email_confirm` and
`web_app_locked` were added on 2026-09-13 at `:45-49`. Fourteen is correct; the comment is stale.)

Presentation → variant, for the `TrackingPresentation` cases
(`app/Enums/TrackingPresentation.php:16-30`):

| Presentation | Variant | Icon |
| --- | --- | --- |
| `completed` | success | `CircleCheck` |
| `finishing` | success | `CircleCheck` |
| `processing`, `preparing`, `logging_in`, `transferring`, `transferring_part_done` | info | `Clock3` |
| `cooldown_tempban`, `cooldown_listing`, `cooldown_daily_limit` | info | `Clock3` |
| `stopped`, `needs_review` | warning | `Hourglass` |
| `cancelled`, `refunded`, `not_reported` | neutral | `CircleAlert` / `RotateCcw` |

Alarm badges: `unplaced` and `silent`, both `danger`, icon `CircleAlert`.

Icons come from `lucide-react`, matching `admin-order-status.ts`: `CircleCheck`, `CircleAlert`,
`Clock3`, `Hourglass`, `RotateCcw`.

### 9.6 Columns

**Admin — eight columns, in this order.** Widths are percentages of the 1104px table.

| # | Header | Width | Sortable | Content |
| --- | --- | --- | --- | --- |
| 1 | `Order` | 13% | no | order-number link; sub = `paid {age} ago` |
| 2 | `Service` | 14% | no | service tag, + `Challenge` outline tag on a challenge phase; sub = progress |
| 3 | `Supplier` | 13% | no | supplier outline tag; sub = supplier reference (mono) |
| 4 | `Waiting` | 13% | **yes, default asc** | bold = time since paid; sub = `at supplier {age}` or `never placed` |
| 5 | `State` | 16% | no | one badge per §9.5; sub = muted status line |
| 6 | `Signal` | 12% | no | `observed_state` (mono) or `—`; sub = `read {age} ago` / `{n} failed reads` / `no reading` |
| 7 | `Cost` | 10%, right | yes | `formatAdminMoney` or `not reported` / `—` |
| 8 | *(unlabelled)* | 9%, right | no | the action button, or `—` |

The `Waiting` header carries the active sort control: label + `ArrowUp`, the arrow in `--primary`.
Inactive sortable headers show `ArrowUpDown` at 50% opacity. Header buttons are 44px tall
(`admin-orders-columns.tsx:79`).

**Staff — six columns.** Columns 1–6 above, re-proportioned; columns 7 and 8 are **gone**, not
disabled and not blank.

| # | Header | Width |
| --- | --- | --- |
| 1 | `Order` | 15% |
| 2 | `Service` | 16% |
| 3 | `Supplier` | 16% |
| 4 | `Waiting` | 15% |
| 5 | `State` | 20% |
| 6 | `Signal` | 18% |

**What the Staff view drops, item by item:**

| Dropped | Why |
| --- | --- |
| The **Cost** column | `fulfillment.view_cost` is Admin-only (§4). The Query does not select the column and the Presenter never emits the key — absent, not null. |
| The **Cost sort** | `sort=actual_cost` is off that actor's allowlist and returns **422**, not a silent fallback. A silent fallback is an oracle that reveals the column exists. |
| The **action column** | `fulfillment.act` is Admin-only (§4.2). The column is removed, not rendered with disabled buttons — a disabled control invites a support question. |
| The **`Refresh` header button** | It triggers supplier reads; it belongs with `fulfillment.act`. |
| The **footer actions in the row detail** | The Sheet still opens for Staff and shows every panel; its footer keeps only `Open order`. |
| **Sidebar sections** | Staff hold no catalog, marketing or settings permission, so their sidebar shows Overview, Orders, Fulfillment, Conversations only. This is existing behaviour, not new. |
| The sidebar footer role label | reads `Staff` rather than `Admin`. |

Staff keep everything else, including the supplier name, the supplier reference, both ages, the
stall and the signal.

### 9.7 The fixture — six rows, exact values

Real shapes, from the code:

- **Order numbers** match `OrderNumber::PATTERN` = `/^AUT-[1-9][0-9]{3,}$/`
  (`app/Checkout/OrderNumber.php:28`).
- **Supplier references** are a free-form `varchar`; real literals in the suite include `574339`
  and `FFT-4242`. Numeric is what both suppliers actually return (`idOrder` at
  `app/Suppliers/UttClient.php:412`).
- **Challenge ids** are lowercase UUIDs (`ChallengeIds::UUID_PATTERN`,
  `app/Suppliers/ChallengeIds.php:22`), e.g. `0f8fad5b-d9cb-469f-a165-70867728950e`. The list
  column shows a count, not the ids.
- **Signal values** are verified supplier literals from
  `app/Suppliers/Translation/SupplierStateTranslator.php` — see §9.10 for the one I could not pin.
- **Money** is `formatAdminMoney({amountMinor, currency:'SAR'}, 'en')` → `SAR 118.40`.
- **Absolute dates** use `Intl.DateTimeFormat(DATE_LOCALE, {dateStyle:'medium', timeStyle:'short',
  timeZone:'UTC'})` → `17 Sep 2026, 14:02`.

Sorted by `Waiting` ascending (longest wait first), which is the default.

| # | Order | Service | Supplier | Waiting | State | Signal | Cost | Action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `AUT-1033`<br>`paid 5h 08m ago` | `Coins`<br>`310K / 600K` | `FFT`<br>`574339` | **`5h 08m`**<br>`at supplier 5h 02m` | badge `Silent` *danger*<br>`Stopped · In progress` | `interrupted`<br>`8 failed reads` | `SAR 96.25` | **Resume** |
| 2 | `AUT-1036`<br>`paid 3h 41m ago` | `Coins`<br>`0 / 250K` | `UTT`<br>`118402` | **`3h 41m`**<br>`at supplier 3h 38m` | badge `Backup codes` *warning*<br>`Waiting for customer` | `WrongBA`<br>`read 2m ago` | `SAR 63.75` | `—` |
| 3 | `AUT-1041`<br>`paid 1h 16m ago` | `Coins`<br>`240K / 600K` | `FFT`<br>`574402` | **`1h 16m`**<br>`at supplier 1h 12m` | badge `Transferring` *info*<br>`In progress` | `—`<br>`read 24s ago` | `SAR 118.40` | `—` |
| 4 | `AUT-1042`<br>`paid 38m ago` | `SBC`<br>`—` | `—` | **`38m`**<br>`never placed` | badge `Unplaced` *danger*<br>`No fulfillment job` | `—`<br>`no reading` | `—` | **Send** *(primary)* |
| 5 | `AUT-1039`<br>`paid 26m ago` | `SBC` `Challenge`<br>`1 / 3 solves` | `FFT`<br>`574388 · 3 ids` | **`26m`**<br>`at supplier 22m` | badge `EA servers` *info*<br>`In progress` | `sessionExpired`<br>`read 1m ago` | `not reported` | **Retry** |
| 6 | `AUT-1040`<br>`paid 14m ago` | `Coins`<br>`600K / 600K` | `UTT`<br>`118417` | **`14m`**<br>`at supplier 12m` | badge `Finishing` *success*<br>`In progress` | `—`<br>`read 18s ago` | `SAR 121.05` | `—` |

**Why each row is there.** Row 1 is the `silent` alarm plus a stopped supplier order, which is why
it is the one offering Resume. Row 2 is a customer-action hold, so we have no action — the only
allowed action is the customer's. Row 3 is healthy. **Row 4 is the row the slice exists for**: a
paid item with *no `fulfillment_jobs` row at all*, dashes in every supplier column, and the only
action that spends new money. Row 5 is the challenge phase, showing that supplier comes from the
placement and that cost is frequently null. Row 6 is nearly done.

Row 4 should be drawn in the **hovered** state (`rgb(36 30 24 / 40%)`) so the action button reads
as reachable.

**Phone cards** (artboard 2) carry rows 1, 2, 4, 5, 6 — five cards, in that order. Each card:

- line 1: order-number link, and the row's badge, right-aligned;
- line 2: `{SUPPLIER} · {reference}` at `0.875rem`/600, and the cost right-aligned in tabular-nums;
- line 3: service tag, progress tag or signal code, and `{waiting} · read {age} ago` pushed right
  at `11px`;
- line 4, **only where there is an action**: for rows 1 and 5, the label on the left and a 36px
  secondary button on the right; for row 4, a **full-width primary button** reading
  `Send to supplier`.

Row 4's button is full width because it is the only one whose action is a purchase; a 44px control
crammed beside a badge is a mis-tap that spends money.

### 9.8 Copy

Admin copy is concise operational English (`design-system.md:39-45`). Every string below is new
and proposed; none is a customer-facing string and the Arabic dialect rule does not apply.

**Page header**

- `h1`: `Fulfillment`
- description: `Paid items a supplier still owes. Longest wait first.`
- header button (Admin only): `Refresh`

**Toolbar**

- search placeholder: `Order number or supplier reference` (desktop), `Order or reference` (phone)
- selects, in order: `All suppliers`, `All states`, `All alarms`, `All services`
- `Columns`, `Filters` (phone), `Reset filters`
- active chip prefixes follow the orders toolbar exactly: `Search: "…"`, `Supplier: …`,
  `State: …`, `Alarm: …`, `Service: …`, `From: …`, `To: …`, with `Active filters:` as the row label

**Column headers**: `Order`, `Service`, `Supplier`, `Waiting`, `State`, `Signal`, `Cost`, and one
unlabelled action column.

**Empty states**

| Case | Copy | Reset button |
| --- | --- | --- |
| Nothing owed | `No supplier owes anything right now.` | none |
| Nothing matching | `No items match these filters.` | `Reset filters` |

**Badge labels** — new English labels. The existing `lang/ar/orders.php` hold-reason strings are
customer Arabic and are not reusable here.

| Source | Value | Badge label |
| --- | --- | --- |
| alarm | `unplaced` | `Unplaced` |
| alarm | `silent` | `Silent` |
| hold | `backup_codes` | `Backup codes` |
| hold | `credentials` | `Credentials` |
| hold | `ea_servers` | `EA servers` |
| hold | `market_locked` | `Market locked` |
| hold | `insufficient_coins` | `Insufficient coins` |
| hold | `active_session` | `Active session` |
| hold | `transfer_list_full` | `Transfer list full` |
| hold | `two_factor_off` | `2FA off` |
| hold | `email_confirm` | `Email confirmation` |
| hold | `web_app_locked` | `Web app locked` |
| hold | `account_banned` | `Account locked` |
| hold | `no_club` | `No club` |
| hold | `no_player` | `No player` |
| hold | `store_stock` | `Store stock` |
| hold | `connection` | `Connection` |
| hold | `maintenance` | `Maintenance` |
| hold | `paused` | `Paused` |
| hold | `below_minimum` | `Below minimum` |
| hold | `platform` | `Platform` |
| hold | `captcha` | `Captcha` |
| hold | `unassigned` | `Unassigned` |
| presentation | `transferring` | `Transferring` |
| presentation | `finishing` | `Finishing` |
| presentation | `processing` | `Processing` |
| presentation | `preparing` | `Preparing` |
| presentation | `logging_in` | `Signing in` |
| presentation | `stopped` | `Stopped` |
| presentation | `needs_review` | `Needs review` |
| presentation | `not_reported` | `Not reported` |

Sub-line status labels reuse the existing admin status vocabulary: `In progress`,
`Waiting for customer`, `Pending`, `Ready`, `Completed`, `Cancelled`. Plus one new,
`No fulfillment job`, for a row with no job.

**Detail sheet (artboard 5), for `AUT-1042`**

- title: `AUT-1042`
- subtitle: `SBC · Console · paid 38m ago. No supplier has been asked for this item.`
- panel 1 heading `Unplaced` (`CircleAlert`, danger tint: border `rgb(255 180 169 / 30%)`,
  background `rgb(255 180 169 / 5%)`); fields `Raised` = `23m ago`,
  `You were mailed` = `23m ago`, `Resolved` = `still open`; note:
  `Paid, automated, and no placement was ever recorded. n8n cannot report this one — from its side the placement succeeded and the callback was lost.`
- panel 2 heading `The item` (`ShoppingBag`); fields `Order` `AUT-1042`, `Service` `SBC`,
  `Platform` `Console`, `Item status` `Received`, `Paid at` `17 Sep 2026, 14:02 UTC`
- panel 3 heading `At the supplier` (`Truck`); fields `Supplier`, `Reference`, `Placed at`,
  `Last reading`, `Actual cost` — **all five an em dash**; note:
  `There is no fulfillment job row for this item, so there is nothing to read, nothing to poll and nothing to resume. Every field above is absent rather than unknown.`
- footer: `Open order` (outline, with an external-link icon) and `Send to supplier` (primary)

Panel field rows: `divide-y` in `rgb(212 168 67 / 10%)`, each row min-height 44px, key
`0.75rem`/600 in `#a89880`, value `0.75rem` in `#ede4d0` right-aligned and tabular.

### 9.9 The re-send control

**It is three actions, not one, and the row's state decides which exists.** Full mechanism in §6;
this is what to draw.

| Row state | Button | Weight | Placement |
| --- | --- | --- | --- |
| No fulfillment job | `Send` (row) / `Send to supplier` (sheet, phone) | **primary**, gold | action column, sheet footer, full-width on a phone card |
| Job, `allowed_actions` has `resume` | `Resume` | outline | action column, inline on a phone card |
| Job, `allowed_actions` has `retry_challenge` | `Retry` | outline | action column, inline on a phone card |
| Job, no allowed action | — | | an em dash |

**A row that already has a placement is never offered `Send`.** That is the double-spend; the
defence is that the control does not exist.

**The confirm is a Dialog, not inline and not a second sheet.** 512px, centred, over the list. The
precedent is `admin-order-refund-control.tsx`, the admin's existing money confirmation. A sheet
would be a second sheet over the detail sheet; an inline confirm on a table row is too easy to
hit by accident for something that spends money.

Dialog content:

- title: `Send AUT-1042 to a supplier`
- body: `One SBC item, paid 38 minutes ago, never placed. Sending asks a supplier to deliver it, and we are charged for what it delivers.`
- `Reason` — a **select**, not a text field, with four options:
  `Never placed`, `Callback never arrived`, `Supplier stopped reporting`, `Customer reported it`
  (codes `never_placed`, `callback_lost`, `supplier_stalled`, `customer_report`)
- a warning-tinted callout (border `rgb(228 183 86 / 30%)`, background `rgb(228 183 86 / 8%)`,
  `Key` icon in `--status-warning`):
  `The request carries the customer's EA account, decrypted for this send only and written to the secret access log. You will be asked for your password.`
- footer: `Cancel` (outline), `Send to supplier` (primary). Never `Confirm`
  (`forms.md:17-18`).

**The four states after the press** (artboard 7). Each is one table row, 1104 wide, plus an alert
of the same width beneath it.

| State | Row | Alert |
| --- | --- | --- |
| **A · In flight** | action button **disabled**, spinner + `Sending…`; row otherwise unchanged | none |
| **B · Sent** | State shows **two** badges: `Unplaced` *danger* **and** `Queued 10:42` *neutral*. Action cell becomes `—` | info: **`Queued. Nothing is placed yet.`** / `The alarm stays open until a supplier reference actually arrives.` |
| **C · Refused** | **completely unchanged** | danger: **`Not sent. Nothing changed.`** / `Try again in a few minutes.` |
| **D · Already running** | unchanged | neutral: **`Already running.`** / `This item is being sent right now. Nothing was sent twice.` |

**B is the one that matters and the easiest to draw wrongly.** A successful press re-opens an
outbox row; it places nothing. So the row says `Queued`, never `Placed`, and the `Unplaced` alarm
**stays on the row** until the sweep sees a job appear. Clearing it at the moment of pressing
would be the screen lying about what the database holds. Resume is the same shape: the row keeps
its status and carries `Resume sent 10:42` until the next reading lands — only
`ApplySupplierObservation` may move a status.

Alert geometry: `rounded-md`, `1px` border, padding `10px 12px`, `0.8125rem`, a 16px leading icon,
title at 600 in the status colour, body in `--muted-foreground`.

**Permission, audit and rate limit** (detail in §4 and §6):

| | |
| --- | --- |
| Permission | `fulfillment.act` — **Admin only**. Rechecked in the Action as well as at the route. |
| Extra gate | `EnsureAdminPassword` (recent password), because the send decrypts the customer's EA credentials. |
| Audit | `fulfillment.resend_requested` before the write, then `fulfillment.resend_dispatched` or `fulfillment.resend_refused`. Subject: the `OrderItem`. Metadata: `order_number`, `order_item_public_id`, `action`, `reason_code`, `supplier`, `previous_event_status`, `outcome`. No supplier prose, no secrets. |
| Rate limit | new `staff-fulfillment-action`: `6/min` per staff member **and** `2/min` per staff member per item. Both buckets carry the caller (Failures rule 6). Not `staff-writes`, which is for our own database. |
| Double-press | disabled button → per-item `Cache::lock` refused rather than queued → guarded conditional `UPDATE` → `ComposePlacementRequest` re-reads `AwaitingPlacement` at send time. Only the last one is load-bearing. |

### 9.10 What I could not pin down

1. **The healthy in-progress supplier status literal.** `observed_state` stores the supplier's
   `status` field, and I verified the *finished* (`finished`, `completed`), *stopped*
   (`interrupted`, `stopped`, `stopping`, `abort`, `aborted`) and *failure* literals
   (`WrongBA`, `sessionExpired`, `LoginFailed`, `LoginFailedDeviceBan`, `FailProxyConn`,
   `FailedPersonaSwitch`, `consoleLoggedIn`, `2FADisabled`, …) plus the SBC set (`entered`,
   `started`, `solvingChallenge`, `fetchSBCInfo`, `fetchChallengeInfo`,
   `waitingForOtherSolve`, `finished`, `alreadyCompleted`). I could **not** find a literal for a
   healthy coins order in progress: the translator drives that presentation from `economyState`
   (`transfersInProgress`, `transferCycleComplete`, `customerHasPlayer`, `customerListedPlayer`)
   rather than from `status`, and several branches pass `observedState: null` outright
   (`SupplierStateTranslator.php:756`, `:776`, `:823`). **So a healthy row's Signal cell showing
   an em dash is realistic, not a placeholder** — which is how rows 3 and 6 are drawn. Do not
   invent a code for them.
2. **Whether `Refresh` belongs on this screen at all.** I drew it because an operator will want
   to force a read, but it would call the same supplier endpoints the poller does and therefore
   needs its own limiter and its own answer on whether Staff may press it. I have put it behind
   `fulfillment.act` for now. It is not load-bearing; cutting it costs nothing.
3. **Index for the default sort.** Nothing currently indexes `orders.paid_at` for this query
   shape. `tables.md` says to add indexes only for verified shapes, so it needs an `EXPLAIN` at
   implementation time, not a guess here.
4. **Exact relative-time wording.** I used `5h 08m`, `38m`, `24s`. The repo has no shared
   relative-time formatter for the admin — every admin surface prints absolute dates through
   `Intl.DateTimeFormat`. A relative format is therefore new, and the exact abbreviations are a
   choice rather than a lift.
5. **The `unplaced` detail sheet's `You were mailed` field.** `fulfillment_alarms.notified_at` is
   real and stamped by `AlertOwnerOfFulfillmentSilence`, but it records that the mail was handed
   to the queue, not that it was delivered. The label should probably say so; I have not found
   wording short enough for a 44px field row.
