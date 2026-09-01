# FUT Champions and Rivals Service Configurators Implementation Plan

**Status:** Shipped (2026-08-16)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship live Arabic/English FUT Champions and Division Rivals configurators with server-authoritative pricing, conditional encrypted account data, a private squad screenshot, cart/checkout integration, and owner-only post-order viewing.

**Architecture:** Add one versioned pricing schedule per manual service and dedicated FUT/Rivals calculators. Dedicated multipart cart actions validate conditional credentials and a private upload, then reuse the existing cart, Paylink checkout, encrypted order-secret, and account-order foundations. The React product page delegates to two service configurators that share field/upload primitives but keep separate reducer state.

**Tech Stack:** Laravel 13, PHP 8.3, MariaDB/SQLite tests, Inertia 3, React 19, TypeScript, Vite, Pest/PHPUnit, Vitest/Testing Library, and the existing Arab UT CSS/token system.

**Spec:** `docs/decisions/2026-08-16-fut-rivals-service-configurators-design.md`

## Global Constraints

- Arabic is the default RTL locale; English is complete from launch.
- PlayStation and PC are supported. Xbox is never accepted or displayed. PC requires EA app or Steam.
- FUT prices are Rank 1 SAR 220, Rank 2 SAR 190, Rank 3 SAR 170, Rank 4 SAR 150, Rank 5 SAR 130, Rank 6 SAR 100. Urgent adds SAR 40 and says 24–36 hours from correct data.
- Rivals cumulative steps are 7→6 SAR 110, 6→5 SAR 120, 5→4 SAR 130, 4→3 SAR 140, 3→2 SAR 150, 2→1 SAR 160, and 1→Elite SAR 170. There is no urgent option.
- EA app collects EA email/password and three distinct eight-digit EA codes.
- Steam adds Steam username/password and never requests Steam Guard or Steam backup codes.
- PlayStation collects PlayStation email/password, three distinct six-character ASCII alphanumeric PlayStation codes, and three distinct eight-digit EA codes; it never requests EA email/password.
- One private JPEG, PNG, or WebP squad screenshot up to 5 MB is required.
- The customer may reveal but never edit credentials and the screenshot after checkout.
- Refunds remain manual. The admin UI and all n8n changes are deferred. The existing secret-free `order.paid` event remains unchanged.
- Services become live only after all automated and browser release gates pass.
- No new runtime frontend or SaaS dependency may be added.
- Before frontend edits, inspect WordPress and load `frontend-design`, `ui-ux-pro-max`, `arrange`, `typeset`, `clarify`, and `adapt`; load `polish` for the final pass.
- Browser acceptance covers RTL/LTR at 320, 390, 768, and 1440 px, keyboard completion, visible focus, 44 px targets, reduced motion, no overflow, and no console errors.

## File Map

- Pricing: `service_price_schedules` migration/model, `FutChampionsPricing`, `RivalsPricing`, and `ReadManualServicePricing`.
- Private fulfillment: `fulfillment_attachments` migration/model, `ManualServiceCredentials`, persistence/cleanup actions, and cart/order relations.
- Store endpoints: dedicated page controller, two strict multipart requests, two cart controllers/actions, two HMAC fingerprints, and Arabic/localized routes.
- Checkout/account: extend `PlaceOrder`, safe cart/order projections, owner-only credential/image controllers, and account live-order UI.
- Frontend: `manual-services` types/API, shared selection/credential/upload/summary components, two configurators, localized copy, and scoped CSS.

---

### Task 1: Versioned Manual-Service Pricing

**Files:**
- Create: `database/migrations/2026_08_16_000001_create_service_price_schedules.php`
- Create: `app/Models/ServicePriceSchedule.php`
- Create: `app/ValueObjects/Pricing/FutChampionsPricing.php`
- Create: `app/ValueObjects/Pricing/RivalsPricing.php`
- Create: `app/Actions/Pricing/ReadManualServicePricing.php`
- Test: `tests/Unit/Pricing/FutChampionsPricingTest.php`
- Test: `tests/Unit/Pricing/RivalsPricingTest.php`
- Test: `tests/Feature/Pricing/ManualServicePricingTest.php`

