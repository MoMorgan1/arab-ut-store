# Repeatable SBC Tier Pricing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add authoritative repeatable-SBC bundle pricing from the signed n8n snapshot through product selection, cart, checkout, and order storage, while enlarging the existing add-to-cart notice.

**Architecture:** The n8n workflow publishes exact platform-specific bundle totals in a strict `completionPricing` configuration block. A shared Laravel value object validates and resolves that block for catalog ingestion, public presentation, cart insertion, and checkout revalidation; the browser sends only a variant ID, selected completion count, and credentials. Cart and Paylink quantities remain one while `completion_count` records the fulfillment quantity.

**Tech Stack:** Laravel 13, PHP 8.4+, Pest, MariaDB/SQLite, React 19, TypeScript, Vitest, Inertia, n8n Code nodes, Node test runner, CSS.

**Spec:** `docs/superpowers/specs/2026-08-15-repeatable-sbc-tier-pricing-design.md`

## Global Constraints

- Unlimited repeatable SBCs start at 5 completions and use 5/10/15/20/30/40/50/75/100 tiers.
- Limited repeatable SBCs expose only valid quantities; maximums from 2 through 4 expose each integer, and maximums from 5 through 99 append the maximum when needed.
- PlayStation/Xbox and PC totals are calculated independently; the old Salla PC-premium approximation is forbidden.
- The three-SAR service fee is charged once per bundle.
- The browser never supplies a price, multiplier, or discount.
- Cart and order item `quantity` remain 1; `completion_count` is safe configuration.
- Existing one-completion catalog rows remain purchasable until refreshed.
- Arabic/English, no-store, idempotency, encrypted credentials, 44px controls, reduced motion, and Paylink integrity remain intact.

---

### Task 1: Publish exact tier totals from n8n

**Files:**
- Modify: `automation/n8n/sbc-catalog-v1/nodes/prepare-snapshot.js`
- Modify: `automation/n8n/sbc-catalog-v1/nodes/validate-snapshot.js`
- Modify: `automation/n8n/sbc-catalog-v1/tests/catalog-transform.test.mjs`
- Modify: `automation/n8n/sbc-catalog-v1/tests/helpers.mjs`
- Modify: `automation/n8n/sbc-catalog-v1/README.md`
- Regenerate: `automation/n8n/sbc-catalog-v1/workflow.json`

**Interfaces:**
- Consumes: EasySBC `repeatable`, `repeats`, `psPrice`, `pcPrice`, and signed one-million-Coin quotes.
- Produces: variant `configuration.completionPricing` with `{version,repeatable,maximum,tiers[]}` and first-tier `priceMinor`.

- [x] **Step 1: Add failing workflow tests for the approved tier matrix**

Add table-driven assertions equivalent to:

```js
assert.deepEqual(ps.configuration.completionPricing.tiers, [
  { completions: 5, multiplierBps: 10000, totalMinor: expectedPs5 },
  { completions: 10, multiplierBps: 9500, totalMinor: expectedPs10 },
  { completions: 15, multiplierBps: 9200, totalMinor: expectedPs15 },
  { completions: 20, multiplierBps: 9000, totalMinor: expectedPs20 },
  { completions: 30, multiplierBps: 8700, totalMinor: expectedPs30 },
  { completions: 40, multiplierBps: 8500, totalMinor: expectedPs40 },
  { completions: 50, multiplierBps: 8200, totalMinor: expectedPs50 },
  { completions: 75, multiplierBps: 7800, totalMinor: expectedPs75 },
  { completions: 100, multiplierBps: 7600, totalMinor: expectedPs100 },
]);
assert.notEqual(ps.configuration.completionPricing.tiers[0].totalMinor,
                pc.configuration.completionPricing.tiers[0].totalMinor);
```

Cover unlimited, maximum 3, maximum 12 with appended tier, `repeats=1`, one fee per bundle, and non-repeatable byte-for-byte parity.

- [x] **Step 2: Run the workflow tests and capture RED**

Run: `npm --prefix automation/n8n/sbc-catalog-v1 test`
Expected: failures show `completionPricing` is absent and repeatable prices still use `legacy-sbc-one-completion-v1`.

- [x] **Step 3: Implement deterministic tier generation**

Add pure helpers inside the n8n Code node:

