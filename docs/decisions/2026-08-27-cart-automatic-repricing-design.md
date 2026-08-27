# Cart Automatic Repricing Design

## Purpose

Coins prices change hourly. A customer whose cart is older than the current pricing run cannot pay at
all: `PlaceOrder` refuses any cart item whose stored `price_version` differs from the variant's, the
checkout endpoint reports `cart_changed`, and the storefront tells the customer to refresh the page.
Refreshing re-renders the cart but never re-prices it, so the instruction is false and the customer is
stuck.

This design replaces the refusal with automatic repricing: the cart always shows the live price, and
the customer confirms the current payable total before any money moves.

## Approved product decisions

Mohamed decided all four on 2026-08-27:

- Repricing happens **both when the cart page is opened and again when checkout is submitted**. The
  cart page therefore never displays a price the customer cannot pay, and the checkout re-check closes
  the window between rendering and pressing pay.
- **Confirmation is required in both directions.** Any change in the payable total — up or down —
  stops the charge and asks for an explicit confirmation. One rule, no case where a customer is
  charged a figure they were not shown. This matters most on the wallet path, where the debit is
  immediate and there is no hosted invoice for the customer to check.
- Items that **cannot** be repriced (variant deactivated or hidden, the coins tier for that quantity no
  longer exists, a FUT Champions rank or Rivals division removed from the live schedule) are **marked
  unavailable in place and block checkout until the customer removes them**. Nothing is deleted from a
  customer's cart without their action; coins and manual-service items carry credentials and
  attachments the customer entered by hand.
- Scope is **all five services**: Coins, Catalog, SBC, FUT Champions, Rivals. They already share one
  pricing function inside `PlaceOrder`, so covering all five costs little more than covering one and
  leaves no remaining dead end.

## Current-state constraints

- `PlaceOrder::currentPrices()` and `currentManualServicePrices()` already compute the authoritative
  live price for all five services. They are `private` and they signal every failure by throwing
  `CheckoutUnavailable`, so nothing else can reuse them and no caller can distinguish "price moved"
  from "item gone".
- `PlaceOrder::validateItem()` refuses on `configuration['price_version'] !== $variant->price_version`
  and on any stored-versus-live price mismatch (`app/Actions/Checkout/PlaceOrder.php:409`).
- `PaylinkCheckoutController` flattens every `CheckoutUnavailable` that is not the phone case into a
  single `cart_changed` 422 with a fixed message.
- `CartController` renders `cart_items.unit_price_halalah` and `total_halalah` verbatim and performs no
  pricing at all.
- The confirmation mechanism already exists: the storefront sends `X-Expected-Total-Halalah`, and
  `PlaceOrder` refuses when it does not equal the computed payable
  (`app/Actions/Checkout/PlaceOrder.php:197`). It is currently optional — `null` skips the check.
- `DiscountEngine::calculateForSnapshots()` already runs on the per-item snapshots produced by
  `validateItem`, so checkout-side discounts follow repriced totals automatically. The cart page uses
  `calculateForCart()` plus `PromotionPricing::resolve()`, both of which read `$cartItem->total_halalah`.
- `CheckoutFingerprint::generate()` builds the idempotency request hash from the cart, locale, and app
  key — not from the expected total.

## Architecture

### 1. One shared repricer

Extract the pricing math out of `PlaceOrder` into `App\Actions\Cart\RepriceCart`, the single answer to
"what does this cart item cost right now".

```
RepriceCart::execute(Cart $cart, bool $lock = false): CartRepricing
```

`CartRepricing` holds one `CartItemPrice` per cart item, keyed by cart-item id. Each entry is one of:

- **priced** — `unitPriceHalalah`, `totalHalalah`, and the live `priceVersion` / `scheduleVersion` /
  `quotedAt` that produced them;
- **unavailable** — a machine-readable reason from a closed set: `variant_inactive`,
  `product_hidden`, `tier_removed`, `schedule_route_removed`, `configuration_invalid`;
- **indeterminate** — `pricing_run_in_progress`, described in §1.1.

The action **returns** outcomes; it never throws for a business condition. This is the whole point of
the extraction: the cart page needs to render an unavailable badge where checkout needs to refuse, and
today both paths share one exception.

`$lock = true` applies `lockForUpdate()` to the variant rows and passes `lock: true` to
`ReadManualServicePricing`, exactly as `PlaceOrder` does today. The cart page calls it with
`$lock = false`.

When a coins item's live quote resolves to a **different** variant than the one on the cart row
(`CoinsQuote::$variantId`), the item is repriced onto the live variant and the snapshot records that
`product_variant_id`; the tier only counts as `tier_removed` when no live variant offers it.

