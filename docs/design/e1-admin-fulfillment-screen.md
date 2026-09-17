# E1 — the admin fulfillment screen

Research and design decisions for slice **E1** of
[`docs/plans/2026-09-12-order-tracking-in-store.md`](../plans/2026-09-12-order-tracking-in-store.md)
(`## Slice E — operations (later)`, lines 997–1001).

No code has been written. This note and the canvas beside it
(`docs/design/e1-admin-fulfillment-screen.canvas.html`) are the whole of E1 up to the owner
gate in `CLAUDE.md`: inspect, canvas, approve, *then* build.

---

## 1. What the plan asks for, and what the database can answer

The plan names six things: **supplier, age, stall, failure, actual cost, retry**.

Four of the six have real data behind them today. Two do not, and one of those two is not
merely empty — it is a column the store has no writer for at all.

| Plan's column | Verdict | Where it comes from |
| --- | --- | --- |
| **supplier** | **Real** | `fulfillment_jobs.supplier` and `fulfillment_placements.supplier` |
| **age** | **Real, but ambiguous** — three different ages exist | `orders.paid_at`, `fulfillment_placements.placed_at`, `fulfillment_jobs.observed_at` |
| **stall** | **Real** | `fulfillment_jobs.hold_reason` / `hold_tone` / `presentation`, plus open `fulfillment_alarms` |
| **failure** | **Not as the plan words it.** `last_error`/`last_error_code` are dead columns | diagnosis has to come from `observed_state` + `observation.status` + `poll_failure_count` |
| **actual cost** | **Real writer, conditional data.** Null unless two preconditions hold | `fulfillment_jobs.actual_cost_halalah` |
| **retry** | **Does not exist for an admin.** No route, no permission, no audit event | `challenge_retries` counts only the *automatic* challenge retry |

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

### 1.2 age — real, but the plan does not say which age

Three distinct clocks exist and they answer three different operator questions:

| Clock | Column | Question it answers |
| --- | --- | --- |
| Customer wait | `orders.paid_at` | how long has this person been waiting |
| At the supplier | `fulfillment_placements.placed_at` (`…000003_create_fulfillment_placements.php:22`) | how long has the supplier had it |
| Reading age | `fulfillment_jobs.observed_at` (`…000001_add_supplier_observations_to_fulfillment_jobs.php:15`) | how stale is what this row is telling me |

The plan says "every item **currently at a supplier**", which argues for `placed_at` as the
primary age. But the third clock is the one that makes the other two trustworthy: a row whose
`observed_at` is two hours old is reporting two-hour-old news regardless of how fresh its
placement is, and `AGENTS.md` **Failures** rule 1 requires exactly that — "leaves the screen
standing with the last good value **and its age on it**".

**Decision (mine, routine):** the list shows the placement age as the sorted column and carries
the reading age as a quiet secondary line under it. Both are real columns; neither is derived.
Customer wait moves to the row detail, because it is the order's clock, not the job's.

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

**This matters for the screen's scope.** An `unplaced` item has *no row in `fulfillment_jobs`*.
A screen built as "list of fulfillment jobs" cannot show the single most urgent thing the
operations slice exists for. See §3.

### 1.4 failure — the plan's column has no data behind it

`fulfillment_jobs` carries `last_error_code` and `last_error`
(`…2026_08_08_000004_create_fulfillment_tables.php:52-53`). **Nothing in the application ever
writes a non-null value to either.** The only writers set them to `null`:
`RecordSupplierPlacement.php:227-228` and `:242-243`. A grep across `app/` finds no other
assignment.

Three more dead surfaces sit beside it:

- **`fulfillment_attempts`** (`…000004_create_fulfillment_tables.php:62-76`) has a model
  (`app/Models/FulfillmentAttempt.php`) and a `HasMany` on the job
  (`app/Models/FulfillmentJob.php:121-125`) and **no writer anywhere in `app/`**. The table is
  empty by construction. Its `error`, `request_metadata`, `response_metadata` and
  per-attempt `actual_cost_halalah` columns are all unreachable.
