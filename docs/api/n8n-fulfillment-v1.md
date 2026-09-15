# n8n Fulfillment Placement v1 API

n8n reports one signed supplier placement per delivery phase for a paid
automated item (Coins or SBC). A challenge item is reported twice: first the
`coins` phase for the funding shipment, then the `challenge` phase for the
solve, each with its own supplier reference. Laravel owns the job lifecycle
from there: a successful placement creates — or idempotently binds — the
fulfillment job and starts polling FFT or UTT for observations. This is the
only inbound fulfillment surface, and there is no read path into the store:
the store polls the suppliers itself, so n8n never needs to look anything up.

## Endpoint

```text
POST /api/automation/v1/fulfillment/placements
```

Production URL:

```text
https://store.arab-ut.com/api/automation/v1/fulfillment/placements
```

Required headers:

```text
X-ArabUT-Key: <public key stored in the ArabUT Fulfillment API credential>
X-ArabUT-Timestamp: <unix seconds>
X-ArabUT-Event: <same value as the placement event id>
X-ArabUT-Signature: <hex HMAC-SHA256>
```

The signature input is exactly:

```text
timestamp + "\n" + eventId + "\n" + raw request body
```

The HMAC secret remains configured as `N8N_FULFILLMENT_SECRET` on the n8n host
and is not included in the exported workflow. The secret must be at least 32
characters; a shorter configured value is treated as unconfigured and every
request is rejected with `401`. A request whose timestamp is more than five
minutes old, or more than five minutes ahead, is rejected with `409`.

## Body