`quoted_at` is set to the ISO-8601 timestamp of the repricing call, in the same format
`AddCoinsToCart` writes.

### 1.1 The coins torn-read guard is kept, not deleted

`QuoteCoins` reads the coins product, variant, and pricing rules **without locks**
(`app/Actions/Pricing/QuoteCoins.php:27-41`). The only thing binding its result to the variant row
`validateItem` locks is the comparison at `app/Actions/Checkout/PlaceOrder.php:470`. Under InnoDB
repeatable read those unlocked reads come from the transaction's earlier snapshot, so while a pricing
run commits, the quote can be computed from the old rules while the locked variant already carries the
new `price_version`.

That comparison is an **internal-consistency guard**, not the staleness check this design removes, and
`RepriceCart` keeps it. Disagreement is neither "priced" nor "unavailable": it yields
`pricing_run_in_progress`, which the checkout route reports as a transient `pricing_updating` (`503`,
"الأسعار بتتحدث دلوقتي، حاول بعد لحظات") and the cart page renders as a retry notice. No cart mutation,
no order. Without this, an order could be charged old-rule totals while its configuration records the
new `price_version` — the exact mis-documentation §3 exists to prevent.

`PlaceOrder::currentPrices()`, `currentManualServicePrices()`, and `validManualConfiguration()` move
into the action. `PlaceOrder` keeps its structural configuration validation and its secret, attachment,
and quantity rules.

### 2. Cart page — live prices, no writes

`CartController` calls `RepriceCart` without locks and assigns the live figures onto the **in-memory**
`CartItem` models before computing discounts:

- The stored `cart_items` row is never written on this path. A GET request must not mutate the cart,
  two open tabs must not race, and the stored values keep a useful meaning of their own: *the price
  when the item was added*.
- Discounts must be computed from live totals or a promotion tier and a coupon minimum would be
  evaluated against stale numbers. Assigning to the loaded models — with an explicit comment and a
  regression test asserting no write occurs — keeps `DiscountEngine::calculateForCart()` and
  `PromotionPricing::resolve()` unchanged.
- **Unavailable items are excluded from the discount computation entirely.** They have no live price,
  and `calculateForCart()` builds a line for every item at `$item->total_halalah`
  (`app/Checkout/DiscountEngine.php:845-865`), so carrying a stale figure would let a dead item satisfy
  a coupon minimum or a bundle tier. They render with their stored price struck out and an unavailable
  badge, contribute nothing to the totals, and the summary is labelled provisional while any remain.

Each item's payload gains three fields:

| Field | Meaning |
| --- | --- |
| `previousTotalHalalah` | the stored total, present only when it differs from the live total |
| `priceChanged` | `true` when the live total differs from the stored total |
| `unavailableReason` | the closed-set reason, or `null` |

`unitPriceHalalah` and `totalHalalah` continue to be the numbers the customer is charged — they now
carry live values. `checkout.canCheckout` becomes false while any item is unavailable.

### 3. Checkout — reprice, then require the shown total

`PlaceOrder::validateItem()` stops comparing versions. Inside the existing transaction it takes the
locked repricing result and **adopts** it:

- an `unavailable` entry throws `CheckoutUnavailable` with the reason, as today;
- a `priced` entry supplies `unit_price_halalah` and `total_halalah` for the snapshot, replacing the
  stored values;
- the snapshot's promotion is resolved from the **live** total, not the stored one
  (`app/Actions/Checkout/PlaceOrder.php:437-442`);
- the snapshot's configuration records the **live** `price_version`, `schedule_version`, and
  `quoted_at`, so the order documents the version actually charged rather than the one the cart was
  built at. Without this the order item would record a stale version.

Cart rows are still not written: on the success path the cart is consumed into an order, and on the
refusal path the transaction rolls back.

#### Two expected totals, both mandatory

Today's single check compares `$expectedPayableHalalah` against `$paymentHalalah` — the total **after**
the wallet deduction (`app/Actions/Checkout/PlaceOrder.php:194-197`) — and the client sends exactly that
post-wallet figure (`resources/js/pages/store/cart.tsx:269-274`). Because
`walletPart = min(balance, total)`, a fully wallet-covered cart computes a payable of `0` on both sides
**whatever the order total is**. A cart shown at 100.00 SAR, fully covered, whose price rises to 120.00
would pass that check and debit the wallet 120.00 without confirmation. Today only the per-item
staleness check this design deletes prevents it. Comparing one figure is therefore not enough.

The storefront sends two headers, both **mandatory**, and either mismatch refuses:

| Header | Meaning |
| --- | --- |
| `X-Expected-Order-Total-Halalah` | the order total after promotions and coupon, **before** the wallet |
| `X-Expected-Total-Halalah` | the cash payable after the wallet deduction — today's meaning, unchanged |

