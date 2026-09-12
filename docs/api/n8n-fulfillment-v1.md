# n8n Fulfillment Placement v1 API

n8n reports one signed supplier placement at a time for a paid automated item
(Coins or SBC). Laravel owns the job lifecycle from there: a successful
placement creates — or idempotently binds — the fulfillment job and starts
polling FFT or UTT for observations. This is the only inbound fulfillment
surface, and there is no read path into the store: the store polls the
suppliers itself, so n8n never needs to look anything up.

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
  "delivery_phase": "challenge"
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `order_item_public_id` | yes | ULID of the order item from the order-paid payload. |
| `supplier` | yes | `fft` or `utt`. |
| `supplier_order_id` | yes | The supplier's own reference, 1-255 characters: the challenge id for FFT, the order id for UTT. |
| `delivery_phase` | no | `coins` or `challenge`. Only meaningful for a challenge delivery; omit it for a plain Coins order. Recorded when the job is created or bound, never used to overwrite a later phase. |

## Idempotency and conflicts

Each order item has at most one job, keyed by
`fulfillment_jobs.idempotency_key = "fulfillment-placement:" + order_item_public_id`.

An identical report — same item, supplier, and supplier reference — is a
successful no-op: the same `200` response, no duplicate row, and no counter or
status change. A job that has since progressed, completed, or failed is not
reopened by a retry.

The first placement wins. A second placement for the same item with a different
supplier or reference is refused with `409 item_placement_conflict`, and the
existing placement is left untouched. A `(supplier, supplier_order_id)` pair can
only ever belong to one order item, so a reference already bound to another item
is refused with `409 supplier_reference_conflict`.

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
recorded or recognised as an identical retry. The job is set to `in_progress`,
the supplier error state is cleared, and polling begins at the next sweep.

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
| 422 | `order_item_unpaid` | Payment has not been confirmed yet. | Retry after the payment event confirms; alert if it persists. |
| 409 | `supplier_reference_conflict` | This supplier reference belongs to another order item. | Do not retry; alert (placement or order mismatch). |
| 409 | `item_placement_conflict` | The item already holds a different placement. | Do not retry; alert. |
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
