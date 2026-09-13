# A manual order gets its own action, not `PlaceOrder`

**Date**: 2026-09-13
**Status**: accepted

## The rule this appears to break

The admin skill's non-negotiables say: "Reuse existing Actions and domain rules. Do not
create an Admin-only duplicate of checkout, refund, pricing, wallet, or fulfillment
logic." The plan for slice G1 said manual orders would reuse `PlaceOrder` for exactly
that reason, and a future reader finding a second order-writing action will reasonably
ask whether that rule was simply ignored.

It was not. `PlaceOrder` cannot serve a manual order, and the measurement is below.

## What `PlaceOrder` requires

Read against `app/Actions/Checkout/PlaceOrder.php`, not from memory:

| line | requirement | a manual order |
| --- | --- | --- |
| `:149` | an **active cart** for the user, locked for update; no cart is `CheckoutUnavailable` | has no cart, and staff must not touch a customer's live cart |
| `:90` | `phone_verified_at` is not null **and** the phone matches E.164 | is created for imported customers whose phones were never verified through this store (owner decision, 2026-09-13: create it anyway — the check exists so a customer cannot buy against a wrong number, and here staff are the ones acting) |
| `:175` | every line repriced live against the catalogue | is priced from the catalogue but the price is **overridable** (owner decision, 2026-09-13) |
| `:250` | subtotal below 500 halalah is refused (the Paylink minimum) | a gift has a zero total |
| `:313`, `:357` | **always** creates exactly one payment, `wallet` or `paylink`; `CheckoutResult` requires a non-null payment | a gift has no payment at all |
| `:374` | marks the cart converted | has no cart to convert |

Five of those six are refusals, not preferences. Bending `PlaceOrder` to accept a
cartless, paymentless, unverified, zero-total order means adding a second mode through
all of it - which is a worse outcome than a separate action, because the storefront's
checkout would then carry branches that only staff reach.

## What is actually reused

The pieces, not the orchestration:

- `App\Checkout\OrderNumber::generate()` for the number
- the `createOrderItem()` field shape (`:627-686`), so an item written by staff is
  indistinguishable in structure from one written by checkout
- the idempotency-claim pattern (`:402-419`)
- `ProductVariant::effectivePriceHalalah()` for the suggested price - the single
  definition every quote, cart and checkout path already reads through
- `AccrueOrderCashback` and the review invite untouched: they fire from
  `TransitionAdminOrder` on completion and need no manual-order branch

Pricing, cashback, loyalty and fulfillment logic are therefore not duplicated. What is
new is only the writing of an order that no cart produced.

## Consequences

- One more action that creates orders, so one more place to keep in step if the order
  schema changes. The item shape is the shared part and the risk is concentrated there.
- A manual order skips phone verification, by decision. The record of who created it is
  the control: every creation writes a staff audit row.
- Because the new action does not go through the Paylink minimum, a zero-total order is
  representable. That is what makes a gift possible at all.
