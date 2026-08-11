# Storefront Services, Catalog, Reviews, and FAQ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add real n8n-backed service discovery, category/product pages, honest reviews, and the existing FAQ to the bilingual Arab UT storefront.

**Architecture:** Laravel receives complete authenticated catalog snapshots from n8n and stores them atomically in the existing catalog tables. Customer requests read only MariaDB and server-prepared public view models; a scheduled review importer strictly projects the approved review endpoint into the existing reviews table. React/Inertia pages compose focused catalog, service-rail, review, and FAQ components inside the current StoreLayout.

**Tech Stack:** PHP 8.3+, Laravel 13, MariaDB/SQLite, React 19, TypeScript, Inertia 3, Tailwind CSS 4 plus the existing authored CSS, Pest, Vitest, Testing Library, Vite.

## Global Constraints

- The production hostname is `store.arab-ut.com`; remove remaining `shop.arab-ut.com` documentation references.
- WordPress and `Arab-ut.com` are the content/design references; the implementation remains warm black/gold with local Thmanyah Serif Display headings and Thmanyah Sans UI text.
- The services rail contains exactly five equal-size cards and remains a horizontal snap rail at every viewport; no autoplay and no oversized flagship card.
- SBC and Objectives are categories. FUT Champions and Rivals are products. Sell Coins links to `https://sell.arab-ut.com/`.
- Arabic is the default locale and English lives under `/en`; every new internal route exists in both.
- Laravel/MariaDB is public source of truth. Customer requests never call n8n.
- n8n credentials stay in environment/credential storage and never enter source, tests, chat, logs, or response bodies.
- Review PII is rejected before persistence or public projection. Low ratings are not suppressed. Verified requires linked order evidence.
- Use the existing FAQ content from `Arab-ut.com` without changing its operational meaning.
- Every eligible non-Coins catalog variant uses a real server-authoritative guest-cart action; do not render checkout, payment, credentials, fulfillment, or automation controls in this slice.
- All UI controls have visible focus and at least 44 by 44 CSS-pixel targets where interactive.
- All decorative motion is disabled by `prefers-reduced-motion: reduce`.
- Use strict RED/GREEN TDD, Clean Code Guard, Test Guard, Docs Guard, and verification-before-completion before each task commit.

---

## File structure

### Catalog ingestion and public read model

- `app/Http/Middleware/VerifyN8nCatalogSignature.php`: validates scoped key, timestamp, event ID, and body HMAC.
- `app/Http/Requests/Automation/CatalogSnapshotRequest.php`: enforces the exact versioned complete-snapshot shape and bounds.
- `app/Http/Controllers/Automation/CatalogSnapshotController.php`: JSON-only endpoint adapter.
- `app/Actions/Catalog/SyncCatalogSnapshot.php`: one transactional automation-owned reconciliation.
- `app/Actions/Catalog/MirrorCatalogMedia.php`: allowlisted, bounded image download and last-good preservation.
- `app/Actions/Catalog/StoreCatalogReader.php`: locale-aware, visibility-aware catalog queries and display-money preparation.
- `app/Http/Controllers/Store/CategoryController.php`: SBC/Objectives list pages.
- `app/Http/Controllers/Store/CatalogProductController.php`: SBC detail and FUT/Rivals product pages.

### Reviews and content

- `database/migrations/2026_08_11_000002_add_source_identity_to_reviews.php`: source identity and safe import metadata.
- `app/Actions/Reviews/ImportStoreReviews.php`: strict safe projection and atomic last-good import.
- `app/Console/Commands/RefreshStoreReviews.php`: bounded HTTP fetch adapter.
- `app/Services/Reviews/StoreReviewReader.php`: homepage and paginated public projections.
- `app/Http/Controllers/Store/ReviewsController.php`: bilingual reviews page.

### React UI

- `resources/js/components/store/service-rail.tsx`: equal-card horizontal service rail and optional overflow controls.
- `resources/js/components/store/reviews-section.tsx`: review summary and equal-card preview rail.
- `resources/js/components/store/faq-section.tsx`: native disclosure FAQ.
- `resources/js/components/store/catalog/`: catalog toolbar, card, grid, states, and detail primitives.
- `resources/js/pages/store/category.tsx`: SBC/Objectives page.
- `resources/js/pages/store/catalog-product.tsx`: SBC/FUT/Rivals detail page.
- `resources/js/pages/store/reviews.tsx`: full reviews page.
- `resources/js/types/store-content.ts`: exact public view-model types.

---

### Task 1: Secure complete catalog snapshot ingestion

**Files:**
- Create: `app/Http/Middleware/VerifyN8nCatalogSignature.php`
- Create: `app/Http/Requests/Automation/CatalogSnapshotRequest.php`
- Create: `app/Http/Controllers/Automation/CatalogSnapshotController.php`
- Create: `app/Actions/Catalog/SyncCatalogSnapshot.php`
- Create: `app/Actions/Catalog/MirrorCatalogMedia.php`
- Modify: `bootstrap/app.php`
- Modify: `config/services.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Automation/CatalogSnapshotTest.php`
- Test: `tests/Unit/Catalog/SyncCatalogSnapshotTest.php`

