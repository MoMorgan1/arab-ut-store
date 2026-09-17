# Fulfillment recovery — a paid order no supplier is working on

Open this when the admin overview shows a number under **Paid items nobody is working on**, or
when the `تنبيه تنفيذ` mail arrives. Both come from the same place: `fulfillment_alarms`, filled
every five minutes by `php artisan fulfillment:alarms`.

**The one rule, before anything else: never place an order at a supplier twice.** A second
placement spends money again, and it puts a second bot on one EA account, which is the thing
`ship-coins` was built to avoid. Everything in section 2 exists to answer one question — has
anybody already placed this — and it is answered before a reference is recorded or a supplier is
touched.

## 1. Which silence is it

The mail line and the alarm row say which of four things happened. They need different work.

| The line reads | Alarm kind | What it means | Go to |
| --- | --- | --- | --- |
| `لم يُرسل لأي مورد بعد` | `unplaced` | Paid, no supplier reference, and the store has not written down a reason | §2 |
| `متوقف ولن يُرسل بدون تدخل` | `unplaced`, blocked | Paid, and the store is refusing to compose the request. The reason code is on the same line | §3, then §2 |
| `لا توجد قراءة جديدة من المورد` | `stale` | Placed, reference recorded, and nothing has read it for an hour | §6 |
| `المورد توقف عن الرد عليه` | `silent` | Placed, and the reads are being attempted and failing | §6 |

Read the open rows directly when the mail is not to hand. On the server, from the release
directory:

```bash
php artisan tinker
>>> App\Models\FulfillmentAlarm::query()->whereNull('resolved_at')
...     ->with('orderItem.order')
...     ->get()
...     ->map(fn ($a) => [$a->kind->value, $a->orderItem?->order?->order_number, $a->orderItem?->public_id, $a->raised_at->diffForHumans(), $a->context]);
```

`context` carries the order number, the service, and — for the two placed kinds — the supplier and
the reference. For an `unplaced` alarm it carries `reason` and `blocked` when the store has one.

## 2. Has anybody already placed it — the three states

A paid order with no supplier reference is in exactly one of three states, and only the first is
safe to place by hand.

**State A — nothing left the store.** The outbox row is still pending and n8n never acknowledged
it. No supplier has heard of this order.

**State B — n8n placed it and the report never landed.** From n8n's side the placement succeeded;
the store's record of it was lost. The supplier *has* this order. Placing it again is the
expensive mistake.

**State C — the store is refusing to compose the request.** Nothing reached n8n and nothing will,
until the thing named in §3 is fixed.

Tell them apart in this order, stopping at the first answer:

**a. The outbox row.** One row per paid order, keyed `order-paid:<order id>`.

```bash
php artisan tinker
>>> $order = App\Models\Order::where('order_number', 'AUT-1234')->sole();
>>> App\Models\IntegrationEvent::where('event_type', 'order.paid')
...     ->where('aggregate_id', $order->public_id)
...     ->first(['event_id', 'status', 'attempts', 'available_at', 'last_error']);
```

| What it says | Reading |
| --- | --- |
| `status: pending`, `last_error: delivery_failed` | n8n never acknowledged a request. State A. |
| `status: pending`, any other `last_error` | The store never got as far as sending. State C — §3. |
| `status: processed` | n8n answered `acknowledged: true`. It believed it had placed. **State B until the supplier says otherwise.** |
| `status: failed` | Retired after ten attempts. `last_error` still names what the attempts failed on. State A or C. |
| No row at all | Nothing was ever queued. State A, and worth asking why — `EnqueueOrderPlacement` writes no row when nothing awaited placement at payment. |

**b. n8n's own execution history.** Open `ship-coins` (or `solve-challenge`) and search the
executions for the order number: the request payload carries `order_number`, and every failure the
workflow throws starts with it. An execution that reached `External: Buy Coins` or
`UTT: Add Order Public` and came back with an id is a placement that happened — State B — and the
id in that node's response is the reference to record in §5.

**c. The supplier.** §4. Slower than both of the above, and the only one that is proof.

## 3. The reasons the store refuses, and what clears each

