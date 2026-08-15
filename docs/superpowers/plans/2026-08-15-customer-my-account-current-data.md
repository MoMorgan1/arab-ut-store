# Customer My Account Current-Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the complete bilingual customer `حسابي` / `My Account` experience on authoritative current Laravel data, with the security and rollout contracts required before archived Salla history is connected.

**Architecture:** Account pages are owner-scoped Inertia routes inside the existing `StoreLayout`. Focused server query/action classes return explicit presentation arrays; React renders those arrays without calculating money, status, ownership, wallet balances, or loyalty. `my_account_enabled` is independent from `legacy_history_enabled`, so this plan ships current data while the archive remains disabled.

**Tech Stack:** PHP 8.3, Laravel 13.17, Fortify, Inertia Laravel/React 3, React 19.2, TypeScript 5.7, Tailwind CSS 4, Pest 4, Vitest 4.

**Spec:** `docs/superpowers/specs/2026-08-15-customer-my-account-design.md`

## Global Constraints

- Customer-facing names are exactly `حسابي` and `My Account`; never `Dashboard`.
- Arabic defaults to RTL, English to LTR, with complete translation-key parity.
- Preserve the verified WordPress hierarchy, Thmanyah typography, near-black/deep-navy surfaces, restrained warm gold, official crest, and mobile-first account navigation.
- Account GET routes require auth, active user, no-store, and Inertia history encryption.
- Never serialize models directly or expose credentials, OTPs, raw payloads, provider secrets, or internal notes.
- Transfer current money as exact minor-unit decimal strings and currency codes; React only formats display.
- No new production/runtime frontend dependency.
- Every implementation step follows red-green-refactor TDD and every task ends with focused tests and a commit.
- Archive import, archive claim, and unified live/archive feed are covered by separate plans and remain behind `legacy_history_enabled=false` here.

---

## File Map

- `config/store.php`: rollout flags and account support configuration.
- `app/Http/Middleware/EnsureActiveUser.php`: reject authenticated inactive sessions.
- `app/Http/Responses/LogoutResponse.php`: clear encrypted Inertia history, then redirect locally.
- `app/Providers/FortifyServiceProvider.php`: bind the custom Fortify logout response.
- `routes/account.php`: canonical Arabic/English account GET and mutation routes.
- `routes/web.php`, `routes/settings.php`: load account routes, redirect dashboard/settings aliases, disable profile deletion.
- `app/Http/Controllers/Account/*`: thin Inertia destination controllers.
- `app/Account/Queries/*`: overview, live-order, wallet, and loyalty projections.
- `app/Account/Actions/*`: profile, pending identity, and password mutations.
- `app/Http/Requests/Account/*`: destination-specific validation and authorization.
- `lang/ar/account.php`, `lang/en/account.php`: native bilingual account copy.
- `resources/js/layouts/my-account-layout.tsx`: account shell inside `StoreLayout`.
- `resources/js/pages/account/*`: account destinations.
- `resources/js/components/account/*`: focused cards, navigation, ledger, forms, and states.
- `resources/js/types/account.ts`: account-only page and DTO types.
- `resources/css/app.css`: WordPress-faithful account layout and responsive styling using existing tokens.
- `tests/Feature/Account/*`: routes, security, projections, mutations, and privacy.
- `resources/js/__tests__/account/*`: rendering, interactions, RTL/LTR, focus, and overflow contracts.

---

### Task 1: Rollout, Active-User, History, and Destructive-Route Security

**Files:**
- Modify: `config/store.php`
- Create: `app/Http/Middleware/EnsureActiveUser.php`
- Create: `app/Http/Middleware/EnsureMyAccountEnabled.php`
- Create: `app/Http/Responses/LogoutResponse.php`
- Modify: `app/Providers/FortifyServiceProvider.php`
- Modify: `app/Http/Controllers/Settings/ProfileController.php`
- Modify: `routes/settings.php`
- Delete: `app/Http/Requests/Settings/ProfileDeleteRequest.php`
- Delete: `resources/js/components/delete-user.tsx`
- Modify: `resources/js/pages/settings/profile.tsx`
- Test: `tests/Feature/Account/AccountSecurityBoundaryTest.php`
- Test: `resources/js/__tests__/settings/profile-settings.test.tsx`