**Interfaces:**
- Consumes: raw JSON body, `X-ArabUT-Key`, `X-ArabUT-Timestamp`, `X-ArabUT-Event`, and `X-ArabUT-Signature`.
- Produces: `SyncCatalogSnapshot::execute(array $snapshot, string $signatureHash): array{runId:string,status:string,applied:int,archived:int}` and committed automation-owned catalog rows.

- [x] **Step 1: Write the failing signature and request-contract feature tests**

```php
it('accepts one fresh correctly signed complete catalog snapshot', function () {
    $payload = catalogSnapshotFixture();

    signedCatalogPost($payload)
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store')
        ->assertExactJson(['data' => [
            'runId' => $payload['runId'],
            'status' => 'completed',
            'applied' => 4,
            'archived' => 0,
        ]]);
});

it('rejects stale replayed malformed partial or incorrectly signed snapshots', function (string $case) {
    $response = postCatalogFailureFixture($case);

    $response->assertStatus(match ($case) {
        'bad-signature' => 401,
        'stale', 'replay' => 409,
        default => 422,
    });
});
```

- [x] **Step 2: Run the feature test to verify RED**

Run: `php artisan test tests/Feature/Automation/CatalogSnapshotTest.php --compact`

Expected: FAIL because the automation route, middleware, and request do not exist.

- [x] **Step 3: Write the failing reconciliation unit tests**

```php
it('atomically upserts source rows archives missing automation rows and preserves manual rows', function () {
    $manual = Product::factory()->manual()->create(['slug' => 'manual-product']);
    $previous = automationCatalogFixture(['external_id' => 'old-product']);

    app(SyncCatalogSnapshot::class)->execute(catalogSnapshotFixture(), str_repeat('a', 64));

    expect(Product::where('external_id', 'sbc-1')->whereNull('archived_at')->exists())->toBeTrue()
        ->and($previous->fresh()->archived_at)->not->toBeNull()
        ->and($manual->fresh()->archived_at)->toBeNull();
});

it('rolls back every catalog row when one item cannot be applied', function () {
    $before = catalogDatabaseDigest();

    expect(fn () => app(SyncCatalogSnapshot::class)->execute(
        catalogSnapshotFixture(['products.1.variants.0.priceMinor' => -1]),
        str_repeat('b', 64),
    ))->toThrow(DomainException::class);

    expect(catalogDatabaseDigest())->toBe($before);
});
```

- [x] **Step 4: Run the reconciliation tests to verify RED**

Run: `php artisan test tests/Unit/Catalog/SyncCatalogSnapshotTest.php --compact`

Expected: FAIL because `SyncCatalogSnapshot` does not exist.

- [x] **Step 5: Implement the exact signed request boundary**

```php
$signed = $request->header('X-ArabUT-Timestamp')."\n"
    .$request->header('X-ArabUT-Event')."\n"
    .$request->getContent();
$expected = hash_hmac('sha256', $signed, Config::string('services.n8n.catalog_secret'));

abort_unless(
    hash_equals($expected, (string) $request->header('X-ArabUT-Signature')),
    401,
);
```

Require schema version `1`, a ULID event ID, unique run ID, UTC timestamp within 300 seconds, `completeSnapshot === true`, exact top-level keys, at most 50 categories, 2,000 products, 10 variants per product, and 5 media items per product. Add the endpoint at `/api/automation/v1/catalog/snapshots` with JSON/no-store handling and throttle `automation-catalog`.

- [x] **Step 6: Implement transactional catalog reconciliation**

Create or find source key `n8n-products`. Upsert categories/products/variants using `(source_id, external_id)`, set `authority=automation`, validate `ServiceType`, `Platform`, derived `Market`, SAR integer minor units, and unique stable slugs/SKUs. Archive missing automation products only after the complete body validates. Record `CatalogSyncRun`, safe `CatalogSyncItem` outcomes, and the integration event claim without secrets.

- [x] **Step 7: Implement safe media mirroring**

Use `Http::accept('image/*')->connectTimeout(3)->timeout(8)->retry([100, 250])`, allow only configured HTTPS hosts, reject redirects to an unapproved host, reject bodies over 5 MB, accept WebP/PNG/JPEG only, write a content-hash filename to the public disk, and update `ProductMedia` only after a successful write. Preserve the previous path on failure.

- [x] **Step 8: Run focused GREEN and static checks**

Run:

```powershell
php artisan test tests/Feature/Automation/CatalogSnapshotTest.php tests/Unit/Catalog/SyncCatalogSnapshotTest.php --compact
vendor/bin/pint --dirty
vendor/bin/phpstan analyse app/Actions/Catalog app/Http/Controllers/Automation app/Http/Middleware/VerifyN8nCatalogSignature.php app/Http/Requests/Automation/CatalogSnapshotRequest.php
```

Expected: all tests pass, Pint clean, PHPStan 0 errors.

- [ ] **Step 9: Commit Task 1**

```bash
git add app/Actions/Catalog app/Http/Controllers/Automation app/Http/Middleware/VerifyN8nCatalogSignature.php app/Http/Requests/Automation/CatalogSnapshotRequest.php bootstrap/app.php config/services.php routes/web.php tests/Feature/Automation tests/Unit/Catalog
git commit -m "feat: ingest authenticated catalog snapshots"
```

---