The reason is the publisher's own word for what stopped it. It appears in three places with the
same spelling: `integration_events.last_error`, the alarm's `context.reason`, and the
`Placement request could not be composed` warning in the log.

| Reason | What is actually wrong | What clears it |
| --- | --- | --- |
| `budget_unavailable` | No applied pricing run, or the applied run carries no cost tier for that platform. A PC challenge on a run whose `tierCosts` has no `pc` group is the usual one | Apply a pricing run that carries the tier, then requeue |
| `credentials_missing` | The item holds no EA account at all | The customer's correction on the order page, or place it by hand |
| `credentials_incomplete` | An email with no password | Same |
| `credentials_purged` | The stored account is past its retention and was deleted | Nothing will restore it. Place by hand, or refund |
| `configuration_incomplete` | A Coins item with no quantity on it | Fix the item, or place by hand |
| `platform_unsupported` | The item is on a platform no supplier serves — anything that is not PlayStation or PC | Place by hand, or refund |
| `challenge_unknown` | The SBC product carries no `easysbc-sbc-<id>` external id, so nothing can say which challenge it is | Fix the catalogue row, then requeue |
| `service_has_no_challenge`, `funding_missing` | A `challenge.ready` event whose item is not an SBC, or whose coins placement is missing | Read the item; neither should be reachable |
| `max_attempts_exceeded` | The row was retired before it ever recorded a reason | The retirement line in the log carries `reason` and `event_id` |

Requeue after fixing the cause — this only moves a `failed` row, and grants it a fresh attempt
budget:

```bash
php artisan orders:requeue-paid-event 01J8ZP...   # the event_id from the row or the log
```

A row still `pending` needs no requeue; the publisher picks it up within a minute of its
`available_at`.

## 4. Asking FFT and UTT by hand

Both supplier APIs are keyed by the supplier's own order id. **With no reference in hand there is
nothing to look up**, so the by-hand search is the supplier's own dashboard — filter by the EA
account email and the hour the order was paid — or n8n's execution record from §2b. There is no
"find my order by customer" call in either API; `docs/api/supplier-endpoints.md` is the whole call
set, and it does not contain one.

Once a candidate reference exists, confirm it with the same call the store's poller makes, using
the configured keys without printing them:

```bash
php artisan tinker
>>> app(App\Suppliers\SupplierRegistry::class)
...     ->for(App\Enums\Supplier::Fft)   // or ::Utt
...     ->observe('574339');
```

What the raw calls are, for a request run from the server itself:

- **FFT coins** — `POST https://futtransfer.top/orderStatusAPI`, JSON
  `{orderID, apiUser, apiKey, externalID: 1, isMotherID: 0}`. It answers `404` with the plain-text
  body `notFound` for a challenge order: that endpoint is coins-only.
- **FFT challenges** — `POST https://futtransfer.top/sbcStatusBulkAPI`, JSON
  `{sbcIDs: [...], apiUser, apiKey}`. Ids are bare — strip any `SBC-` prefix.
- **UTT** — `POST https://utautotransfer.com/api/getOrder`, form-encoded `{apiKey, idOrder}`. It
  answers `200` with no `order` key for an id it does not know, so a `200` is not proof of an
  order.

The credentials live on the server as `SUPPLIER_FFT_API_USER`, `SUPPLIER_FFT_API_KEY` and
`SUPPLIER_UTT_API_KEY`. Read them through the client as above rather than echoing them into a
shell history.

## 5. Recording a reference that was placed by hand

There are exactly two writers of a placement, and both go through
`App\Actions\Fulfillment\RecordSupplierPlacement`, so both take the same locks, the same
idempotency keys and the same refusals.

**On an order that already exists** — the case this runbook is about — there is no admin screen.
The endpoint the workflows use is the only surface, and the practical way to reach it at 2am is
the action behind it:

