# Project Cleanup Round 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the four live defects and remove the highest-confidence duplication and doc drift found by the 2026-09-10 whole-project review, without changing any customer-visible behaviour except the two bug fixes.

**Architecture:** Every change is local: a new enum method or small helper replaces literal copies; Actions stop re-checking the role that `AdminAccess` already decides; docs are edited to match the code that ships. No new tables, no new routes, no UI redesign. Big refactors (cart.tsx, CartController, DiscountEngine scope enum, shared HTTP helper, pagination component) are deliberately out of scope for this round.

**Tech Stack:** Laravel 13 / PHP 8.3 (Pest, Pint, PHPStan level 7), Inertia 3 / React 19 / TypeScript (Vitest, ESLint, Prettier, tsc).

**Spec:** The review findings recorded in this plan's "Source findings" section at the bottom. Owner decisions taken on 2026-09-10: automatic cashback stays and gets documented; nothing else in scope needs a product decision.

## Global Constraints

- Branch from up-to-date `main`; never push to `main`; open a pull request.
- `npm run ci:check` and the full Pest suite (`php vendor/bin/pest --parallel`) must pass. `composer test` is what CI runs; locally use the pest command above plus `php vendor/bin/pint --test` and `php vendor/bin/phpstan analyse`.
- No customer-visible copy changes. No new dependencies.
- Arabic copy, if any is touched, stays simple Gulf-leaning Arabic.
- Commit after every task with the exact message given. Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Do not touch: `resources/js/pages/store/cart.tsx`, `app/Http/Controllers/Store/CartController.php` beyond the one method named in Task 8, `app/Checkout/DiscountEngine.php` beyond Task 9, any `admin-*-pagination.tsx`, any `lib/*-api.ts`.

---

### Task 1: `ServiceType::isManual()` replaces seven literal arrays

**Files:**
- Modify: `app/Enums/ServiceType.php`
- Modify: `app/Actions/Cart/RepriceCart.php:286`
- Modify: `app/Actions/Checkout/PlaceOrder.php:591`
- Modify: `app/Account/Queries/ReadLiveOrder.php:316`
- Modify: `app/Http/Controllers/Account/OrderItemCredentialsController.php:32`
- Modify: `app/Http/Controllers/Account/OrderItemSquadImageController.php:30`
- Modify: `app/Http/Controllers/Store/ManualServiceProductController.php:42`
- Test: `tests/Unit/Enums/ServiceTypeTest.php` (create)

**Interfaces:**
- Produces: `ServiceType::isManual(): bool` (true for `FutChampions` and `Rivals` only) and `ServiceType::manual(): list<ServiceType>` (the same two cases, for `whereIn` queries).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ServiceType;