**Interfaces:**
- Produces: `config('store.features.my_account_enabled')`, `config('store.features.legacy_history_enabled')`.
- Produces: invokable `EnsureActiveUser::handle(Request $request, Closure $next): Response`.
- Produces: invokable `EnsureMyAccountEnabled::handle(Request $request, Closure $next): Response` returning 404 while account rollout is disabled.
- Produces: `LogoutResponse` implementing `Laravel\Fortify\Contracts\LogoutResponse` and calling `Inertia::clearHistory()`.
- Removes: the live `profile.destroy` route, dead controller/request code, and its customer control until deletion retention rules are approved.

- [x] **Step 1: Write failing boundary tests**

```php
test('account and legacy history rollout controls stay independent', function (
    bool $accountEnabled,
    bool $legacyEnabled,
    int $expectedStatus,
) {
    Route::middleware(['web', EnsureMyAccountEnabled::class])
        ->get('/_test/account-rollout', fn () => response()->noContent());

    config()->set('store.features.my_account_enabled', $accountEnabled);
    config()->set('store.features.legacy_history_enabled', $legacyEnabled);

    $this->get('/_test/account-rollout')->assertStatus($expectedStatus);
})->with([
    'account disabled while archive enabled' => [false, true, 404],
    'account enabled while archive disabled' => [true, false, 204],
]);

test('account deletion is not routable', function () {
    expect(Route::has('profile.destroy'))->toBeFalse();
});

test('logout asks inertia to clear encrypted browser history', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withHeader('X-Inertia', 'true')->post('/logout')
        ->assertRedirect('/')
        ->assertSessionHas('inertia.clear_history', true);
});
```

- [x] **Step 2: Run the boundary tests and confirm failure**

Run: `php artisan test tests/Feature/Account/AccountSecurityBoundaryTest.php`

Expected: FAIL because the flags, middleware/response, and route removal do not exist.

- [x] **Step 3: Add the rollout flags and active-user middleware**

```php
'features' => [
    'my_account_enabled' => env('MY_ACCOUNT_ENABLED', true),
    'legacy_history_enabled' => env('LEGACY_HISTORY_ENABLED', false),
],
```

`EnsureActiveUser` aborts with 403 for inactive authenticated sessions. `EnsureMyAccountEnabled` checks only the account flag so the legacy flag cannot disable current-data account destinations.

- [x] **Step 4: Bind a Fortify logout response that clears history**

```php
final class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        Inertia::clearHistory();

        return redirect($request->route('locale') === 'en' ? '/en' : '/');
    }
}
```

Bind it in `FortifyServiceProvider::register()`. Remove the profile delete route, its dead controller/request code, and the legacy delete-account control.

- [x] **Step 5: Run focused tests and format PHP**

Run: `php artisan test tests/Feature/Account/AccountSecurityBoundaryTest.php tests/Feature/Auth/AuthenticationTest.php`

Run: `vendor/bin/pint --dirty`

Expected: PASS; `/logout` still invalidates the session through Fortify and returns a history-clearing response.

- [x] **Step 6: Commit the security boundary**

```bash
git add app config routes resources/js tests docs/superpowers/plans/2026-08-15-customer-my-account-current-data.md
git commit -m "feat: secure my account rollout boundary"
```

### Task 2: Canonical Routes, Shared URLs, and Translation Contract