```js
const SERVICE_FEE_SAR = 3;
const REPEAT_LABOR_PER_RUN = 1.10;
const STANDARD_TIERS = [[5,10000],[10,9500],[15,9200],[20,9000],
  [30,8700],[40,8500],[50,8200],[75,7800],[100,7600]];

function repeatTiers(maximum) {
  if (maximum == null || maximum >= 100) return STANDARD_TIERS;
  if (maximum < 5) return Array.from({length: maximum}, (_, i) => [i + 1, 10000]);
  const tiers = STANDARD_TIERS.filter(([count]) => count <= maximum);
  if (tiers.at(-1)[0] !== maximum) {
    tiers.push([maximum, Math.max(7000, tiers.at(-1)[1] - 200)]);
  }
  return tiers;
}

function repeatTotalMinor(coins, quote, completions, multiplierBps) {
  const rate = quote.totalHalalah / 100 / 1_000_000;
  const perRun = coins * multiplier(coins) * 1.02 * rate + REPEAT_LABOR_PER_RUN;
  return (Math.round(perRun * (multiplierBps / 10000) * completions)
          + SERVICE_FEE_SAR) * 100;
}
```

Emit independently for PS and PC. Non-repeatable variants emit a one-tier compatibility block whose total equals the existing formula.

- [x] **Step 4: Make workflow validation exact and fail closed**

Validate declared keys, version 1, boolean repeatability, `maximum`, strictly increasing counts, exact approved multipliers, positive integer totals, and `priceMinor === tiers[0].totalMinor`. Reject a product whose PS and PC completion counts differ.

- [x] **Step 5: Regenerate the export and run GREEN**

Run:

```powershell
npm --prefix automation/n8n/sbc-catalog-v1 run build
npm --prefix automation/n8n/sbc-catalog-v1 test
```

Expected: package tests pass and `workflow.json` contains the source from `prepare-snapshot.js` and `validate-snapshot.js` exactly.

- [x] **Step 6: Commit the workflow contract**

```powershell
git add automation/n8n/sbc-catalog-v1
git commit -m "feat(automation): price repeatable sbc bundles"
```

### Task 2: Add one Laravel pricing value object and strict snapshot boundary

**Files:**
- Create: `app/ValueObjects/Pricing/SbcCompletionPricing.php`
- Modify: `app/Http/Requests/Automation/SbcCatalogSnapshotRequest.php`
- Modify: `app/Actions/Catalog/SyncCatalogSnapshot.php`
- Modify: `tests/Feature/Automation/CatalogSnapshotTest.php`
- Create: `tests/Unit/Pricing/SbcCompletionPricingTest.php`

**Interfaces:**
- Consumes: untrusted variant configuration plus the variant's effective first-tier price.
- Produces: `SbcCompletionPricing::fromConfiguration(array $configuration, int $fallbackMinor, bool $requireDeclared): self`, `tierTotal(int): ?int`, `tiers(): list<array{completions:int,multiplierBps:int,totalMinor:int}>`, and `fingerprint(): string`.

- [x] **Step 1: Write RED unit tests for parsing and legacy fallback**

Test exact unlimited/limited/non-repeatable blocks and reject unknown keys, duplicate/descending counts, wrong multipliers, invalid maximum, nonpositive totals, and first-tier mismatch. Prove `requireDeclared=false` creates `[{completions:1,multiplierBps:10000,totalMinor:$fallbackMinor}]`; `requireDeclared=true` rejects absence.

- [x] **Step 2: Write RED feature tests for snapshot atomicity and versioning**

Post signed SBC snapshots and assert:

```php
expect($variant->price_version)->toBe(1);
// same tiers, expiry changed
expect($variant->fresh()->price_version)->toBe(1);
// one tier total changed
expect($variant->fresh()->price_version)->toBe(2);
```

Also prove malformed tier data returns 422 and leaves product, variants, run count, and integration-event count unchanged.

- [x] **Step 3: Run focused PHP tests and capture RED**

Run: `php vendor/bin/pest tests/Unit/Pricing/SbcCompletionPricingTest.php tests/Feature/Automation/CatalogSnapshotTest.php`
Expected: missing value object and accepted malformed configuration failures.

- [x] **Step 4: Implement the immutable parser/resolver**

Use integer-only validation and canonical JSON fingerprinting:

```php
public function tierTotal(int $completions): ?int
{
    foreach ($this->tiers as $tier) {
        if ($tier['completions'] === $completions) return $tier['totalMinor'];
    }
    return null;
}

public function fingerprint(): string
{
    return hash('sha256', json_encode($this->tiers, JSON_THROW_ON_ERROR));
}
```

