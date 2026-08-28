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

Each service maps into the reason set explicitly, so nothing is left to an implementer's judgement:

| Condition | Reason |
| --- | --- |
| variant missing, `is_active` false, or product not storefront-visible | `variant_inactive` / `product_hidden` |
| SBC `SbcCompletionPricing::tierTotal()` returns null (`app/Actions/Checkout/PlaceOrder.php:483-507`) | `tier_removed` |
| coins quantity no longer offered by any live variant | `tier_removed` |
| `SbcCompletionPricing::fromConfiguration()` throws `DomainException` | `configuration_invalid` |
| manual-service rank or division route absent from the live schedule (`app/Actions/Checkout/PlaceOrder.php:526-548`) | `schedule_route_removed` |

`quoted_at` is set to the ISO-8601 timestamp of the repricing call, in the same format
`AddCoinsToCart` writes. `price_version` is recorded for every service; `schedule_version` **only** for
FUT Champions and Rivals — the coins, catalog, and SBC configuration allow-lists do not carry it
(`app/Support/SafeOrderItemConfiguration.php:28-38`), and manual-service configurations are checked
against an exact key set (`app/Actions/Checkout/PlaceOrder.php:569`), so adding a key anywhere else
fails validation rather than being ignored.

### 1.1 The coins torn-read guard is kept, not deleted

`QuoteCoins` reads the coins product, variant, and pricing rules **without locks**
(`app/Actions/Pricing/QuoteCoins.php:27-41`). The only thing binding its result to the variant row
`validateItem` locks is the comparison at `app/Actions/Checkout/PlaceOrder.php:470`. Under InnoDB
repeatable read those unlocked reads come from the transaction's earlier snapshot, so while a pricing
run commits, the quote can be computed from the old rules while the locked variant already carries the
new `price_version`.

That comparison is an **internal-consistency guard**, not the staleness check this design removes, and
`RepriceCart` keeps it — but **split into its two legs**, which mean opposite things. The comparison at
line 470 is compound (`variantId` **or** `priceVersion`), and collapsing both into one outcome would
strand a whole class of carts forever:

- **`priceVersion` differs, same variant** — a pricing run is committing right now.
  `ApplyCoinsPricingRun` only increments `price_version` on the existing rows
  (`app/Actions/Pricing/ApplyCoinsPricingRun.php:56-60,98-100`), so this is genuinely transient: one
  short transaction. Outcome `pricing_run_in_progress`, reported by the checkout route as
  `pricing_updating` (`503`, "الأسعار بتتحدث دلوقتي، حاول بعد لحظات"). No cart mutation, no order.
- **`variantId` differs** — this is **permanent**, not a run window. Pricing runs never change variant
  ids; a different id means the old variant was deactivated and replaced, since
  `CoinsCatalogReader::variant()` resolves the single *active* variant per platform
  (`app/Services/Catalog/CoinsCatalogReader.php:36-44`). Outcome: reprice onto the quoted variant and
  record that `product_variant_id` on the order item, or `tier_removed` when the quantity is no longer
  offered. Routing this to `pricing_updating` would loop the customer through a transient-retry message
  that can never clear.

This guard fires only under locks, so it belongs to the checkout path. On the unlocked render path the
quote and the variant read come from the same snapshot and cannot disagree on `priceVersion`; the cart
page therefore has no retry notice for it. Without the guard, an order could be charged old-rule totals
while its configuration records the new `price_version` — the exact mis-documentation §3 exists to
prevent.

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
carry live values. An unavailable item also carries `promotion: null`: `safeCartItem`'s fallback
`$discountResult?->linePromotion(…) ?? $this->itemPromotion($cartItem)`
(`app/Http/Controllers/Store/CartController.php:157`) would otherwise resolve a promotion badge from
the stored total on an item that has no live price.

#### Checkout eligibility must move into the `cart` payload

Checkout is blocked while any item is unavailable — but `canCheckout` currently lives in the `cartPage`
prop (`app/Http/Controllers/Store/CartController.php:62-65`), and every partial reload on the cart page
requests only `cart` (`resources/js/pages/store/cart.tsx:285,631,656`). Inertia runs the controller but
delivers only the requested prop, so `cartPage.checkout` stays stale. Item removal is worse: it is a
plain XHR that filters local state and reloads nothing
(`resources/js/pages/store/cart.tsx:73-78,783-796`), while the button's disabled state reads the stale
prop (`resources/js/pages/store/cart.tsx:423`).

Left alone, the design's own escape hatch dead-ends: the customer removes the unavailable item and the
checkout button stays dead until a manual refresh — the exact experience this work exists to delete.
So:

- `canCheckout` moves into the `cart` payload, alongside the items it depends on;
- item removal calls `router.reload({ only: ['cart'] })` instead of filtering local state, so removal,
  repricing, and eligibility all refresh together.

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

**The client computes both headers over the same filtered set the server does** — unavailable items
excluded. The client currently sums every item (`resources/js/pages/store/cart.tsx:65-71`); if it
summed a set the server filtered, every checkout would be refused. `canCheckout: false` hides this
today, but the two rules only work stated together.

**Both `previous…` figures in the refusal payload are the request's own headers**, not something the
server reconstructs. The server never stored what the customer was shown: cart rows hold
pre-repricing prices and discounts recompute from live rows. Nothing else can supply them.

**This breaks the existing checkout tests and that churn is part of the work.** Twelve checkout POSTs
send no expected-total header today (`tests/Feature/Checkout/PaylinkCheckoutTest.php:99,110,113,134,141,151,203,209,224,240,270`
and `tests/Feature/Checkout/CartWalletTest.php:297`); under a mandatory header every one of them fails
and must be updated.