**Interfaces:**
- Produces `FutChampionsPricing::fromConfiguration(array): self`, `priceForRank(int $rank, bool $urgent): int`, and `urgentSurcharge(): int`.
- Produces `RivalsPricing::fromConfiguration(array): self`, `priceForRoute(string $from, string $to): int`, and `availableTargets(string $from): array`.
- Produces `ReadManualServicePricing::futChampions(bool $lock = false)` and `rivals(bool $lock = false)`, each returning schedule plus parsed pricing.

- [ ] **Step 1: Write failing unit tests for all approved prices and invalid input**

```php
$fut = FutChampionsPricing::fromConfiguration([
    'ranks' => ['1' => 22000, '2' => 19000, '3' => 17000, '4' => 15000, '5' => 13000, '6' => 10000],
    'urgent_surcharge_halalah' => 4000,
]);
expect($fut->priceForRank(1, false))->toBe(22000)
    ->and($fut->priceForRank(6, true))->toBe(14000);

$rivals = RivalsPricing::fromConfiguration([
    'steps' => ['7:6' => 11000, '6:5' => 12000, '5:4' => 13000, '4:3' => 14000, '3:2' => 15000, '2:1' => 16000, '1:elite' => 17000],
]);
expect($rivals->priceForRoute('5', 'elite'))->toBe(75000);
```

- [ ] **Step 2: Run RED tests**

Run: `php artisan test tests/Unit/Pricing/FutChampionsPricingTest.php tests/Unit/Pricing/RivalsPricingTest.php`

Expected: FAIL because both value objects are missing.

- [ ] **Step 3: Implement strict immutable calculators**

Reject missing/extra keys, non-integer/zero/negative prices, ranks outside 1–6, unknown divisions, and same/lower Rivals targets with `DomainException`. Use one ordered Rivals ladder constant.

- [ ] **Step 4: Add migration/model/reader tests**

Create unique `service_type`, positive `version` default 1, JSON `configuration`, `is_active`, and timestamps. Insert the exact approved FUT/Rivals schedules idempotently; prove fresh migrate, rollback, inactive fail-closed, and locked reads.

- [ ] **Step 5: Run GREEN tests**

Run: `php artisan test tests/Unit/Pricing/FutChampionsPricingTest.php tests/Unit/Pricing/RivalsPricingTest.php tests/Feature/Pricing/ManualServicePricingTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_08_16_000001_create_service_price_schedules.php app/Models/ServicePriceSchedule.php app/ValueObjects/Pricing/FutChampionsPricing.php app/ValueObjects/Pricing/RivalsPricing.php app/Actions/Pricing/ReadManualServicePricing.php tests/Unit/Pricing tests/Feature/Pricing/ManualServicePricingTest.php
git commit -m "feat: add manual service pricing schedules"
```

### Task 2: Manual Service Page Contracts and Catalog Identity

**Files:**
- Create: `app/Http/Controllers/Store/ManualServiceProductController.php`
- Modify: `routes/web.php`
- Create: `database/migrations/2026_08_16_000003_provision_manual_service_catalog.php`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Test: `tests/Feature/Store/ManualServicePageTest.php`

**Interfaces:**
- Consumes Task 1 pricing readers.
- Produces `manualService` Inertia props containing service, add URL, active state, schedule version, public pricing, platforms, tutorials, and product identity. It never contains credentials or private paths.

- [ ] **Step 1: Write failing route/page tests**

Cover `/fut-champions`, `/rivals`, `/en/fut-champions`, and `/en/rivals`; exact public prices; only PlayStation/PC; exact tutorial URLs; inactive state; bilingual copy; and no `xbox`, password, code, or path in JSON props.

- [ ] **Step 2: Run RED test**

Run: `php artisan test tests/Feature/Store/ManualServicePageTest.php`

Expected: FAIL because the generic controller has no `manualService` props.

- [ ] **Step 3: Implement dedicated controller/routes and deterministic product identity**

