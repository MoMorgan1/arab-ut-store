# Instant Coins, Guest Cart, and Storefront Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every legal Coins amount show its exact price immediately, let guests securely complete the configurator and add to cart, claim that cart after authentication, and finish the approved WordPress-faithful storefront polish.

**Architecture:** Laravel remains the sole pricing authority. It loads catalog, validated rules, and a fresh display rate once, then emits compact indexed quote schedules using the existing integer-safe calculator and fixed-point conversion arithmetic in memory; React performs only bounds-checked schedule lookup and the cart endpoint re-quotes. Guest and authenticated carts share a `CartOwner` boundary backed by an HMAC guest token and a database-enforced one-active-cart invariant; authentication transactionally claims the guest cart without decrypting credentials.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia v3, React 19, TypeScript, Pest, Vitest, SQLite, MariaDB 12.3, Vite, local Thmanyah fonts.

## Global Constraints

- The price must change synchronously for every valid 10,000-coin increment; the homepage must make no per-amount quote request.
- The browser never implements pricing, tier, override, rounding, or FX formulas; it only reads a server-generated indexed schedule.
- Add-to-cart always re-runs `QuoteCoins` and persists the authoritative SAR total.
- Guests may finish EA credentials, summary, and add-to-cart without login; login is reserved for a future real checkout boundary.
- EA email, password, and five distinct eight-digit backup codes never enter URLs, sessions, Inertia props, logs, analytics, local/session storage, idempotency responses, or cart configuration.
- Guest database identity is an HMAC only; a raw session identifier or raw guest token must never be persisted.
- Preserve JSON-only, CSRF, no-store, throttle, idempotency, encrypted-secret, expiry, purge, and safe-projection protections.
- Enforce exactly one active SAR cart per user or guest owner on SQLite and MariaDB, including concurrent first-add and claim paths.
- Do not add checkout, payment, Google login, or any other nonfunctional control.
- Use Thmanyah Serif Display for headings and large editorial text, Thmanyah Sans for body and controls, exact approved Arabic/English copy, and WordPress warm black/gold materials.
- Keep required ExchangeRate-API attribution as a linked, keyboard-reachable item inside currency preferences; remove only its footer placement.
- Decorative coins are `aria-hidden`, pointer-inert, responsive, and motionless under `prefers-reduced-motion`.
- Every task follows strict RED → GREEN, commits only owned files, and receives spec and quality review before the next task.

---

### Task 1: Exact Server Quote Schedules

**Files:**
- Create: `app/Actions/Pricing/BuildCoinsQuoteSchedule.php`
- Modify: `app/ValueObjects/Pricing/CoinsQuote.php`
- Modify: `app/Actions/Pricing/QuoteCoins.php`
- Modify: `app/Http/Controllers/Store/HomeController.php`
- Test: `tests/Unit/Pricing/BuildCoinsQuoteScheduleTest.php`
- Test: `tests/Feature/Store/HomeCoinsConfiguratorTest.php`
- Test: `tests/Feature/Store/CoinsQuoteTest.php`

**Interfaces:**
- Produces `BuildCoinsQuoteSchedule::execute(Platform $platform, ?DeliveryMode $delivery, int $maximum, string $displayCurrency): array`.
- The returned array shape is `platform`, `delivery`, `market`, `minimum`, `maximum`, `increment`, `productId`, `variantId`, `priceVersion`, `pricedAt`, `displayCurrency`, `totalsHalalah`, and `displayTotalsMinor`.
- Array index `i` maps only to `minimum + (i * increment)`; totals arrays have identical length.
- `CoinsQuote` gains `public int $priceVersion`, populated from `ProductVariant::price_version`, and includes it in `toArray()`.
- `HomeController` emits `quoteSchedules` keyed by `playstation:normal`, `playstation:fast`, and `pc`; the diagnostic `quoteUrl` remains.

- [x] **Step 1: Write schedule RED tests**

Add datasets proving 196 normal entries, 1,996 fast entries, 196 PC entries, exact first/last index quantities, tier and override totals, one shared timestamp, matching array lengths, display-currency fixed-point values, `priceVersion`, safe integers, and fail-closed stale/malformed/missing rates. Example assertion:

```php
$schedule = app(BuildCoinsQuoteSchedule::class)->execute(
    Platform::PlayStation,
    DeliveryMode::Fast,
    20_000_000,
    'SAR',
);

expect($schedule['minimum'])->toBe(50_000)
    ->and($schedule['increment'])->toBe(10_000)
    ->and($schedule['totalsHalalah'])->toHaveCount(1_996)
    ->and($schedule['displayTotalsMinor'])->toHaveCount(1_996)
    ->and($schedule['totalsHalalah'][0])->toBe(
        app(QuoteCoins::class)->execute(
            Platform::PlayStation,
            DeliveryMode::Fast,
            50_000,
        )->total->halalah(),
    );
```