test('only FUT Champions and Rivals are manual services', function (): void {
    expect(ServiceType::FutChampions->isManual())->toBeTrue()
        ->and(ServiceType::Rivals->isManual())->toBeTrue()
        ->and(ServiceType::Coins->isManual())->toBeFalse()
        ->and(ServiceType::Sbc->isManual())->toBeFalse()
        ->and(ServiceType::Objectives->isManual())->toBeFalse()
        ->and(ServiceType::manual())->toBe([ServiceType::FutChampions, ServiceType::Rivals]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Unit/Enums/ServiceTypeTest.php`
Expected: FAIL with "Call to undefined method App\Enums\ServiceType::isManual()"

- [ ] **Step 3: Add the methods**

```php
enum ServiceType: string
{
    case Coins = 'coins';
    case Sbc = 'sbc';
    case Objectives = 'objectives';
    case Rivals = 'rivals';
    case FutChampions = 'fut_champions';

    /**
     * A manual service is fulfilled by a booster signing into the customer's
     * account, so it carries credentials and a squad image instead of coins.
     */
    public function isManual(): bool
    {
        return in_array($this, self::manual(), true);
    }

    /** @return list<self> */
    public static function manual(): array
    {
        return [self::FutChampions, self::Rivals];
    }
}
```

- [ ] **Step 4: Replace each literal**

In `RepriceCart.php:286` and `PlaceOrder.php:591` the body `return in_array($service, [ServiceType::FutChampions, ServiceType::Rivals], true);` becomes `return $service->isManual();`.

In `ReadLiveOrder.php:316`: `if (! in_array($item->service_type, [ServiceType::FutChampions, ServiceType::Rivals], true))` becomes `if (! $item->service_type->isManual())`.

In `OrderItemCredentialsController.php:32` and `OrderItemSquadImageController.php:30`: `->whereIn('service_type', [ServiceType::FutChampions, ServiceType::Rivals])` becomes `->whereIn('service_type', ServiceType::manual())`.

In `ManualServiceProductController.php:42`: `abort_unless(in_array($service, [ServiceType::FutChampions, ServiceType::Rivals], true), 404)` becomes `abort_unless($service->isManual(), 404)` (keep whatever the second argument already is).

Do not touch `UpdateServicePriceScheduleStatus.php:40`; Task 2 handles it.

- [ ] **Step 5: Run the suites that cover those paths**

Run: `php vendor/bin/pest tests/Unit/Enums tests/Feature/Cart tests/Feature/Checkout tests/Feature/Account tests/Feature/Store --parallel`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Enums/ServiceType.php app/Actions/Cart/RepriceCart.php app/Actions/Checkout/PlaceOrder.php app/Account/Queries/ReadLiveOrder.php app/Http/Controllers/Account app/Http/Controllers/Store/ManualServiceProductController.php tests/Unit/Enums/ServiceTypeTest.php
git commit -m "refactor: ServiceType::isManual() replaces seven literal arrays"
```

---

### Task 2: Coins can no longer pass the schedule-status controller and fail in the Action

**Context:** `ServicePricingStatusController` accepts `coins` but `UpdateServicePriceScheduleStatus` rejects it, so a Coins status toggle passes controller validation and then fails inside the Action with a different error shape. The Action is the business boundary: Coins has a price schedule (bands are editable) but cannot be deactivated. Align the controller to the Action.

**Files:**
- Modify: `app/Enums/ServiceType.php`
- Modify: `app/Http/Controllers/Admin/ServicePricingStatusController.php:30`
- Modify: `app/Http/Controllers/Admin/ServicePricingController.php:30`
- Modify: `app/Admin/Presenters/AdminServicePricingPage.php:28`
- Modify: `app/Admin/Actions/UpdateServicePriceScheduleStatus.php:40`
- Test: `tests/Feature/Admin/ServicePricingStatusRouteTest.php` (create)

**Interfaces:**
- Consumes: `ServiceType::isManual()` from Task 1.
- Produces: `ServiceType::scheduled(): list<ServiceType>` = `[FutChampions, Rivals, Coins]`, the services that own a `ServicePriceSchedule` row.

- [ ] **Step 1: Write the failing test**

Look at `tests/Feature/Admin/UpdateServicePriceScheduleTest.php` for how an Admin actor with confirmed MFA and password confirmation is created (it uses helpers from `tests/Feature/Admin/StaffFixtures.php`); reuse the same fixture calls. The route is `settings.service-pricing.status.store` with `{serviceType}` and a JSON body `{ "action": "deactivate", "expected_active": true }` (confirm the field names from `app/Http/Requests/Admin/UpdateServiceStatusRequest.php` before writing).

```php
<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServicePriceSchedule;

// Reuse the same admin fixture as UpdateServicePriceScheduleTest.php.

test('coins is rejected at the controller with the same message the action would give', function (): void {
    $admin = adminWithConfirmedMfa(); // whatever helper UpdateServicePriceScheduleTest uses

    $this->actingAs($admin)
        ->postJson(route('settings.service-pricing.status.store', ['serviceType' => 'coins']), [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service_type']);

    expect(ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->value('is_active'))->toBeTrue();
});

test('a manual service can be deactivated through the same route', function (): void {
    $admin = adminWithConfirmedMfa();

    $this->actingAs($admin)
        ->postJson(route('settings.service-pricing.status.store', ['serviceType' => 'rivals']), [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertOk();

    expect(ServicePriceSchedule::query()->where('service_type', ServiceType::Rivals)->value('is_active'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/Admin/ServicePricingStatusRouteTest.php`
Expected: the coins case FAILS (the controller currently lets it through and the Action's ValidationException may still yield 422, so if it passes accidentally, assert additionally that the response JSON message equals the controller's own message; the point is that the controller decides, not the Action).

- [ ] **Step 3: Add `scheduled()` to the enum and use it**

```php
    /** @return list<self> Services that own a ServicePriceSchedule row. */
    public static function scheduled(): array
    {
        return [self::FutChampions, self::Rivals, self::Coins];
    }
```

`ServicePricingController.php:30` and `AdminServicePricingPage.php:28`: replace the literal `[ServiceType::FutChampions, ServiceType::Rivals, ServiceType::Coins]` with `ServiceType::scheduled()`.

`ServicePricingStatusController.php:30`: replace `! in_array($type, [ServiceType::FutChampions, ServiceType::Rivals, ServiceType::Coins], true)` with `! $type->isManual()`.

`UpdateServicePriceScheduleStatus.php:40`: replace `! in_array($type, [ServiceType::FutChampions, ServiceType::Rivals], true)` with `! $type->isManual()`.

- [ ] **Step 4: Run the tests**

Run: `php vendor/bin/pest tests/Feature/Admin/ServicePricingStatusRouteTest.php tests/Feature/Admin/UpdateServicePriceScheduleTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/ServiceType.php app/Http/Controllers/Admin/ServicePricingStatusController.php app/Http/Controllers/Admin/ServicePricingController.php app/Admin/Presenters/AdminServicePricingPage.php app/Admin/Actions/UpdateServicePriceScheduleStatus.php tests/Feature/Admin/ServicePricingStatusRouteTest.php
git commit -m "fix(admin): the schedule-status controller rejects coins the way the action does"
```

---

### Task 3: Admin Actions stop re-checking the role that `AdminAccess` decides

**Context:** `app/Admin/Authorization/AdminAccess::allows()` already returns true for Admin and, for Staff, only for the five `STAFF` permissions. Every permission checked by the twelve Actions below is Admin-only, so the extra `if ($actor->role !== UserRole::Admin)` block is dead code that `permissions.md` forbids ("no role comparisons in feature code").

**Files:**
- Modify (remove the role block in each): `app/Admin/Actions/GrantStaffAccess.php:38-40`, `SetAdminCategoryStorefrontVisibility.php:41-43`, `SetAdminProductStorefrontVisibility.php:41-43`, `SetAdminVariantPriceOverride.php:51-53`, `UpdateAdminCustomerContact.php:37-39`, `UpdateAdminCustomerStatus.php:33-35`, `UpdateAdminProduct.php:49-51`, `UpdateServicePriceSchedule.php:43-45`, `UpdateServicePriceScheduleStatus.php:34-36`, `UpdateStaffRole.php:31-33`, `UpdateStaffStatus.php:31-33`
- Modify: `app/Actions/Checkout/RefundPaylinkOrder.php:40-42`
- Test: `tests/Unit/Admin/AdminAccessTest.php` (create if absent; extend if present)

**Interfaces:** none new.

- [ ] **Step 1: Write the failing test that pins the invariant the removal relies on**

```php
<?php

use App\Admin\Authorization\AdminAccess;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\User;

test('staff never hold the permissions the admin actions require', function (AdminPermission $permission): void {
    $staff = User::factory()->make(['role' => UserRole::Staff, 'is_active' => true]);

    expect((new AdminAccess)->allows($staff, $permission))->toBeFalse();
})->with([
    AdminPermission::StaffManage,
    AdminPermission::CatalogManage,
    AdminPermission::CustomersUpdateContact,
    AdminPermission::CustomersUpdateStatus,
    AdminPermission::SettingsManage,
    AdminPermission::OrdersRefund,
]);
```

Confirm each Action's `can(...)` uses one of those six permissions before deleting its role block. If an Action checks a permission not in this list, add that permission to the dataset and confirm it is not in `AdminAccess::STAFF`.

- [ ] **Step 2: Run test to verify it passes already** (this is a pin, not a red test)

Run: `php vendor/bin/pest tests/Unit/Admin/AdminAccessTest.php`
Expected: PASS

- [ ] **Step 3: Delete the twelve role blocks**

In each listed file remove exactly the three lines of the form:

```php
        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may ...');
        }
```

and the blank line above it. If `UserRole` is then unused in the file, remove the `use App\Enums\UserRole;` import (Pint will not do this for you; PHPStan will not fail on it, but ESLint-style hygiene applies).

In `RefundPaylinkOrder.php:40-42` remove the block that throws `CheckoutUnavailable('Only an admin may refund an order.')`. The route `admin.api.orders.refund` in `routes/admin.php` is already behind `can:orders.refund`, which `AdminAccess` grants to Admin only. Check whether `RefundPaylinkOrder` has any caller outside that route (`grep -rn RefundPaylinkOrder app routes`); if a console or webhook caller exists that passes a non-admin actor, keep the block and note it in the commit message instead.

- [ ] **Step 4: Run the admin suites**

Run: `php vendor/bin/pest tests/Feature/Admin tests/Unit/Admin tests/Feature/Checkout --parallel`
Expected: PASS. If a test asserted the exact "Only Admin actors may…" message, change the assertion to expect `AuthorizationException` from the `can()` check with the message that check throws (read it from the Action).

- [ ] **Step 5: Commit**

```bash
git add app/Admin/Actions app/Actions/Checkout/RefundPaylinkOrder.php tests/Unit/Admin/AdminAccessTest.php
git commit -m "refactor(admin): actions trust AdminAccess instead of re-checking the role"
```

---

### Task 4: `GrantAdminRole` records its audit through `RecordStaffAudit`

**Context:** `app/Console/Commands/GrantAdminRole.php:65` writes `StaffAuditLog` directly, bypassing `StaffAuditEvent`, the only place metadata is validated. `RecordStaffAudit::execute()` requires a `User $actor`; the console has none.

**Files:**
- Modify: `app/Admin/Actions/RecordStaffAudit.php`
- Modify: `app/Console/Commands/GrantAdminRole.php:62-76`
- Test: `tests/Feature/Admin/StaffAuditTest.php` (extend) and the existing GrantAdminRole command test (find it with `grep -rln 'GrantAdminRole\|admin:grant' tests`)

**Interfaces:**
- Produces: `RecordStaffAudit::executeFromConsole(?Model $subject, StaffAuditEvent $event): StaffAuditLog`. `execute(User $actor, ...)` stays strict. The console path refuses to run unless `app()->runningInConsole()`, so a web request can never write an actor-less row.
- **Amended 2026-09-10 after review.** The first draft made `execute()` accept a nullable actor; the reviewer (gpt-5.6-luna) flagged that this let any caller write an unauthenticated audit row, and gpt-5.6-sol asked for the split to be recorded here. The split API is the approved contract.

- [ ] **Step 1: Write the failing test**

```php
test('a console audit has no actor but still goes through the event guard', function (): void {
    $target = User::factory()->create();

    $log = app(RecordStaffAudit::class)->execute(
        null,
        $target,
        new StaffAuditEvent('staff.role_changed', ['previous_role' => 'customer', 'new_role' => 'admin', 'source' => 'console'], null),
    );

    expect($log->actor_user_id)->toBeNull()
        ->and($log->action)->toBe('staff.role_changed')
        ->and($log->metadata['source'])->toBe('console');
});

test('a console audit still rejects secret-looking metadata', function (): void {
    expect(fn () => new StaffAuditEvent('staff.role_changed', ['password' => 'x'], null))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/Admin/StaffAuditTest.php`
Expected: FAIL with a TypeError (argument 1 must be of type User, null given)

- [ ] **Step 3: Add the console-only path** (superseded text below kept for history; implement the split API from the Interfaces block)

```php
    public function execute(?User $actor, ?Model $subject, StaffAuditEvent $event): StaffAuditLog
    {
        if ($actor !== null
            && (! $actor->is_active || ! in_array($actor->role, [UserRole::Admin, UserRole::Staff], true))) {
            throw new AuthorizationException('Only active Admin or Staff actors may record staff audits.');
        }

        return StaffAuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $event->action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'metadata' => $event->metadata,
            'ip_address' => $event->ipAddress,
        ]);
    }
```

- [ ] **Step 4: Route the command through it**

In `GrantAdminRole.php` inject `RecordStaffAudit` (the command's `handle()` can take it as a parameter: `public function handle(RecordStaffAudit $recordStaffAudit): int`) and replace the `StaffAuditLog::query()->create([...])` call inside the transaction with:

```php
            $recordStaffAudit->execute(
                null,
                $user,
                new StaffAuditEvent('staff.role_changed', [
                    'previous_role' => $previousRole->value,
                    'new_role' => $targetRole->value,
                    'source' => 'console',
                ], null),
            );
```

Add `use App\Admin\Actions\RecordStaffAudit;` and `use App\Admin\Audit\StaffAuditEvent;`; drop the `StaffAuditLog` import if unused.

- [ ] **Step 5: Run the tests**

Run: `php vendor/bin/pest tests/Feature/Admin/StaffAuditTest.php` plus the GrantAdminRole command test file you found.
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Admin/Actions/RecordStaffAudit.php app/Console/Commands/GrantAdminRole.php tests
git commit -m "fix(admin): console role grants audit through StaffAuditEvent like every other write"
```

---

### Task 5: React stops deciding the Admin role on the categories page

**Files:**
- Modify: `resources/js/pages/admin/categories/index.tsx:35-37`
- Test: `resources/js/__tests__/admin/` (find the categories test with `grep -rln "categories" resources/js/__tests__/admin`)

**Interfaces:** none.

- [ ] **Step 1: Write or adjust the test**

If a test renders `AdminCategoriesIndex` with `permissions: ['catalog.manage']` and `adminIdentity.role: 'staff'`, it should now expect the manage controls to be visible: the server decides who holds `catalog.manage`. Add:

```tsx
it('shows manage controls to anyone the server granted catalog.manage', () => {
    render(<AdminCategoriesIndex {...sampleProps({ permissions: ['catalog.manage'], adminIdentity: { ...sampleProps().adminIdentity, role: 'staff' } })} />);
    expect(screen.getByRole('button', { name: /new category|add category/i })).toBeInTheDocument();
});
```

Match the button name to the actual copy in `props.adminUi.categories` (read the fixture in `resources/js/__tests__/admin/admin-test-fixtures.ts`).

- [ ] **Step 2: Run it to see it fail**

Run: `npx vitest run resources/js/__tests__/admin --reporter=dot -t "catalog.manage"`
Expected: FAIL (control hidden)

- [ ] **Step 3: Remove the role clause**

```tsx
    const canManage = props.permissions.includes('catalog.manage');
```

- [ ] **Step 4: Run vitest and tsc**

Run: `npx vitest run resources/js/__tests__/admin --reporter=dot && npx tsc --noEmit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/admin/categories/index.tsx resources/js/__tests__/admin
git commit -m "fix(admin): categories page trusts the granted permission, not the role"
```

---

### Task 6: `chat.service-prices` resolves its locale like every other chat route

**Context:** `routes/chat.php:14-16` lacks `SetChatLocale`, so `BuildServicePriceLabels` (which reads `app()->getLocale()` for SBC suggestion names at line 91) answers in whatever locale the request happened to carry. `docs/ai-assistant/ARCHITECTURE.md:13` documents the middleware as applied to every chat route.

**Files:**
- Modify: `routes/chat.php:14-16`
- Test: `tests/Feature/Chat/ChatServicePricesRouteTest.php` (extend)

**Interfaces:** none.

- [ ] **Step 1: Write the failing test**

`SetChatLocale::localeFor()` honours a `locale` query input when it is one of `config('store.locales')`. Read `BuildServicePriceLabels::execute()` to find a label that differs between `ar` and `en` (an SBC suggestion name or a service label) and assert on it:

```php
test('the price labels follow the requested chat locale', function (): void {
    $arabic = $this->getJson(route('chat.service-prices', ['locale' => 'ar']))->assertOk()->json('prices');
    $english = $this->getJson(route('chat.service-prices', ['locale' => 'en']))->assertOk()->json('prices');

    expect($arabic)->not->toBe($english);
});
```

If the fixture database has no SBC products in tests, seed one with the factory used in `tests/Feature/Chat/ChatCardsTest.php` or the nearest chat cards test, so the two payloads actually differ.

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/Chat/ChatServicePricesRouteTest.php`
Expected: FAIL (both payloads identical)

- [ ] **Step 3: Add the middleware**

```php
    Route::get('/chat/service-prices', ServicePriceController::class)
        ->middleware([SetChatLocale::class, 'throttle:chat-read'])
        ->name('chat.service-prices');
```

- [ ] **Step 4: Run the chat suites**

Run: `php vendor/bin/pest tests/Feature/Chat --parallel`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add routes/chat.php tests/Feature/Chat/ChatServicePricesRouteTest.php
git commit -m "fix(chat): service prices resolve the chat locale like the other chat routes"
```

---

### Task 7: One `CartOwnerKey::canonical()` for the five fingerprints

**Files:**
- Create: `app/Security/CartOwnerKey.php`
- Modify: `app/Security/CoinsCartFingerprint.php`, `SbcCartFingerprint.php`, `CatalogCartFingerprint.php`, `FutChampionsCartFingerprint.php`, `RivalsCartFingerprint.php` (delete each private `canonicalOwner`, call the shared one)
- Test: `tests/Unit/Security/CartOwnerKeyTest.php` (create)

**Interfaces:**
- Produces: `CartOwnerKey::canonical(string $ownerKey): array{user_id: int}|array{owner_key: string}`, throws `InvalidArgumentException('The cart fingerprint owner is invalid.')`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Security\CartOwnerKey;

test('a user owner key canonicalises to its integer id', function (): void {
    expect(CartOwnerKey::canonical('user:42'))->toBe(['user_id' => 42]);
});

test('a guest owner key is kept verbatim', function (): void {
    $key = 'guest:'.str_repeat('a', 64);

    expect(CartOwnerKey::canonical($key))->toBe(['owner_key' => $key]);
});

test('anything else is rejected', function (string $bad): void {
    expect(fn () => CartOwnerKey::canonical($bad))->toThrow(InvalidArgumentException::class);
})->with(['user:0', 'user:abc', 'guest:short', 'admin:1', '']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Unit/Security/CartOwnerKeyTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Create the helper**

```php
<?php

namespace App\Security;

use InvalidArgumentException;

/**
 * The owner half of every cart fingerprint. A signed-in customer is
 * identified by id so a re-login keeps the same cart; a guest by the
 * 64-hex key the cookie carries.
 */
final class CartOwnerKey
{
    /** @return array{user_id: int}|array{owner_key: string} */
    public static function canonical(string $ownerKey): array
    {
        if (preg_match('/\Auser:([1-9][0-9]*)\z/D', $ownerKey, $matches) === 1) {
            return ['user_id' => (int) $matches[1]];
        }

        if (preg_match('/\Aguest:[0-9a-f]{64}\z/D', $ownerKey) === 1) {
            return ['owner_key' => $ownerKey];
        }

        throw new InvalidArgumentException('The cart fingerprint owner is invalid.');
    }
}
```

- [ ] **Step 4: Replace the five copies**

In each fingerprint class delete the private `canonicalOwner` method and change `...self::canonicalOwner($ownerKey)` to `...CartOwnerKey::canonical($ownerKey)`. Remove the now-unused `InvalidArgumentException` import where it was only used by the deleted method.

- [ ] **Step 5: Run the cart and security suites**

Run: `php vendor/bin/pest tests/Unit/Security tests/Feature/Cart tests/Unit/Cart --parallel && php vendor/bin/phpstan analyse app/Security`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Security tests/Unit/Security
git commit -m "refactor(security): one CartOwnerKey::canonical() behind the five cart fingerprints"
```

---

### Task 8: Promotion badge fallback lives on the Promotion model

**Context:** `CartController::promotionBadge()` (line 274) and `StoreCatalogReader::promotionBadge()` (line 348) are the same fallback chain: badge in the current locale, else the other locale, else (catalog only) the promotion name. Put the chain on the model so both read it.

**Files:**
- Modify: `app/Models/Promotion.php`
- Modify: `app/Http/Controllers/Store/CartController.php:274-284` (delete the method, call the model)
- Modify: `app/Actions/Catalog/StoreCatalogReader.php:348-359` (delete the method, call the model)
- Test: `tests/Unit/Models/PromotionBadgeTest.php` (create)

**Interfaces:**
- Produces: `Promotion::badgeFor(string $locale): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Promotion;

test('the badge prefers the requested locale, then the other, then the name', function (): void {
    $promotion = Promotion::factory()->make(['badge_ar' => 'خصم', 'badge_en' => 'Sale', 'name_ar' => 'اسم', 'name_en' => 'Name']);
    expect($promotion->badgeFor('ar'))->toBe('خصم')->and($promotion->badgeFor('en'))->toBe('Sale');

    $promotion->badge_ar = '  ';
    expect($promotion->badgeFor('ar'))->toBe('Sale');

    $promotion->badge_en = '';
    expect($promotion->badgeFor('ar'))->toBe('اسم')->and($promotion->badgeFor('en'))->toBe('Name');
});
```

Check the real column names on `Promotion` (`badge_ar`, `badge_en`, `name_ar`, `name_en`) in `database/migrations` before running; adjust the test if the name columns differ.

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Unit/Models/PromotionBadgeTest.php`
Expected: FAIL (undefined method badgeFor)

- [ ] **Step 3: Add the model method**

```php
    /** The badge shown on a discounted line, falling back across locales and finally to the name. */
    public function badgeFor(string $locale): string
    {
        $other = $locale === 'ar' ? 'en' : 'ar';

        foreach (["badge_{$locale}", "badge_{$other}", "name_{$locale}", "name_{$other}"] as $field) {
            $value = trim((string) $this->{$field});

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
```

- [ ] **Step 4: Replace both call sites**

`CartController.php`: delete `promotionBadge()`; where it was called, use `$promotion->promotion->badgeFor(app()->getLocale())`.

`StoreCatalogReader.php`: delete `promotionBadge()`; where it was called, use `$promotion->promotion->badgeFor($locale)`.

(`CartController` previously fell back to an empty string rather than the name; the name fallback is the catalog behaviour and is the better one for the cart line too. Note this in the commit body.)

- [ ] **Step 5: Run the affected suites**

Run: `php vendor/bin/pest tests/Unit/Models tests/Feature/Cart tests/Feature/Catalog tests/Feature/Store --parallel`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/Promotion.php app/Http/Controllers/Store/CartController.php app/Actions/Catalog/StoreCatalogReader.php tests/Unit/Models/PromotionBadgeTest.php
git commit -m "refactor(store): promotion badge fallback lives on the model, not in two callers"
```

---

### Task 9: Delete the two uncalled `DiscountEngine` methods

**Files:**
- Modify: `app/Checkout/DiscountEngine.php:49-59` (`resolvePromotion`) and `:100-110` (`evaluateSnapshotsCoupon`)

**Interfaces:** none.

- [ ] **Step 1: Prove they are dead**

Run: `grep -rn "resolvePromotion\|evaluateSnapshotsCoupon" app resources routes tests --include=*.php --include=*.tsx | grep -v "DiscountEngine.php"`
Expected: no output. If any line appears, stop and leave that method in place.

- [ ] **Step 2: Delete both methods** including their docblocks. If `snapshotsToLines()` then has no caller inside the class, delete it too (repeat the grep for it). If `promotionPricing` is then unused, keep it (it is used by other methods; verify with grep before touching the constructor).

- [ ] **Step 3: Run the checkout suite and PHPStan**

Run: `php vendor/bin/pest tests/Feature/Checkout tests/Unit/Pricing --parallel && php vendor/bin/phpstan analyse app/Checkout`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Checkout/DiscountEngine.php
git commit -m "chore(checkout): drop two DiscountEngine methods nothing calls"
```

---

### Task 10: Dead TypeScript exports, files, and one unused dependency

**Files:**
- Delete: `resources/js/components/app-logo-icon.tsx`, `resources/js/types/index.ts`, `resources/views/vendor/mail/html/themes/default.css`
- Modify (drop the `export` keyword when the symbol is used inside its own file; delete the symbol when it is not used anywhere): `resources/js/components/admin/admin-unread-badge.tsx:3` (`playAdminChime`), `resources/js/components/admin/products/admin-variant-price-dialog.tsx:74` (`getVariantCompletionPricing`), `resources/js/components/chat/chat-widget.tsx:43,53` (`SHEET_HEIGHT_RATIO`, `mobileSheetGeometry`), `resources/js/lib/analytics.ts:16,59,73,121,359` (`CONSENT_VERSION`, `analyticsVendors`, `analyticsEnabled`, `TRACKING_QUERY`, `trackPageView`), `resources/js/lib/cart-wallet-api.ts:1` (`CartWalletError`), `resources/js/lib/phone-country-codes.ts:18` (`phoneCountryCodes`), `resources/js/lib/utils.ts:10` (`toUrl`), `resources/js/components/admin/admin-order-status.ts:52` (`getStatusCssColor`)
- Modify: `resources/js/components/admin/customers/admin-customers-toolbar.tsx:57` — delete its `dateRangePatch` and import the one from `resources/js/components/admin/orders/admin-orders-toolbar.tsx:62` only if the two bodies are identical (diff them first); if they differ, leave both and say so in the commit body.
- Modify: `package.json` — remove `concurrently` from dependencies; run `npm install` so `package-lock.json` follows.

**Interfaces:** none.

- [ ] **Step 1: Confirm each deletion with grep before making it**

For every symbol: `grep -rn "<symbol>" resources/js` must show only its own definition (and, for the "drop export" cases, uses inside the same file). For each file: `grep -rn "<basename without extension>" resources/js resources/views config` must be empty. `default.css`: confirm `config/mail.php` `'theme' => 'arab-ut'` and that nothing references `themes/default`.

- [ ] **Step 2: Apply the deletions**

- [ ] **Step 3: Run the full JS gate**

Run: `npm run ci:check`
Expected: PASS (vitest, eslint, prettier, tsc, tsc playwright, build). ESLint's unused-vars rule will flag anything you left half-deleted.

- [ ] **Step 4: Commit**

```bash
git add -A resources/js resources/views package.json package-lock.json
git commit -m "chore(js): remove dead exports, two dead files, the unused mail theme and concurrently"
```

---

### Task 11: Docs say what the code does

**Files (each line quoted is the current text; replace with the text that follows it):**

- `docs/ai-assistant/SECURITY.md:21` — "All admin conversation routes, conversation details, and `GET /admin/support/unread-count` are strictly protected by `can:chat.view`" → "Reading the inbox (`admin.conversations`, `admin.conversations.show`, `GET /admin/support/unread-count`) requires `can:chat.view`. Replying, adding an internal note, taking over, and resolving a ticket (`POST …/reply`, `…/note`, `…/take-over`, `PATCH /admin/tickets/{publicId}`) require `can:chat.reply`."
- `docs/ai-assistant/ADMIN-INBOX.md:12` — same replacement as above, shortened to one bullet.
- `docs/ai-assistant/ADMIN-INBOX.md:20` and `docs/ai-assistant/ARCHITECTURE.md:81` — "purged after 48 hours of inactivity" → "purged after 48 hours of inactivity unless the conversation has an open ticket, a handoff in `requested`/`active`, or a waiting/running agent turn (`MaintainChatConversations`)."
- `docs/ai-assistant/ARCHITECTURE.md:13` — keep the sentence; it becomes true after Task 6.
- `docs/ai-assistant/ARCHITECTURE.md:30-34` — add four rows to the admin route table for `POST /admin/conversations/{publicId}/reply`, `…/note`, `…/take-over`, and `PATCH /admin/tickets/{publicId}`, each with permission `chat.reply` and throttle `admin` (read `routes/admin.php:261-291` for the exact route names).
- `docs/ai-assistant/ARCHITECTURE.md:73-77` and `docs/ai-assistant/TOOLS.md:13` — the derived surfaces are four, not three: service cards, the add-to-cart offer, choice chips (`BuildAssistantChoices`, attached at `FinalizeAgentTurn.php:82` as `choices`), and the SBC shelf (`shelf.v1`, attached at `FinalizeAgentTurn.php:98`). Add one sentence per new surface saying it is server-derived and never a tool.
- `docs/ai-assistant/TOOLS.md:9` and `docs/ai-assistant/RAG.md:11` — "`support-v3`" → "the configured prompt (`support-v9` today; `config/ai-assistant.php` is the source of truth)".
- `docs/ai-assistant/RAG.md:7` — "45 bilingual" → "47 bilingual".
- `docs/ai-assistant/OPERATIONS.md:9-10` — append: "Conversations with an open ticket, a handoff in `requested`/`active`, or a waiting/running agent turn are never auto-closed."
- `docs/ai-assistant/OPERATIONS.md:24` — "1 email per hour per ticket" → "1 email per hour per conversation (a per-ticket throttle would let a second email fire minutes later on a new ticket; see `SendStaffReply.php`)".
- `docs/product/discovery-record.md:122` — "Silver by default, Gold from SAR 2,000, and Platinum from SAR 5,000" → "Silver from SAR 500, Gold from SAR 2,000, and Platinum from SAR 10,000 (seeded by `loyalty:seed-tiers`; editable in the admin)". Confirm the command name in `app/Console/Commands/SeedLoyaltyTiers.php`.
- `docs/product/v1-blueprint.md:178` — "v1 has no points or automatic cashback engine." → "Each tier carries a cashback basis-points rate; completed orders accrue wallet cashback automatically (see `docs/decisions/2026-09-10-automatic-cashback-decision.md`). There is no points engine."
- `docs/product/v1-blueprint.md:189` — delete the bullet "Loyalty points or automatic cashback." and replace with "Loyalty points."
- `docs/product/v1-blueprint.md:160,187-188` and `docs/product/discovery-record.md:242` — the FAQ/policy editor and admin review moderation shipped under `docs/decisions/2026-09-02-faq-editor-design.md` and `2026-09-02-customer-order-reviews-design.md`; rewrite each exclusion to say they are in scope and point at those decisions. Leave "visual page builder" and "menu management" excluded.
- `docs/api/paylink-checkout-v1.md:30` — "admin or staff" → "admin (`can:orders.refund`)".
- `docs/README.md:9` — remove "complete admin and support operations" and "customer notifications" from the planned list (both shipped). `docs/README.md:36` — "Implemented foundation; future phases planned" → "Phases 1–3 and human handoff implemented; see STATUS.md".
- `.agents/skills/arab-ut-admin/references/permissions.md:5-25` — add rows: `customers.update_contact` yes/no, `loyalty.view` yes/no, `loyalty.manage` yes/no, `chat.view` yes/no, `chat.reply` yes/no, `marketing.view` yes/no, `marketing.manage` yes/no. Confirm each Staff column against `AdminAccess::STAFF` (none of the seven is in it, so all are Admin-only).
- Create: `docs/decisions/2026-09-10-automatic-cashback-decision.md`:

```markdown
# Automatic wallet cashback stays

**Date:** 2026-09-10
**Owner decision:** Mohamed, during the project cleanup review.

## Context

`docs/product/v1-blueprint.md` and the 2026-08-15 my-account decision excluded an
automatic cashback engine from v1. The code ships one anyway:
`App\Loyalty\Actions\AccrueOrderCashback` runs from `TransitionAdminOrder` when an
order completes and credits the customer's wallet at the tier's
`cashback_basis_points` (seeded by `SeedLoyaltyTiers`).

## Decision

Keep it. The behaviour is intended and live. The product docs are corrected to
describe it rather than the code being removed.

## Consequences

- Tier thresholds and cashback rates are admin-editable; the seeded defaults are
  Silver SAR 500, Gold SAR 2,000, Platinum SAR 10,000.
- Wallet credit is not revenue in analytics (see 2026-09-02 analytics decision).
```

**Interfaces:** none.

- [ ] **Step 1: Apply every edit above.** Quote-check each "current text" string with grep before replacing; if a line moved, find it by content.

- [ ] **Step 2: Run Prettier on the touched markdown**

Run: `npx prettier --write docs .agents/skills/arab-ut-admin/references/permissions.md && npx prettier --check docs`
Expected: clean

- [ ] **Step 3: Commit**

```bash
git add docs .agents/skills/arab-ut-admin/references/permissions.md
git commit -m "docs: sync the assistant, product and permission docs with the code that ships"
```

---

### Task 12: Full gates

- [ ] **Step 1:** `npm run ci:check` → PASS
- [ ] **Step 2:** `php vendor/bin/pint --test && php vendor/bin/phpstan analyse && php vendor/bin/pest --parallel` → PASS
- [ ] **Step 3:** `git log --oneline main..HEAD` shows eleven commits, one per task (Task 12 adds none).

---

## Source findings (the spec this plan implements)

From the 2026-09-10 whole-project review (Standards axis, Spec axis, Hygiene pass):

- HARD: twelve Admin Actions plus `RefundPaylinkOrder` re-check `role === Admin` next to a correct `can()`; `permissions.md` forbids role comparisons in feature code. → Task 3.
- HARD: `categories/index.tsx:36` gates on `adminIdentity.role === 'admin'`; the admin skill says React never decides permission. → Task 5.
- HARD (live drift): `ServicePricingStatusController:30` accepts Coins, `UpdateServicePriceScheduleStatus:40` rejects it. → Task 2.
- HARD: `GrantAdminRole.php:65` writes `StaffAuditLog` directly, bypassing `StaffAuditEvent`. → Task 4.
- HARD: `permissions.md` matrix lacks seven permissions that exist in `AdminPermission`. → Task 11.
- Spec (c): `chat.service-prices` lacks `SetChatLocale` while `ARCHITECTURE.md:13` says every chat route has it. → Task 6.
- Spec (c)/(d): SECURITY.md, ADMIN-INBOX.md, ARCHITECTURE.md, OPERATIONS.md, RAG.md, TOOLS.md, docs/README.md, v1-blueprint.md, discovery-record.md, paylink-checkout-v1.md drift as itemised in Task 11.
- Spec (b): automatic cashback ships without a decision. Owner: keep and document. → Task 11 decision file.
- JUDGEMENT: manual-service literal array ×7, `canonicalOwner` ×5, `promotionBadge` ×2, two uncalled `DiscountEngine` methods. → Tasks 1, 7, 8, 9.
- Hygiene: dead TS exports/files, unused mail theme, `concurrently`. → Task 10.

Deferred to a later round (need design first): `cart.tsx` split and `useCartCredentialPanel`, `CartController` presenter extraction, `DiscountEngine` scope enum, shared `lib/http.ts`, one `AdminTablePagination`, `SelectServiceOptions` detector map, reader/presenter placement rule, `types/admin.ts` split, `StaffAuditEvent` allowlist, old prompt files v1–v7, the `perf/storefront-critical-path` branch.