Replace only FUT/Rivals route targets in both locale groups. Reuse official product/service media when present. The data migration creates missing manual FUT/Rivals products and PlayStation/PC variants by stable slug/SKU, preserves existing matching manual records, rejects automation-owned slug/SKU conflicts, and removes only rows it created during rollback.

- [ ] **Step 4: Add exact Arabic/English copy**

Include approved delivery language, correct-data timing, do-not-login note, manual shortfall refund wording, password-change note, and no new mandatory risk acknowledgement.

- [ ] **Step 5: Run route and translation regressions**

Run: `php artisan test tests/Feature/Store/ManualServicePageTest.php tests/Feature/Store/StoreShellRoutesTest.php tests/Feature/Store/StoreTranslationParityTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/Store/ManualServiceProductController.php routes/web.php database/migrations/2026_08_16_000003_provision_manual_service_catalog.php lang tests/Feature/Store/ManualServicePageTest.php
git commit -m "feat: expose FUT and Rivals service pages"
```

### Task 3: Conditional Secrets and Private Squad Images

**Files:**
- Create: `database/migrations/2026_08_16_000002_create_fulfillment_attachments.php`
- Create: `app/Models/FulfillmentAttachment.php`
- Modify: `app/Models/CartItem.php`
- Modify: `app/Models/OrderItem.php`
- Create: `app/ValueObjects/Cart/ManualServiceCredentials.php`
- Create: `app/Actions/Cart/PersistManualServiceFulfillment.php`
- Create: `app/Actions/Cart/DeleteCartItemFulfillment.php`
- Test: `tests/Feature/Store/ManualServiceFulfillmentSecurityTest.php`

**Interfaces:**
- Produces `ManualServiceCredentials::fromValidated(array): self`, `payload(): array`, and `maskedSummary(): array`.
- Produces `PersistManualServiceFulfillment::execute(CartItem, ManualServiceCredentials, UploadedFile): void`.
- Produces `DeleteCartItemFulfillment::execute(CartItem): void`.

- [ ] **Step 1: Write failing security tests**

Cover all three credential shapes, unknown fields, exact/distinct code formats, uppercase PlayStation normalization, byte-preserved passwords, encrypted database text, safe masks, private random file path, MIME/content/size validation, and file cleanup after rollback.

- [ ] **Step 2: Run RED test**

Run: `php artisan test tests/Feature/Store/ManualServiceFulfillmentSecurityTest.php`

Expected: FAIL because attachment and parser boundaries are absent.

- [ ] **Step 3: Implement exactly-one-owner attachment schema**

Store nullable cart/order item IDs, kind, disk, path, MIME, bytes, SHA-256, timestamps, and cross-driver constraints/triggers proving exactly one owner. Add one `squadImage()` relation to each item model.

- [ ] **Step 4: Implement secret parsing and private upload transaction**

Store files under server-generated `fulfillment/squad-images/{ULID}` names, ignore client names, validate decoded JPEG/PNG/WebP up to 5 MB, encrypt payload via existing cast, save only safe mask facts, and delete a newly written file on any failed database path.

- [ ] **Step 5: Run GREEN and schema tests**

Run: `php artisan test tests/Feature/Store/ManualServiceFulfillmentSecurityTest.php tests/Feature/Database/DomainSchemaTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add database/migrations/2026_08_16_000002_create_fulfillment_attachments.php app/Models app/ValueObjects/Cart/ManualServiceCredentials.php app/Actions/Cart/PersistManualServiceFulfillment.php app/Actions/Cart/DeleteCartItemFulfillment.php tests/Feature/Store/ManualServiceFulfillmentSecurityTest.php tests/Feature/Database
git commit -m "feat: secure manual service fulfillment data"
```

### Task 4: FUT and Rivals Add-to-Cart APIs

**Files:**
- Create: `app/Http/Requests/Store/FutChampionsCartRequest.php`
- Create: `app/Http/Requests/Store/RivalsCartRequest.php`
- Create: `app/Http/Controllers/Store/FutChampionsCartController.php`
- Create: `app/Http/Controllers/Store/RivalsCartController.php`
- Create: `app/Actions/Cart/AddFutChampionsToCart.php`
- Create: `app/Actions/Cart/AddRivalsToCart.php`
- Create: `app/Security/FutChampionsCartFingerprint.php`
- Create: `app/Security/RivalsCartFingerprint.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Store/FutChampionsCartTest.php`
- Test: `tests/Feature/Store/RivalsCartTest.php`