- [x] **Step 2: Run backend RED**

Run:

```powershell
$commonGitDir = (Resolve-Path (git rev-parse --git-common-dir)).Path
$env:PHPRC = (Resolve-Path (Join-Path $commonGitDir '..\..\tools\php.ini')).Path
$env:PHP_INI_SCAN_DIR=''
php vendor/bin/pest tests/Unit/Pricing/BuildCoinsQuoteScheduleTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php tests/Feature/Store/CoinsQuoteTest.php --compact
```

Expected: FAIL because `BuildCoinsQuoteSchedule` and `quoteSchedules` do not exist and quote payloads lack `priceVersion`.

- [x] **Step 3: Implement the schedule builder**

Load the selected product/variants, all required active rules, and the selected display rate once before entering the quantity loops. Use `CoinsPriceCalculator` for every entry and reuse one validated fixed-point currency converter for the complete batch. Do not invoke `QuoteCoins` or query the exchange-rate model inside a quantity loop:

```php
final readonly class BuildCoinsQuoteSchedule
{
    public function __construct(
        private CoinsCatalogReader $catalog,
        private CoinsPriceCalculator $calculator,
        private ConvertDisplayMoney $convertDisplayMoney,
    ) {}

    /** @return array<string, mixed> */
    public function execute(
        Platform $platform,
        ?DeliveryMode $delivery,
        int $maximum,
        string $displayCurrency,
    ): array {
        $minimum = Config::integer('coins.quantity.minimum');
        $increment = Config::integer('coins.quantity.increment');
        $totalsHalalah = [];
        $displayTotalsMinor = [];
        $context = $this->loadPricingContextOnce($platform, $delivery, $displayCurrency);

        for ($quantity = $minimum; $quantity <= $maximum; $quantity += $increment) {
            $total = $this->calculator->calculate($context->rule, $quantity, $context->normalRule);
            $display = $context->displayConverter->convert($total);
            $totalsHalalah[] = $total->halalah();
            $displayTotalsMinor[] = $display['amountMinor'];
        }

        if (count($totalsHalalah) !== count($displayTotalsMinor)) {
            throw new DomainException('A complete Coins quote schedule is unavailable.');
        }

        return [
            'platform' => $platform->value,
            'delivery' => $delivery?->value,
            'market' => $platform->market()->value,
            'minimum' => $minimum,
            'maximum' => $maximum,
            'increment' => $increment,
            'productId' => $context->productId,
            'variantId' => $context->variantId,
            'priceVersion' => $context->priceVersion,
            'pricedAt' => now('UTC')->toIso8601String(),
            'displayCurrency' => $displayCurrency,
            'totalsHalalah' => $totalsHalalah,
            'displayTotalsMinor' => $displayTotalsMinor,
        ];
    }
}
```

Before returning, reject inconsistent product ID, variant ID, price version, platform, delivery, or currency across entries and reject array lengths that do not equal `intdiv($maximum - $minimum, $increment) + 1`.

Add a focused all-three-schedules budget regression asserting at most 10 database queries and less than 1,000 ms. Prove pricing parity against `QuoteCoins` at minimum/maximum values, every tier boundary, every exact override, and the fast/normal floor boundary. The performance test must fail if catalog, rule, or rate reads occur inside the quantity loop.

- [x] **Step 4: Emit all schedules from the homepage**

Inject `BuildCoinsQuoteSchedule`, read the middleware-selected display currency from the request/session contract already shared by Inertia, and build the three exact keys. Catch pricing/domain/value failures at the same public fail-closed boundary used today; never serialize a partial or malformed schedule as available.

- [x] **Step 5: Run backend GREEN and static checks**

Run the Step 2 command, then:

```powershell
php vendor/bin/phpstan analyse app/Actions/Pricing/BuildCoinsQuoteSchedule.php app/ValueObjects/Pricing/CoinsQuote.php app/Http/Controllers/Store/HomeController.php
php vendor/bin/pint --dirty
```

Expected: all focused tests pass, PHPStan reports 0 errors, Pint exits 0.

- [x] **Step 6: Commit Task 1**

