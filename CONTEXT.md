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

## Nothing arrives from a Supplier. We ask, or we do not know.

Owner's correction, 2026-09-12, and the single most load-bearing fact about this integration.

**A Supplier never notifies us of anything.** There is no callback, no webhook, no push. Every
change in a delivery's state is discovered because the store asked. If nobody asks, the order sits
at whatever we last recorded, however wrong that has become.

The credential fix is the clearest case. When an Item is held because the account details are
wrong and the customer submits corrected ones, the Supplier's reply is `success` - and that means
exactly two things: the details were updated, and it will try again. It is not a verdict. Whether
the new details actually work is discovered later, by polling, when the state changes to
transferring or back to the same error.

So a credential submission has three outcomes, not two: **accepted**, then later **worked** or
**wrong again** - and the third is only ever visible to a poll.

Three consequences, each easy to get backwards:

- A successful submission must never be shown to the customer as "fixed". It is "received, we are
  trying again". Saying "fixed" and then returning to the same hold reason reads as a store that
  does not know what is happening to its own orders.
- **A credential fix has to be followed by a prompt read.** A customer who has just corrected
  their password is watching the screen. Leaving them on the ordinary background cadence means
  minutes of "trying again" before anything moves, when the answer may already exist at the
  Supplier.
- An Item moving from in-progress back to the same hold reason is **correct behaviour**, not a
  regression. Any rule forbidding a status from going backwards has to permit it, or a second
  wrong password becomes invisible.

Customer-facing text for every Supplier state the tracker knows is transcribed in the tracker
repo's `STATUS_MAPPING.md`, including which states offer an edit button and which send the
customer to support instead.

## What the Suppliers actually return

Read from live orders on 2026-09-12 with the owner's keys, read-only. Written down because two of
these were being guessed from the tracker's frontend code, and two of the guesses were wrong.

**Coins progress is reported in different units by each Supplier.** FFT sends `amount` and
`amountOrdered` **in thousands** - an order of 500,000 coins reads `amountOrdered: 500`. UTT sends
`amountProcessed` and `amountTotal` as **raw coins**, which is why the normalising mapper divides
by 1000 on that side and the store multiplies on the other. Field names are `amount` /
`amountOrdered` after normalisation; `delivered` and `total` are the tracker's own computed
variables, not Supplier fields.

**Delivery overshoots.** Real completed orders show `amountOrdered: 500` against `amount: 502`,
and `amountTotal: 3000000` against `amountProcessed: 3000150`. Progress is not bounded by the
amount ordered, so anything rendering a percentage has to survive more than 100%.

**A Challenge order is not readable from the coins status endpoint.** Asking `orderStatusAPI` for
an SBC order answers HTTP 404 with the plain-text body `notFound` - not JSON, and not an error
about the order being missing. Challenge status has its own endpoint (`sbcStatusBulkAPI`).

**A Supplier status response carries secrets we did not ask for.** UTT's `getOrder` returns
`nameAccount`, `emailAccount`, `passwordAccount` and `backupCodes` - the customer's EA password in
plaintext - on **every status poll**, alongside the delivery fields. FFT's response carries
`toPay` and `sellerReceives`, our own cost. Anything that persists a Supplier payload must
therefore keep an allowlist of fields, not a list of fields to hide: the first version of ours
masked addresses, stored everything else, and would have written customers' passwords into our
database on every poll for the life of every order.

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