### Task 2: Build the server-side catalog read model and bilingual routes

**Files:**
- Create: `app/Actions/Catalog/StoreCatalogReader.php`
- Create: `app/Http/Controllers/Store/CategoryController.php`
- Create: `app/Http/Controllers/Store/CatalogProductController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `routes/web.php`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `resources/js/types/store-shell.ts`
- Create: `tests/Feature/Store/StoreCatalogRoutesTest.php`
- Modify: `tests/Feature/Store/StoreTranslationParityTest.php`

**Interfaces:**
- Consumes: active visible catalog rows and the selected display currency.
- Produces: exact Inertia props for `store/category` and `store/catalog-product`, including already-converted minor-unit totals.

- [x] **Step 1: Write failing bilingual route and query tests**

```php
it('renders SBC and Objectives as categories and FUT and Rivals as products', function (string $uri, string $component, string $service) {
    seedPublicCatalog();

    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('catalog.service', $service)
            ->missing('catalog.rawPayload'));
})->with([
    ['/sbc', 'store/category', 'sbc'],
    ['/en/objectives', 'store/category', 'objectives'],
    ['/fut-champions', 'store/catalog-product', 'fut_champions'],
    ['/en/rivals', 'store/catalog-product', 'rivals'],
]);
```

Add assertions for hidden/archived/inactive exclusion, Challenges-to-Upgrades mapping, localized fallback, stable newest/recommended/price sorting, display-currency conversion, missing-rate price omission, pagination bounds, and 404 for an unknown or wrong-service slug.

- [x] **Step 2: Run the catalog route tests to verify RED**

Run: `php artisan test tests/Feature/Store/StoreCatalogRoutesTest.php --compact`

Expected: FAIL because the new routes/controllers/read model do not exist.

- [x] **Step 3: Implement `StoreCatalogReader`**

```php
/** @return array{items:list<array<string,mixed>>,meta:array<string,mixed>} */
public function category(
    ServiceType $service,
    string $locale,
    string $displayCurrency,
    array $filters,
): array {
    $converter = $this->displayMoney->prepare($displayCurrency);

    return $this->presentPaginator(
        $this->publicProducts($service)->with(['category', 'variants', 'media']),
        $converter,
        $locale,
        $filters,
    );
}
```

Use allowlisted query values only: `q` up to 80 characters; SBC filter `all|players|icons|upgrades|foundations`; sort `recommended|newest|price_asc|price_desc`; page 1 or greater. Prepare the exchange-rate converter once per request, never per product.

- [x] **Step 4: Implement category and product controllers/routes**

Register Arabic and localized English routes for `/sbc`, `/sbc/{product:slug}`, `/objectives`, `/objectives/{product:slug}`, `/fut-champions`, and `/rivals`. Task 6 owns the reviews reader and `/reviews` route. Replace the old SBC/FUT simple-page entries. Keep legal pages in `SimpleStorePageController`.

Expose route URLs needed by the service rail through a focused `homeContent` prop rather than expanding unrelated header navigation.

- [x] **Step 5: Add exact Arabic/English catalog copy and types**

Add translation trees for `services`, `catalog`, `product`, `reviews`, and `faq`. Keep customer-facing text in locale files only. Update `SimpleStorePageKey` to remove `sbc` and `fut_champions`; preserve only genuine simple legal pages.

- [x] **Step 6: Run focused GREEN and route/list checks**

Run:

```powershell
php artisan test tests/Feature/Store/StoreCatalogRoutesTest.php tests/Feature/Store/StoreTranslationParityTest.php tests/Feature/Store/StoreShellRoutesTest.php --compact
php artisan route:list --path=sbc
php artisan route:list --path=objectives
php artisan route:list --path=fut-champions
php artisan route:list --path=rivals
```

Expected: all tests pass and every internal route has default Arabic plus `/en` coverage.

- [x] **Step 7: Commit Task 2**

```bash
git add app/Actions/Catalog app/Http/Controllers/Store app/Http/Middleware/HandleInertiaRequests.php routes/web.php lang/ar/store.php lang/en/store.php resources/js/types/store-shell.ts tests/Feature/Store
git commit -m "feat: expose bilingual service catalog routes"
```

---

### Task 3: Add real catalog products to the existing guest cart

**Files:**
- Create: `app/Http/Requests/Store/CatalogCartRequest.php`
- Create: `app/Http/Controllers/Store/CatalogCartController.php`
- Create: `app/Actions/Cart/AddCatalogItemToCart.php`
- Create: `app/Security/CatalogCartFingerprint.php`
- Modify: `app/Http/Middleware/RequireCoinsCartJson.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Store/CartController.php`
- Modify: `resources/js/pages/store/cart.tsx`
- Modify: `resources/js/types/store-shell.ts`
- Create: `resources/js/lib/catalog-cart-api.ts`
- Test: `tests/Feature/Store/CatalogCartTest.php`
- Modify: `tests/Feature/Store/CoinsCartTest.php`
- Create: `resources/js/__tests__/store/catalog-cart-api.test.ts`
- Modify: `resources/js/__tests__/store/store-cart.test.tsx`

**Interfaces:**
- Consumes: `{variantId: string}` JSON plus CSRF and `Idempotency-Key` from the current guest or authenticated owner.
- Produces: `AddCatalogItemToCart::execute(CartOwner $owner, string $variantPublicId, string $idempotencyKey, string $locale): array{status:int,body:array<string,mixed>}` and a safe cart response/redirect target.

- [ ] **Step 1: Write failing server behavior tests**

```php
it('adds one eligible authoritative catalog variant to the guest cart', function () {
    $variant = createCatalogCartVariant(ServiceType::Sbc, Platform::PlayStation, 12_500);

    $this->postJson('/cart/items/catalog', ['variantId' => $variant->public_id], [
        'Idempotency-Key' => (string) Str::ulid(),
    ])->assertCreated()
        ->assertHeader('Cache-Control', 'no-store')
        ->assertJsonPath('data.cartCount', 1)
        ->assertJsonPath('data.cartUrl', '/cart');

    expect(CartItem::sole()->unit_price_halalah)->toBe(12_500)
        ->and(CartItem::sole()->configuration)->toMatchArray([
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'price_version' => 1,
        ]);
});
```

Add real database scenarios for sale-price precedence, guest isolation, authenticated owner, replay returning one line, mismatched replay 409, non-JSON/CSRF/no-store behavior, hidden/archived product, inactive/unknown/Coins variant, zero price, and a payload attempting to supply price/configuration being rejected by exact-key validation.

- [ ] **Step 2: Run backend tests to verify RED**

Run: `php artisan test tests/Feature/Store/CatalogCartTest.php --compact`

Expected: FAIL because the catalog cart endpoint/action do not exist.

- [ ] **Step 3: Implement exact request and authoritative action**

```php
$variant = ProductVariant::query()
    ->where('public_id', $variantPublicId)
    ->where('is_active', true)
    ->where('service_type', '!=', ServiceType::Coins)
    ->whereHas('product', fn (Builder $query) => $query
        ->where('is_visible', true)
        ->whereNull('archived_at'))
    ->with('product')
    ->lockForUpdate()
    ->sole();