```powershell
git add app/Actions/Pricing/BuildCoinsQuoteSchedule.php app/Actions/Pricing/QuoteCoins.php app/ValueObjects/Pricing/CoinsQuote.php app/Http/Controllers/Store/HomeController.php tests/Unit/Pricing/BuildCoinsQuoteScheduleTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php tests/Feature/Store/CoinsQuoteTest.php
git commit -m "feat: publish exact Coins quote schedules"
```

---

### Task 2: Instant Browser Schedule Lookup

**Files:**
- Create: `resources/js/lib/coins-quote-schedule.ts`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/js/pages/store/home.tsx`
- Modify: `resources/js/components/configurator/coins/coins-configurator.tsx`
- Modify: `resources/js/components/configurator/coins/amount-step.tsx`
- Modify: `resources/js/components/configurator/coins/configurator-state.ts`
- Modify: `resources/js/components/configurator/coins/use-coins-quote-request.ts`
- Test: `resources/js/__tests__/store/coins-schedule.test.ts`
- Test: `resources/js/__tests__/store/coins-home.test.tsx`

**Interfaces:**
- `CoinsQuoteSchedule` exactly mirrors Task 1.
- `quoteFromSchedule(schedule, quantity): CoinsQuote | null` validates integer bounds, increment alignment, index presence, safe positive totals, currency, ULIDs, and ISO timestamp before returning a quote.
- `CoinsConfigurator` receives `quoteSchedules` and never uses `quoteUrl` for schedule-covered homepage interactions.
- The existing GET quote parser/hook stays available for compatibility tests but is not mounted by the homepage configurator.

- [x] **Step 1: Write frontend RED tests**

Cover exact lookup at minimum, middle, and maximum; rejection of off-step/out-of-bounds/mismatched arrays/unsafe totals/currency mismatch; no fetch on platform, delivery, input, chip, slider, and adjustment changes; immediate total replacement without refreshing copy; invalid editing retains the last exact total until commit; platform/delivery changes fail closed until a matching schedule exists.

```ts
expect(fetchMock).not.toHaveBeenCalled();
await user.click(screen.getByRole('button', { name: '500K' }));
expect(screen.getByText('SAR 100.00')).toBeVisible();
expect(screen.queryByText(/Refreshing|نحدّث/u)).not.toBeInTheDocument();
```

- [x] **Step 2: Run frontend RED**

```powershell
npx vitest run resources/js/__tests__/store/coins-schedule.test.ts resources/js/__tests__/store/coins-home.test.tsx
```

Expected: FAIL because schedule types/parser do not exist and the current configurator calls the quote endpoint.

- [x] **Step 3: Implement the strict lookup**

```ts
export function quoteFromSchedule(
    schedule: CoinsQuoteSchedule,
    quantity: number,
): CoinsQuote | null {
    if (
        !Number.isSafeInteger(quantity) ||
        quantity < schedule.minimum ||
        quantity > schedule.maximum ||
        (quantity - schedule.minimum) % schedule.increment !== 0
    ) {
        return null;
    }

    const index = (quantity - schedule.minimum) / schedule.increment;
    const amountHalalah = schedule.totalsHalalah[index];
    const amountMinor = schedule.displayTotalsMinor[index];

    if (
        !Number.isSafeInteger(amountHalalah) || amountHalalah <= 0 ||
        !Number.isSafeInteger(amountMinor) || amountMinor <= 0
    ) {
        return null;
    }

    return {
        productId: schedule.productId,
        variantId: schedule.variantId,
        priceVersion: schedule.priceVersion,
        platform: schedule.platform,
        market: schedule.market,
        delivery: schedule.delivery,
        quantity,
        total: { amountHalalah, currency: 'SAR' },
        displayTotal: {
            amountMinor,
            currency: schedule.displayCurrency,
        },
        pricedAt: schedule.pricedAt,
    };
}
```

Validate the complete schedule once at the prop boundary and do not silently truncate arrays.

- [x] **Step 4: Replace network lifecycle with derived schedule state**

Select keys as `pc` or `${platform}:${delivery}`. Every committed quantity action derives the next quote in the same reducer/render cycle. Remove `loading` and `refreshing` presentation from schedule-driven transitions while preserving validation/unavailable states. Do not interpolate between schedule points.

- [x] **Step 5: Run frontend GREEN and static gates**

```powershell
npx vitest run resources/js/__tests__/store/coins-schedule.test.ts resources/js/__tests__/store/coins-home.test.tsx
npm run lint
npm run format:check
npm run types:check
npm run build
```

Expected: focused tests and all four static/build gates pass.

- [x] **Step 6: Commit Task 2**

```powershell
git add resources/js/lib/coins-quote-schedule.ts resources/js/types/coins.ts resources/js/pages/store/home.tsx resources/js/components/configurator/coins resources/js/__tests__/store/coins-schedule.test.ts resources/js/__tests__/store/coins-home.test.tsx
git commit -m "feat: show exact Coins prices instantly"
```

---

### Task 3: Guest Cart Owner and Database Invariant

**Files:**
- Create: `app/ValueObjects/Cart/CartOwner.php`
- Create: `app/Actions/Cart/ResolveCartOwner.php`
- Create: `database/migrations/2026_08_10_000003_expand_active_cart_invariant_to_guests.php`
- Modify: `app/Models/Cart.php`
- Modify: `app/Security/CoinsCartFingerprint.php`
- Test: `tests/Unit/Cart/CartOwnerTest.php`
- Test: `tests/Feature/Database/CartSecuritySchemaTest.php`
- Test: `tests/Integration/ActiveCartInvariantUpgradeTest.php`
- Test: `tests/Integration/CoinsCartConcurrencyTest.php`
- Modify: `.github/workflows/tests.yml`

**Interfaces:**
- `CartOwner` exposes `databaseKey(): string`, `userId(): ?int`, `sessionKey(): ?string`, and `idempotencyScope(): string`.
- `ResolveCartOwner::forRequest(Request $request): CartOwner` returns `user:<id>` when authenticated; otherwise it reads or creates a 32-byte random session token under `coins_guest_owner_token`, persists only `hash_hmac('sha256', token, app key)` as `session_key`, and returns `guest:<hmac>` as the active owner key.
- `Cart::activeForOwner(CartOwner $owner)` replaces user-only lookup.
- `CoinsCartFingerprint::generate(string $ownerKey, array $validated)` replaces the user-ID argument.

- [x] **Step 1: Write owner and invariant RED tests**

Prove stable owner in one guest session, different owners across sessions, authenticated precedence, no raw token/session ID in DB/loggable arrays, duplicate active guest and user carts rejected, converted carts release the key, invalid supplied keys are derived by DB, upgrade backfills valid user/guest rows, duplicate legacy rows fail migration, and same-guest concurrent first-add creates one cart.

- [x] **Step 2: Run SQLite RED**

```powershell
php vendor/bin/pest tests/Unit/Cart/CartOwnerTest.php tests/Feature/Database/CartSecuritySchemaTest.php tests/Integration/ActiveCartInvariantUpgradeTest.php --compact
```

Expected: FAIL because guest ownership and the expanded migration do not exist.

- [x] **Step 3: Implement the owner value and resolver**

Use an opaque token stored only inside Laravel's server-side session:

```php
final readonly class CartOwner
{
    private function __construct(
        private string $databaseKey,
        private ?int $userId,
        private ?string $sessionKey,
    ) {}

    public static function user(int $userId): self
    {
        return new self("user:{$userId}", $userId, null);
    }

    public static function guest(string $hmac): self
    {
        return new self("guest:{$hmac}", null, $hmac);
    }
}
```

The resolver must validate the token as 64 lowercase hex characters after HMAC and must not serialize or expose the original token.

- [x] **Step 4: Expand SQLite and MariaDB derivation**

SQLite expression index and derivation triggers must derive:

```sql
CASE
  WHEN status = 'active' AND currency = 'SAR' AND user_id IS NOT NULL
    THEN 'user:' || user_id
  WHEN status = 'active' AND currency = 'SAR' AND user_id IS NULL AND session_key IS NOT NULL
    THEN 'guest:' || session_key
  ELSE NULL