- **`fulfillment_jobs.attempt_count`** is cast (`app/Models/FulfillmentJob.php:64`) and never
  incremented. Every `attempt_count` write in `app/` belongs to `AgentTurn`, a different model.
- **`FulfillmentStatus::Failed`** is **never assigned**, anywhere — not in `app/`, not in
  `database/`, not in `tests/`. It appears only as a read: an exclusion in the poll selection
  (`app/Console/Commands/PollFulfillmentJobs.php:251`), an exclusion in the alarm sweep
  (`SweepFulfillmentAlarms.php:124`), and a terminal check
  (`PollFulfillmentJobs.php:408`). **A "Failed" filter chip on this screen would return zero
  rows forever.**

What *does* exist for diagnosis, and what the screen should use instead:

| Signal | Column | Written by |
| --- | --- | --- |
| The supplier's own last status code | `fulfillment_jobs.observed_state` | `ApplySupplierObservation.php:335` — and `TranslatedState` says it plainly: "Supplier codes are carried in `$observedState` **for diagnosis only**" (`app/Suppliers/Translation/TranslatedState.php:14`) |
| The masked supplier payload | `fulfillment_jobs.observation` (JSON) | `ApplySupplierObservation.php:369`, allowlisted to 25 keys at `:440-472` |
| Consecutive fruitless reads | `fulfillment_jobs.poll_failure_count` | `PollFulfillmentJobs.php:350` (reset) and `:360-362` (increment) |
| The classified stall | `fulfillment_jobs.hold_reason` | `ApplySupplierObservation.php:334` |

The read failure *reason itself* — `unavailable`, `not_configured`, `unreadable` — is never
stored. It lives in an in-memory `ObservationOutcome` (`app/Actions/Fulfillment/ObservationOutcome.php`)
and in the log (`ObserveFulfillmentJob.php:93-96`, `:110-114`, `:124-127`, `:176-180`). The
screen can show *that* a read failed and *how many times in a row*; it cannot show *why*
without a schema change.

**Design consequence:** the canvas has **no "failure" column**. It has a *Signal* column showing
the supplier's own code plus the consecutive-failure count, and the row detail shows the masked
observation. A mock with an empty "Last error" column would be a promise the store cannot keep.

### 1.5 actual cost — a real writer with two preconditions

`fulfillment_jobs.actual_cost_halalah` is written at
`app/Actions/Fulfillment/ApplySupplierObservation.php:307`, from
`app/Fulfillment/SupplierCostInHalalah.php:27-44`. It requires **both**:

1. The supplier payload carries a positive cost — `toPay` for FFT, `_costEur` for UTT
   (`SupplierCostInHalalah.php:29-35`); and
2. there is an **applied** `PriceRun` whose payload holds a positive
   `observations.ratioEuroUsd` (`SupplierCostInHalalah.php:46-56`). That key survives the
   apply-time allowlist (`app/Actions/Pricing/ApplyCoinsPricingRun.php:132`), so an applied run
   does keep it.

With no applied run, **nothing is written** — deliberately: "a cost we cannot convert is not a
cost we should invent" (`SupplierCostInHalalah.php:16-17`). Per
`docs/plans/2026-09-14-ship-coins-workflow.md` and the fulfillment status notes, pricing runs
have been failing and carrying no `tierCosts`, so in practice this column is expected to be
**null on most rows right now**. It is a real column with a real writer, not a dead one — but
the screen must render null as "not reported", never as `0.00`.

Note also: the cost is read from the **raw** payload before masking and kept on its own column;
the stored `observation` JSON deliberately never carries it (`ApplySupplierObservation.php:52-54`,
and `toPay`/`_costEur` are absent from `STORABLE_OBSERVATION_KEYS` at `:440-472`).

### 1.6 retry — there is no admin retry, and adding one is an owner decision

`ResumeItemDelivery` and `RetryItemChallenge` exist (`app/Actions/Fulfillment/`), but they are
**customer** actions. Their only routes are storefront routes — `routes/web.php:83-106` and the
localized mirror at `:243-266` — and both go through `ResolveActionableItem`, which fails closed
unless the job's own `allowed_actions` column lists the action
(`app/Actions/Fulfillment/ResolveActionableItem.php:40-42`).

