# Arab UT — Domain Context

A glossary, and nothing else. No implementation details, no decisions, no plans.
Decisions live in `docs/decisions/`. Where this file and the code disagree on a word,
this file states the intent and the disagreement is worth fixing.

## The order

**Order** — what a customer pays for in one transaction. Carries one or more Order Items
and one public handle the customer quotes to support.

**Order Item** — one purchased service on an Order. Each Item has its own service type,
platform, configuration, account credentials and progress. An Order is never delivered as
a single unit: its Items are. One Item finishing must never make another Item's failure
invisible.

**Service Type** — Coins, SBC, Objectives, Rivals, FUT Champions.

**Automated service** — Coins and SBC. Delivered by a Supplier bot with no human in the path.

**Manual service** — Objectives, Rivals, FUT Champions. Delivered by a person Mohamed
coordinates directly.

## Fulfillment and Tracking are two different things

Owner's distinction, stated 2026-09-12. These were previously conflated under the single
word "fulfillment", which caused a design round to be built on the wrong model.

**Fulfillment** — *placing* a paid Order Item with a Supplier and recording the Supplier
Order Reference that comes back. Fulfillment is a write, and it is finished the moment the
item is lodged with the Supplier and its reference is stored. Mohamed's phrase for it is
"حط الطلب في البوتات".

**Tracking** — *reading* the progress of an already-placed Item back from its Supplier and
showing it to the customer. Tracking is a read, and it can only begin after Fulfillment
has recorded the reference.

**Supplier Order Reference** — the identifier a Supplier assigns to an Item it has accepted.
It is the only join between an Arab UT Order and the work happening inside a Supplier.
Recording it is the last act of Fulfillment and the first requirement of Tracking; without
it an Item is paid for and invisible.

> Conflict worth knowing: the codebase's `fulfillment_jobs` table and `FulfillmentStatus`
> enum span both concerns at once. The glossary splits them because the owner does.

## Suppliers

**Supplier** — the external service that performs an automated delivery. Exactly two exist.
Mohamed calls a Supplier a "بوت".

**FFT** — FUT Transfer. Default Supplier for both Coins and Challenges.

**UTT** — UT Auto Transfer. Alternate Supplier, Coins only. Reports its own state
vocabulary, which means nothing to a customer until it is translated.

## Progress, state, and what the customer must do

**Order Status** — the customer-facing state of an Order or Item: pending payment,
received, in progress, waiting for customer, completed, cancelled, refunded. Seven values,
and the spine the rest of the system runs on — lists, receipts, cashback, admin
permissions, legal transitions.

**Hold Reason** — why an Item stopped, expressed as something the customer can act on
rather than as a supplier error code.

**Progress** — how far a delivery has actually got: coins transferred of coins ordered,
challenges solved of challenges requested. Progress is *not* a status. One status covers
many progress values, and quoting a percentage is not the same as naming a state.

**Supplier State** — the raw state word a Supplier reports. An internal fact, never a
customer-facing concept. It is translated into an Order Status plus a Hold Reason.

**Self-service action** — something a customer does to their own Order to unblock it:
correcting account credentials, resuming a stopped delivery, retrying a failed challenge.
Owner rule, 2026-09-12: the customer keeps full control of these.

## Correcting credentials is a two-step protocol, not a request and a verdict

Owner's description, 2026-09-12. This shapes every screen and state machine that touches a
credential fix, and getting it wrong makes the store lie to the customer.

When an Item is held because the account details are wrong and the customer submits corrected
ones, the Supplier answers **immediately** — and that answer means only "received". It is not a
judgement on whether the new details work. The Item then moves to trying again, and the Supplier's
bot attempts a fresh login some time later. That attempt is where the truth appears: either the
details are wrong again and the Item returns to the same hold, or they work and the delivery
starts.

So a credential submission has three outcomes, not two: **received**, then later **worked** or
**wrong again**. The third arrives in a subsequent observation, not in the response to the
submission.

Two consequences worth stating because they are easy to get backwards:

- A successful submission must never be reported to the customer as "fixed". It is "received, we
  are trying again". Saying "fixed" and then returning to the same hold reason a minute later
  reads as the store not knowing what is happening.
- An Item moving from in-progress back to the same hold reason is **correct behaviour here**, not
  a regression to be guarded against. Any rule that forbids a status going backwards has to permit
  this, or a second wrong password becomes invisible.

## Challenges

**Challenge / SBC** — a Squad Building Challenge. Mohamed calls these "طلبات التحديات".

A Challenge is not delivered on its own. Shipping coins is *how* a Challenge gets solved,
so every Challenge delivery runs in two phases against the same account:

1. **Coins phase** — the Supplier ships the coins the Challenge costs, plus any coins the
   customer separately purchased on the same Order.
2. **Challenge phase** — once the *whole* shipment has landed, the Supplier enters the
   Challenge and solves it.

So an Order for "100k coins + one Challenge" is one shipment of the Challenge cost plus
100k, and the Challenge starts only after that full amount is delivered. The coins the
customer bought and the coins the Challenge consumes travel together because they go to
one account in one session. This is the delivery mechanism, not an optimisation, and the
phase order is load-bearing: a Challenge that starts before its funding has landed fails.

"One account in one session" is the condition, not an assumption that can be skipped.
Credentials are collected per Order Item, so two Items on one Order may name two different
accounts, or two different platforms; combining their shipments is only correct when they
name the same account on the same platform going to the same Supplier. Anything else is two
shipments.

One purchased Challenge Item can require the Supplier to solve the same Challenge several
times.

> The letters SBC also name the Saudi Business Center certificate badge in the footer.
> Unrelated. Never let the two meet in one identifier.
