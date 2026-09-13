# Domain vocabulary

The words this store's code uses, and what each one means. A glossary, not a spec:
nothing here describes how anything is built. Where a term was settled by an owner
decision, the date is given.

## Orders

**Order** — one purchase by one customer, carrying its own money columns and a list of
items. It is addressed everywhere by its **order number**, never by its internal id.

**Order number** — the short handle on every visible surface. Three shapes exist and all
three resolve: `AUT-1043` (issued by the store today), `AUT-K7M2QX` (the older random
form), and a bare Salla number such as `277538068` (what the importer stores verbatim,
and what every order in production carries). The 26-character ULID is the internal join
key and never appears in a generated URL.

**Channel** — where an order came from, and the only thing that changes how it behaves:

| value | meaning |
| --- | --- |
| `store` | a customer bought it themselves through checkout |
| `salla_import` | history brought over from the Salla store; read-only, earns nothing |
| `manual` | staff created it on a customer's behalf (owner decision, 2026-09-13) |

A `manual` order behaves like a `store` order in every rule that today asks whether the
channel is `salla_import`.

**Manual order** — an order staff create for a customer who paid outside the store, or
who is being given something. It is a real order: a real number, real items, the customer's
own account, the tracking page and the shareable link. It is rare.

**Gift** — a manual order with a zero total and no payment. Nothing marks it beyond that,
because a bank-transfer manual order always carries money and a gift never does, so the
two cannot be confused (owner decision, 2026-09-13). A gift earns no cashback, and needs
no rule saying so: the cashback basis is the total minus wallet, which is zero.

## Money

**Halalah** — the unit every money column is stored in. 100 halalah is 1 SAR. No money
is ever stored as a decimal.

**Payment** — a record of money actually received, with a provider naming how. `wallet`
and `paylink` exist today; a manual order adds one for a bank transfer.

**Eligible spend** — what counts towards a customer's loyalty tier: an order's total, once
the wallet amount and the captured payments on it cover that total. An imported order is
counted without that check, because its payments were never recorded here.

**Cashback** — a wallet credit earned once per completed order, sized from the total minus
whatever the wallet already paid. An order paid entirely from wallet balance therefore
earns nothing, by arithmetic rather than by a rule.

## Fulfillment

**Fulfillment job** — the store's record of one order item being delivered by a supplier.
Exactly one job per item.

**Supplier** — the bot that actually delivers. **FFT** serves coins and challenges; **UTT**
serves coins only, so the choice is not a free swap.

**Supplier reference** — the id the supplier gave the order it is delivering. One per
item, never one per order (owner decision, 2026-09-13): each item is a separate order at
the supplier, and a job is bound to an item.

**Placement** — handing an item to a supplier. A manual order can skip it: when staff
already placed the item by hand, they paste the supplier reference and the job is bound to
it at creation.

**Phase** — which half of a challenge delivery a job is in. Coins arrive first; the
challenge is solved after. A later phase re-opens polling on a job whose earlier phase
finished.

**Observation** — what a supplier last said about a job, stored with the time it was read.
The tracking page renders the stored observation and its age; it never calls a supplier
while rendering.

**Hold reason** — why an order is waiting, in the customer's terms. Seven of them recover
on their own without the customer doing anything, and those still offer the button, because
"wait" and "try now" are different answers and only the customer knows which they want
(owner decision, 2026-09-13).

## People

**Customer** — someone who buys. **User** is the account row; a customer is a user with the
customer role. The two are not interchangeable in the code and should not be in speech.

**Staff** — an account that can work the admin dashboard. Staff may create a manual order,
gifts included (owner decision, 2026-09-13); every creation records who made it.

## Access

**Capability link** — a per-order signed link that opens the tracking page with no sign-in,
for sending over WhatsApp. It shows status only: never a price, a payment, or anything the
account page keeps behind a login. Its actions stop once the order is terminal.