No database access or floating-point calculation belongs in this class.

- [x] **Step 5: Enforce the parser only on the SBC snapshot request**

In `SbcCatalogSnapshotRequest::after()`, parse every variant with `requireDeclared=true`, attach field-specific validator errors, and compare completion counts between both variants of each product.

- [x] **Step 6: Extend price-version authority**

In `SyncCatalogSnapshot`, compare canonical completion-pricing fingerprints in addition to base/sale minor units. Do not compare expiry or other configuration fields. Increment exactly once when any effective tier changes.

- [x] **Step 7: Run GREEN and static checks**

Run:

```powershell
php vendor/bin/pest tests/Unit/Pricing/SbcCompletionPricingTest.php tests/Feature/Automation/CatalogSnapshotTest.php
php vendor/bin/phpstan analyse app/ValueObjects/Pricing/SbcCompletionPricing.php app/Http/Requests/Automation/SbcCatalogSnapshotRequest.php app/Actions/Catalog/SyncCatalogSnapshot.php --memory-limit=1G
php vendor/bin/pint --test app/ValueObjects/Pricing/SbcCompletionPricing.php app/Http/Requests/Automation/SbcCatalogSnapshotRequest.php app/Actions/Catalog/SyncCatalogSnapshot.php
```

- [x] **Step 8: Commit the Laravel pricing boundary**

```powershell
git add app/ValueObjects/Pricing/SbcCompletionPricing.php app/Http/Requests/Automation/SbcCatalogSnapshotRequest.php app/Actions/Catalog/SyncCatalogSnapshot.php tests/Unit/Pricing/SbcCompletionPricingTest.php tests/Feature/Automation/CatalogSnapshotTest.php
git commit -m "feat(catalog): validate sbc completion tiers"
```

### Task 3: Carry selected completion counts safely through cart and checkout

**Files:**
- Modify: `app/Http/Requests/Store/SbcCartRequest.php`
- Modify: `app/Security/SbcCartFingerprint.php`
- Modify: `app/Actions/Cart/AddSbcToCart.php`
- Modify: `app/Actions/Checkout/PlaceOrder.php`
- Modify: `app/Http/Controllers/Store/CartController.php`
- Modify: `tests/Feature/Store/SbcCartTest.php`
- Modify: `tests/Feature/Checkout/PlaceOrderTest.php`
- Modify: `tests/Feature/Checkout/PaylinkCheckoutTest.php`

**Interfaces:**
- Consumes: exact request `{variantId,completionCount,credentials}`.
- Produces: a bundle cart line with quantity 1 and safe `completion_count`, then an identical order snapshot and Paylink amount.

- [x] **Step 1: Write RED cart request and idempotency tests**

Assert missing/string/zero/unavailable counts return 422 no-store, valid 5/10 tiers use exact totals, exact retry creates one item/secret, and same key with count 5 then 10 returns 409. Assert the JSON response never contains tier tables or credentials.

- [x] **Step 2: Write RED checkout tests**

Create a tiered SBC cart line and prove checkout copies `completion_count=10`, keeps item quantity 1, and snapshots the selected bundle total. Mutate/remove the current tier or its price version and assert checkout fails before order/Paylink creation.

- [x] **Step 3: Run focused tests and capture RED**

Run: `php vendor/bin/pest tests/Feature/Store/SbcCartTest.php tests/Feature/Checkout/PlaceOrderTest.php tests/Feature/Checkout/PaylinkCheckoutTest.php`
Expected: `completionCount` is rejected as unknown and checkout uses the base variant price.

- [x] **Step 4: Add count validation and fingerprinting**

Add `'completionCount' => ['required','integer','min:1','max:100']`, include it in the top-level allowlist, and include canonical `'completion_count' => (int) $validated['completionCount']` in `SbcCartFingerprint`.

- [x] **Step 5: Resolve the selected tier under the variant lock**

Parse with legacy fallback, require `tierTotal($completionCount)` to be non-null, and create:

```php
[
  'quantity' => 1,
  'unit_price_halalah' => $tierTotal,
  'total_halalah' => $tierTotal,
  'configuration' => [
    'service_type' => 'sbc',
    'platform' => $variant->platform->value,
    'market' => $variant->market->value,
    'completion_count' => $completionCount,
    'quoted_at' => now()->utc()->toIso8601String(),
    'price_version' => $variant->price_version,
  ],
]
```

