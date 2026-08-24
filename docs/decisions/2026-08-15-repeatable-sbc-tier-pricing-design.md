# Repeatable SBC Tier Pricing and Cart Notice Design

**Status:** Approved by Mohamed on 2026-08-15

**Complexity:** Ambitious

## Outcome

Repeatable SBC products expose the same quantity tiers and discounts as the approved original Salla workflow, while PlayStation/Xbox and PC prices are calculated independently and exactly. The selected bundle is priced authoritatively from the signed SBC catalog snapshot, revalidated when it enters the cart and again at checkout, then preserved in the immutable order snapshot. The existing add-to-cart notice becomes larger and clearer without obscuring the mobile storefront.

## Approved product decisions

- Keep the original repeatable-tier quantities and discounts.
- An unlimited repeatable SBC starts at five completions; a one-completion purchase is not offered for that product.
- A limited repeatable SBC exposes only quantities allowed by its source limit.
- Calculate PlayStation/Xbox and PC bundle totals independently. Do not retain Salla's approximate single PC-premium workaround.
- Non-repeatable SBC behavior and pricing remain unchanged.
- The workflow remains the producer of SBC bundle prices, using the signed Laravel one-million-Coin bases. Laravel owns schema validation, price-version authority, cart resolution, and checkout revalidation.
- Keep Paylink line-item quantity equal to one because one cart line represents one priced SBC bundle. Store the number of completions explicitly in safe cart/order configuration.

## Pricing contract

### Eligibility

An SBC is repeatable only when the source marks it repeatable and `repeats` is either `null` (unlimited) or an integer greater than one. A source record with `repeats = 1` follows the non-repeatable price path.

### Tier quantities and discounts

The approved standard tiers are:

| Completions | Multiplier |
| ---: | ---: |
| 5 | 1.00 |
| 10 | 0.95 |
| 15 | 0.92 |
| 20 | 0.90 |
| 30 | 0.87 |
| 40 | 0.85 |
| 50 | 0.82 |
| 75 | 0.78 |
| 100 | 0.76 |

- Unlimited or maximum at least 100: publish the standard tiers through 100.
- Maximum from 5 through 99: publish standard tiers not exceeding the maximum. If the maximum is not already a tier, append it with `max(0.70, previousMultiplier - 0.02)`.
- Maximum from 2 through 4: publish every integer from 1 through the maximum with multiplier `1.00`.
- Non-repeatable: publish one completion only, using the existing non-repeatable formula.

### Platform totals

For each platform independently:

```text
coinMarkup(c) =
  1.15  when c < 50,000
  1.10  when 50,000 <= c < 900,000
  1.00  when 900,000 <= c <= 1,000,000
  1.025 when c > 1,000,000

perCompletion = sourceCoins * coinMarkup(sourceCoins) * 1.02 * sarPerCoin
                + 1.10 SAR labor

bundleTotalSar = round(perCompletion * multiplier * completions)
                 + 3 SAR service fee
bundleTotalMinor = bundleTotalSar * 100
```

The three-SAR service fee is charged once per selected bundle, matching the effective total produced by the original Salla base-plus-option construction. JavaScript's existing `Math.round` result is the publishing authority; Laravel consumes integer minor units and does not repeat floating-point arithmetic.

The non-repeatable formula stays byte-for-byte equivalent to the current workflow.

### Snapshot shape

Each SBC variant configuration includes one exact platform-specific pricing block:

```json
{
  "completionPricing": {
    "version": 1,
    "repeatable": true,
    "maximum": null,
    "tiers": [
      { "completions": 5, "multiplierBps": 10000, "totalMinor": 2200 },
      { "completions": 10, "multiplierBps": 9500, "totalMinor": 3910 }
    ]
  }
}
```

`maximum` is `null` only for unlimited products. Tier completions must be unique and strictly increasing; multipliers must match the approved tier-generation rule; totals must be positive integers. `priceMinor` must equal the first tier's `totalMinor`. The SBC-scoped snapshot validator rejects the complete snapshot before any write when this contract is malformed.

Laravel remains the authority for `priceVersion`. A new variant starts at version 1. A version is preserved when base, sale, and all `completionPricing.tiers` are unchanged. A change to any effective tier total, tier quantity, or availability increments the version exactly once. Changes to non-price metadata such as expiry do not increment it.

## Storefront contract

`StoreCatalogReader` exposes only a sanitized, display-currency-converted tier list; raw workflow fields, source Coin values, and pricing bases never reach the browser.

- Non-repeatable product: no extra quantity selector; the implicit completion count is one.
- Repeatable product: show a compact `عدد مرات التنفيذ` / `Number of completions` selector above the EA credential form.
- Default to the first valid tier: five for unlimited products, or one for limited products below five.
- Platform changes preserve the selected completion count because both platform variants publish the same completion choices. If malformed data breaks that invariant, the product fails closed rather than silently changing quantity.
- Selecting a tier updates the displayed total synchronously without a network request.
- The summary displays platform, number of completions, and exact converted total.
- The add request includes the selected completion count as an integer.