The first closes the wallet hole; the second keeps a wallet-balance change (a cashback landing, an
admin credit) from silently shifting how much cash the customer is charged. `null` no longer skips
either check: an absent header is `checkout_validation_error`.

**Accepted consequence:** two items moving in opposite directions by the same amount cancel in both
sums and check out unconfirmed, recording per-item prices the customer was not shown. Owner decision 2
is about the payable total, and per-item expectations would mean shipping the whole cart in the
request. Recorded here as accepted, not overlooked.

### 4. The confirmation contract

When the recomputed payable total differs from the header, the controller answers `422` with a new code
and the figures needed to render a confirmation, instead of today's opaque `cart_changed`:

```json
{
  "error": { "code": "cart_repriced", "message": "…" },
  "repricing": {
    "orderTotalHalalah": 12000,
    "previousOrderTotalHalalah": 10000,
    "payableHalalah": 12000,
    "previousPayableHalalah": 10000,
    "couponRemoved": false
  }
}
```

No per-item array. Cancelling reloads the cart, which already renders every changed line from live
prices, and every extra field enlarges the strict parser this design requires. The dialog needs the
two totals and the coupon flag.

`PlaceOrder` carries these figures on a dedicated `CartRepriced` exception rather than stuffing them
into `CheckoutUnavailable`, so the controller maps one exception type to one response shape.

The client parses this payload with the same strictness `safeSuccess()` already applies to the success
body — exact key set, non-negative integers only — and rejects anything else as `unsafe_response`. Every
identifier that crosses the wire is a `public_id`, matching what the cart page already receives
(`app/Http/Controllers/Store/CartController.php:160`); `RepriceCart` keys its results by the internal
cart-item id and the controller maps them on the way out.

When only the wallet balance moved and the cart itself did not, the dialog uses distinct copy — the two
order totals are equal and only the payable differs, which would otherwise read as an unexplained
price change.

#### The two refusals that must also carry repricing context

A downward reprice can newly trip the Paylink subtotal floor
(`app/Actions/Checkout/PlaceOrder.php:175-177`) or the partial-wallet minimum gap
(`app/Actions/Checkout/PlaceOrder.php:201-207`). Both stay refusals — neither is a price the customer
can confirm — but both responses gain a `priceChanged: true` flag and the new total, so the customer is
told the price moved rather than meeting another opaque wall. Leaving them bare would recreate exactly
the dead end this work exists to remove.

On confirmation the storefront re-submits with a **fresh** `Idempotency-Key` and the new
`X-Expected-Total-Halalah`. The refused attempt rolled back its idempotency claim inside the
transaction, so reuse would also work; a fresh key removes any dependence on that and cannot collide
with a `CheckoutFingerprint` that changed in between.

### 5. Coupons and the wallet

- The two compared totals cover promotions, coupon, and wallet between them: the first is computed
  after promotions and coupon, the second after the wallet deduction.
- **The coupon path needs a second discount pass.** When repricing pushes the eligible net below a
  coupon's minimum, `DiscountEngine` throws before any `DiscountResult` exists
  (`app/Checkout/DiscountEngine.php:258-261`, minimum at `:686-687`), so the new totals the payload
  promises are never computed. `PlaceOrder` therefore catches `CouponRejected` and re-runs
  `calculateForSnapshots()` **with `$coupon = null`**, still inside the transaction, to obtain the
  coupon-free totals; it then raises `CartRepriced` carrying them with `couponRemoved: true`. The
  transaction rolls back as today and the coupon is cleared from the cart on the way out — the figures
  survive because they live on the exception, not in the database.
- **The coupon is cleared even if the customer cancels.** This is current behaviour
  (`app/Actions/Checkout/PlaceOrder.php:97-104`) and it is kept: the coupon genuinely no longer
  qualifies for this cart, so leaving it attached would only reproduce the same refusal. It is a cart
  mutation the customer did not consent to, so it must be stated in the dialog and in the reloaded cart
  — "الكوبون اتشال لأن الإجمالي نزل تحت الحد الأدنى" — not applied silently.
- The "below the Paylink minimum" and wallet-gap refusals stay refusals, with the added repricing
  context described in §4.

### 6. Storefront UI

Three additions to `resources/js/pages/store/cart.tsx`:

1. a notice above the item list when any item has `priceChanged`, with the old total struck through
   next to the new one on that item;
2. an "unavailable" badge on affected items, with the existing remove control emphasised and the
   checkout button disabled while any remain;