END
```

MariaDB must use the equivalent `STORED GENERATED` expression and a unique index. The migration must reject duplicate active owner rows before altering the invariant and restore the authenticated-only invariant on `down()`.

- [x] **Step 5: Run SQLite and MariaDB GREEN**

Run Step 2, then start the repository's isolated MariaDB profile and run:

```powershell
php artisan migrate:fresh --force
php vendor/bin/pest --configuration phpunit.mariadb.xml tests/Feature/Database/CartSecuritySchemaTest.php tests/Integration/ActiveCartInvariantUpgradeTest.php tests/Integration/CoinsCartConcurrencyTest.php --compact
php artisan migrate:rollback --force
php artisan migrate --force
```

Expected: SQLite and MariaDB tests pass; fresh, rollback, and re-migrate succeed; no disposable server remains running.

- [x] **Step 6: Extend the MariaDB CI lane**

Keep all existing cart security suites in `.github/workflows/tests.yml` and ensure the same guest-owner concurrency test runs under the pinned MariaDB service.

- [x] **Step 7: Commit Task 3**

```powershell
git add app/ValueObjects/Cart/CartOwner.php app/Actions/Cart/ResolveCartOwner.php app/Models/Cart.php app/Security/CoinsCartFingerprint.php database/migrations/2026_08_10_000003_expand_active_cart_invariant_to_guests.php tests/Unit/Cart/CartOwnerTest.php tests/Feature/Database/CartSecuritySchemaTest.php tests/Integration/ActiveCartInvariantUpgradeTest.php tests/Integration/CoinsCartConcurrencyTest.php .github/workflows/tests.yml
git commit -m "feat: enforce secure guest cart ownership"
```

---

### Task 4: Guest Add-to-Cart, Cart Read, and Configurator Flow

**Files:**
- Modify: `app/Actions/Cart/AddCoinsToCart.php`
- Modify: `app/Http/Controllers/Store/CoinsCartController.php`
- Modify: `app/Http/Controllers/Store/CartController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Store/HomeController.php`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/js/components/configurator/coins/coins-configurator.tsx`
- Modify: `resources/js/pages/store/cart.tsx`
- Test: `tests/Feature/Store/CoinsCartTest.php`
- Test: `tests/Feature/Store/HomeCoinsConfiguratorTest.php`
- Test: `resources/js/__tests__/store/coins-credentials-flow.test.tsx`
- Test: `resources/js/__tests__/store/store-cart.test.tsx`