```bash
php artisan tinker
>>> app(App\Actions\Fulfillment\RecordSupplierPlacement::class)->execute([
...     'order_item_public_id' => '01K52J0V3B7X2Y9Q5H8N1M4R6V',   // the ITEM, not the order
...     'supplier' => 'fft',                                       // 'fft' or 'utt'
...     'supplier_order_id' => '574339',
...     'delivery_phase' => 'coins',                               // 'coins' or 'challenge'
...     // 'challenge_ids' => ['c6d05f3b-...'],                    // required for 'challenge'
... ]);
```

It answers `['outcome' => 'recorded', 'jobPublicId' => ...]`, or `replayed` when the same reference
is already on that item — both mean the store now knows. Anything else is a refusal that has to be
read rather than retried: `supplier_reference_conflict` (that reference is on another order),
`item_conflict` (this item already has a different reference for that phase),
`challenge_ids_required`, `not_automated`, `unpaid`. `docs/api/n8n-fulfillment-v1.md` explains each.

The signed HTTP surface is the same action and the same outcomes, for when the report is being
driven from n8n rather than a shell:

```text
POST /api/automation/v1/fulfillment/placements
```

**On an order being created now**, the drawer does it properly and should be used instead: admin
**Orders** → **New order** → **Delivery** → **I already placed it by hand**, then per item
**Supplier**, **What was placed**, **Supplier order reference**, and **Challenge IDs** for a
challenge. The job is bound as the order is created.

*Known gap, 2026-09-17:* the drawer's other option, **Not placed yet**, says "someone places it and
adds the reference afterwards" — and that "afterwards" has no screen. Until it has one, the tinker
call above is the path, which is why this section exists.

Either way, polling starts on the next tick of `fulfillment:poll` (every minute) and the job moves
to `in_progress`.

## 6. The reference exists but the readings stopped

`stale` means nothing is reading the job; `silent` means reads are being attempted and are failing.
The first is almost always ours.

1. **Is the loop running at all?** The poller logs `Fulfillment poll completed.` once a minute. No
   line for an hour and every `stale` alarm has one cause: the scheduler cron. Check it the way
   `hostinger-deployment.md` describes, then `php artisan schedule:list`.
2. **Force one pass** and read what it says: `php artisan fulfillment:poll --deadline=20`. The
   summary line carries `attempted`, `observed`, `unreadable`, `unavailable`, `not_configured` and
   `circuit_skipped`, which is usually the whole diagnosis.
3. **Is the job still due?** A live job with `next_poll_at` null is one the reconciler stopped, and
   a `leased_until` far in the future is a lease a killed tick never released.
4. **Ask the supplier by hand** (§4). A supplier that does not recognise the id means the recorded
   reference is wrong: there is no un-place, so correct it only when the real reference is known,
   and leave the wrong one in the note on the order.
5. `not_configured` in the summary is a missing supplier key on the server, not a supplier fault.
   It is logged at error level for that reason.

## 7. Closing the alarm

**The sweep closes what the sweep opened.** An alarm resolves when its condition is gone — a
placement recorded, a reading landed, the item cancelled or refunded — and nothing else should
write `resolved_at`. To close it now instead of within five minutes:

```bash
php artisan fulfillment:alarms
```

It prints `Raised N, resolved N, N open, N notified.`, and the panel's count is the `open` number.
Resolved rows stay in the table as history; the same silence returning reuses the row and mails
once more.

Closing a row by hand while the condition is still true does not help: the next sweep opens it
again and mails about it again, because the sweep reads the world rather than the table.

## 8. What recovery cannot do

- **There is no un-place.** Once a supplier has the order, the store has no cancel path for it.
  Money already spent at a supplier is recovered through the supplier, not through the store.
- **A refund is the admin refund path**, not this runbook, and it does not stop a supplier that is
  already delivering.
- **Nothing here is said to the customer.** The order page already shows the customer what state
  their order is in; a supplier's name and our internals never appear on it.

## Related

- `docs/api/n8n-fulfillment-v1.md` — the placement contract and every refusal it can answer with.
- `docs/api/supplier-endpoints.md` — the whole FFT and UTT call set, and the units trap in it.
- `docs/operations/hostinger-deployment.md` — the scheduler and the release layout.
- `docs/operations/hostinger-rollback.md` — rolling the release back, and what a rollback leaves
  untouched at the suppliers.