```json
{
  "order_item_public_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
  "supplier": "fft",
  "supplier_order_id": "FFT-CHL-8837410",
  "delivery_phase": "challenge",
  "challenge_ids": [
    "c6d05f3b-63a1-4328-98e3-b09e4a305fbb"
  ]
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `order_item_public_id` | yes | ULID of the order item from the order-paid payload. |
| `supplier` | yes | `fft` or `utt`. |
| `supplier_order_id` | yes | The supplier's own reference, 1-255 characters: the challenge id for FFT, the order id for UTT. |
| `delivery_phase` | yes | `coins` or `challenge`. A plain Coins order reports `coins`. An SBC item reports `coins` for the funding shipment and `challenge` for the solve. `challenge` is refused unless the item is SBC and the supplier is `fft`. |
| `challenge_ids` | conditionally | Array of UUID strings or a single comma-separated string. Required when `delivery_phase` is `challenge`; refused when `delivery_phase` is `coins`. |

## Idempotency and conflicts

Each order item has at most one job, keyed by
`fulfillment_jobs.idempotency_key = "fulfillment-placement:" + order_item_public_id`.
Each job holds at most one placement per phase, keyed by
`fulfillment_placements.idempotency_key = "fulfillment-placement:" + order_item_public_id + ":" + delivery_phase`.
The job row mirrors the first placement's supplier, reference, and phase so
existing readers keep working; the placements table is the complete record and
the second phase never overwrites the mirror.

An identical report — same item, phase, supplier, supplier reference, and challenge ids — is a
successful no-op: the same `200` response, no duplicate row, and no counter or
status change. Challenge ID sets differing only in case or `SBC-` prefix are treated as identical. A job that has since progressed, completed, or failed is not
reopened by a retry.

Within one phase, the first placement wins. A second placement for the same item
and phase with a different supplier, reference, or challenge id set is refused with
`409 item_placement_conflict`, and the recorded placement is left untouched.

A `(supplier, supplier_order_id)` pair is one supplier-side order. It may be
reported for **several items of the same order in the `coins` phase** - one
shipment funding a challenge's coins and the coins bought beside it, the way
`Fulfillment v14` merged them and `ship-coins` still does (owner decision,
2026-09-14); report it once per item, and each item gets its own job reading
the same shipment. Anywhere else - an item on another order, or the
`challenge` phase - a reference already recorded is refused with
`409 supplier_reference_conflict`.

## Success response

```json
{
  "data": {
    "acknowledged": true,
    "order_item_public_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "supplier": "fft",
    "supplier_order_id": "FFT-CHL-8837410",
    "job_public_id": "01BX5ZZKBKACTAV9WEVGEMMVRZ"
  }
}
```

Success is `200` with `data.acknowledged: true`, whether the placement was just
recorded or recognised as an identical retry. On the first placement the job is
set to `in_progress`, the supplier error state is cleared, and polling begins at
the next sweep; a later phase only adds its placement row.

## Errors

Every error carries `Cache-Control: no-store`. Domain and signature errors use:

```json
{
  "error": {
    "code": "supplier_reference_conflict",
    "message": "..."
  }
}
```

Validation failures use Laravel's standard `422` body with `message` and
`errors`.

| Status | `error.code` | Meaning | n8n action |
| ---: | --- | --- | --- |
| 401 | `invalid_signature` | Key or signature mismatch, or the configured secret is shorter than 32 characters. | Fix credentials; retrying unchanged will not help. |
| 409 | `stale_placement` | Timestamp outside the ±5 minute window. | Re-sign with a fresh timestamp and retry. |
| 404 | `order_item_not_found` | No order item with that ULID. | Do not retry; alert. |
| 422 | `service_not_automated` | The item is not deliverable by a supplier; only Coins and SBC are automated. | Do not retry; alert. |
| 422 | `service_has_no_challenge` | A `challenge` phase was reported for an item that is not SBC. | Do not retry; alert (phase or item mismatch). |
| 422 | `supplier_cannot_solve_challenges` | A `challenge` phase was reported for UTT; only FFT solves challenges. | Do not retry; alert (supplier or item mismatch). |
| 422 | `challenge_ids_required` | A `challenge` phase was reported without any challenge ids. | Include the challenge ids returned by FFT and retry. |
| 422 | `challenge_ids_not_permitted` | A `coins` phase was reported carrying `challenge_ids`. | Remove `challenge_ids` from coins placements and retry. |
| 422 | `invalid_challenge_ids` | One or more challenge ids failed UUID format validation. | Correct the invalid ids and retry; a partial list is rejected rather than partially stored because dropping invalid ids leaves missing challenges untracked and invisible. |
| 422 | `order_item_unpaid` | Payment has not been confirmed yet. | Retry after the payment event confirms; alert if it persists. |
| 409 | `supplier_reference_conflict` | This supplier reference is already recorded on an item of another order, or on a challenge phase. | Do not retry; alert (placement or order mismatch). |
| 409 | `item_placement_conflict` | The item already holds a different placement for this phase. | Do not retry; alert. |
| 409 | `placement_conflict` | A concurrent request won the unique-index race. | Retry once; the retry returns the idempotent success or the precise conflict. |
| 429 | `fulfillment_rate_limited` | More than 10 requests per minute for the key. | Back off and retry. |
| 422 | — | Payload validation failed. | Fix the payload before retrying. |

## Rate limit

`10` requests per minute per `X-ArabUT-Key`, falling back to the caller IP when
the header is absent. One placement per order item plus n8n's own retries fits
comfortably inside the window.

Signature authentication runs before the rate limiter: an invalid signature
cannot consume the authenticated credential's limiter bucket, and the `429`
carries `Cache-Control: no-store` like every other response.

## The placement request (`order.paid`, schema version 2)

The other direction. When an order is paid and holds at least one automated
item (Coins or SBC) that no supplier has been asked to deliver, the store
queues one `order.paid` event and the scheduler posts it to
`N8N_ORDER_PAID_URL` (`orders:publish-paid-events`, every minute). This is
the request the `ship-coins` workflow starts from; it replaces the Salla
`order.created` webhook, the `Router Logic1` option parsing, and the
`ArabUT Price Settings` sheet in `Fulfillment v14`.

No event is queued for an order of booster services alone, nor for a manual
order whose every automated item already carries a pasted supplier
reference (owner decisions, 2026-09-14). A manual order **without** a
reference is dispatched exactly like a storefront order.

### Headers and signature

Same convention as the placement report, with the publisher's own key pair:

```text
X-ArabUT-Key: <N8N_ORDER_PAID_KEY>
X-ArabUT-Timestamp: <unix seconds>
X-ArabUT-Event: <same value as eventId>
X-ArabUT-Signature: hex HMAC-SHA256 of timestamp + "\n" + eventId + "\n" + raw body, keyed with N8N_ORDER_PAID_SECRET
```

### Body

```json
{
  "eventId": "01K52J0V4M8R1YQ6H2Z7N9P3C5",
  "eventType": "order.paid",
  "schemaVersion": 2,
  "occurredAt": "2026-09-14T09:12:44+00:00",
  "data": {
    "order_public_id": "01K52J0V3B7X2Y9Q5H8N1M4R6T",
    "order_number": "AUT-7K2MQ4",
    "channel": "store",
    "locale": "ar",
    "currency": "SAR",
    "total_halalah": 61500,
    "item_count": 2,
    "customer_name": "فهد العتيبي",
    "items": [
      {
        "order_item_public_id": "01K52J0V3C1A8W4E7R2T9Y6U3I",
        "service": "coins",
        "platform": "playstation",
        "supplier_platform": "PS",
        "quantity": 1,
        "coins": { "quantity": 1250000, "delivery": "fast" },
        "budget": { "max_eur_per_100k": 1.0, "basis": "console_fast:2000K", "pricing_version": 12 },
        "account": {
          "ea_email": "fahad@example.test",
          "ea_password": "…",
          "backup_codes": ["11111111", "22222222", "33333333"],
          "current_balance": 350000,
          "credential_version": 1
        }
      },
      {
        "order_item_public_id": "01K52J0V3D5S9F2G6H8J1K4L7Z",
        "service": "sbc",
        "platform": "playstation",
        "supplier_platform": "PS",
        "quantity": 1,
        "sbc": { "set_id": 412, "times_to_solve": 3 },
        "budget": { "max_eur_per_100k": 1.0, "basis": "console_fast:2000K", "pricing_version": 12 },
        "account": { "…": "same shape" }
      }
    ]
  }
}
```

| Field | Notes |
| --- | --- |
| `data.channel` | `store`, `manual` or `salla_import`. |
| `data.customer_name` | What both suppliers file the account under (`customerName` at FFT, `name` at UTT). Phone and email are not sent. |
| `items[].order_item_public_id` | The ULID the placement report must echo back. |
| `items[].service` | `coins` or `sbc`. Booster services never appear. |
| `items[].platform` / `supplier_platform` | The store's value and the suppliers' spelling. Only `playstation` → `PS` and `pc` → `PC` exist; an item on any other platform is never sent. |
| `items[].quantity` | The order line's quantity. |
| `items[].coins.quantity` | Coins to ship. |
| `items[].coins.delivery` | `fast`, `normal`, or `null` on PC. `normal` is v14's slow shipping. |
| `items[].sbc.set_id` | FFT's `setID` (the EasySBC id the SBC catalogue keys the product by; the catalogue's join check proves them equal). |
| `items[].sbc.times_to_solve` | `completion_count × quantity`, v14's `sbcTimesToSolve`. |
| `items[].budget.max_eur_per_100k` | The most a supplier may charge per 100K coins for this item, in euros. v14's `calculatedMaxPrice` / `buyNowThreshold`. |
| `items[].budget.basis` | Which figure produced it: `console_fast:<tier>K`, `pc:<tier>K`, or `cycle:ps`. For audit; nothing downstream should branch on it. |
| `items[].budget.pricing_version` | The applied pricing run the table came from. |
| `items[].account` | The EA account, decrypted for this request only (ADR 2026-09-12). `current_balance` is what the customer said they hold, or `null`; v14 defaulted an unknown balance to 200,000. `credential_version` rises when the customer corrects the account. |

### Where the budget comes from

`Fulfillment v14` read `ArabUT Price Settings`: a USD-per-million figure per
platform, one for slow delivery and six by quantity tier for fast, times the
sheet's USD→EUR rate, divided by ten. That sheet was filled by the hourly
supplier probe, and the probe now publishes the same figures to the store
inside every pricing run (`observations.tierCosts`, `cyclePSUsdPerM`,
`ratioEuroUsd`). The store keeps them from the newest **applied** run and
computes, at send time:

- fast console delivery and every PC order: the first tier whose cap reaches
  the quantity (1M, 2M, 5M, 10M, 15M, 20M; anything larger uses the last);
- slow console delivery: the FFT cycle cost (`slow_AnyQty` in the sheet);
- a challenge: the 2M tier, as v14 did (`fast_Tier2`), because the coins a
  challenge costs are only known once FFT prices it at solve time.

Owner decision, 2026-09-14: the observed cost is sent as it is, with no margin
on top, exactly as v14 took the sheet's number. When one order carries both a
challenge and coins, v14 merged them and priced the total by tier; here each
item carries its own budget, and the workflow should take the larger of the
two for the merged shipment.

### What the store guarantees about retries

The outbox row holds identifiers only. Everything in `items` is read when
the request is about to leave: the EA account from `order_item_secrets`
(each read written to `secret_access_logs` with purpose
`fulfillment_placement`), the budget from the newest applied pricing run,
and the item list from the order as it stands. So a retried send carries a
password the customer corrected after the first attempt, a price the market
moved to since, and leaves out an item staff have meanwhile placed by hand.

A request that cannot be composed is not sent at all - half an order is worse
than none - and the row goes back to pending with the reason as
`last_error`: `budget_unavailable` (no applied pricing run, or one without
the cost table), `credentials_missing`, `credentials_incomplete` (an email
with no password, which a manual order may hold), `credentials_purged`,
`challenge_unknown`, `platform_unsupported`, or `delivery_failed` (n8n did
not acknowledge). After ten attempts the row is retired as failed and
surfaces on the admin queue-health panel;
`php artisan orders:requeue-paid-event <eventId>` sends it again.

### What n8n must do

- Place what the request carries and report each placement through the
  endpoint above, then answer `{"data":{"acknowledged":true}}` with a 2xx.
- Anything else - a 5xx, a timeout, `acknowledged: false` - is a retry in
  one, two, four … up to sixty minutes, and **a retry carries only the items
  still unplaced**: the store re-reads the order before every send and leaves
  out any item whose placement was reported. So there is nothing to
  deduplicate on the n8n side, and a workflow that places one shipment per run
  may answer `acknowledged: false` while items remain; the next attempt brings
  them. The store waits 60 seconds for the answer.
- Save no execution data on this workflow or any sub-workflow it calls, in
  every mode (success, error, manual, progress), before a real order runs
  through it - the condition the ADR attaches to carrying the account here.

## The solve request (`challenge.ready`, schema version 1)

The third direction, added 2026-09-15. A challenge item is delivered in two
phases: `ship-coins` buys the coins and reports a `coins` placement; when the
store observes that shipment complete, it queues one `challenge.ready` event
for the item and the scheduler posts it to `N8N_SOLVE_CHALLENGE_URL`
(`orders:publish-challenge-events`, every minute). This is the request the
`solve-challenge` workflow starts from. It replaces the `SBC: Split SBC IDs`
→ `SBC: Submit Solve` half of `Fulfillment v14`, which submitted the solve
with the email captured when the order arrived; here the account is read
again when this request leaves (`docs/plans/2026-09-15-solve-challenge-workflow.md`).

No event is queued for a coins item, for a challenge whose `challenge`
placement already exists (staff pasted the reference), or while an admin hold
sits on the item. A repeated "completed" reading of the same shipment queues
nothing new.

### Headers and signature

Same convention, with the solve publisher's own key pair:

```text
X-ArabUT-Key: <N8N_SOLVE_CHALLENGE_KEY>
X-ArabUT-Timestamp: <unix seconds>
X-ArabUT-Event: <same value as eventId>
X-ArabUT-Signature: hex HMAC-SHA256 of timestamp + "\n" + eventId + "\n" + raw body, keyed with N8N_SOLVE_CHALLENGE_SECRET
```

### Body

```json
{
  "eventId": "01K5A2Q7M3R8X1YQ6H2Z7N9P3C5",
  "eventType": "challenge.ready",
  "schemaVersion": 1,
  "occurredAt": "2026-09-15T13:02:11+00:00",
  "data": {
    "order_public_id": "01K52J0V3B7X2Y9Q5H8N1M4R6T",
    "order_number": "AUT-7K2MQ4",
    "order_item_public_id": "01K52J0V3B7X2Y9Q5H8N1M4R6V",
    "customer_name": "فهد العتيبي",
    "item": {
      "order_item_public_id": "01K52J0V3B7X2Y9Q5H8N1M4R6V",
      "service": "sbc",
      "platform": "playstation",
      "supplier_platform": "PS",
      "quantity": 1,
      "sbc": { "set_id": 412, "times_to_solve": 2 },
      "funding": { "supplier": "utt", "supplier_order_id": "574339" },
      "account": {
        "ea_email": "player@example.com",
        "ea_password": "…",
        "backup_codes": ["11111111", "22222222"],
        "current_balance": 350000,
        "credential_version": 3
      }
    }
  }
}
```

- `sbc.set_id` is FFT's `setID` (the EasySBC id the catalogue keys the
  product by); `times_to_solve` is the customer's completion count times the
  quantity, as the placement request carried it.
- `funding` names the coins placement the solve draws on, for the alert and
  the log. Only FFT solves; the coins may have come from either supplier.
- There is no `budget`: the coins are bought, and FFT prices the solve.
- The account is read from `order_item_secrets` when the request leaves
  (`secret_access_logs` purpose `fulfillment_challenge`), so a correction made
  during the coins phase is what the solve is submitted with.

### What the store guarantees about retries

The same outbox: a request that cannot be composed goes back to pending with
the reason as `last_error` - `credentials_missing`, `credentials_incomplete`,
`credentials_purged`, `challenge_unknown`, `platform_unsupported`,
`funding_missing`, `item_missing`, or `delivery_failed` - on the same
one-to-sixty-minute backoff, is retired after ten attempts, and is sent again
with `php artisan orders:requeue-paid-event <eventId>` (the command serves
both outboxes). An event whose `challenge` placement appears meanwhile is
finished without a request.

### What n8n must do

- Check the set is still offered and priced (`availableSBCsAPI`), submit it
  once (`newSBCAPI` with `timesToSolve`), and report the solve through the
  placement endpoint above with `delivery_phase: "challenge"`,
  `supplier: "fft"`, the first `sbcSolveID` as `supplier_order_id` and every
  `sbcSolveID` in `challenge_ids`; then answer `{"data":{"acknowledged":true}}`.
- Anything else is a retry on the store's backoff. A set FFT no longer prices
  is not going to fix itself: fail the run so the error workflow alerts, and
  the owner solves by hand and pastes the reference through the admin.
- The store polls the solve itself (`sbcStatusBulkAPI`) from the moment the
  placement is reported; the workflow does not wait for it.
- Save no execution data on this workflow, for the same reason as above.