**Interfaces:**
- `AddCoinsToCart::execute(CartOwner $owner, array $validated, string $idempotencyKey, string $locale): array`.
- POST cart routes keep `NoStore`, `RequireCoinsCartJson`, and `throttle:coins-cart` but no longer require `auth`.
- Cart read and shared `cartCount` resolve the same owner.
- The configurator always advances Amount → Credentials → Summary; `authenticated` no longer gates credentials and `resumeUrl` is removed from `CoinsCartConfig`.

- [ ] **Step 1: Write guest-flow RED tests**

Cover guest 201 add, same-session replay, different-session conflict/isolation, encrypted secret, safe response, cart read/count, CSRF, JSON-only, throttle, validation, rollback, expiry, no URL/session/config/response leakage, guest amount Continue entering credentials, successful redirect to cart, and absence of login/checkout/payment controls.

- [ ] **Step 2: Run guest RED**

```powershell
php vendor/bin/pest tests/Feature/Store/CoinsCartTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php --compact
npx vitest run resources/js/__tests__/store/coins-credentials-flow.test.tsx resources/js/__tests__/store/store-cart.test.tsx
```

Expected: guest endpoint tests receive 401 and the UI still links to login/resume.

- [ ] **Step 3: Generalize the cart action**

Replace user-specific scope, fingerprint, lookup, and insert values with `CartOwner`. For a guest cart, write `user_id = null`, `session_key = $owner->sessionKey()`, and rely on the DB-generated `active_owner_key`. Preserve the existing transaction, row locks, server re-quote, encrypted payload, safe configuration, and idempotency response.

- [ ] **Step 4: Open only the intended guest boundary**

Remove `auth` only from canonical and localized POST Coins-cart routes. Do not relax JSON, CSRF, throttle, or no-store middleware. Remove the now-dead resume route/controller/prop only after all callers and tests are migrated.

- [ ] **Step 5: Make cart read/count owner-aware**

Use `ResolveCartOwner` in `CartController` and shared Inertia props. Keep `safeConfiguration()` and `safeCredentials()` as the only projection paths; do not expose owner keys or session hashes.

- [ ] **Step 6: Update the configurator flow**

Remove the guest login link branch from `AmountStep`. Continue always dispatches `step-entered: credentials`; submission uses the same in-memory credentials and UUID idempotency lifecycle for guests and users. Successful 201 clears credential state and navigates to the safe cart URL.

- [ ] **Step 7: Run GREEN, leak scans, and static gates**

Run Step 2, then:

```powershell
rg -n "ea_password|backup_codes|credentials" app resources/js | rg "session|query|localStorage|sessionStorage|Log::|logger\(|configuration"
php vendor/bin/phpstan analyse app/Actions/Cart app/Http/Controllers/Store app/Http/Middleware/HandleInertiaRequests.php
php vendor/bin/pint --dirty
npm run ci:check
```

Expected: focused suites pass, leak scan has no unsafe credential path, PHPStan/Pint/frontend CI pass.

- [ ] **Step 8: Commit Task 4**

