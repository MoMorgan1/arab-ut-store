# F2 — `solve-challenge`: the challenge workflow, adapted from Fulfillment v14

**Status:** approved 2026-09-15 after the owner asked for the challenge phase ("لازم برضو نظبط
موضوع التحديات"); the retry question answered the same day. In build.
**Reference:** `automation/n8n/fulfillment-v14/workflow-v14-salla.json`, the SBC branch read
node by node on 2026-09-15; `automation/n8n/ship-coins-v1/` for the shape to copy.
**Decision it serves:** `docs/plans/2026-09-12-order-tracking-in-store.md`, *The challenge
solve gets its own workflow* (2026-09-13): the solve starts from a fresh payload, composed by
the store when the coins phase finishes, so a corrected email reaches the supplier.

## What v14 does today, read from the export

v14's SBC branch is not downstream of its coins branch. `Router Logic1` deletes the plain-coins
items of an order that also holds a challenge, folds their amount into `extraCoinsK`, and the SBC
branch buys its own coins and then solves. In the store that first half already exists:
`ship-coins` funds a challenge item (`SBC: Get Available SBCs` → `SBC: Match & Validate` →
`Supplier Decision Engine` → placement, reported with `delivery_phase: coins`). What is left is
the second half:

| # | v14 node(s) | What it does | Fate in `solve-challenge` |
| --- | --- | --- | --- |
| 1 | `SBC: Split SBC IDs` | one item per bought set: `setID`, `sbcName`, `timesToSolve`, `eaEmail`, `orderId` | **Kept.** The set comes from the request (`sbc.set_id`, `sbc.times_to_solve`); the name and challenge count are read again from `availableSBCsAPI`, because ship-coins computed and discarded them. |
| 2 | `SBC: Get Available SBCs` + the "set exists / is priced" part of `SBC: Match & Validate` | `POST futtransfer.top/availableSBCsAPI`; an unknown set or a `consolePrice`/`pcPrice` of 0 throws "الطلب يحتاج حل يدوي" | **Kept as the guard.** The store already refused an unpriced challenge at funding time; it is re-checked here because a set can expire between the two phases. |
| 3 | `SBC: Submit Solve` | `POST futtransfer.top/newSBCAPI`, JSON: `setID`, `account: [eaEmail]`, `accountType: customer`, `timesToSolve`, `fillSBC: 0`, `customName: "Order #<id> - <name>"`, `clubItemHandling: exclude`, `consoleLock: 1` | **Kept as is.** One call per set, exactly the v14 body. |
| 4 | `SBC: Solve Accepted?` | `data[0].status === 'processed'`, else `WA: SBC Solve Invalid` ("usually no customer with that email") | **Kept.** A rejection fails the run loudly; the error workflow alerts on Telegram. |
| 5 | `SBC: Log to Sheet1` (`sbcSolveID`s) | the supplier's solve ids | **Replaced** by the placement report: `POST /api/automation/v1/fulfillment/placements` with `delivery_phase: challenge`, `supplier: fft`, `supplier_order_id`, `challenge_ids`. |
| 6 | `SBC Solve: Prepare Poll` → `Poll Wait` → `Check Status` (`sbcStatusBulkAPI`) → `Evaluate` → stall alerts, Salla pushes, `retrySBCAPI` auto-retry, `Salla: SBC Done` | the poll loop | **Dropped.** D3a polls `sbcStatusBulkAPI` through `FftClient::observeChallenges`, the translator reads `sbcStatus`, the customer sees the challenge card. *Auto-retry of transient statuses: kept, in the store's poll (F2d, owner decision below).* |
| 7 | `SBC: Log to Sheet`, `Sheet: SBC Actual Cost` (`+ challengeAmount × 0.1`) | estimated and actual cost | **Dropped.** The coins cost is on the job (`actual_cost_halalah`, 2026-09-15). FFT's per-challenge solve fee is not on any API answer v14 read; it stays a sheet-era figure until FFT exposes it. |

Two facts that shape everything else:

- **Only FFT solves.** `newSBCAPI`, `sbcStatusBulkAPI`, `retrySBCAPI` are FFT; UTT has no solver
  (`Supplier::handlesChallenges()`, `UttClient::retryChallenge()` throws by contract). The coins
  may have come from UTT; the challenge placement is always `fft`.
- **The store does not know which challenges were bought until the solve is reported.** An SBC
  item's configuration holds `completion_count` only; the set id is the product's `external_id`
  (`easysbc-sbc-<setId>`), read by `ComposePlacementRequest::challengeSetId()`. The bought ids
  land on `fulfillment_placements.supplier_challenge_ids`.

## The trigger — the store, when the funding lands

Owner decision already taken: the store fires, n8n never polls. The moment is
`ApplySupplierObservation::persistJobObservation()` reaching `FulfillmentStatus::Completed` on a
job whose `delivery_phase` is `coins` and whose item is `ServiceType::Sbc`, with no `challenge`
placement yet. Inside the same transaction (the item is already row-locked there, the way
`EnqueueOrderPlacement` sits inside the payment transaction) the store writes one outbox row:

```
event_type      challenge.ready
aggregate_type  order_item
aggregate_id    <order item public id>
schema_version  1
payload         { order_public_id, order_number, order_item_public_id }
idempotency_key challenge-ready:<order item id>
```

Identifiers only, as with `order.paid`: the account and the set travel in the wire body,
composed at send time. The publisher is the `PublishOrderPaidEvent` pattern with its own
configuration triple (`N8N_SOLVE_CHALLENGE_URL/KEY/SECRET`, `services.n8n.solve_challenge_*`),
its own command (`orders:publish-challenge-events`, every minute, exit zero on deferral), the
same atomic claim, backoff, ceiling and retirement. The wire body:

```json
{
  "eventId": "…", "eventType": "challenge.ready", "schemaVersion": 1, "occurredAt": "…",
  "data": {
    "order_public_id": "…", "order_number": "AUT-1029", "order_item_public_id": "…",
    "customer_name": "…",
    "item": {
      "service": "sbc", "platform": "playstation", "supplier_platform": "PS",
      "sbc": { "set_id": 1234, "times_to_solve": 2 },
      "funding": { "supplier": "utt", "supplier_order_id": "574339" },
      "account": { "ea_email": "…", "ea_password": "…", "backup_codes": [], "credential_version": 3 }
    }
  }
}
```

`ComposePlacementRequest` already builds the `item` block for the placement request; the
challenge request reuses its account and set composition and adds `funding` (the coins
placement, so the alert names it). Headers and signature are the `order.paid` ones
(`X-ArabUT-Key/-Timestamp/-Event/-Signature`, HMAC-SHA256 over `timestamp\neventId\nbody`).
Reading the secret is logged to `secret_access_logs` as the placement request's is.

## What `solve-challenge` is

```
Webhook (challenge.ready v1)
  → Config                    Set: N8N_SOLVE_CHALLENGE_KEY/SECRET, N8N_FULFILLMENT_KEY/SECRET,
                              FFT_API_USER/KEY, ARABUT_STORE_URL   (the ship-coins pattern, #147)
  → Verify Request            key, ±300 s window, HMAC over the raw bytes; eventType/schemaVersion
  → SBC: Get Available SBCs   POST availableSBCsAPI                                   (v14, as is)
  → Validate Set              Code: the set exists and is priced for the platform; else throw
                              "الطلب يحتاج حل يدوي" with set id, name, platform          (v14 text)
  → Submit Solve              POST newSBCAPI, the v14 body, once                       (v14, as is)
  → Solve Accepted?           data[0].status === 'processed'; else throw                (v14)
  → Extract Solve Ids         Code: sbcSolveID list → the placement report row
  → Sign Report → Report Placement → Confirm Report                     (ship-coins nodes, reused)
  → Respond                   { data: { acknowledged: true } }
```

`times_to_solve` is passed to FFT in one call, as v14 did; one item is one set, so the v14
per-set split is a single item here. Every failure throws: the run fails, the error workflow
alerts on Telegram, the store's outbox row is released with `delivery_failed` and retried on
backoff until the ceiling, then retired with the log line naming the requeue command. An
unpriced or vanished set is the one failure that will not fix itself; the alert says so and
Mohamed solves it by hand, then pastes the reference through the admin (G1's placement form).

## The placement report

`docs/api/n8n-fulfillment-v1.md` already accepts it. Two things fixed here, because the
contract's example (`FFT-CHL-8837410`) was written before the API was read:

- `challenge_ids` is the list of `sbcSolveID` values from the `newSBCAPI` answer, verbatim.
  `RecordSupplierPlacement` normalises them through `ChallengeIds::normalize()` and refuses the
  whole report if one is not a UUID. **Whether FFT's `sbcSolveID` is a UUID is not proven by
  the export** (v14 wrote it into a sheet cell); it is checked against a live answer before the
  report node is wired, and if it is not, the normaliser is widened to FFT's real shape first.
- `supplier_order_id` for the challenge phase is the first `sbcSolveID`. It must not collide
  with any other placement, and a solve id is unique at FFT.

The store side needs nothing new to poll it: once the challenge placement lands,
`ApplySupplierObservation` re-opens the job (Rule 8), `ObserveFulfillmentJob` reads the ids
from the challenge placement and calls `sbcStatusBulkAPI`, and the card shows the solve.

## Slices

| Slice | Where | What |
| --- | --- | --- |
| F2a | store | `challenge.ready` outbox row on coins completion of an SBC item; `ComposeChallengeRequest`; publisher + command + config; docs/api contract section; tests. Inert until `N8N_SOLVE_CHALLENGE_URL` is set. |
| F2b | n8n | `automation/n8n/solve-challenge-v1/` built the ship-coins way (build script, node sources, tests, README); imported by hand, keys typed into Config. |
| F2c | ops | Live check of `sbcSolveID`'s shape on one real solve; the four keys on the store and in Config; the first end-to-end challenge on Mohamed's own account, with the coins phase already proven (AUT-1029). |
| F2d | store | Automatic `retrySBCAPI` on transient solve statuses, on v14's cadence, from the poll (owner decision below). |

v14 keeps its SBC branch until F2c passes; from that day v14 receives no order at all (the store
sends `order.paid` only to ship-coins), so two solvers never race.

## Transient solve statuses retry on their own — owner decision, 2026-09-15

v14 re-fired `retrySBCAPI` for `sessionExpired`, `LoginFailed`, `clickFailed`,
`noSolutionFound` and the rest of its transient list, every fourth poll up to the fourteenth.
Asked whether the coins ruling (no automatic resume, 2026-09-15) extends to challenges, the
owner answered that a challenge retry should stay automatic: "ايوه نفضل تعيد". The two rulings
differ for a reason: a stopped coins order may have been stopped by a person, a transient solve
status is the supplier's own hiccup.

Since the poll loop lives in the store (D3a), so does the retry: **F2d** — when
`ObserveFulfillmentJob` reads a challenge whose `sbcStatus` is in `SBC_RETRYABLE_STATUSES`, the
store calls `FftClient::retryChallenge()` on v14's cadence (every fourth read of the same
transient status, at most three times per challenge), records the attempt on the job, and leaves
the customer's retry button as it is. Statuses that need a decision (wrong credentials, no
coins, console logged in) are never retried automatically, as in v14.