**Files:**
- Create: `routes/account.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `app/Http/Controllers/Account/OverviewController.php`
- Create: `lang/ar/account.php`
- Create: `lang/en/account.php`
- Modify: `resources/js/types/store-shell.ts`
- Test: `tests/Feature/Account/AccountRoutesTest.php`
- Test: `tests/Feature/Account/AccountTranslationParityTest.php`

**Interfaces:**
- Produces route names `account.overview`, `account.orders`, `account.wallet`, `account.profile`, `account.security`, `account.support` and `localized.account.*`.
- Produces `storeShell.accountUrl` pointing to `/my-account` or `/en/my-account` for authenticated users.
- Produces `accountUi = trans('account')` for every account destination.

- [ ] **Step 1: Write failing route and translation tests**

Cover guest redirect, Arabic/English canonical URLs, `/dashboard` redirect, active-user 403, `Cache-Control: no-store`, `encryptHistory=true`, and exact Arabic/English translation leaf parity.

- [ ] **Step 2: Run focused tests and confirm failure**

Run: `php artisan test tests/Feature/Account/AccountRoutesTest.php tests/Feature/Account/AccountTranslationParityTest.php`

- [ ] **Step 3: Define account route groups**

Use middleware `['auth', EnsureActiveUser::class, NoStore::class, 'inertia::encrypt']`. Define the unprefixed Arabic group and an English `/en` group with `->defaults('locale', 'en')`; do not create `/ar/my-account` as a canonical URL.

- [ ] **Step 4: Add the overview controller and shared URL**

The controller returns `Inertia::render('account/overview', ['accountUi' => trans('account')])`. Update `HandleInertiaRequests` to choose the locale-specific account route for authenticated customers and retain login for guests.

- [ ] **Step 5: Add complete native translations**

Define navigation, overview, orders, wallet, profile, security, support, status, action, error, empty, verification, and accessibility keys in both files. Arabic uses `حسابي`, `نظرة عامة`, `طلباتي`, `محفظتي`, `بياناتي`, `الأمان`, and `الدعم`.

- [ ] **Step 6: Run focused tests, route inspection, and format**

Run: `php artisan route:list --path=my-account`

Run: `php artisan test tests/Feature/Account/AccountRoutesTest.php tests/Feature/Account/AccountTranslationParityTest.php tests/Feature/Store/StoreShellRoutesTest.php`

- [ ] **Step 7: Commit the canonical contract**

```bash
git add routes/account.php routes/web.php app/Http/Middleware/HandleInertiaRequests.php app/Http/Controllers/Account/OverviewController.php lang/ar/account.php lang/en/account.php resources/js/types/store-shell.ts tests/Feature/Account
git commit -m "feat: add canonical my account routes"
```

### Task 3: Exact Money, Live Summary, and Loyalty Queries

**Files:**
- Create: `app/Account/Presenters/AccountMoney.php`
- Create: `app/Account/Queries/ReadAccountOverview.php`
- Create: `app/Account/Queries/ResolveLiveActionableOrder.php`
- Create: `app/Account/Queries/ResolveLoyaltyProgress.php`
- Modify: `app/Http/Controllers/Account/OverviewController.php`
- Test: `tests/Feature/Account/AccountOverviewTest.php`
- Test: `tests/Unit/Account/AccountMoneyTest.php`

**Interfaces:**
- Produces `AccountMoney::fromMinor(int $amountMinor, string $currency): array{amountMinor:string,currency:string}`.
- Produces `ReadAccountOverview::for(User $user, string $locale): array{metrics:array,activeOrder:?array,recentOrders:list<array>,loyalty:?array}`.
- Uses live orders only until `legacy_history_enabled` is connected by the archive plan.

- [ ] **Step 1: Write failing projection tests**

Test empty accounts, owner scoping, actionable priority, three newest orders, open/completed counts, wallet absence, exact string money, current completed net-SAR loyalty, completed refunds, and no credential/configuration leakage.

- [ ] **Step 2: Run tests and confirm failure**

Run: `php artisan test tests/Unit/Account/AccountMoneyTest.php tests/Feature/Account/AccountOverviewTest.php`

- [ ] **Step 3: Implement the exact money presenter and query classes**

Queries select explicit columns and eager-load only public item names/statuses. Loyalty equals completed settled order totals minus completed refunds, never below zero; wallet-funded value counts as part of the order total.

- [ ] **Step 4: Add query output as controller props**

Return only `summary`, `activeOrder`, `recentOrders`, and `loyalty`; never pass `Order`, `OrderItem`, `WalletAccount`, or `User` models as destination props.

- [ ] **Step 5: Run tests, PHPStan on changed namespaces, and format**

Run: `php artisan test tests/Unit/Account tests/Feature/Account/AccountOverviewTest.php`

Run: `vendor/bin/phpstan analyse app/Account app/Http/Controllers/Account --no-progress`

- [ ] **Step 6: Commit the current-data read boundary**

```bash
git add app/Account app/Http/Controllers/Account/OverviewController.php tests/Unit/Account tests/Feature/Account/AccountOverviewTest.php
git commit -m "feat: project current account summary"
```

### Task 4: WordPress-Faithful Account Layout and Overview

**Files:**
- Create: `resources/js/types/account.ts`
- Create: `resources/js/layouts/my-account-layout.tsx`
- Create: `resources/js/components/account/account-navigation.tsx`
- Create: `resources/js/components/account/account-order-card.tsx`
- Create: `resources/js/components/account/account-metric.tsx`
- Create: `resources/js/pages/account/overview.tsx`
- Modify: `resources/js/types/index.ts`
- Modify: `resources/css/app.css`
- Test: `resources/js/__tests__/account/account-overview.test.tsx`
- Test: `resources/js/__tests__/account/account-navigation.test.tsx`

**Interfaces:**
- Consumes the exact Task 3 projections.
- Produces `MyAccountLayout` wrapping `StoreLayout`, a separate POST logout button, horizontal mobile navigation, and desktop side navigation.

- [ ] **Step 1: Write failing rendering and interaction tests**

Assert `حسابي`/`My Account`, selected navigation semantics, separate button logout, active-order prominence, honest empty states, safe money rendering, keyboard focus, and no `Dashboard` label.

- [ ] **Step 2: Run Vitest and confirm failure**

Run: `npm test -- resources/js/__tests__/account/account-overview.test.tsx resources/js/__tests__/account/account-navigation.test.tsx`

- [ ] **Step 3: Implement types, layout, and focused components**

Use existing CSS variables and assets. Account navigation links are at least 44px high, expose `aria-current="page"`, and never prefetch wallet/security/order detail pages. Logout uses Inertia POST, `router.flushAll()`, and no anchor semantics.

- [ ] **Step 4: Implement the overview page and states**

Render the welcome area, actionable order, wallet/open/completed metrics, loyalty progress, three recent orders, and branded empty/unavailable states in the approved WordPress order.

- [ ] **Step 5: Run frontend checks**

Run: `npm test -- resources/js/__tests__/account`

Run: `npm run types:check && npm run lint:check && npm run format:check`

- [ ] **Step 6: Commit the account shell**

```bash
git add resources/js/types resources/js/layouts/my-account-layout.tsx resources/js/components/account resources/js/pages/account/overview.tsx resources/css/app.css resources/js/__tests__/account
git commit -m "feat: build branded my account overview"
```

### Task 5: Live Orders List and Canonical Detail

**Files:**
- Create: `app/Account/Queries/ReadLiveOrders.php`
- Create: `app/Http/Controllers/Account/OrdersController.php`
- Create: `app/Http/Controllers/Account/LiveOrderController.php`
- Modify: `app/Http/Controllers/Store/OrderController.php`
- Modify: `routes/account.php`
- Create: `resources/js/pages/account/orders.tsx`
- Create: `resources/js/pages/account/live-order.tsx`
- Test: `tests/Feature/Account/AccountOrdersTest.php`
- Test: `resources/js/__tests__/account/account-orders.test.tsx`

**Interfaces:**
- Produces owner-scoped live order cards ordered by `placed_at DESC, public_id DESC` with bounded pagination.
- Produces current detail props with safe item progress and payment recovery URL only when permitted.

- [ ] **Step 1: Write failing owner, pagination, status, and privacy tests**

- [ ] **Step 2: Run focused tests and confirm failure**

Run: `php artisan test tests/Feature/Account/AccountOrdersTest.php`

- [ ] **Step 3: Implement server projections and canonical routes**

Filters use an allowlisted canonical status; unknown filters return validation errors. The legacy `/orders/{publicId}` destination redirects to the locale-correct canonical detail only after the same owner-scoped lookup succeeds.

- [ ] **Step 4: Write and run failing React tests**

Run: `npm test -- resources/js/__tests__/account/account-orders.test.tsx`

- [ ] **Step 5: Implement order cards, filters, pagination, details, and explicit refresh**

The refresh button performs `router.reload({ only: ['order'] })`, preserves focus, and disappears for terminal orders. Page props never expose saved EA secrets or raw item configuration.

- [ ] **Step 6: Run focused backend/frontend checks and commit**

```bash
git add app/Account/Queries/ReadLiveOrders.php app/Http/Controllers/Account app/Http/Controllers/Store/OrderController.php routes/account.php resources/js/pages/account resources/js/__tests__/account/account-orders.test.tsx tests/Feature/Account/AccountOrdersTest.php
git commit -m "feat: add customer live order history"
```

### Task 6: Immutable Wallet Ledger and Loyalty Page Modules

**Files:**
- Create: `database/migrations/2026_08_15_000001_add_wallet_entry_sequence.php`
- Modify: `app/Models/WalletEntry.php`
- Create: `app/Account/Queries/ReadWalletLedger.php`
- Create: `app/Http/Controllers/Account/WalletController.php`
- Modify: `routes/account.php`
- Create: `resources/js/pages/account/wallet.tsx`
- Create: `resources/js/components/account/wallet-ledger.tsx`
- Test: `tests/Feature/Account/AccountWalletTest.php`
- Test: `tests/Feature/Database/WalletSequenceInvariantTest.php`
- Test: `resources/js/__tests__/account/account-wallet.test.tsx`

**Interfaces:**
- Produces monotonic `sequence` unique per wallet; existing unique `reference` remains the idempotency key.
- Produces newest-sequence-first page data with exact string amounts and optional safe order reference.

- [ ] **Step 1: Write failing migration/invariant and wallet route tests**

- [ ] **Step 2: Run tests and confirm failure**

Run: `php artisan test tests/Feature/Database/WalletSequenceInvariantTest.php tests/Feature/Account/AccountWalletTest.php`

- [ ] **Step 3: Add sequence safely and project the ledger**

Backfill each wallet deterministically by `(created_at, id)`, add unique `(wallet_account_id, sequence)`, and retain immutable update/delete triggers. New wallet actions allocate the next sequence under `lockForUpdate()` in the same transaction as balance update.

- [ ] **Step 4: Add wallet UI tests, then implement the page**

Cover no-wallet, zero balance, credits/debits/refunds/adjustments, large amounts, text-plus-color semantics, and bounded pagination.

- [ ] **Step 5: Run migration lifecycle, focused tests, and commit**

```bash
git add database/migrations app/Models/WalletEntry.php app/Account/Queries/ReadWalletLedger.php app/Http/Controllers/Account/WalletController.php routes/account.php resources/js/pages/account/wallet.tsx resources/js/components/account/wallet-ledger.tsx tests/Feature resources/js/__tests__/account/account-wallet.test.tsx
git commit -m "feat: expose immutable customer wallet ledger"
```

### Task 7: Staged Profile Identity Changes

**Files:**
- Create: `database/migrations/2026_08_15_000002_create_user_identity_changes.php`
- Create: `app/Models/UserIdentityChange.php`
- Create: `app/Account/Actions/RequestEmailChange.php`
- Create: `app/Account/Actions/ConfirmEmailChange.php`
- Create: `app/Account/Actions/RequestPhoneChange.php`
- Create: `app/Account/Actions/ConfirmPhoneChange.php`
- Create: `app/Http/Controllers/Account/ProfileController.php`
- Create: `app/Http/Controllers/Account/ProfileEmailController.php`
- Create: `app/Http/Controllers/Account/ProfilePhoneController.php`
- Create: `app/Http/Requests/Account/ProfileUpdateRequest.php`
- Create: `resources/js/pages/account/profile.tsx`
- Test: `tests/Feature/Account/AccountProfileTest.php`
- Test: `resources/js/__tests__/account/account-profile.test.tsx`

**Interfaces:**
- Produces one pending identity-change record with encrypted candidate value, normalized hash, expiry, attempts, and consumed timestamp.
- Current `email`/`phone` remain unchanged until verification succeeds atomically.

- [ ] **Step 1: Write failing profile and pending-change tests**

Cover names/preferences, email typo safety, email conflict, old-address notice, phone OTP hash/expiry/attempt/cooldown, rate limiting, atomic swap, and no OTP/candidate logging.

- [ ] **Step 2: Run tests and confirm failure**

- [ ] **Step 3: Implement the schema, model, requests, and actions**

Use separate `kind=email|phone`, encrypted candidate value, HMAC normalized lookup, hashed token/code, and single-use confirmation. Require current password for password-capable accounts or a recent trusted verification timestamp for passwordless accounts.

- [ ] **Step 4: Add React tests and implement the profile state machine**

Use persistent labels and call `dontRemember('verification_code', 'otp')`; focus the first invalid field and preserve the form section after server validation.

- [ ] **Step 5: Run focused tests, security scan, and commit**

```bash
git add database/migrations app/Models/UserIdentityChange.php app/Account/Actions app/Http/Controllers/Account app/Http/Requests/Account resources/js/pages/account/profile.tsx tests/Feature/Account/AccountProfileTest.php resources/js/__tests__/account/account-profile.test.tsx
git commit -m "feat: verify staged profile identity changes"
```

### Task 8: Password Change, Setup, and Recovery-Safe Security Page

**Files:**
- Create: `app/Http/Controllers/Account/SecurityController.php`
- Create: `app/Account/Actions/SetAccountPassword.php`
- Create: `app/Http/Requests/Account/PasswordChangeRequest.php`
- Create: `app/Http/Requests/Account/PasswordSetupRequest.php`
- Modify: `routes/account.php`
- Create: `resources/js/pages/account/security.tsx`
- Test: `tests/Feature/Account/AccountSecurityTest.php`
- Test: `resources/js/__tests__/account/account-security.test.tsx`

**Interfaces:**
- Produces `passwordMode: 'change'|'setup'` based only on whether a password exists.
- Change requires current password; setup requires recent trusted re-authentication; both use Laravel password defaults and throttle.

- [ ] **Step 1: Write failing password-capable and passwordless tests**

- [ ] **Step 2: Run tests and confirm failure**

- [ ] **Step 3: Implement safe server actions and routes**

Do not expose the password hash. Reject standard email recovery when the user lacks a verified deliverable email; route those users to WhatsApp recovery/claim copy.

- [ ] **Step 4: Add React tests and implement the security page**

Call `dontRemember('current_password', 'password', 'password_confirmation')`; expose password manager autocomplete values and accessible success/error announcements.

- [ ] **Step 5: Run focused checks and commit**

```bash
git add app/Http/Controllers/Account/SecurityController.php app/Account/Actions/SetAccountPassword.php app/Http/Requests/Account routes/account.php resources/js/pages/account/security.tsx tests/Feature/Account/AccountSecurityTest.php resources/js/__tests__/account/account-security.test.tsx
git commit -m "feat: add customer account security flows"
```

### Task 9: Support Destination and Failure/Empty States

**Files:**
- Create: `app/Http/Controllers/Account/SupportController.php`
- Modify: `routes/account.php`
- Create: `resources/js/pages/account/support.tsx`
- Create: `resources/js/components/account/account-section-error.tsx`
- Test: `tests/Feature/Account/AccountSupportTest.php`
- Test: `resources/js/__tests__/account/account-states.test.tsx`

**Interfaces:**
- Produces configured WhatsApp/email URLs and optional safe public order number only.
- Produces reusable navigation-preserving error/empty/retry states.

- [ ] **Step 1: Write failing safe-contact and state tests**

- [ ] **Step 2: Run tests and confirm failure**

- [ ] **Step 3: Implement server projection and support UI**

Never include raw provider IDs, notes, credentials, or personal data in contact URLs. Missing support configuration renders a controlled unavailable state.

- [ ] **Step 4: Run focused tests and commit**

```bash
git add app/Http/Controllers/Account/SupportController.php routes/account.php resources/js/pages/account/support.tsx resources/js/components/account/account-section-error.tsx tests/Feature/Account/AccountSupportTest.php resources/js/__tests__/account/account-states.test.tsx
git commit -m "feat: add safe customer support destination"
```

### Task 10: WordPress Parity, Impeccable Polish, and Full Current-Data Verification

**Files:**
- Modify: `resources/css/app.css`
- Modify: account components/pages only where verification finds defects
- Test: `tests/Feature/Account/AccountPropPrivacyTest.php`
- Test: browser harness files selected at this task after confirming no equivalent exists

**Interfaces:**
- Consumes every current-data account destination.
- Produces the acceptance evidence required before the historical archive plan begins.

- [ ] **Step 1: Write the forbidden-prop test**

Recursively scan account Inertia props and fail on keys containing `raw_payload`, `password`, `otp`, `credentials`, `secret`, or `internal_notes`.

- [ ] **Step 2: Run the complete backend/frontend account suites**

Run: `php artisan test tests/Feature/Account tests/Unit/Account`

Run: `npm test -- resources/js/__tests__/account`

- [ ] **Step 3: Run the required UI skills and compare with WordPress**

Load `frontend-design`, `ui-ux-pro-max`, `arrange`, `typeset`, `clarify`, `adapt`, and final `polish`; compare against the inspected WordPress dashboard/orders/profile hierarchy and current storefront tokens.

- [ ] **Step 4: Verify browser behavior at every required viewport**

Verify Arabic RTL and English LTR at 320, 390, 768, and 1440px; logout then Back; user switch isolation; focus after route/validation; 44px targets; 200% zoom; reduced motion; no horizontal overflow; and no console errors. Add a dev-only browser harness only if repeatable coverage cannot be achieved with the repository's available tooling.

- [ ] **Step 5: Run the full quality gate**

Run: `composer ci:check`

Run: `npm run ci:check`

Expected: all PHP/JS tests, PHPStan, Pint, ESLint, Prettier, TypeScript, and production build pass.

- [ ] **Step 6: Commit verified current-data My Account**

```bash
git add app config routes database lang resources tests docs/superpowers
git commit -m "feat: complete current-data customer account"
```

## Follow-on Plans

After this plan passes, amend and execute `docs/superpowers/plans/2026-08-09-salla-history-import.md` for importer concurrency, run states, reconciliation blockers, sanitized retention, and `live_order_id`. Then write the archive linking/read-model plan for verified claims, owner-scoped ULIDs, database `UNION ALL`, deduplication, stable unified pagination, archived details, and controlled `legacy_history_enabled` activation.