```powershell
git add app/Actions/Cart/AddCoinsToCart.php app/Http/Controllers/Store/CoinsCartController.php app/Http/Controllers/Store/CartController.php app/Http/Middleware/HandleInertiaRequests.php app/Http/Controllers/Store/HomeController.php routes/web.php resources/js/types/coins.ts resources/js/components/configurator/coins/coins-configurator.tsx resources/js/pages/store/cart.tsx tests/Feature/Store/CoinsCartTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php resources/js/__tests__/store/coins-credentials-flow.test.tsx resources/js/__tests__/store/store-cart.test.tsx
git commit -m "feat: let guests securely add Coins to cart"
```

---

### Task 5: Transactional Guest-Cart Claim After Authentication

**Files:**
- Create: `app/Actions/Cart/ClaimGuestCart.php`
- Create: `app/Listeners/ClaimGuestCartAfterLogin.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Auth/GuestCartClaimTest.php`
- Test: `tests/Integration/CoinsCartConcurrencyTest.php`

**Interfaces:**
- `ClaimGuestCart::execute(string $guestSessionHmac, User $user): void` is idempotent and transactional.
- The login listener reads the existing `coins_guest_owner_token` from the request session, derives its HMAC through `ResolveCartOwner`, calls the action after successful authentication, and forgets the token only after commit.
- Registration uses the same successful-login event path; there is no duplicate registration-specific claim implementation.

- [ ] **Step 1: Write claim RED tests**

Cover guest-only cart transfer, merge into an existing user cart, cart-item and encrypted-secret relation retention, no secret decryption/access log, repeated claim no-op, rollback on injected failure, two concurrent claims, locale-preserving login/registration redirects, and no cross-session claim.

- [ ] **Step 2: Run claim RED**

```powershell
php vendor/bin/pest tests/Feature/Auth/GuestCartClaimTest.php tests/Integration/CoinsCartConcurrencyTest.php --compact
```

Expected: FAIL because no claim action/listener exists.

- [ ] **Step 3: Implement the transactional claim**

Inside one transaction, lock the guest and user active carts in deterministic owner-key order. If only the guest cart exists, update its `user_id`, clear `session_key`, and let the DB derive `user:<id>`. If both exist, bulk-update guest `cart_items.cart_id` to the user cart, then delete only the empty guest cart. Never load or mutate `CartItemSecret::encrypted_payload`.

- [ ] **Step 4: Wire the successful-auth event**

Register one listener for `Illuminate\Auth\Events\Login`. The listener must return without a guest token or non-`User` authenticatable and must not swallow a claim failure. Preserve Fortify's localized intended destination behavior.

- [ ] **Step 5: Run SQLite and MariaDB GREEN**

Run Step 2 under SQLite and the isolated MariaDB profile. Verify same-owner parallel login processes result in one active user cart, every original item, every secret relation, and no guest cart.

- [ ] **Step 6: Commit Task 5**

```powershell
git add app/Actions/Cart/ClaimGuestCart.php app/Listeners/ClaimGuestCartAfterLogin.php app/Providers/AppServiceProvider.php tests/Feature/Auth/GuestCartClaimTest.php tests/Integration/CoinsCartConcurrencyTest.php
git commit -m "feat: claim guest carts after authentication"
```

---

### Task 6: Storefront Authentication Shell

**Files:**
- Modify: `resources/js/layouts/auth/auth-simple-layout.tsx`
- Modify: `resources/js/layouts/auth-layout.tsx`
- Modify: `resources/js/pages/auth/login.tsx`
- Modify: `resources/js/pages/auth/register.tsx`
- Modify: `resources/js/pages/auth/forgot-password.tsx`
- Modify: `resources/js/pages/auth/reset-password.tsx`
- Modify: `resources/js/types/auth.ts`
- Modify: `lang/ar/auth_ui.php`
- Modify: `lang/en/auth_ui.php`
- Modify: `resources/css/app.css`
- Test: `resources/js/__tests__/auth/auth-storefront.test.tsx`
- Test: `tests/Feature/Auth/LocalizedAuthTest.php`

**Interfaces:**
- Auth pages compose existing `StoreHeader` and `StoreFooter` through the shared shell props; they do not duplicate either component.
- Login/register use a two-panel desktop region: form card plus truthful account-benefits panel; mobile order is form then benefits.
- Forgot/reset retain a single focused form card inside the same storefront shell.

- [ ] **Step 1: Write auth-shell RED tests**

Assert one banner/navigation/contentinfo, correct active account control, localized AR/EN actions/links, form-first DOM order, exact benefit copy, no Google/payment/checkout controls, Serif Display headings, Sans labels, 44px targets, safe intended selection only, and secret-free URL/props.

