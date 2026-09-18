# n8n Customer Notification v1 API

The store tells a customer on WhatsApp when their order stops on them. The
store owns everything except the send itself: it detects the transition,
decides whether a message is owed, composes it from its own catalogue,
records it in `notification_deliveries`, and hands one signed request per
message to an n8n webhook. n8n only calls Whapi.

This replaces the sending half of `Customer Notifier v2`. The workflow's own
status detection (substring matches on Salla status names, which have nothing
to map onto after the migration), its `$getWorkflowStaticData`
de-duplication (which does not survive an n8n restart) and its Supabase log
are all gone: the canonical status, `notification_deliveries` plus the
idempotency key below, and our own database take their place. What is kept
is the message catalogue, ported to `lang/{ar,en}/notifications.php`.

## Direction

Unlike the placement endpoint in `n8n-fulfillment-v1.md`, nothing here is
inbound to the store. The store POSTs to a webhook URL the operator
configures (`N8N_CUSTOMER_NOTIFY_URL`); there is no read path into the
store and no endpoint n8n needs from it.

## Configuration

Three values, all on the store side as `N8N_CUSTOMER_NOTIFY_URL`,
`N8N_CUSTOMER_NOTIFY_KEY` and `N8N_CUSTOMER_NOTIFY_SECRET`, mirrored in the
workflow's `Config` node (the instance keeps neither environment variables
nor `$vars`, owner decision 2026-09-14):

| Value | Rule |
| --- | --- |
| URL | Must be `https`. Empty means sending is switched off entirely. |
| Key | The publisher identity, compared with `X-ArabUT-Key`. |
| Secret | HMAC secret, at least 32 characters. A shorter value is treated as unconfigured. |

**Unset means inert.** With no webhook configured the publisher does not
even claim a row: no attempt burned, no backoff, no alarming log line.
Merging the queue writers sends nobody anything; setting the three values
is the switch that starts sending.

## Headers and signature

Same convention as the fulfillment contract, with this publisher's own key
pair:

```text
X-ArabUT-Key: <N8N_CUSTOMER_NOTIFY_KEY>
X-ArabUT-Timestamp: <unix seconds>
X-ArabUT-Event: <same value as eventId>
X-ArabUT-Signature: hex HMAC-SHA256 of timestamp + "\n" + eventId + "\n" + raw body, keyed with N8N_CUSTOMER_NOTIFY_SECRET
```

n8n verifies exactly as `ship-coins` verifies `order.paid`: read the raw
bytes off the Webhook node (raw-body mode on, never a re-serialised copy -
PHP escapes Arabic where JavaScript does not), compare the key, check the
HMAC, reject a timestamp more than five minutes out. A request that fails
verification is thrown, not answered.

## Body

```json
{
  "eventId": "01K5F8Q2M3R8X1YQ6H2Z7N9P3C5",
  "eventType": "customer.notify",
  "schemaVersion": 1,
  "occurredAt": "2026-09-18T10:04:11+00:00",
  "data": {
    "notification_public_id": "01K5F8Q2N1A8X1YQ6H2Z7N9P3C5",
    "order_number": "AUT-7K2MQ4",
    "order_item_public_id": "01K52J0V3C1A8W4E7R2T9Y6U3I",
    "template": "credentials",
    "locale": "ar",
    "idempotency_key": "customer-notify:item:881:credentials:12043",
    "to": "966512345678",
    "body": "أهلاً فهد 👋\n\n❌ ما قدرنا ندخل على حساب EA — طلب #AUT-7K2MQ4\n\n…"
  }
}
```

| Field | Notes |
| --- | --- |
| `data.notification_public_id` | The `notification_deliveries` row, for operator tracing. |
| `data.order_number` | For the n8n execution log and the alert; never a secret. |
| `data.order_item_public_id` | `null` for order-level messages (cancellation, refund). |
| `data.template` | The catalogue key: fourteen hold reasons plus `order_cancelled` and `order_refunded`. |
| `data.locale` | `ar` or `en`: the language the body is already composed in. |
| `data.idempotency_key` | `customer-notify:{subject}:{template}:{historyId}` - see below. |
| `data.to` | International digits without the `+`, as Whapi takes it. |
| `data.body` | The complete message, composed by the store. n8n sends it verbatim and never edits it. |

## Idempotency key

The same item, the same template and the same transition is one message.
`{historyId}` is the status-history row the transition wrote, so a retried
send carries the same key while a genuine recurrence after recovery carries
a new one. n8n keeps the key of every send it made: a request whose key was
already sent is answered `{"data":{"acknowledged":true}}` without a second
Whapi call.

## What n8n must do

1. Verify the request as above. A verification failure throws (the Error
   Workflow alerts); it is never answered as an acknowledgement.
2. If `idempotency_key` was already sent, answer acknowledged without
   sending again.
3. POST to Whapi, synchronously:
   ```text
   POST https://gate.whapi.cloud/messages/text
   { "to": <data.to>, "body": <data.body> }
   ```
4. Answer `{"data":{"acknowledged":true}}` with a 2xx **only once Whapi
   accepted the message** (its own 2xx). Anything else - a Whapi 4xx for a
   dead number, a Whapi 5xx, a timeout, a thrown verification - is
   `acknowledged: false` or a non-2xx, and the store retries.
5. Answer fast, well under the store's 60-second ceiling. No polling, no
   waiting loops, no status detection: the decision to send arrived made.
6. Save no execution data on this workflow, in every mode - the body
   carries the customer's name and phone number, and the ADR condition that
   covers credential-bearing workflows covers this one.

Seven hold reasons never produce a request at all: the ones that recover on
their own (`ea_servers`, `store_stock`, `connection`, `no_player`,
`maintenance`, `paused`, `below_minimum`). A message about something that
fixes itself is noise.

## What the store does on each answer

| n8n answer | Store action |
| --- | --- |
| 2xx with `data.acknowledged: true` | The delivery row is marked `sent`, the outbox event `processed`. Done. |
| 2xx with `acknowledged: false`, any 5xx, a timeout | The event goes back to `pending` with `last_error: delivery_failed` on a 1, 2, 4 … up to 60-minute backoff. The delivery row stays `queued` with the same reason. |
| No webhook configured | Nothing is claimed, sent, or logged as an alarm. Rows wait; holds that cleared meanwhile expire at send time (below). |

After ten attempts the event is retired as `failed`, the delivery row with
it, and both surface on the admin queue-health panel;
`php artisan orders:requeue-paid-event <eventId>` grants a fresh budget
(the command serves all three outbox types, and resets the delivery row
alongside the event).

A message that is no longer true is never sent: if the hold cleared while
the row waited - recovered, completed, cancelled or refunded under it - the
publisher marks the delivery `expired` and finishes the event without
calling n8n. "Your order stopped" must never arrive after the order moved
again.

## Rate and volume

One request per message, every minute at most fifty rows a tick, each send
serialised per order item. A hold is messaged once per transition, so
ordinary volume is a handful of requests a day, not a stream.