Arabic is RTL-native, English is LTR-native, controls are at least 44px, and the selector remains usable at 320, 390, 768, and 1440 CSS pixels. Reduced motion changes no pricing behavior.

## Cart and checkout integrity

The SBC cart request has the exact top-level fields `variantId`, `completionCount`, and `credentials`. Unknown fields remain rejected. `completionCount` is part of the HMAC idempotency fingerprint, so the same key cannot be reused with a different bundle.

`AddSbcToCart` locks the active variant and resolves the requested count against its validated `completionPricing` block. It writes:

- `quantity = 1`;
- `unit_price_halalah = selected tier total`;
- `total_halalah = selected tier total`;
- safe configuration containing `service_type`, `platform`, `market`, `completion_count`, `quoted_at`, and `price_version`.

No tier price supplied by the browser is accepted. An unavailable count or malformed current pricing returns a localized, no-store 422 response and creates no cart item or credential secret.

`PlaceOrder` locks the variant again, requires the same `priceVersion`, resolves `completion_count` against the current tier block, and compares the exact cart amounts. A changed or removed tier stops checkout with the existing price-changed recovery behavior. The order item keeps `quantity = 1` and copies `completion_count` into its safe configuration for fulfillment. Paylink receives one digital line at the exact bundle total, avoiding division and rounding errors.

The cart page displays the selected completion count for SBC lines. Coins quantity behavior remains unchanged.

## Add-to-cart notice

The current top-center notice remains a five-second, dismissible, accessible status message with a progress bar. It is refined rather than replaced:

- desktop maximum width grows from 32rem to 38rem;
- image stage grows from 64px to 80px on desktop and 72px on narrow mobile;
- title, item label, and selected bundle receive clearer hierarchy;
- the event detail may include an optional concise selection label such as `5 مرات · سوني / إكس بوكس`;
- primary and secondary actions remain 44px minimum and keyboard accessible;
- safe-area spacing and a minimum mobile gutter prevent viewport clipping;
- no modal behavior, focus trap, background freeze, or automatic navigation;
- reduced motion removes arrival/progress animation while preserving the notice and dismiss action.

## Compatibility and rollout

1. Deploy Laravel and frontend support with a temporary compatibility reader for existing SBC variants that lack `completionPricing`; those products remain one-completion only.
2. Update the inactive/source-controlled n8n workflow and its tests to emit strict tier blocks.
3. Run the workflow in dry-run mode and inspect product, platform, tier, and price counts.
4. Publish one signed complete SBC snapshot and require a fresh 201 response.
5. Verify repeatable and non-repeatable products, cart totals, checkout revalidation, and order snapshots in Arabic and English.
6. Remove no compatibility data automatically. The fallback disappears naturally as every active SBC variant is refreshed by the complete snapshot.

The rollout never activates a partially validated snapshot. An n8n source/API/translation/pricing failure performs no catalog write.

## Failure handling

- Malformed, missing, duplicate, descending, or out-of-policy tiers reject the entire SBC snapshot.
- A platform pair with different completion choices rejects the product snapshot.
- A missing currency conversion makes tier pricing unavailable in the page instead of displaying stale or mixed totals.
- A transport failure preserves the idempotency key so an exact retry can replay safely.
- A conclusive 422/409 rotates the attempt key after the customer changes the rejected input.
- Existing credential encryption, no-store responses, owner isolation, and secret redaction remain unchanged.

## Verification

- Workflow unit tests cover every tier boundary, limited maximum insertion, unlimited products, platform-separated totals, one service fee per bundle, non-repeatable parity, exact snapshot shape, and dry-run/apply safety.
- Laravel snapshot tests cover strict SBC tier validation, atomic rejection, source isolation, and server-owned price-version changes for tier edits.
- SBC cart tests cover every accepted/rejected completion count, platform totals, idempotent replay, count mismatch conflict, encrypted credential persistence, and no secret/price trust from the browser.
- Checkout tests cover exact tier revalidation, removed/changed tier rejection, order configuration, and Paylink bundle amount.
- React tests cover default tier, synchronous platform/tier totals, request body, summary, localized validation, locking, retry, and the enlarged notice.
- Browser verification covers Arabic and English at 320/390/768/1440, keyboard operation, reduced motion, overflow, and console health.
- Full backend/frontend CI and the existing MariaDB critical cart/checkout suite must remain green before deployment.

## Non-goals

- No arbitrary free-form completion quantity.
- No customer-authored price, discount, or multiplier.
- No separate database pricing-tier table in this release.
- No changes to Coins quantity pricing.
- No changes to Paylink provider selection, credential handling, refunds, or card collection.
- No automatic activation of n8n without an inspected dry run and a successful controlled snapshot.