- [x] **Step 6: Revalidate tiers at checkout and expose safe cart configuration**

For SBC only, require integer `completion_count`, resolve the current tier with the same value object, and compare exact unit/total values. Add `completion_count` to `PlaceOrder::safeConfiguration()` and `CartController::safeConfiguration()` only when it is 1..100.

- [x] **Step 7: Run GREEN and static checks**

Run focused Pest, targeted PHPStan, Pint, and `git diff --check` for the owned files.

- [x] **Step 8: Commit cart and checkout integrity**

```powershell
git add app/Http/Requests/Store/SbcCartRequest.php app/Security/SbcCartFingerprint.php app/Actions/Cart/AddSbcToCart.php app/Actions/Checkout/PlaceOrder.php app/Http/Controllers/Store/CartController.php tests/Feature/Store/SbcCartTest.php tests/Feature/Checkout/PlaceOrderTest.php tests/Feature/Checkout/PaylinkCheckoutTest.php
git commit -m "feat(store): secure sbc bundle checkout"
```

### Task 4: Present tier data and build the product selector

**Files:**
- Modify: `app/Actions/Catalog/StoreCatalogReader.php`
- Modify: `resources/js/types/store-content.ts`
- Modify: `resources/js/lib/sbc-cart-api.ts`
- Modify: `resources/js/components/store/catalog/sbc-product-configurator.tsx`
- Modify: `resources/js/pages/store/cart.tsx`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/Store/StoreCatalogRoutesTest.php`
- Modify: `resources/js/__tests__/store/store-sbc-product.test.tsx`
- Modify: `resources/js/__tests__/store/sbc-cart-api.test.ts`
- Modify: `resources/js/__tests__/store/store-cart.test.tsx`

**Interfaces:**
- Consumes: validated tier blocks and `completion_count` cart configuration.
- Produces: sanitized `CatalogVariant.completionTiers`, a synchronous selector, request `completionCount`, and cart quantity copy.

- [x] **Step 1: Write RED backend presentation tests**

Assert every public tier contains only `{completions,price}` with display-currency money, old variants expose one completion, and malformed declared pricing fails the product closed. Raw multiplier/source fields must not appear in Inertia props.

- [x] **Step 2: Write RED React and API tests**

Cover default 5, limited default 1, tier switch, platform switch preserving count, immediate totals, exact request body, unavailable mismatch, loading locks, error focus, and cart display `10 مرات` / `10 completions`.

- [x] **Step 3: Run focused RED suites**

Run:

```powershell
php vendor/bin/pest tests/Feature/Store/StoreCatalogRoutesTest.php
npx vitest run resources/js/__tests__/store/store-sbc-product.test.tsx resources/js/__tests__/store/sbc-cart-api.test.ts resources/js/__tests__/store/store-cart.test.tsx
```

- [x] **Step 4: Expose sanitized converted tiers**

Extend the TypeScript contract:

```ts
type CatalogCompletionTier = { completions: number; price: CatalogMoney };
type CatalogVariant = {
  id: string; name: string; platform: string; price: CatalogMoney | null;
  completionTiers: CatalogCompletionTier[];
};
```

Use `ConvertDisplayMoney` for every tier and expose no tier list when any conversion fails.

- [x] **Step 5: Implement the WP-aligned selector and synchronous summary**

Render the selector only when more than one tier exists. Keep platform and credential controls locked during submission. Send `completionCount` but never a price. Add localized labels for one/multiple completions and selected bundle.

- [x] **Step 6: Show completion count in cart lines**

Extend `StoreCartConfiguration` with optional `completion_count` and show it only for SBC items. Do not change Coins quantity labels.

- [x] **Step 7: Run GREEN and frontend gates**

Run focused Pest/Vitest, `npm run lint:check`, `npm run format:check`, `npm run types:check`, and `npm run build`.

- [x] **Step 8: Commit the storefront selector**

```powershell
git add app/Actions/Catalog/StoreCatalogReader.php resources/js/types/store-content.ts resources/js/lib/sbc-cart-api.ts resources/js/components/store/catalog/sbc-product-configurator.tsx resources/js/pages/store/cart.tsx lang/ar/store.php lang/en/store.php resources/css/app.css tests/Feature/Store/StoreCatalogRoutesTest.php resources/js/__tests__/store/store-sbc-product.test.tsx resources/js/__tests__/store/sbc-cart-api.test.ts resources/js/__tests__/store/store-cart.test.tsx
git commit -m "feat(store): select repeatable sbc bundles"
```

### Task 5: Enlarge and enrich the add-to-cart notice

**Files:**
- Modify: `resources/js/lib/cart-added-event.ts`
- Modify: `resources/js/components/store/cart-added-notice.tsx`
- Modify: `resources/js/components/store/catalog/sbc-product-configurator.tsx`
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/store-layout.test.tsx`
- Modify: `resources/js/__tests__/store/store-sbc-product.test.tsx`