**Accepted consequence:** figures *below* the two totals can still move while both totals hold — two
items shifting in opposite directions by the same amount, or a promotion-versus-coupon split changing
the recorded `discount_halalah`. Those check out unconfirmed and record per-item prices the customer
was not shown. Owner decision 2 is about the payable total, and per-item expectations would mean
shipping the whole cart in the request. Recorded here as accepted, not overlooked.

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
- **Only the minimum-spend rejection is a repricing event.** `DiscountEngine` rejects coupons for
  invalid, expired, first-order-only, and usage-limit reasons *before* it reaches the minimum check
  (`app/Checkout/DiscountEngine.php:618-649` versus `:686-687`). A coupon that expired between render
  and pay has nothing to do with repricing, and presenting it as `cart_repriced` with "the total fell
  below the minimum" copy would be a lie. `CouponRejected` carries a `reason`
  (`app/Exceptions/Checkout/CouponRejected.php:10-12`); **only `CouponRejection::Minimum` takes the
  path below.** Every other reason keeps today's `StaleCartCoupon` behaviour unchanged.
- **The minimum path needs a second discount pass.** `DiscountEngine` throws before any
  `DiscountResult` exists (`app/Checkout/DiscountEngine.php:258-261`), so the new totals the payload
  promises are never computed. `PlaceOrder` catches `CouponRejected`, and on the minimum reason re-runs
  `calculateForSnapshots()` **with `$coupon = null`**, still inside the transaction, to obtain the
  coupon-free totals; it then raises `CartRepriced` carrying them with `couponRemoved: true`. The
  second call cannot itself re-throw, and the engine's only cross-call state is the benign
  `activeCartPromotions` memo (`app/Checkout/DiscountEngine.php:43,830-838`). The figures survive the
  rollback because they live on the exception, not in the database.
- **`execute()` needs a `CartRepriced` handler that clears the coupon.** Today's clearing runs only in
  the `StaleCartCoupon` catch (`app/Actions/Checkout/PlaceOrder.php:97-104`), which the new exception
  never reaches. Without a handler the loop never terminates: coupon attached → reprice below minimum →
  `cart_repriced` → customer confirms → coupon still attached → rejected again → `cart_repriced` again.
  `execute()` therefore catches `CartRepriced` and, when `couponRemoved` is set, performs the same
  `coupon_id => null` update outside the transaction before rethrowing.
- **The coupon is cleared even if the customer cancels.** The coupon genuinely no longer qualifies for
  this cart, so leaving it attached would only reproduce the refusal. It is still a cart mutation the
  customer did not consent to, so it must be stated in the dialog and in the reloaded cart —
  "الكوبون اتشال لأن الإجمالي نزل تحت الحد الأدنى" — never applied silently.
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
`checkout_cart_changed` string keeps its place for the genuine cart-changed cases, while
`store.checkout.price_changed` (`lang/ar/store.php:122`, `lang/en/store.php:125`) is orphaned by
`cart_repriced` and is removed.

The cart page maps the two new codes explicitly. It currently maps only `cart_changed` and falls back
to a generic message (`resources/js/pages/store/cart.tsx:434-436`), which would render a `429` from
`throttle:coins-cart` — 10 requests per minute per owner, shared with item removal, coupon, and wallet
toggles (`app/Providers/AppServiceProvider.php:68-73`, `config/coins.php:10`) — as an unexplained
checkout error. A fix-the-cart-then-confirm sequence can plausibly reach that ceiling, so `429` gets
its own "حاول بعد دقيقة" message rather than a shrug.

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
- **Pest, coupon loop** — after a `cart_repriced` carrying `couponRemoved: true`, the coupon is
  detached and the confirming re-submit succeeds. Without the `CartRepriced` handler this test loops.
- **Pest, coupon reason split** — an expired coupon still produces `cart_changed`, not `cart_repriced`.
- **Pest, coins variant replaced vs pricing run** — a `variantId` mismatch reprices onto the live
  variant, while a `priceVersion`-only mismatch returns `pricing_updating`. One test per leg, so
  collapsing them again fails loudly.
- **Vitest, eligibility refresh** — removing an unavailable item re-enables the checkout button
  without a full page reload.
- **Existing suite** — the twelve checkout POSTs listed in §3 are updated to send both headers.
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

Reviewed again the same day by a separate read-only OpenCode session (`opencode-go/glm-5.3-flash`),
targeting the three fixes above because they were new and unreviewed. Verdict:
`APPROVE WITH CHANGES`. It confirmed fixes 1, 3, 4, and 5 hold, and found three further defects — two
of them introduced by the first round's own fixes:

1. §1.1 collapsed the compound guard at `app/Actions/Checkout/PlaceOrder.php:470` into one outcome,
   which would route the **permanent** variant-replacement case into an endless transient-retry 503 and
   make §1's replacement rule unreachable — now split by leg (§1.1);
2. blocking checkout via `cartPage.canCheckout` dead-ends against the existing Inertia partial reloads
   and the local-state removal flow, so removing the unavailable item would leave the button dead —
   the flag moves into the `cart` payload and removal reloads it (§2);
3. `CartRepriced` had no coupon-clearing handler, making the confirm flow loop forever (§5).

It also caught that only `CouponRejection::Minimum` is a repricing event, that both `previous…` figures
can only come from the request headers, that the client must filter unavailable items from its own
header sums, and that the mandatory header breaks twelve existing checkout tests. All are folded in
above.

Both reviews independently agreed the architecture is sound and that every defect found was a
specification defect, not a structural one.

## Complexity

Medium. One extracted action plus a value object, focused edits in `PlaceOrder`, `CartController`,
`PaylinkCheckoutRequest`, and `PaylinkCheckoutController`, two language files, one storefront page, and
the test set above.