$unitPrice = $variant->sale_price_halalah ?? $variant->price_halalah;
```

Reject non-positive prices. Reuse `AcquireActiveCart`, the current idempotency table, three-attempt root transaction behavior, owner scope, `quoted_at`, and safe response conventions. Generate the request fingerprint from canonical owner plus variant public ID using the app key. Never accept a price, product name, service type, platform, market, or configuration from the browser.

- [ ] **Step 4: Expand JSON/no-store exception handling without weakening Coins**

Rename the middleware only if the resulting name describes both endpoints; otherwise add a focused catalog JSON middleware. Force JSON for both localized/default catalog POST routes and return generic 500/no-store envelopes without reflecting request bodies.

- [ ] **Step 5: Write failing safe cart projection tests**

Assert that a catalog line renders its current localized product name, service type, platform, total, and `requiresCredentials=true`, while poisoned product/configuration values outside the allowlist are absent. Eager-load only `items.productVariant.product.media` and `items.secret`; do not expose source IDs or automation metadata.

- [ ] **Step 6: Implement cart projection and TypeScript contract**

Add a safe `product` object to every cart item:

```ts
product: {
    imageUrl: string | null;
    name: string;
    serviceType: 'coins' | 'sbc' | 'objectives' | 'rivals' | 'fut_champions';
};
```

Use the locale-specific product name and a validated local public media path. Coins retains its existing label/icon. Non-Coins lines show the real product name and the localized details-required state; no secret form or checkout appears.

- [ ] **Step 7: Write failing browser API helper tests**

```ts
it('posts only the public variant id with CSRF and one idempotency key', async () => {
    const response = await addCatalogItem('/cart/items/catalog', '01JVARIANT');

    expect(response.cartUrl).toBe('/cart');
    expect(JSON.parse(fetchBody())).toEqual({ variantId: '01JVARIANT' });
});
```

Cover 201 parsing, 409, 422, 503/500 generic errors, invalid success JSON, transport retry-key reuse, and no local/session storage writes.

- [ ] **Step 8: Implement the focused fetch helper**

Use same-origin `fetch`, `Accept: application/json`, `Content-Type: application/json`, the current CSRF meta token, one in-memory ULID per user attempt, strict exact response parsing, and no credential/storage behavior. Page components own loading/error UI and redirect to the returned cart URL only after 201.

- [ ] **Step 9: Run focused GREEN and regression gates**

Run:

```powershell
php artisan test tests/Feature/Store/CatalogCartTest.php tests/Feature/Store/CoinsCartTest.php --compact
npm test -- resources/js/__tests__/store/catalog-cart-api.test.ts resources/js/__tests__/store/store-cart.test.tsx
vendor/bin/phpstan analyse app/Actions/Cart/AddCatalogItemToCart.php app/Http/Controllers/Store/CatalogCartController.php app/Http/Controllers/Store/CartController.php app/Http/Requests/Store/CatalogCartRequest.php app/Security/CatalogCartFingerprint.php
npm run types
```

Expected: all pass with zero secret/price trust regressions.

- [ ] **Step 10: Commit Task 3**

```bash
git add app/Actions/Cart/AddCatalogItemToCart.php app/Http/Controllers/Store app/Http/Requests/Store/CatalogCartRequest.php app/Security/CatalogCartFingerprint.php app/Http/Middleware bootstrap/app.php routes/web.php resources/js/lib/catalog-cart-api.ts resources/js/pages/store/cart.tsx resources/js/types/store-shell.ts tests/Feature/Store resources/js/__tests__/store
git commit -m "feat: add catalog products to guest cart"
```

---

### Task 4: Build the equal-card homepage services rail

**Files:**
- Create: `resources/js/types/store-content.ts`
- Create: `resources/js/components/store/service-rail.tsx`
- Modify: `resources/js/pages/store/home.tsx`
- Modify: `resources/css/app.css`
- Create: `resources/js/__tests__/store/store-service-rail.test.tsx`
- Modify: `resources/js/__tests__/store/coins-home.test.tsx`
- Add: `public/images/store/services/*`

**Interfaces:**
- Consumes: `HomeServiceCard[]` with `key`, `title`, `description`, `href`, `imageUrl`, and `external`.
- Produces: one labelled horizontal snap region with five equal cards and optional previous/next buttons.

- [ ] **Step 1: Write the failing service rail tests**

```tsx
it('renders five equal service links in the approved order', () => {
    render(<ServiceRail services={services} translations={translations} />);

    expect(screen.getAllByRole('link')).toHaveLength(5);
    expect(screen.getAllByTestId('service-card')).toHaveClass(
        'store-service-card',
    );
    expect(screen.getByRole('link', { name: /Sell Coins/ })).toHaveAttribute(
        'href',
        'https://sell.arab-ut.com/',
    );
});

it('uses native horizontal scrolling without autoplay', () => {
    vi.useFakeTimers();
    const { container } = render(
        <ServiceRail services={services} translations={translations} />,
    );
    const rail = container.querySelector('.store-services-rail__track');

    vi.advanceTimersByTime(30_000);
    expect(rail?.scrollLeft).toBe(0);
});
```

Also assert safe external `target=_blank rel="noreferrer noopener"`, 44px controls, RTL scroll direction, alt text, keyboard-reachable cards, and no button when the mocked track does not overflow.

- [ ] **Step 2: Run the rail tests to verify RED**

Run: `npm test -- resources/js/__tests__/store/store-service-rail.test.tsx`

Expected: FAIL because `ServiceRail` does not exist.

- [ ] **Step 3: Implement focused rail behavior**

```tsx
export type HomeServiceCard = {
    description: string;
    external: boolean;
    href: string;
    imageUrl: string;
    key: 'sbc' | 'objectives' | 'fut_champions' | 'rivals' | 'sell_coins';
    title: string;
};
```

Use a semantic `<section>` and `<ul>`. The track uses `overflow-x:auto`, `scroll-snap-type:inline mandatory`, equal `grid-auto-columns`, and logical inline properties. Buttons call `scrollBy({left: directionSign * cardStep, behavior: reducedMotion ? 'auto' : 'smooth'})`; they do not run timers.

- [ ] **Step 4: Add service assets and equal geometry CSS**

Use owned WordPress/current-site art or existing navigation marks. Convert to optimized WebP when needed. Keep `object-fit:contain`, a fixed aspect-ratio image stage, identical card min/max block size, two-line title/description clamps, CTA aligned to the bottom, and no per-service spanning selector.

- [ ] **Step 5: Integrate after the Coins section**

Pass `homeContent.services` from the controller and render `<ServiceRail>` immediately after the existing Coins section. Preserve the Coins component and quote schedules unchanged.

- [ ] **Step 6: Run focused GREEN and frontend static checks**

Run:

```powershell
npm test -- resources/js/__tests__/store/store-service-rail.test.tsx resources/js/__tests__/store/coins-home.test.tsx
npm run lint
npm run format:check
npm run types
```

Expected: all pass.

- [ ] **Step 7: Commit Task 3**

```bash
git add resources/js/types/store-content.ts resources/js/components/store/service-rail.tsx resources/js/pages/store/home.tsx resources/css/app.css resources/js/__tests__/store public/images/store/services
git commit -m "feat: add equal storefront service rail"
```

---

### Task 5: Build SBC, Objectives, and service product React pages

**Files:**
- Create: `resources/js/components/store/catalog/catalog-toolbar.tsx`
- Create: `resources/js/components/store/catalog/catalog-card.tsx`
- Create: `resources/js/components/store/catalog/catalog-grid.tsx`
- Create: `resources/js/components/store/catalog/catalog-state.tsx`
- Create: `resources/js/pages/store/category.tsx`
- Create: `resources/js/pages/store/catalog-product.tsx`
- Modify: `resources/css/app.css`
- Create: `resources/js/__tests__/store/store-category.test.tsx`
- Create: `resources/js/__tests__/store/store-catalog-product.test.tsx`

**Interfaces:**
- Consumes: the `StoreCategoryPageProps` and `StoreCatalogProductPageProps` contracts from `store-content.ts`.
- Produces: URL-driven accessible filters/search/sort, authoritative variant selectors, and real Add to Cart controls.

- [ ] **Step 1: Write failing category behavior tests**

```tsx
it('submits SBC search filter and sort as locale-preserving GET parameters', async () => {
    renderCategory(sbcProps);

    await user.type(screen.getByRole('searchbox'), 'icon');
    await user.click(screen.getByRole('button', { name: 'Icons' }));
    await user.selectOptions(screen.getByRole('combobox'), 'price_asc');

    expect(router.get).toHaveBeenLastCalledWith(
        '/en/sbc',
        { filter: 'icons', q: 'icon', sort: 'price_asc' },
        expect.objectContaining({ preserveScroll: true, replace: true }),
    );
});
```

Add tests for Challenges shown under Upgrades, empty state, image fallback, price missing state, stable pagination links, platform chips, variant selection, add-button loading/error state, and redirect to the returned cart URL after 201.

- [ ] **Step 2: Write failing product detail tests**

Assert breadcrumb, Serif Display heading class, contained image, platform/variant summaries, localized price, selected-variant Add to Cart, error focus, and absence of checkout/payment controls.

- [ ] **Step 3: Run page tests to verify RED**

Run: `npm test -- resources/js/__tests__/store/store-category.test.tsx resources/js/__tests__/store/store-catalog-product.test.tsx`

Expected: FAIL because the components/pages do not exist.

- [ ] **Step 4: Implement category primitives and pages**

Use a real GET search form for no-JavaScript behavior. Enhance with Inertia `router.get` after submit/filter/sort. Keep the query in the URL, render a results count live region only after navigation, and use `<nav aria-label>` for pagination.

- [ ] **Step 5: Implement catalog/product visual styling**

Use WordPress SBC hierarchy with current tokens: editorial hero, compact toolbar, equal cards, contained art, subdued category metadata, gold price, and quiet empty state. At 320px cards are one column; 768px two columns; 1440px three/four based on available width. No horizontal document overflow.

- [ ] **Step 6: Run focused GREEN and frontend gate**

Run:

```powershell
npm test -- resources/js/__tests__/store/store-category.test.tsx resources/js/__tests__/store/store-catalog-product.test.tsx
npm run ci:check
```

Expected: page tests and the complete frontend gate pass.

- [ ] **Step 7: Commit Task 4**

```bash
git add resources/js/components/store/catalog resources/js/pages/store/category.tsx resources/js/pages/store/catalog-product.tsx resources/js/types/store-content.ts resources/css/app.css resources/js/__tests__/store
git commit -m "feat: build service catalog pages"
```

---

### Task 6: Import and expose reviews without PII

**Files:**
- Create: `database/migrations/2026_08_11_000002_add_source_identity_to_reviews.php`
- Modify: `app/Models/Review.php`
- Create: `app/Actions/Reviews/ImportStoreReviews.php`
- Create: `app/Console/Commands/RefreshStoreReviews.php`
- Create: `app/Services/Reviews/StoreReviewReader.php`
- Create: `app/Http/Controllers/Store/ReviewsController.php`
- Modify: `config/services.php`
- Modify: `routes/console.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Console/RefreshStoreReviewsTest.php`
- Test: `tests/Feature/Store/StoreReviewsTest.php`
- Test: `tests/Unit/Reviews/ImportStoreReviewsTest.php`

**Interfaces:**
- Consumes: the approved existing n8n reviews URL through `RefreshStoreReviews` only.
- Produces: safe `Review` rows and `StoreReviewReader::homepage()` / `paginate()` public projections.

- [ ] **Step 1: Write failing migration and importer safety tests**

```php
it('projects only safe review fields and never persists source PII', function () {
    $payload = reviewSourceFixture([
        'phone' => '+966500000000',
        'email' => 'private@example.test',
        'customer_name' => 'Private Full Name',
        'rating' => 2,
        'comment' => 'A real low rating',
    ]);

    app(ImportStoreReviews::class)->execute($payload);

    $review = Review::sole();
    expect($review->rating)->toBe(2)
        ->and($review->reviewer_name)->toBe(trans('store.reviews.anonymous_customer'))
        ->and(json_encode($review->getAttributes()))
        ->not->toContain('+966500000000', 'private@example.test', 'Private Full Name');
});
```

Add idempotency, all ratings 1-5, public-name allowlist, linked-order verified derivation, malformed response rollback, source deletion/visibility reconciliation, and last-good preservation tests.

- [ ] **Step 2: Run importer tests to verify RED**

Run: `php artisan test tests/Unit/Reviews/ImportStoreReviewsTest.php --compact`

Expected: FAIL because source identity columns and importer do not exist.

- [ ] **Step 3: Add review source identity migration**

Add `source_key`, `external_id`, and `content_hash` columns with unique `(source_key, external_id)`. Do not add raw payload, phone, email, avatar, or external order columns. Make `reviewer_name` hold only an explicitly public name or localized generic label.

- [ ] **Step 4: Implement strict safe projection and atomic import**

Validate exact source shape through a dedicated mapper. Normalize rating to integer 1-5, strip tags, bound review text to 2,000 characters, accept a display name only from a documented public-name field, and otherwise use the generic localized label. Set verified in the public view only when `order_item_id !== null`.

- [ ] **Step 5: Write failing command/schedule tests**

```php
Http::fake([
    config('services.n8n.reviews_url') => Http::response(reviewSourceFixture(), 200),
]);

$this->artisan('reviews:refresh')->assertSuccessful();

Http::assertSentCount(1);
expect(Review::count())->toBeGreaterThan(0);
```

Assert connect timeout, response timeout, bounded retries, no request on storefront routes, `withoutOverlapping`, count-only command output, and failed refresh retaining existing reviews.

- [ ] **Step 6: Implement command, schedule, reader, controller, and routes**

Use the configured existing URL, `acceptJson()`, `connectTimeout(3)`, `timeout(10)`, and `retry([150, 350], throw: false)`. Schedule hourly with `withoutOverlapping(15)->onOneServer()` when the production cache driver supports shared locks. The reviews controller only calls `StoreReviewReader`.

- [ ] **Step 7: Run focused GREEN and cross-engine migration lifecycle**

Run:

```powershell
php artisan test tests/Unit/Reviews/ImportStoreReviewsTest.php tests/Feature/Console/RefreshStoreReviewsTest.php tests/Feature/Store/StoreReviewsTest.php --compact
php artisan migrate:rollback --step=1 --force
php artisan migrate --force
vendor/bin/phpstan analyse app/Actions/Reviews app/Console/Commands/RefreshStoreReviews.php app/Services/Reviews app/Http/Controllers/Store/ReviewsController.php
```

Expected: all pass. Repeat the migration and focused review tests on the repository's MariaDB test profile before commit.

- [ ] **Step 8: Commit Task 5**

```bash
git add database/migrations/2026_08_11_000002_add_source_identity_to_reviews.php app/Models/Review.php app/Actions/Reviews app/Console/Commands/RefreshStoreReviews.php app/Services/Reviews app/Http/Controllers/Store/ReviewsController.php config/services.php routes/console.php routes/web.php tests/Feature/Console/RefreshStoreReviewsTest.php tests/Feature/Store/StoreReviewsTest.php tests/Unit/Reviews
git commit -m "feat: import honest storefront reviews"
```

---

### Task 7: Build the homepage reviews preview, reviews page, and FAQ

**Files:**
- Create: `resources/js/components/store/reviews-section.tsx`
- Create: `resources/js/components/store/faq-section.tsx`
- Create: `resources/js/pages/store/reviews.tsx`
- Modify: `resources/js/pages/store/home.tsx`
- Modify: `resources/js/types/store-content.ts`
- Modify: `resources/css/app.css`
- Create: `resources/js/__tests__/store/store-reviews-section.test.tsx`
- Create: `resources/js/__tests__/store/store-faq-section.test.tsx`
- Create: `resources/js/__tests__/store/store-reviews-page.test.tsx`
- Modify: `resources/js/__tests__/store/coins-home.test.tsx`

**Interfaces:**
- Consumes: safe review summary/preview and localized FAQ entries from HomeController; paginated safe reviews from ReviewsController.
- Produces: homepage review/FAQ sections and the full reviews page.

- [ ] **Step 1: Write failing review UI tests**

Assert rating distribution, all-rating rendering, generic customer fallback, evidence-only verified label, equal cards, no marquee/autoplay, pagination, empty state, and absence of phone/email patterns in rendered DOM.

- [ ] **Step 2: Write failing exact FAQ tests**

```tsx
it('renders the approved FAQ as native disclosures', () => {
    render(<FaqSection entries={arabicFaq} translations={faqTranslations} />);

    expect(screen.getAllByRole('group')).toHaveLength(4);
    expect(
        screen.getByText('ما أوقات عمل المتجر؟').closest('details'),
    ).toBeTruthy();
});
```

Assert exact Arabic text, meaning-equivalent English text, one `<summary>` per `<details>`, visible focus classes, and no hidden answer duplication.

- [ ] **Step 3: Run UI tests to verify RED**

Run: `npm test -- resources/js/__tests__/store/store-reviews-section.test.tsx resources/js/__tests__/store/store-faq-section.test.tsx resources/js/__tests__/store/store-reviews-page.test.tsx`

Expected: FAIL because the components/page do not exist.

- [ ] **Step 4: Implement review components and page**

Use a semantic list, stars with an accessible numeric label, and the same equal-card rail mechanics without autoplay. Render the distribution as text plus proportional bars, not color alone. Use localized `Intl.DateTimeFormat` and never reconstruct source names.

- [ ] **Step 5: Implement native FAQ and exact content**

Seed or project the four approved entries from locale/config into HomeController. Use native `<details>` so disclosure remains operable without custom state. Style the marker, border, and open state with existing tokens.

- [ ] **Step 6: Assemble homepage sections**

Render ServiceRail, ReviewsSection, and FaqSection after Coins in the approved order. HomeController reads a maximum of six reviews and four FAQ items with bounded queries. It performs zero HTTP calls.

- [ ] **Step 7: Run focused and full frontend GREEN**

Run:

```powershell
npm test -- resources/js/__tests__/store/store-reviews-section.test.tsx resources/js/__tests__/store/store-faq-section.test.tsx resources/js/__tests__/store/store-reviews-page.test.tsx resources/js/__tests__/store/coins-home.test.tsx
npm run ci:check
```

Expected: all Vitest, ESLint, Prettier, TypeScript, and Vite build checks pass.

- [ ] **Step 8: Commit Task 6**

```bash
git add resources/js/components/store/reviews-section.tsx resources/js/components/store/faq-section.tsx resources/js/pages/store/reviews.tsx resources/js/pages/store/home.tsx resources/js/types/store-content.ts resources/css/app.css resources/js/__tests__/store lang/ar/store.php lang/en/store.php app/Http/Controllers/Store/HomeController.php
git commit -m "feat: add reviews and FAQ storefront sections"
```

---

### Task 8: Correct documentation, integrate workflow contract, and verify the complete slice

**Files:**
- Create: `docs/api/n8n-catalog-v1.md`
- Modify: `docs/product/discovery-record.md`
- Modify: `docs/product/v1-blueprint.md`
- Modify: `docs/architecture/workflow-integration-audit.md`
- Modify: `.env.example`
- Modify: `README.md` if it contains the old hostname or missing scheduler instructions
- Test: `tests/Feature/Foundation/StorefrontDocumentationTest.php`
- Create: `.superpowers/sdd/2026-08-11-storefront-services-reviews-faq/final-report.md`

**Interfaces:**
- Consumes: the implemented signed catalog endpoint and review refresh command.
- Produces: the exact secret-free n8n contract, deployment configuration names, scheduler instructions, and final verification evidence.

- [ ] **Step 1: Write the failing documentation/config contract test**

```php
it('documents store arab ut as the canonical storefront and exposes required config keys', function () {
    expect(file_get_contents(base_path('docs/product/v1-blueprint.md')))
        ->toContain('store.arab-ut.com')
        ->not->toContain('shop.arab-ut.com')
        ->and(config('services.n8n.reviews_url'))->toBeString()
        ->and(config('services.n8n.catalog_key'))->toBeString();
});
```

- [ ] **Step 2: Run the documentation test to verify RED**

Run: `php artisan test tests/Feature/Foundation/StorefrontDocumentationTest.php --compact`

Expected: FAIL because the old hostname remains and the new contract document/config keys are incomplete.

- [ ] **Step 3: Document the exact n8n request contract**

Document header names, signature canonical string, timestamp window, schema version, every exact JSON field/type/bound, example request with fake values, success/error envelopes, idempotent replay behavior, archive semantics, media allowlist, and safe retry instructions. Do not include live URLs containing secrets or real signatures.

- [ ] **Step 4: Correct domain and operational docs**

Replace canonical storefront references with `store.arab-ut.com`. Document that Hostinger cron runs Laravel scheduler every minute, catalog comes from signed n8n snapshots, reviews are refreshed hourly, and last-good public data remains available on source failure.

- [ ] **Step 5: Run full automated verification**

Run:

```powershell
composer ci:check
git diff --check
php artisan route:list
php artisan schedule:list
```

Expected: Composer validation, Pint, PHPStan, full Pest, full Vitest, ESLint, Prettier, TypeScript, and Vite build all pass. Routes and schedules include the new catalog/review contracts exactly once.

- [ ] **Step 6: Run real MariaDB gates**

On an isolated repository-approved MariaDB instance: run `migrate:fresh`, the catalog/review/domain schema tests, full rollback, remigrate, then re-run the focused tests. Verify all migrations are Ran and cleanly shut down/remove the disposable instance.

- [ ] **Step 7: Run the browser matrix**

Verify Arabic and English at 320, 390, 768, and 1440 CSS pixels for:

- homepage service rail, reviews, and FAQ;
- SBC list search/filter/sort and one SBC detail;
- Objectives list;
- FUT Champions and Rivals product pages;
- Reviews list and pagination.

Record equal service-card dimensions, rail drag/scroll and keyboard controls, RTL order, Sell Coins external target, Thmanyah computed fonts, image containment, 44px targets, 200% zoom, reduced motion, no horizontal overflow, no PII in DOM/URL/storage, and zero console warnings/errors.

- [ ] **Step 8: Run guard reviews and write final report**

Run Clean Code Guard over changed production files, Test Guard over changed tests, and Docs Guard over the API/product docs. Record RED/GREEN evidence, source/PII boundary, browser matrix, MariaDB lifecycle, and all concerns in the final report.

- [ ] **Step 9: Commit Task 7**

```bash
git add docs .env.example README.md tests/Feature/Foundation/StorefrontDocumentationTest.php .superpowers/sdd/2026-08-11-storefront-services-reviews-faq/final-report.md
git commit -m "docs: finalize storefront catalog integration"
```

---

## Self-review record

- **Spec coverage:** Tasks 1-2 cover authenticated n8n catalog data and bilingual server routes; Task 3 covers real server-authoritative catalog cart addition; Tasks 4-5 cover equal service cards and every category/product page; Tasks 6-7 cover safe honest reviews and exact FAQ; Task 8 covers domain correction, integration handoff, all automated/browser/cross-engine gates.
- **No placeholders:** The plan contains concrete paths, commands, public interfaces, payload/security rules, test cases, and commit boundaries. There are no deferred fake controls.
- **Type consistency:** `HomeServiceCard`, `StoreCategoryPageProps`, `StoreCatalogProductPageProps`, and review projections are created in `store-content.ts` before their consuming pages. Backend service types match `ServiceType` enum values exactly.
- **Scope boundary:** Catalog products can be added to the real guest cart. Checkout, payment, service credentials/configurators, admin catalog UI, and fulfillment adaptation remain excluded and no control suggests they exist.