`fulfillment_jobs.challenge_retries` is **not** an operator retry log. It is the automatic
challenge-retry counter, per challenge id, written and cleared by
`app/Fulfillment/ChallengeAutoRetry.php:70`, `:95`
(`…2026_09_15_000001_add_challenge_retries_to_fulfillment_jobs.php:7-15`).

The plan already promised the admin button and already flagged it as owner territory:

> "Resuming stays a deliberate act: the customer's button on the card, and **the admin's when
> the admin surface exists**. Nothing in D3 may call `resumeOrderAPI` on its own."
> — `docs/plans/2026-09-12-order-tracking-in-store.md:1032-1035`

E1 *is* that surface. But an admin resume spends money at a supplier and is customer-visible,
which `CLAUDE.md` puts squarely in the ask-first column. **The canvas shows the control, disabled,
with the question attached.** See §6, Q1.

---

## 2. Which existing screen this extends

**`/admin/orders` is the parent, and the pattern to copy exactly.** Not a new visual language —
the same one, with different columns.

The pieces being lifted, as they stand:

| Piece | File |
| --- | --- |
| Page shell, toolbar/table/pagination composition, query-failure alert | `resources/js/pages/admin/orders/index.tsx` |
| Desktop semantic table + mobile card list + shared empty state | `resources/js/components/admin/orders/admin-orders-table.tsx` |
| Compact record summary for phone | `resources/js/components/admin/orders/admin-orders-mobile-card.tsx` |
| Status pill | `resources/js/components/admin/admin-badge.tsx`, `admin-order-status.ts` |
| Money rendering | `resources/js/components/admin/admin-money.ts` |
| Server query shape, allowlisted sort, projection | `app/Admin/Queries/ListAdminOrders.php` |

The screen also has an existing **entry point already in production**. The overview's queue-health
banner already counts open alarms — `silentItems` and `oldestSilenceAt` in
`app/Admin/Queries/ReadQueueHealth.php:151-170`, rendered at
`resources/js/components/admin/admin-queue-health.tsx:155-180`. It is a number with nowhere to go.
E1 is where that number should link, and that link is the cheapest possible navigation addition:
the `architecture.md` rule "do not add an empty navigation destination for a deferred feature"
is satisfied because the destination stops being empty the day E1 ships.

**Placement:** a top-level `Fulfillment` entry in `AdminShell` navigation
(`app/Admin/Presenters/AdminShell.php:29-141`), between `Orders` and `Customers`, plus the
banner link above.

---

## 3. Scope: this is a list of *items*, not a list of jobs

The most important structural decision, and the one that disagrees with the obvious reading of
the plan.

"Every item currently at a supplier" sounds like `SELECT * FROM fulfillment_jobs`. But an
`unplaced` alarm — the one kind n8n **cannot** report, the whole reason `fulfillment_alarms`
exists (`app/Admin/Queries/ReadQueueHealth.php:27-30`) — is by definition an item with **no
fulfillment job row** (`AwaitingPlacement.php:91`, `whereDoesntHave('fulfillmentJob')`).

A jobs-only screen would be blind to exactly the failure the slice exists to surface, and blind
in the same shape as the failure it is replacing: "no failed job, no failed event, nothing
waiting anywhere" (`ReadQueueHealth.php:27-30`).

**Decision (mine, routine):** the screen lists **order items in fulfillment**, left-joined to
their job. An unplaced item renders as a row with an em dash in every supplier column and an
`Unplaced` alarm pill. The row is not a hole in the table; the hole *is* the finding.

---

## 4. Permission

**A new `fulfillment.view` case on `App\Enums\AdminPermission`, Admin-only.**

Reasoning against the alternatives:

- **Not `orders.view`.** Staff hold `orders.view` (`app/Admin/Authorization/AdminAccess.php:12-19`),
  and this screen exposes supplier identity, supplier order references and our cost per shipment.
  `permissions.md` is explicit that any Staff expansion "requires a new explicit owner decision
  before code or documentation changes" — reusing `orders.view` would grant Staff a new surface
  by accident rather than by decision.