- [ ] **Step 2: Run auth RED**

```powershell
npx vitest run resources/js/__tests__/auth/auth-storefront.test.tsx
php vendor/bin/pest tests/Feature/Auth/LocalizedAuthTest.php --compact
```

Expected: FAIL because auth uses the standalone simple shell and lacks the value panel.

- [ ] **Step 3: Compose the shared storefront shell**

Use the same shell props already emitted by `HandleInertiaRequests`. Keep form state in each page, not the layout. The value panel uses only these implemented benefits:

- Arabic: `سلتك تكمل معك بعد تسجيل الدخول`، `بيانات EA مشفّرة داخل السلة المؤقتة`، `غيّر اللغة والعملة من نفس المتجر`.
- English: `Your cart continues after you sign in`, `EA credentials stay encrypted in the temporary cart`, `Change language and currency in the same store`.

- [ ] **Step 4: Apply WordPress-faithful responsive styling**

Use existing warm/card/gold tokens; set auth H1 and benefit statement to `Thmanyah Serif Display` at weight 700 or 900, not synthetic 800. At desktop use two balanced panels; at <=768px stack form first. Preserve visible focus, password reveal, error association, and reduced motion.

- [ ] **Step 5: Run auth GREEN and browser build**

Run Step 2 plus lint, Prettier, TypeScript, and Vite build.

- [ ] **Step 6: Commit Task 6**

```powershell
git add resources/js/layouts/auth resources/js/layouts/auth-layout.tsx resources/js/pages/auth resources/js/types/auth.ts lang/ar/auth_ui.php lang/en/auth_ui.php resources/css/app.css resources/js/__tests__/auth/auth-storefront.test.tsx tests/Feature/Auth/LocalizedAuthTest.php
git commit -m "feat: integrate authentication with the storefront"
```

---

### Task 7: Approved Simple Storefront Polish — Luna Ownership

**Files:**
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `lang/ar/ui.php`
- Modify: `lang/en/ui.php`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/js/types/store-shell.ts`
- Modify: `resources/js/pages/store/home.tsx`
- Modify: `resources/js/components/store/store-header.tsx`
- Modify: `resources/js/components/store/store-preferences.tsx`
- Modify: `resources/js/components/store/store-footer.tsx`
- Modify: `resources/css/app.css`
- Create: `public/images/store/coins/ut-coin-160.webp`
- Create: `public/images/store/coins/ut-coin-240.webp`
- Test: `tests/Feature/Store/StoreTranslationParityTest.php`
- Test: `resources/js/__tests__/store/coins-home.test.tsx`
- Test: `resources/js/__tests__/store/store-header.test.tsx`
- Test: `resources/js/__tests__/store/store-footer.test.tsx`

**Interfaces:**
- Arabic stats use `{ value: '+30', unit: 'مليار', label: 'كوينز تم توصيلها' }`; English stats use the same explicit value/unit shape.
- `StoreHeader` owns a live client URL state synchronized from the Inertia prop plus `hashchange` and `popstate`.
- `exchange_rate_attribution` moves from `footer` translations to `preferences` translations and renders once inside the open dialog.

- [ ] **Step 1: Write copy, bidi, hash, decorative, and attribution RED tests**

Assert the exact approved copy; `<bdi dir="ltr">+30</bdi>` precedes the Arabic unit; decorative images have empty alt, `aria-hidden`, and `draggable=false`; changing from `/` to `#coins` transfers `aria-current` from Home to Coins; footer lacks rate attribution; preferences dialog contains the verified linked attribution.

- [ ] **Step 2: Run polish RED**

```powershell
php vendor/bin/pest tests/Feature/Store/StoreTranslationParityTest.php --compact
npx vitest run resources/js/__tests__/store/coins-home.test.tsx resources/js/__tests__/store/store-header.test.tsx resources/js/__tests__/store/store-footer.test.tsx
```

- [ ] **Step 3: Apply exact copy and stable bidi composition**

Use:

```php
'title' => 'اشتري كوينز فيفا 27',
'intro' => 'اختر المنصة ونوع التوصيل والكمية، وأكمل طلبك خلال دقائق — توصيل آمن وضمان كامل.',
'subtitle' => 'نوصل كوينز فيفا 27 لحسابك بسرعة وأمان — مع ضمان كامل.',
```

Render stat value and unit as separate spans, with the numeric span isolated by `<bdi dir="ltr">`.

- [ ] **Step 4: Add live hash state and relocate attribution**