**Interfaces:**
- Produces multipart POST routes `/cart/items/fut-champions` and `/cart/items/rivals` plus localized equivalents.
- Returns status 201 (or replay) with `{data:{cartItemId,cartCount,cartUrl}}` and no secret fields.

- [ ] **Step 1: Write failing FUT API tests**

Cover six ranks, urgent surcharge, prior matches not affecting price, both platforms/launchers, conditional fields, required image, inactive/stale schedule, guest/authenticated carts, replay/conflict, ciphertext, and a secret-free response.

- [ ] **Step 2: Write failing Rivals API tests**

Cover every edge, all valid multi-step routes, 5→Elite = 75000, invalid same/lower/unknown targets, rejection of urgent, both platforms/launchers, fulfillment persistence, replay/conflict, and safe response.

- [ ] **Step 3: Run RED tests**

Run: `php artisan test tests/Feature/Store/FutChampionsCartTest.php tests/Feature/Store/RivalsCartTest.php`

Expected: FAIL because endpoints/actions are absent.

- [ ] **Step 4: Implement strict requests and transactional actions**

Reject unknown nested/top-level fields; normalize emails/usernames/codes but preserve passwords; lock schedule and platform variant; price only on server; acquire the active cart; create quantity-one item; persist secret/upload; and complete the idempotency claim.

- [ ] **Step 5: Implement secret-safe HMAC fingerprints**

Fingerprint owner, product/variant, schedule version, normalized public selection, normalized secret payload, and file SHA-256 using the app key. Never persist inputs.

- [ ] **Step 6: Run GREEN/regression tests**