- **Not `dashboard.view`.** Staff hold that one too.
- **Not `settings.view`.** Wrong domain.

So: `fulfillment.view`, Admin yes / Staff no, added to the enum, to the `permissions.md` matrix,
and left out of `AdminAccess::STAFF`. Route admission (`can:fulfillment.view`) plus the query's
own check, per `permissions.md` — "route admission, request/policy authorization, and high-risk
Action checks are separate layers".

A second permission, `fulfillment.act`, would be needed for the resume/retry control in §1.6.
It is **not** proposed here; it waits on Q1.

Two drift notes found while reading, neither blocking:

- `AdminPermission::OrdersCreate` (`app/Enums/AdminPermission.php:9`) and
  `AdminPermission::MarketingView`/`MarketingManage` (`:32-33`) exist in the enum and in
  `AdminAccess`, but `orders.create` is missing from the matrix table in
  `.agents/skills/arab-ut-admin/references/permissions.md:5-33`. The skill doc is behind the code.
- `permissions.md:51-53` says Staff holds "the five approved" permissions; `AdminAccess::STAFF`
  now lists six.

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
| `status` | `pending`, `ready`, `in_progress`, `waiting_for_customer`, `completed`, `cancelled` — from `FulfillmentStatus`, **less `failed`** | `fulfillment_jobs.status` |
| `alarm` | `unplaced`, `silent`, `any`, `none` — from `FulfillmentAlarmKind` plus two aggregates | open `fulfillment_alarms` |
| `hold` | the 21 `OrderHoldReason` values, plus `held` / `clear` | `fulfillment_jobs.hold_reason` |
| `service` | `coins`, `sbc` — the two automated services (`RecordSupplierPlacement.php:29`) | `order_items.service_type` |
| `paid_from`, `paid_to` | `Y-m-d` | `orders.paid_at`, parsed exactly as `ListAdminOrders::applyDateFilters` does (`:194-208`) |

`failed` is excluded from the status allowlist on purpose: §1.4. If the owner wants it present
for future-proofing, that is Q4.

### 5.2 Sort allowlist

| `sort` | Column | Default |
| --- | --- | --- |
| `paid_at` | `orders.paid_at` | ✅ `asc` |
| `placed_at` | `fulfillment_placements.placed_at` | |
| `observed_at` | `fulfillment_jobs.observed_at` | |
| `poll_failures` | `fulfillment_jobs.poll_failure_count` | |
| `actual_cost` | `fulfillment_jobs.actual_cost_halalah` | |

`direction` ∈ `asc`, `desc`. `per_page` from the existing per-page option list. A tiebreak on
`order_items.id` in the same direction, mirroring `ListAdminOrders.php:125-126`.

**Why `paid_at asc` and not `placed_at desc`.** Two reasons, and the first is not a preference.
An `unplaced` row (§3) has no placement at all, so `placed_at` is null for exactly the rows that
matter most — sorting on it either buries them or needs a `COALESCE` the allowlist then has to
defend. `orders.paid_at` is the one clock every row on this screen has, because being paid is
what puts an item here. Second: ascending puts the longest-waiting customer at the top, which is
the order an operator works in. `/admin/orders` defaults to `placed_at desc` (newest first)
because it is a ledger; this screen is a queue, and a queue is read from the front.

### 5.3 Indexes

`fulfillment_jobs` already indexes `status`, `supplier`, `['status','next_poll_at']`,
`['status','last_viewed_at']` and `leased_until`
(`…000004_create_fulfillment_tables.php:45-58`, `…000001_add_supplier_observations…:27-28`).
`fulfillment_alarms` indexes `['resolved_at','notified_at']`
(`…2026_09_16_000001_create_fulfillment_alarms.php:31`). None of those serves
`ORDER BY fulfillment_placements.placed_at`. `tables.md` says to add indexes "only for verified
query shapes", so the index decision belongs to implementation with an `EXPLAIN` behind it, not
to this note.

### 5.4 What must never reach the props

