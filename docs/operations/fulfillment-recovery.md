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

The mail line and the alarm row say which of three things happened. They need different work.

| The line reads | Alarm kind | What it means | Go to |
| --- | --- | --- | --- |
| `لم يُرسل لأي مورد بعد` | `unplaced` | Paid, no supplier reference, and the store has not written down a reason | §2 |
| `متوقف ولن يُرسل بدون تدخل` | `unplaced`, blocked | Paid, and the store is refusing to compose the request. The reason code is on the same line | §3, then §2 |
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

`context` carries the order number, the service, and — for a `silent` alarm — the supplier, the
reference and the failed-read count. For an `unplaced` alarm it carries `reason` and `blocked` when
the store has written one down.

## 2. Has anybody already placed it — the three states

A paid order with no supplier reference is in one of three states, and only one of them is safe to
place by hand.

**State A — nothing left the store.** No supplier has heard of this order.

**State B — a supplier already has it and the store does not know.** n8n placed the order and the
store's record of the placement was lost. Placing it again buys the same coins twice at today's
prices and puts a second bot on one EA account.

**State C — the store is refusing to compose the request.** Nothing reached n8n and nothing will,
until the thing named in §3 is fixed. Nothing is at a supplier.

> **The outbox row cannot tell State A from State B, and nothing below changes that.** The store
> records `delivery_failed` whenever n8n did not answer `acknowledged: true` within sixty seconds —
> and the workflow places the supplier order *before* it answers. A run that bought coins at second
> 58 and answered at second 61 is written down here as a failure. Worse, `ship-coins` answers
> `acknowledged: false` **by design** when an order needs several shipments and it has just placed
> the first one, and the store records that as `delivery_failed` too. So `delivery_failed` is not
> merely inconclusive: it is the normal reading for an order that *was* partly placed.
>
> **Nothing in this section authorises a manual placement.** Only two things do, and both are
> needed: the n8n execution history showing no placement call succeeded for this order (b), and the
> supplier showing no matching order on that EA account in that window (c). Read the outbox row to
> know where to look first — never to decide.

**a. The outbox row — where to look first.** One row per paid order, keyed `order-paid:<order id>`.

```bash
php artisan tinker
>>> $order = App\Models\Order::where('order_number', 'AUT-1234')->sole();
>>> App\Models\IntegrationEvent::where('event_type', 'order.paid')
...     ->where('aggregate_id', $order->public_id)
...     ->first(['event_id', 'status', 'attempts', 'available_at', 'last_error']);
```

| What it says | What it does and does not mean |
| --- | --- |
| `status: pending`, `last_error: delivery_failed` | n8n did not acknowledge. It may still have placed — a timeout, a lost response, or a deliberate `acknowledged: false` after placing one shipment of several. **A or B; check both (b) and (c).** |
| `status: pending`, any other `last_error` | The store never got as far as sending, so nothing is at a supplier. State C — §3. This is the one reading that stands on its own. |
| `status: processed` | n8n answered `acknowledged: true`. It believed it had placed. **State B until the supplier says otherwise.** |
| `status: failed` | Retired after ten attempts; `last_error` still names what they failed on. Read that value in the two rows above — a composition reason is C, `delivery_failed` is A or B. |
| No row at all | Nothing was ever queued, so n8n was never told. State A — but (b) and (c) still apply, because a person may have placed this order by hand earlier without recording it. |

**b. n8n's own execution history — mandatory.** Open `ship-coins` (and `solve-challenge` for a
challenge) and search the executions for the order number: the request payload carries
`order_number`, and every failure the workflow throws starts with it. Read every execution for that
order, not just the last: one may have placed a shipment and a later one failed.

- An execution that reached `External: Buy Coins` or `UTT: Add Order Public` and came back with an
  id **placed an order**. That id is the reference to record in §5, and no new placement is needed
  for the items it covered.
- An execution that threw before those nodes placed nothing.
- No execution at all for that order number means n8n was never asked — consistent with State A,
  and still not sufficient on its own.

If execution data is off on that workflow, or the runs have aged out, treat the answer as unknown
and rely entirely on (c).

**c. The supplier — the only proof.** §4. Check the EA account for an order in the window between
payment and now, whatever (a) and (b) said. Only when both (b) and (c) come back empty is a manual
placement safe.

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
the action behind it. **Do not run this until §2 (b) and (c) both came back empty, or until you
hold a reference a supplier actually issued.**

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

A `silent` alarm means the poller is reading and the reads keep coming back with nothing — six of
them in a row, which is the better part of an hour. The counter behind it (`poll_failure_count`)
only rises when a read was attempted, so the first question is still whether anything is reading at
all.

1. **Is the loop running at all?** The poller logs `Fulfillment poll completed.` once a minute. No
   line for an hour is the scheduler cron, not the supplier, and no alarm reports that on its own —
   a stopped loop leaves every job looking exactly as healthy as it did the minute it stopped.
   Check the cron the way `hostinger-deployment.md` describes, then `php artisan schedule:list`.
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