Synchronize a `liveCurrentUrl` state from `currentUrl`; on `hashchange`/`popstate`, use `window.location.pathname + window.location.search + window.location.hash`. Feed this state to `activeState` and `StorePreferences`. Move the existing official link, unchanged, from the footer to the preferences dialog.

- [ ] **Step 5: Add floating coins and typography corrections**

Reuse the exact WordPress transparent source. Use 160/240 WebP derivatives for elements rendered larger than 40 CSS pixels; never scale the 80px asset above its useful size. Keep decorations behind content and outside hit regions. Add only missing Serif Display selectors and replace synthetic `font-weight: 800` on large auth/wordmark text with available 700 or 900.

- [ ] **Step 6: Run polish GREEN and responsive browser matrix**

Run Step 2 plus AR/EN at 320, 390, 768, and 1440 pixels. Verify direct hash load, same-page click, browser back, computed fonts, bidi order, pointer hit regions, reduced motion, no overflow, link reachability, and zero console errors/warnings.

- [ ] **Step 7: Commit Task 7**

```powershell
git add lang/ar/store.php lang/en/store.php lang/ar/ui.php lang/en/ui.php resources/js/types/coins.ts resources/js/types/store-shell.ts resources/js/pages/store/home.tsx resources/js/components/store/store-header.tsx resources/js/components/store/store-preferences.tsx resources/js/components/store/store-footer.tsx resources/css/app.css public/images/store/coins tests/Feature/Store/StoreTranslationParityTest.php resources/js/__tests__/store/coins-home.test.tsx resources/js/__tests__/store/store-header.test.tsx resources/js/__tests__/store/store-footer.test.tsx
git commit -m "feat: polish the Arab UT storefront journey"
```

---

### Task 8: Cross-System Verification, Guards, and Final Review

**Files:**
- Create: `.superpowers/sdd/2026-08-10-instant-coins-guest-cart-storefront/final-report.md`

**Interfaces:**
- No new production interface. This task proves the end-to-end contracts and records exact evidence.

- [ ] **Step 1: Run Clean Code, Test, and Docs guard reviews**

Inspect changed production code for duplicated pricing/owner logic, broad controllers, unsafe serialization, hidden side effects, dead compatibility contracts, and synthetic font weights. Inspect tests for implementation-coupled CSS assertions, missing behavior assertions, mocks of code under test, false-positive preconditions, and SQLite-only database claims. Verify the spec, plan, report, routes, and provider attribution claims against current code and official docs.

- [ ] **Step 2: Run the full aggregate gate**

```powershell
$commonGitDir = (Resolve-Path (git rev-parse --git-common-dir)).Path
$toolsDir = (Resolve-Path (Join-Path $commonGitDir '..\..\tools')).Path
$env:PHPRC = Join-Path $toolsDir 'php.ini'
$env:PHP_INI_SCAN_DIR=''
php (Join-Path $toolsDir 'composer.phar') ci:check
git diff --check
```

Expected: Composer validate, Pint, PHPStan, full Pest, full Vitest, ESLint, Prettier, TypeScript, and Vite build all exit 0.

- [ ] **Step 3: Run the final MariaDB gate**

On an isolated disposable MariaDB instance: migrate fresh, run all pricing schedule, cart schema, endpoint, purge, claim, upgrade, and concurrency suites, rollback, re-migrate, and confirm no listener/process/data directory remains.

- [ ] **Step 4: Run the full browser contract**

Verify Arabic and English at 320, 390, 768, and 1440 pixels for homepage, all five configurator steps, guest cart, login, and registration. Exercise every amount control and prove the DOM total changes before any network response; prove zero quote requests during amount changes and one server-requoted POST on add. Add as guest, inspect safe cart/count, log in, prove claim/merge, verify direct `#coins`, back/forward, visible focus, 44px controls, reduced motion, exact fonts, no horizontal overflow, no secrets in URL/DOM/storage/network response, and zero console errors/warnings.

- [ ] **Step 5: Request task and whole-branch review**

Review every task's exact commit range for both spec compliance and code quality. Resolve all Critical/Important findings through scoped fix/re-review loops. Run one most-capable whole-branch review over the merge base through HEAD. When that review returns findings, run exactly one bounded final fix wave followed by one scoped re-review.

- [ ] **Step 6: Write the final report and finish locally**

Record RED/GREEN outputs, MariaDB lifecycle, browser matrix, provider attribution location, security leak scans, guard verdicts, concerns, and commit hashes. Do not push or deploy. Use `superpowers:finishing-a-development-branch` and merge locally into `main` only after the final review is clean.