3. a confirmation dialog on `cart_repriced` showing old total, new total, and a coupon-removed line
   when applicable, with confirm and cancel. Cancel returns to the cart with the new figures applied.

This is customer-facing UI, so **AGENTS.md gate 4 applies**: inspect the WordPress reference and the
current implementation, load `frontend-design`, `ui-ux-pro-max`, and the relevant Impeccable skills,
reach parity, then refine, and finish with a `polish` pass. Arabic RTL and English LTR must both be
verified at 320px, 390px, 768px, and 1440px, with keyboard focus, 44px touch targets, reduced motion,
no horizontal overflow, and a clean console.

New translation keys land in `lang/ar/store.php` and `lang/en/store.php`; the existing
`checkout_cart_changed` string keeps its place for the genuine cart-changed cases.

## Testing

- **Pest, per service** — a cart of each of the five services, repriced up and down, reaches checkout
  and is refused with `cart_repriced` carrying the correct figures; re-submitting with the new expected
  total places the order at the new price, and the order item records the live version.
- **Pest, unavailability** — a deactivated variant, a removed coins tier, and a removed manual-service
  route each surface `unavailableReason` on the cart page and refuse checkout.
- **Pest, no-write invariant** — rendering the cart page for a stale cart changes no
  `cart_items` row.
- **Pest, coupon** — repricing below a coupon minimum yields `couponRemoved: true` and the coupon is
  cleared.
- **Pest, mandatory headers** — checkout with either expected-total header absent is rejected.
- **Pest, wallet, full coverage** — a cart **entirely** covered by the wallet whose price moved is
  refused before the wallet is debited and the balance is unchanged. This is the case a
  partial-coverage test would pass while the hole stayed open; it is written with the balance strictly
  above both the old and the new total.
- **Pest, wallet balance moved** — an unchanged cart whose wallet balance changed between render and
  pay is refused with equal order totals and differing payables.
- **Pest, torn coins quote** — a quote computed from pre-run rules against a variant already carrying
  the new `price_version` yields `pricing_updating` and places no order.
- **Pest, aggregate cancellation** — two items moving oppositely by equal amounts is pinned as the
  accepted behaviour, so a future change to it is deliberate.
- **Pest, floor refusals** — repricing into the Paylink subtotal floor and into the wallet minimum gap
  each return `priceChanged: true` and the new total.
- **Pest, idempotency** — a retry with the **same** key after a refusal succeeds (proving the claim
  rolled back), and a replayed key returns the original order without consulting the expected totals.
- **Pest, converted cart** — confirming in one tab after the cart was converted in another is refused,
  not double-charged.
- **Pest, quantity > 1** — a catalog item with quantity above one reprices unit and line total
  consistently (`app/Actions/Checkout/PlaceOrder.php:509`).
- **Pest, coins variant replaced** — a tier that moved to a different live variant reprices onto it and
  the order records that `product_variant_id`.
- **Pest, guest cart** — the cart page renders repriced and unavailable items for a guest owner.
- **Vitest** — `paylink-checkout-api` parses and rejects `cart_repriced` payloads; the cart page renders
  the notice, badge, and dialog.
- **Playwright** — the full path: stale cart, open cart page, see the notice, press pay, confirm, reach
  the hosted payment step.

Gates are `npm run ci:check` and `composer test`, both run by the orchestrator, not claimed by a worker.

## Out of scope

- Background repricing of carts while the customer is away.
- Any change to how prices are produced (`ApplyCoinsPricingRun`, admin overrides, manual-service
  schedules).
- Re-pricing an order that already exists and is awaiting payment.
- Notifying customers by WhatsApp or email that their cart price moved.

## Review record

Reviewed 2026-08-27 by a separate read-only Claude session (Fable) against the actual code. Verdict:
`APPROVE WITH CHANGES`. Three blocking defects were found and are fixed above:

1. the confirmation compared only the post-wallet payable, so a fully wallet-covered cart could be
   debited at an unshown figure — now two mandatory expected totals (§3);
2. deleting the `price_version` equality check also deleted the coins torn-read guard — now kept and
   given its own outcome (§1.1);
3. `couponRemoved: true` was unobtainable, because the coupon rejection throws before any totals
   exist — now a second coupon-free discount pass (§5).

The review confirmed as correct: the idempotency rollback and fresh-key reasoning, the in-memory
model mutation on the GET render path, and the two-tab confirmation race resolving through the cart
`lockForUpdate` and converted status. Its remaining points are folded into §2, §3, §4, §5, and the
test list.

## Complexity

Medium. One extracted action plus a value object, focused edits in `PlaceOrder`, `CartController`,
`PaylinkCheckoutRequest`, and `PaylinkCheckoutController`, two language files, one storefront page, and
the test set above.