`security.md` and `audit-logging.md` apply unchanged. The `observation` JSON is already
allowlisted and email-masked server-side (`ApplySupplierObservation.php:440-472`, `:516-532`) —
the presenter passes the stored column through and adds nothing. No credential, no
`lease_token`, no raw provider metadata. Cost is a nonnegative minor-unit integer rendered
through `formatAdminMoney`.

---

## 6. Questions the owner must answer

**Q1 — Does the admin get resume and retry-challenge buttons in E1, or is E1 read-only?**
The plan promised the admin button (`…order-tracking-in-store.md:1032-1035`) and nothing has
built it. Both actions call a supplier and both change what a customer sees, so `CLAUDE.md`
requires the ask. Read-only E1 is smaller, ships sooner, and leaves the operator with the
customer's own button as the only lever. The canvas shows the control **disabled**, so the
answer can be "yes" without a second canvas.

**Q2 — Which age is the primary column?** §1.2. My proposal: time at the supplier
(`placed_at`) as the sorted column, reading age (`observed_at`) as a secondary line. The
alternative is customer wait (`paid_at`) first, which reads as "who is angriest" rather than
"what is stuck".

**Q3 — `last_error` is a dead column. Kill it or fill it?** Three options:
(a) leave it, and E1 never shows it; (b) drop `last_error`, `last_error_code`, `attempt_count`
and the whole `fulfillment_attempts` table in a later slice; (c) start writing the
`ObservationOutcome` reason to it, which makes the plan's "failure" column real but is a schema
and write-path change well outside E1. Same question for `FulfillmentStatus::Failed`, which
nothing sets.

**Q4 — Should the status filter offer `failed`?** It would always return zero rows today. My
proposal: leave it out until Q3 is answered.

**Q5 — Staff access.** Proposal is Admin-only (§4). If Mohamed wants Staff to work the
fulfillment queue, that is the explicit owner decision `permissions.md:51-53` demands, and it
means Staff see supplier identity, supplier references, and our cost per shipment.

**Q6 — Should an operator be able to resolve an alarm by hand?** `fulfillment_alarms.resolved_at`
is only ever set by the sweep when the condition goes away (`SweepFulfillmentAlarms.php:191-198`).
An item Mohamed has fixed outside the store stays on the screen until the sweep agrees. A manual
resolve is a write, needs an audit event, and can lie about reality — so it is his call, not mine.

---

## 7. What the code contradicts in the plan

1. **"failure"** — the plan implies a stored failure per item. `last_error`, `last_error_code`,
   `attempt_count`, `fulfillment_attempts` and `FulfillmentStatus::Failed` are all dead
   (§1.4). Diagnosis has to be assembled from `observed_state`, `poll_failure_count` and
   `hold_reason`.
2. **"retry"** — the plan lists it as a column. There is no admin retry path at all, and
   `challenge_retries` counts an automatic retry, not an operator one (§1.6).
3. **"every item currently at a supplier"** — taken literally this excludes the `unplaced`
   alarm, which is the one silence nothing else can report (§3).
4. **"actual cost"** — real, but null unless an applied pricing run carries `ratioEuroUsd`
   (§1.5). The plan does not flag that dependency, and the fulfillment status notes say pricing
   runs are currently failing.
5. **supplier is per-placement, not per-job** — a challenge item's second phase can sit at a
   different placement row than the job mirror advertises (§1.1).

---

## 8. Canvas

`docs/design/e1-admin-fulfillment-screen.canvas.html`, served by `npm run canvas:serve`
(`tools/canvas-serve.mjs`), following the convention of `docs/design/order-tracking.canvas.html`.

Three artboards, English LTR (the Admin is English-only — owner decision 2026-08-21,
`design-system.md:36`), at 390px and 1440px:

1. **The list at rest** — realistic rows built only from columns that have writers.
2. **The empty state** — the good day, which is what this screen shows most of the time.
3. **Row detail for an item that failed to place** — the `unplaced` alarm, the row with no job.

Admin copy is short operational English (`design-system.md:39-45`). The Arabic dialect rule does
not apply here, and nothing on this screen is ever shown to a customer.