Run: `php artisan test tests/Feature/Store/FutChampionsCartTest.php tests/Feature/Store/RivalsCartTest.php tests/Feature/Store/CoinsCartTest.php tests/Feature/Store/SbcCartTest.php tests/Integration/ActiveCartInvariantUpgradeTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```powershell
git add app/Http/Requests/Store app/Http/Controllers/Store app/Actions/Cart app/Security routes/web.php tests/Feature/Store/FutChampionsCartTest.php tests/Feature/Store/RivalsCartTest.php
git commit -m "feat: add FUT and Rivals cart APIs"
```

### Task 5: Safe Cart Projection and Deletion Cleanup

**Files:**
- Modify: `app/Http/Controllers/Store/CartController.php`
- Modify: `app/Http/Controllers/Store/CartItemController.php`
- Modify: `resources/js/types/store-shell.ts`
- Modify: `resources/js/pages/store/cart.tsx`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Test: `tests/Feature/Store/StoreCartTest.php`
- Test: `resources/js/__tests__/store/store-cart.test.tsx`

**Interfaces:**
- Produces safe cart fields `pc_launcher`, `target_rank`, `urgent`, `from_division`, `to_division`, schedule version, credential readiness, and `squadImagePresent`.
- Reuses cart-item DELETE; produces no manual-service credential PATCH.

- [ ] **Step 1: Write failing backend/frontend cart tests**

Assert localized rank/route/urgent labels, exact totals, readiness, no account fields/private paths in Inertia props, no edit action for FUT/Rivals, and deletion of secret row plus physical image.

- [ ] **Step 2: Run RED tests**

Run: `php artisan test tests/Feature/Store/StoreCartTest.php; npm test -- resources/js/__tests__/store/store-cart.test.tsx`

Expected: FAIL because manual-service configuration is not projected/rendered.

- [ ] **Step 3: Implement safe projection and UI**

Render platform/launcher, rank or route, urgency, price, and readiness only. Build this from public configuration and masks; never decrypt secrets for the normal cart response.

- [ ] **Step 4: Route deletion through fulfillment cleanup**

Delete the private file and attachment with the cart item, tolerate an already-missing file, and preserve owner authorization.

- [ ] **Step 5: Run regressions and commit**

Run: `php artisan test tests/Feature/Store/StoreCartTest.php tests/Feature/Store/CoinsCartTest.php tests/Feature/Store/SbcCartTest.php; npm test -- resources/js/__tests__/store/store-cart.test.tsx resources/js/__tests__/store/cart-items-api.test.ts`

Expected: PASS.

```powershell
git add app resources/js resources/css lang tests/Feature/Store/StoreCartTest.php
git commit -m "feat: show manual services in cart"
```

### Task 6: Checkout Repricing and Immutable Transfer

**Files:**
- Modify: `app/Actions/Checkout/PlaceOrder.php`
- Modify: `app/Security/CheckoutFingerprint.php`
- Test: `tests/Feature/Checkout/ManualServiceCheckoutTest.php`
- Modify test: `tests/Feature/Checkout/PlaceOrderTest.php`
- Modify test: `tests/Feature/Checkout/PaylinkCheckoutTest.php`
- Modify test: `tests/Feature/Fulfillment/PublishOrderPaidEventTest.php`

**Interfaces:**
- Consumes Tasks 1 and 3.
- Produces immutable order configuration, encrypted `OrderItemSecret`, and one order-owned `FulfillmentAttachment`.

- [ ] **Step 1: Write failing checkout tests**

Cover valid conversion, exact totals, ciphertext, attachment owner transfer, stale version/price, inactive schedule, missing/invalid fulfillment, cart conversion, retry, and absence of secrets from payments/events/idempotency output.

- [ ] **Step 2: Run RED test**

Run: `php artisan test tests/Feature/Checkout/ManualServiceCheckoutTest.php`

Expected: FAIL because `PlaceOrder` treats FUT/Rivals as generic catalog items.

- [ ] **Step 3: Add explicit FUT/Rivals validation and repricing branches**

Keep Coins/SBC unchanged. Lock schedule; validate the exact public config; recalculate; require matching encrypted credential shape and squad image; fail stale carts without mutating them.

- [ ] **Step 4: Transfer fulfillment atomically**

Write the in-memory payload through the existing encrypted order cast, then change attachment ownership from cart item to order item in the checkout transaction. Never duplicate the physical file.

- [ ] **Step 5: Prove payment/n8n contracts remain secret-free**

Search synthetic emails/passwords/codes/paths across integration payload, payment metadata, idempotency response, captured logs, and outbound payment requests.

- [ ] **Step 6: Run GREEN/regression tests and commit**

Run: `php artisan test tests/Feature/Checkout/ManualServiceCheckoutTest.php tests/Feature/Checkout/PlaceOrderTest.php tests/Feature/Checkout/PaylinkCheckoutTest.php tests/Feature/Fulfillment/PublishOrderPaidEventTest.php`

Expected: PASS.

```powershell
git add app/Actions/Checkout/PlaceOrder.php app/Security/CheckoutFingerprint.php tests/Feature/Checkout tests/Feature/Fulfillment/PublishOrderPaidEventTest.php
git commit -m "feat: checkout manual service orders"
```

### Task 7: Owner-Only Post-Order Reveal

**Files:**
- Create: `app/Http/Controllers/Account/OrderItemCredentialsController.php`
- Create: `app/Http/Controllers/Account/OrderItemSquadImageController.php`
- Modify: `routes/account.php`
- Modify: `app/Account/Queries/ReadLiveOrder.php`
- Modify: `resources/js/types/account.ts`
- Modify: `resources/js/pages/account/live-order.tsx`
- Modify: `lang/ar/account.php`
- Modify: `lang/en/account.php`
- Test: `tests/Feature/Account/OrderItemFulfillmentAccessTest.php`
- Test: `resources/js/__tests__/account/account-orders.test.tsx`

**Interfaces:**
- Produces owner-only GET credentials and squad-image routes with `Cache-Control: no-store`.
- Successful credential reveal writes `SecretAccessLog` purpose `customer_order_reveal`.
- No post-checkout PATCH/PUT route exists.

- [ ] **Step 1: Write failing isolation tests**

Assert owner success, other user 404, guest auth handling, item/order mismatch 404, deleted data 404, no-store, access attribution/IP/time, no update route, and ordinary account props remaining secret-free.

- [ ] **Step 2: Run RED tests**

Run: `php artisan test tests/Feature/Account/OrderItemFulfillmentAccessTest.php tests/Feature/Account/AccountPropPrivacyTest.php`

Expected: FAIL because reveal routes/controllers are missing.

- [ ] **Step 3: Implement owner-scoped controllers**

Resolve order through authenticated owner and requested item. Return only the normalized approved credential shape, not internal metadata/path. Log successful secret access and stream the image from private storage.

- [ ] **Step 4: Add explicit reveal UI**

Initial Inertia props contain safe configuration and reveal URLs only. Fetch secrets after click; offer local show/hide; show the private screenshot. Never write the response to browser storage.

- [ ] **Step 5: Run GREEN/regressions and commit**

Run: `php artisan test tests/Feature/Account/OrderItemFulfillmentAccessTest.php tests/Feature/Account/AccountPropPrivacyTest.php tests/Feature/Account/AccountOrdersTest.php; npm test -- resources/js/__tests__/account/account-orders.test.tsx resources/js/__tests__/account/account-states.test.tsx`

Expected: PASS.

```powershell
git add app/Http/Controllers/Account routes/account.php app/Account/Queries/ReadLiveOrder.php resources/js/types/account.ts resources/js/pages/account/live-order.tsx lang tests/Feature/Account resources/js/__tests__/account
git commit -m "feat: reveal order fulfillment data to owners"
```

### Task 8: Shared React Manual-Service Foundations

**Files:**
- Create: `resources/js/types/manual-services.ts`
- Create: `resources/js/lib/manual-service-cart-api.ts`
- Create: `resources/js/components/configurator/manual-services/selection-card.tsx`
- Create: `resources/js/components/configurator/manual-services/credentials-fields.tsx`
- Create: `resources/js/components/configurator/manual-services/squad-upload.tsx`
- Create: `resources/js/components/configurator/manual-services/order-summary.tsx`
- Test: `resources/js/__tests__/store/manual-service-cart-api.test.ts`
- Test: `resources/js/__tests__/store/manual-service-credentials.test.tsx`

**Interfaces:**
- Produces `ManualServicePlatform`, `PcLauncher`, and discriminated credential state/wire types.
- Produces `addManualService(url, FormData, idempotencyKey)` returning cart item/count/URL.
- Produces accessible controlled form primitives with no secret persistence.

- [ ] **Step 1: Complete the mandatory UI preflight**

Inspect WordPress service cards/logos/product hierarchy and current Laravel Coins/SBC/cart behavior. Load and announce `frontend-design`, `ui-ux-pro-max`, `arrange`, `typeset`, `clarify`, and `adapt` before TSX/CSS edits.

- [ ] **Step 2: Write failing API/component tests**

Cover same-origin multipart POST, CSRF/idempotency headers, in-flight lock, safe errors, conditional EA/Steam/PlayStation fields, password reveal, exact code inputs, tutorials, image accept/size errors, preview revocation, labels/errors, and zero storage writes.

- [ ] **Step 3: Run RED tests**

Run: `npm test -- resources/js/__tests__/store/manual-service-cart-api.test.ts resources/js/__tests__/store/manual-service-credentials.test.tsx`

Expected: FAIL because modules are absent.

- [ ] **Step 4: Implement semantic controlled primitives**

Use fieldsets, legends, native inputs, radios, and buttons. Associate errors with stable IDs; ensure reveal/remove controls are 44 px; clear only incompatible dependent state; revoke preview object URLs.

- [ ] **Step 5: Run GREEN/static gates and commit**

Run: `npm test -- resources/js/__tests__/store/manual-service-cart-api.test.ts resources/js/__tests__/store/manual-service-credentials.test.tsx; npm run types:check; npm run lint:check; npm run format:check`

Expected: PASS.

```powershell
git add resources/js/types/manual-services.ts resources/js/lib/manual-service-cart-api.ts resources/js/components/configurator/manual-services resources/js/__tests__/store/manual-service-*.test.*
git commit -m "feat: add manual service form foundations"
```

### Task 9: FUT Champions Configurator

**Files:**
- Create: `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx`
- Modify: `resources/js/pages/store/catalog-product.tsx`
- Modify: `resources/js/types/store-content.ts`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Test: `resources/js/__tests__/store/fut-champions-configurator.test.tsx`

**Interfaces:**
- Consumes Tasks 2, 4, and 8.
- Produces complete FUT interaction and dispatches the existing `arabut:cart-added` event.

- [ ] **Step 1: Write failing customer-flow tests**

Cover platform-first flow, conditional launcher, all six server prices, urgent +4000, standard/current-FUT and urgent 24–36 copy, conditional credentials, required image, upstream resets, secret-free review, double-submit lock, first-error focus, retry, cart event, and both locales.

- [ ] **Step 2: Run RED test**

Run: `npm test -- resources/js/__tests__/store/fut-champions-configurator.test.tsx`

Expected: FAIL because the page still renders a generic select.

- [ ] **Step 3: Implement one reducer-owned FUT flow**

Derive display price from server minor units only. Hold secrets only in component memory and `FormData` during submit. Never echo them in summary, URL, event detail, or storage.

- [ ] **Step 4: Delegate from product page**

Keep SBC unchanged. Render FUT only for service `fut_champions`; preserve the generic control for other generic products.

- [ ] **Step 5: Run GREEN/regression tests and commit**

Run: `npm test -- resources/js/__tests__/store/fut-champions-configurator.test.tsx resources/js/__tests__/store/store-catalog-product.test.tsx resources/js/__tests__/document-title.test.ts`

Expected: PASS.

```powershell
git add resources/js/components/configurator/manual-services/fut-champions-configurator.tsx resources/js/pages/store/catalog-product.tsx resources/js/types/store-content.ts lang resources/js/__tests__/store/fut-champions-configurator.test.tsx
git commit -m "feat: build FUT Champions configurator"
```

### Task 10: Division Rivals Configurator

**Files:**
- Create: `resources/js/components/configurator/manual-services/rivals-configurator.tsx`
- Modify: `resources/js/pages/store/catalog-product.tsx`
- Modify: `resources/js/types/store-content.ts`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Test: `resources/js/__tests__/store/rivals-configurator.test.tsx`

**Interfaces:**
- Consumes Tasks 2, 4, and 8.
- Produces complete Rivals interaction and the existing cart-added event.

- [ ] **Step 1: Write failing customer-flow tests**

Cover starting points 7–1, only higher targets, every edge/multi-step total including 5→Elite = 75000, no urgent UI/payload, non-guaranteed one-to-three-day copy, conditional credentials, required image, target reset, safe summary, submit/error/cart behavior, and both locales.

- [ ] **Step 2: Run RED test**

Run: `npm test -- resources/js/__tests__/store/rivals-configurator.test.tsx`

Expected: FAIL because the configurator is absent.

- [ ] **Step 3: Implement reducer and route display calculation**

Use server-provided ordered edges. Omit same/lower targets and clear an invalid target after start change. Backend remains authoritative.

- [ ] **Step 4: Delegate from product page and run GREEN tests**

Run: `npm test -- resources/js/__tests__/store/rivals-configurator.test.tsx resources/js/__tests__/store/fut-champions-configurator.test.tsx resources/js/__tests__/store/store-catalog-product.test.tsx; npm run types:check`

Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add resources/js/components/configurator/manual-services/rivals-configurator.tsx resources/js/pages/store/catalog-product.tsx resources/js/types/store-content.ts lang resources/js/__tests__/store/rivals-configurator.test.tsx
git commit -m "feat: build Division Rivals configurator"
```