**Interfaces:**
- Consumes: optional `selectionLabel` in `CartAddedDetail`.
- Produces: a 38rem desktop/viewport-safe mobile status notice with larger media and bundle copy.

- [x] **Step 1: Write RED notice tests**

Dispatch an event with `selectionLabel: '5 مرات · سوني / إكس بوكس'` and assert image, item, selection, cart link, dismiss button, progress element, five-second removal, and explicit dismiss. Prove events without `selectionLabel` remain valid.

- [x] **Step 2: Run focused Vitest and capture RED**

Run: `npx vitest run resources/js/__tests__/store-layout.test.tsx resources/js/__tests__/store/store-sbc-product.test.tsx`
Expected: selection copy is absent and the event type rejects the extra field.

- [x] **Step 3: Implement the optional selection line and responsive sizing**

Add `selectionLabel?: string`, render it only when non-empty, set desktop max width 38rem and media 5rem, and use 4.5rem media plus safe gutters on narrow screens. Preserve `role=status`, dismiss behavior, actions, safe area, and reduced motion.

- [x] **Step 4: Run GREEN and accessibility-oriented static checks**

Run focused Vitest, ESLint, Prettier, TypeScript, and production build.

- [x] **Step 5: Commit notice polish**

```powershell
git add resources/js/lib/cart-added-event.ts resources/js/components/store/cart-added-notice.tsx resources/js/components/store/catalog/sbc-product-configurator.tsx resources/css/app.css resources/js/__tests__/store-layout.test.tsx resources/js/__tests__/store/store-sbc-product.test.tsx
git commit -m "fix(store): enlarge cart addition notice"
```

### Task 6: Full verification and controlled rollout evidence

**Files:**
- Modify: `automation/n8n/sbc-catalog-v1/README.md`
- Create: `.superpowers/sdd/2026-08-15-repeatable-sbc-tier-pricing/final-report.md`

**Interfaces:**
- Consumes: all prior tasks.
- Produces: verified code, workflow dry-run evidence, deployment readiness, and a rollout report.

- [x] **Step 1: Run aggregate repository gates**

Run: `composer ci:check`
Expected: Composer validation, Pint, PHPStan, Pest, Vitest, ESLint, Prettier, TypeScript, and Vite all exit 0.

- [x] **Step 2: Run the exact workflow package gate**

Run: `npm --prefix automation/n8n/sbc-catalog-v1 test`
Expected: export freshness and all workflow tests pass.

- [x] **Step 3: Run MariaDB critical selections**

Against the repository's disposable MariaDB configuration, run catalog snapshot, SBC cart, checkout, and concurrency suites. Require migration fresh/rollback/remigrate success and no owned database process after cleanup.

- [x] **Step 4: Run AR/EN browser matrix**

Verify a repeatable and non-repeatable SBC at 320/390/768/1440: tier defaults, platform preservation, synchronous totals, add request, notice size/dismiss/progress, cart completion count, keyboard focus, reduced motion, no overflow, and zero console errors.

- [ ] **Step 5: Perform controlled n8n dry run only**

Use the production webhook in `dry_run` mode. Record source/eligible/repeatable counts, PS/PC tier counts, lowest/highest totals, and zero catalog POSTs. Do not apply if any invariant or count is unexpected.

- [x] **Step 6: Write the final report and commit evidence**

Record exact commands, pass/fail totals, browser matrix, dry-run counts, known warnings, and rollout state. Force-add the ignored report and commit only verified artifacts.

- [ ] **Step 7: Apply/deploy only after evidence is green**

Publish the code, run one signed complete SBC snapshot, require HTTP 201, verify live repeatable/non-repeatable products and checkout, then confirm the scheduled workflow retains its guarded two-hour cadence. If production credentials or access are unavailable, stop with code deployment-ready and report the exact external blocker rather than fabricating rollout success.