### Task 11: WordPress-Parity Styling and Polish

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/js/components/configurator/manual-services/selection-card.tsx`
- Modify: `resources/js/components/configurator/manual-services/credentials-fields.tsx`
- Modify: `resources/js/components/configurator/manual-services/squad-upload.tsx`
- Modify: `resources/js/components/configurator/manual-services/order-summary.tsx`
- Modify: `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx`
- Modify: `resources/js/components/configurator/manual-services/rivals-configurator.tsx`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Test: focused manual-service Vitest files

**Interfaces:**
- Produces final WordPress-continuous presentation without changing backend/wire contracts.

- [ ] **Step 1: Reinspect WordPress and Laravel side by side**

Record component order, official logos/assets, media treatment, Thmanyah roles, spacing rhythm, warm black/gold surfaces, field hierarchy, and phone/desktop behavior. Reproduce identity before refinement.

- [ ] **Step 2: Implement responsive branded styling**

Use existing tokens/fonts. Keep fields readable; use restrained gold emphasis; avoid generic SaaS cards, glassmorphism, replacement palettes/fonts, and excessive motion.

- [ ] **Step 3: Run automated accessibility/interaction tests**

Run: `npm test -- resources/js/__tests__/store/fut-champions-configurator.test.tsx resources/js/__tests__/store/rivals-configurator.test.tsx resources/js/__tests__/store/manual-service-credentials.test.tsx`

Expected: PASS.

- [ ] **Step 4: Run required browser matrix**

Verify `/fut-champions`, `/rivals`, `/en/fut-champions`, and `/en/rivals` at 320/390/768/1440. Complete with keyboard; inspect focus, 44 px targets, preview cleanup, no overflow, reduced motion, secret-free URL/storage/props, private-image behavior, and console/network errors.

- [ ] **Step 5: Load and apply final `polish` skill**

Fix alignment, spacing, wrapping, loading/error/success states, RTL/LTR direction, and WordPress-identity drift. Repeat browser checks after material fixes.

- [ ] **Step 6: Commit**

```powershell
git add resources/css/app.css resources/js lang
git commit -m "feat: polish FUT and Rivals storefronts"
```

### Task 12: Full Verification and Launch-Readiness Audit

**Files:**
- Modify: `docs/operations/storefront-runbook.md`
- Modify: `docs/plans/2026-08-16-fut-rivals-service-configurators.md`
- Create: `.superpowers/sdd/2026-08-16-fut-rivals-service-configurators/final-report.md`

**Interfaces:**
- Produces authoritative requirement-by-requirement evidence and proves deferred scope did not leak in.

- [ ] **Step 1: Run backend gates**

Run: `composer validate --strict --no-check-publish; vendor/bin/pint --parallel --test; vendor/bin/phpstan analyse; php artisan test`

Expected: every command exits 0.

- [ ] **Step 2: Run frontend gates**

Run: `npm run test; npm run lint:check; npm run format:check; npm run types:check; npm run build`

Expected: every command exits 0.

- [ ] **Step 3: Verify migration lifecycle and exact seeded schedules**

Run up/down/up against the test database, then rerun pricing/schema/cart/checkout/access suites. Verify both services active with exact approved values.

- [ ] **Step 4: Audit secrets and deferred scope**

Search responses, logs, events, notifications, idempotency rows, URLs, rendered source, browser storage, and built assets for synthetic secrets/private paths. Confirm no n8n payload change, admin UI, automatic refund, Xbox, post-order update route, or Steam-code field exists.

- [ ] **Step 5: Repeat the browser acceptance matrix and checkout rehearsal**

Confirm add-to-cart, payment test flow, immutable order snapshot, owner reveal, cross-customer isolation, correct live availability, and zero console/cache problems.

- [ ] **Step 6: Run Clean Code Guard, Test Guard, Docs Guard, and verification-before-completion**

Fix each actionable in-scope finding, rerun the affected suite, and rerun complete gates if production code changes.

- [ ] **Step 7: Record evidence and commit**

```powershell
git add docs .superpowers/sdd/2026-08-16-fut-rivals-service-configurators/final-report.md
git commit -m "docs: verify FUT and Rivals launch readiness"
```

The final report maps every price, field matrix, image rule, cart/order behavior, deferred item, and browser requirement to a test or inspection result. Production deployment remains a separate controlled action and is not implied by branch completion.
