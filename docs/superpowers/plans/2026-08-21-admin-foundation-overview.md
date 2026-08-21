# Admin Foundation and Overview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a secure, bilingual, MFA-enforced Arab UT Admin-first
near-MVP entry point with centralized permissions/auditing and a real
operational overview.

**Architecture:** Extend the existing Laravel/Inertia monolith. Fortify owns
TOTP enrollment and challenge, native Laravel Gates consume the fixed
`UserRole` permission matrix, and dedicated Admin middleware protects isolated
routes. Backend Query/Presenter classes project bounded operational metrics to
an Arabic-first React shell that reuses the verified Arab UT design system.

**Tech Stack:** PHP 8.3, Laravel 13, Fortify, MariaDB/SQLite, Inertia 3, React
19, TypeScript, Vite 8, Tailwind 4, Radix primitives, Vitest, Pest, Playwright.

**Spec:** `docs/superpowers/specs/2026-08-21-admin-dashboard-design.md`

## Global Constraints

- Every Admin and Staff account is active, password-backed, and confirmed for
  Fortify TOTP before ordinary Admin access.
- Admin/Staff authenticate interactively only through email/password followed
  by TOTP; Google/WhatsApp attempts fail with generic customer-safe copy.
- Sensitive Admin actions require recent password confirmation; this plan
  establishes the reusable boundary but adds no financial/credential action.
- Keep all 19 enum abilities. Admin receives all 19; Staff receives exactly
  `dashboard.view`, `orders.view`, `orders.update`, `orders.cancel`, and
  `order_credentials.view`, as recorded by the superseding 2026-08-21 owner
  decision in the spec and Admin skill.
- Customer and ServiceAccount receive no Admin permission.
- Laravel is the authorization/business boundary; React permissions are display
  projections only.
- Reuse the existing `staff_audit_logs` table; audit metadata is allowlisted and
  secret-free.
- No credential, TOTP seed/recovery code, token, password, or raw provider
  metadata enters Inertia shared props, HTML, URLs, audit metadata, or logs.
- Admin routes use authentication, active-user checks, role admission,
  confirmed MFA, `NoStore`, and `inertia.encrypt` as applicable.
- Arabic RTL and English LTR are equal targets at 320, 390, 768, and 1440 CSS
  pixels with keyboard focus, 44px targets, 200% zoom, reduced motion, no
  horizontal document overflow, and no browser console errors.
- Preserve local Thmanyah fonts and Arab UT near-black/warm-gold tokens. Reject
  liquid glass, neon gradients, generic SaaS cards, and copied shadcn-admin
  architecture.
- Strict TDD: every behavior change starts with a focused failing test that is
  observed before production code.
- Do not add TanStack Table, Spatie Permission, Activitylog, Query Builder,
  Horizon, Pulse, or a separate Admin API/application in this plan.

---

### Task 1: Fortify TOTP Storage, Challenge, and Privileged Login Boundary

**Files:**

- Create: `database/migrations/2026_08_21_000001_add_two_factor_authentication_to_users.php`
- Create: `resources/js/pages/auth/two-factor-challenge.tsx`
- Create: `tests/Feature/Admin/AdminMfaAuthenticationTest.php`
- Modify: `app/Models/User.php`
- Modify: `config/fortify.php`
- Modify: `app/Providers/FortifyServiceProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Http/Controllers/Auth/GoogleAuthenticationController.php`
- Modify: `app/Http/Controllers/Auth/WhatsAppLoginController.php`
- Modify: `lang/ar/auth_ui.php`
- Modify: `lang/en/auth_ui.php`
- Modify: `resources/js/types/auth.ts`
- Modify: `resources/js/__tests__/auth/localized-auth.test.tsx`

**Interfaces:**

- Consumes: existing Fortify email/password authentication, Google/WhatsApp
  customer login, `UserRole`, auth layouts, and `auth_ui` translation props.
- Produces: Fortify's named routes `two-factor.login`,
  `two-factor.login.store`, `two-factor.enable`, `two-factor.confirm`,
  `two-factor.qr-code`, `two-factor.recovery-codes`, and
  `two-factor.regenerate-recovery-codes`; `User` uses
  `Laravel\Fortify\TwoFactorAuthenticatable`; confirmed privileged accounts are
  challenged after password validation.

- [ ] **Step 1: Write the failing migration and model tests**

Add tests proving a migrated user has nullable two-factor columns, the model
hides them, and the Fortify trait reports unconfirmed/enabled states correctly:

```php
test('users support encrypted Fortify TOTP state without serializing secrets', function () {
    $user = User::factory()->create();

    expect(Schema::hasColumns('users', [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ]))->toBeTrue()
        ->and(class_uses_recursive($user))->toContain(TwoFactorAuthenticatable::class)
        ->and(array_keys($user->toArray()))->not->toContain(
            'two_factor_secret',
            'two_factor_recovery_codes',
        );
});
```

- [ ] **Step 2: Run the model test and verify RED**

Run:

```powershell
php artisan test tests/Feature/Admin/AdminMfaAuthenticationTest.php --filter="support encrypted Fortify"
```

Expected: FAIL because the columns and trait do not exist.

- [ ] **Step 3: Implement the forward migration and User trait**

The migration adds nullable `text` secret/recovery columns and nullable
timestamp confirmation after `password`. Its `down()` drops only those three
columns. Add `TwoFactorAuthenticatable` to the `User` traits; keep the fields in
`#[Hidden]`.

```php
Schema::table('users', function (Blueprint $table): void {
    $table->text('two_factor_secret')->nullable()->after('password');
    $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
    $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
});
```

- [ ] **Step 4: Verify migration lifecycle and GREEN**

Run the focused test, then:

```powershell
php artisan migrate:fresh --force
php artisan migrate:rollback --force
php artisan migrate --force
```

Expected: PASS; fresh → rollback → remigrate succeeds.

- [ ] **Step 5: Write failing Fortify challenge tests**

Cover:

```php
test('confirmed staff password login requires a TOTP challenge', function () {
    ['user' => $staff] = confirmedTotpUser(UserRole::Staff);

    $this->post('/login', [
        'email' => $staff->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($staff->id);
});

test('a valid TOTP or unused recovery code completes the challenged session', function () {
    ['user' => $staff, 'secret' => $secret] = confirmedTotpUser(UserRole::Staff);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->withSession(['login.id' => $staff->id])
        ->post(route('two-factor.login.store'), ['code' => $code])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($staff);
    expect(session()->has('login.id'))->toBeFalse();
});

test('the challenge page follows the privileged users preferred locale', function (
    string $locale,
    string $direction,
) {
    ['user' => $staff] = confirmedTotpUser(UserRole::Staff);
    $staff->update(['preferred_locale' => $locale]);

    $this->withSession(['login.id' => $staff->id])
        ->get(route('two-factor.login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/two-factor-challenge')
            ->where('locale', $locale)
            ->where('direction', $direction));
})->with([
    'Arabic' => ['ar', 'rtl'],
    'English' => ['en', 'ltr'],
]);
```

Define this real helper in the test file:

```php
/** @return array{user: User, secret: string} */
function confirmedTotpUser(UserRole $role): array
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
            'recovery-code-one',
        ], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return compact('user', 'secret');
}
```

- [ ] **Step 6: Run challenge tests and verify RED**

Expected: missing Fortify feature/routes/view and no TOTP redirect.

- [ ] **Step 7: Enable and configure Fortify TOTP**

Add:

```php
Features::twoFactorAuthentication([
    'confirm' => true,
    'confirmPassword' => true,
]),
```

Define a dedicated `two-factor` rate limiter keyed by challenged user/session
and IP, configure `fortify.limiters.two-factor`, and register
`Fortify::twoFactorChallengeView(...)`. Resolve the challenged user ID from the
session only to choose `preferred_locale`; pass no user model or sensitive
fields to Inertia.

- [ ] **Step 8: Implement challenge translations, types, and React page**

Add `two_factor_challenge` to `AuthPage` and `AuthUiTranslations` with exact
Arabic/English fields for TOTP code, recovery code, switch action, validation,
and submit. Submit to the Wayfinder `two-factor.login.store` form route.

The page renders exactly one active input mode, preserves a visible label,
uses `inputMode="numeric"` and `autoComplete="one-time-code"` for TOTP, and
never stores either value.

- [ ] **Step 9: Write failing privileged Google/WhatsApp bypass tests**

Add cases to the existing auth tests proving an active Admin/Staff is not logged
in through either bypassing controller, receives the same generic failure copy
as another authentication failure, and does not leak the privileged role.
Retain customer Google/WhatsApp success cases.

- [ ] **Step 10: Run bypass tests and verify RED**

Expected: current controllers authenticate privileged accounts directly.

- [ ] **Step 11: Fail closed in Google/WhatsApp controllers**

Before linking/logging in an existing Google user and before `Auth::login()` in
WhatsApp verification, permit only `UserRole::Customer`. Throw/return the
existing generic auth error; do not emit a role-specific error. New Google users
remain Customer through the current default.

- [ ] **Step 12: Run Task 1 focused gates and commit**

Run:

```powershell
php artisan test tests/Feature/Admin/AdminMfaAuthenticationTest.php tests/Feature/Auth/GoogleAuthenticationTest.php tests/Feature/Auth/WhatsAppLoginTest.php
npm run test -- resources/js/__tests__/auth/localized-auth.test.tsx
vendor\bin\pint --test app config database tests
php vendor\bin\phpstan analyse --memory-limit=1G
```

Commit:

```powershell
git add app config database lang resources/js tests
git commit -m "feat(admin): enforce privileged TOTP authentication"
```

---

### Task 2: Central Permission Matrix and Secret-Safe Audit Foundation

> **Superseding scope note (2026-08-21):** Task 2 was initially implemented and
> tested with a broader 13-permission Staff allowlist. Mohamed subsequently
> narrowed the near-MVP to an Admin-first dashboard. Task 2A replaces only that
> Staff allowlist with the exact five permissions below while retaining the 19
> enum abilities, full Admin access, and the original Task 2 audit foundation.
> Historical Task 2 test evidence remains historical; Task 2A records new
> RED/GREEN and mutation evidence for the superseding decision.

**Files:**

- Create: `app/Enums/AdminPermission.php`
- Create: `app/Admin/Authorization/AdminAccess.php`
- Create: `app/Admin/Audit/StaffAuditEvent.php`
- Create: `app/Admin/Actions/RecordStaffAudit.php`
- Create: `app/Providers/AdminServiceProvider.php`
- Create: `tests/Feature/Admin/AdminPermissionTest.php`
- Create: `tests/Feature/Admin/StaffAuditTest.php`
- Modify: `bootstrap/providers.php`

**Interfaces:**

- Consumes: `UserRole`, `StaffAuditLog`, Laravel Gate, and the exact spec
  permission matrix.
- Produces:
    - `AdminPermission` backed string enum with all 19 values;
    - `AdminAccess::allows(User $user, AdminPermission $permission): bool`;
    - Gate abilities named by each enum value;
    - `StaffAuditEvent::__construct(string $action, array $metadata, ?string $ipAddress)`;
    - `RecordStaffAudit::execute(User $actor, ?Model $subject, StaffAuditEvent $event): StaffAuditLog`.

- [ ] **Step 1: Write the failing permission matrix dataset**

Use a dataset containing every exact permission/role boolean from the current
spec. Assert the enum contains exactly the approved 19 string values.
Assert inactive privileged users, Customer, and ServiceAccount are always
denied even when a matrix row would otherwise allow.

```php
expect(app(AdminAccess::class)->allows($user, AdminPermission::from($ability)))
    ->toBe($expected)
    ->and(Gate::forUser($user)->allows($ability))->toBe($expected);
```

- [ ] **Step 2: Run permission tests and verify RED**

Expected: enum/access/provider classes do not exist.

- [ ] **Step 3: Implement enum, matrix, and Gate provider**

Keep one matrix inside `AdminAccess`:

```php
private const STAFF = [
    AdminPermission::DashboardView,
    AdminPermission::OrdersView,
    AdminPermission::OrdersUpdate,
    AdminPermission::OrdersCancel,
    AdminPermission::OrderCredentialsView,
];
```

Admin receives all enum cases. Register one Gate per case in
`AdminServiceProvider`; add the provider to `bootstrap/providers.php`.

- [ ] **Step 4: Verify permission GREEN and mutation checks**

Run the full permission dataset. Mutation-prove one removed Staff permission
and one retained Staff permission, restore the exact matrix, and rerun GREEN.

- [ ] **Step 5: Write failing audit action tests**

Cover actor, subject morph, stable action, IP, metadata round-trip, inactive or
nonprivileged actor rejection, invalid action names, and recursive forbidden
metadata keys (`password`, `credential`, `secret`, `token`, `recovery_code`,
`encrypted_payload`, `provider_metadata`).

- [ ] **Step 6: Run audit tests and verify RED**

Expected: audit value/action classes do not exist.

- [ ] **Step 7: Implement the audit value and action**

`StaffAuditEvent` validates action with
`\A[a-z][a-z0-9]*(?:\.[a-z0-9_]+)+\z`, IP length ≤45, and recursively rejects
forbidden metadata keys before `RecordStaffAudit` creates one row. The Action
requires an active Admin/Staff actor and never catches storage errors.

- [ ] **Step 8: Run Task 2 focused gates and commit**

```powershell
php artisan test tests/Feature/Admin/AdminPermissionTest.php tests/Feature/Admin/StaffAuditTest.php
vendor\bin\pint --test app bootstrap tests
php vendor\bin\phpstan analyse --memory-limit=1G
git add app bootstrap tests
git commit -m "feat(admin): centralize permissions and audit events"
```

---

### Task 3: Admin Admission Middleware, Routes, and MFA Enrollment

**Files:**

- Create: `app/Http/Middleware/EnsureAdminAccess.php`
- Create: `app/Http/Middleware/EnsureAdminPassword.php`
- Create: `app/Http/Middleware/EnsureAdminMfa.php`
- Create: `app/Http/Middleware/PrivateNoStore.php`
- Create: `app/Http/Controllers/Admin/Security/AdminMfaController.php`
- Create: `app/Admin/Presenters/AdminMfaState.php`
- Create: `routes/admin.php`
- Create: `resources/js/lib/admin-mfa-api.ts`
- Create: `resources/js/pages/admin/security/mfa.tsx`
- Create: `resources/js/types/admin.ts`
- Create: `lang/ar/admin.php`
- Create: `lang/en/admin.php`
- Create: `tests/Feature/Admin/AdminAccessTest.php`
- Create: `tests/Feature/Admin/AdminMfaEnrollmentTest.php`
- Create: `resources/js/__tests__/admin/admin-mfa-api.test.ts`
- Create: `resources/js/__tests__/admin/admin-mfa-page.test.tsx`
- Modify: `routes/web.php`
- Modify: `app/Providers/FortifyServiceProvider.php`

**Interfaces:**

- Consumes: Task 1 Fortify routes/trait, Task 2 Gates, existing
  `EnsureActiveUser`, account password setup route, existing private-cache
  response conventions, Inertia history encryption, and CSRF meta token.
- Produces:
    - `EnsureAdminAccess`: admits active Admin/Staff only;
    - `EnsureAdminPassword`: redirects passwordless actors to localized account
      security setup;
    - `EnsureAdminMfa`: redirects unconfirmed actors to localized Admin MFA page;
    - `PrivateNoStore`: overwrites Admin response cache control with exact
      `no-store, private`;
    - `AdminMfaState::for(User $user, string $locale): array` with booleans and
      same-origin endpoint URLs only;
    - `/admin/security/mfa` and `/en/admin/security/mfa` named
      `admin.security.mfa` / `localized.admin.security.mfa`.

- [ ] **Step 1: Write failing route admission tests**

Cover guest redirect, Customer/ServiceAccount 403, inactive Staff 403,
passwordless Staff redirect to localized account security, unconfirmed Staff
redirect to MFA enrollment, and confirmed Admin/Staff access. Assert exact
`Cache-Control: no-store, private` for rendered Admin responses.

- [ ] **Step 2: Run admission tests and verify RED**

Expected: routes/middleware do not exist.

- [ ] **Step 3: Implement middleware and route groups**

Require `routes/admin.php` from `routes/web.php`. Define a base middleware array
with `auth`, `EnsureActiveUser`, `EnsureAdminAccess`, `PrivateNoStore`, and
`inertia.encrypt`. Define MFA enrollment routes with `EnsureAdminPassword` and
`password.confirm`; ordinary Admin routes add `EnsureAdminMfa`.

`PrivateNoStore::handle()` calls the downstream response and then sets
`Cache-Control` to exact `no-store, private`. Do not change the existing
`NoStore` middleware or its public/cart contracts.

In `FortifyServiceProvider::boot()`, after Fortify is configured, append
`PrivateNoStore` and the dedicated two-factor management throttle to the named
enable/confirm/disable/QR/secret/recovery routes through Laravel's route
collection. Fail startup in local/testing if an expected enabled route name is
missing; do not silently leave a sensitive route unprotected.

- [ ] **Step 4: Write failing MFA state/API tests**

Prove the Inertia page contains booleans and relative URLs but no secret,
recovery code, user model, password, or email. Prove Fortify QR/recovery JSON is
available only after password confirmation and uses private no-store headers.

- [ ] **Step 5: Run MFA state tests and verify RED**

Expected: controller/presenter/API client are absent.

- [ ] **Step 6: Implement presenter/controller and translations**

`AdminMfaState` returns:

```php
[
    'passwordConfigured' => is_string($user->password),
    'enabled' => is_string($user->two_factor_secret),
    'confirmed' => $user->two_factor_confirmed_at !== null,
    'routes' => [
        'enable' => route('two-factor.enable', absolute: false),
        'confirm' => route('two-factor.confirm', absolute: false),
        'qrCode' => route('two-factor.qr-code', absolute: false),
        'recoveryCodes' => route('two-factor.recovery-codes', absolute: false),
        'regenerateRecoveryCodes' => route('two-factor.regenerate-recovery-codes', absolute: false),
        'disable' => route('two-factor.disable', absolute: false),
    ],
]
```

Controller renders only `admin/security/mfa`, `adminUi`, and `mfa` plus ordinary
safe shared props.

- [ ] **Step 7: Implement and test the same-origin MFA API client**

The client validates same-origin relative URLs and exact JSON shapes, supplies
CSRF for mutations, uses `cache: 'no-store'`/`credentials: 'same-origin'`, and
defines typed failures. It never persists QR/recovery/code data.

- [ ] **Step 8: Load required UI skills and implement the enrollment page**

Before frontend edits, read `frontend-design`, `ui-ux-pro-max`, `clarify`,
`adapt`, and `polish`; inspect `.impeccable.md`, the live WordPress identity,
current auth layout, and current UI primitives.

Page states: start, enabling, QR/code confirmation, confirmed recovery-code
display, regenerate confirmation, failure/retry, and password-not-configured
redirect copy. Recovery codes appear only after explicit request and clear on
route change/unmount. All buttons have explicit Arabic/English labels and 44px
targets.

- [ ] **Step 9: Run Task 3 focused gates and commit**

```powershell
php artisan test tests/Feature/Admin/AdminAccessTest.php tests/Feature/Admin/AdminMfaEnrollmentTest.php
npm run test -- resources/js/__tests__/admin/admin-mfa-api.test.ts resources/js/__tests__/admin/admin-mfa-page.test.tsx
npm run types:check
npm run lint:check
npm run format:check
vendor\bin\pint --test app routes tests
php vendor\bin\phpstan analyse --memory-limit=1G
git add app lang resources/js routes tests
git commit -m "feat(admin): require MFA enrollment for dashboard access"
```

---

### Task 4: Operational Overview Query and Safe Admin Shell Projection

**Files:**

- Create: `database/migrations/2026_08_21_000002_add_admin_overview_indexes.php`
- Create: `app/Admin/Queries/ReadAdminOverview.php`
- Create: `app/Admin/Presenters/AdminShell.php`
- Create: `app/Admin/Presenters/AdminOverviewPage.php`
- Create: `app/Http/Controllers/Admin/OverviewController.php`
- Create: `tests/Feature/Admin/AdminOverviewTest.php`
- Create: `tests/Feature/Admin/AdminPropPrivacyTest.php`
- Modify: `routes/admin.php`
- Modify: `lang/ar/admin.php`
- Modify: `lang/en/admin.php`

**Interfaces:**

- Consumes: Task 2 permissions/Gates, Task 3 ordinary confirmed-MFA route group,
  `OrderStatus`, `PaymentStatus`, Orders/Payments/Refunds/StaffAuditLog, and
  existing minor-unit money conventions.
- Produces:
    - `ReadAdminOverview::for(User $actor, int $days): array`;
    - `AdminShell::for(User $actor, string $locale): array`;
    - `AdminOverviewPage::for(User $actor, string $locale, int $days): array`;
    - `/admin?range=7|30` and `/en/admin?range=7|30` named `admin.overview` and
      `localized.admin.overview`.

- [ ] **Step 1: Write failing index lifecycle tests**

Add assertions for planned composite indexes on orders status/activity,
payments status/paid timestamp, refunds status/created timestamp, and audit
created timestamp. Include SQLite fresh/rollback/remigrate coverage and the
existing MariaDB schema job list.

- [ ] **Step 2: Run index test and verify RED**

Expected: named indexes are absent.

- [ ] **Step 3: Implement the forward/rollback index migration**

Create and reverse these exact indexes:

```php
$table->index(['status', 'placed_at', 'id'], 'idx_orders_admin_status_activity');
$table->index(['status', 'paid_at', 'id'], 'idx_payments_admin_status_paid');
$table->index(['status', 'created_at', 'id'], 'idx_refunds_admin_status_created');
$table->index(['created_at', 'id'], 'idx_staff_audits_admin_created');
```

Do not remove the existing single-column indexes.

- [ ] **Step 4: Write failing overview behavior tests**

Seed mixed statuses/dates and assert literal results for:

```php
[
    'rangeDays' => 7,
    'orders' => [
        'received' => 1,
        'inProgress' => 1,
        'waitingForCustomer' => 1,
    ],
    'payments' => ['pending' => 1, 'failed' => 1],
    'refunds' => ['failed' => 1],
    'capturedRevenue' => ['amountMinor' => '1250', 'currency' => 'SAR'],
    'oldestUnresolvedOrder' => [
        'id' => '01K5ADM1N0V3RV13W000000001',
        'number' => 'AUT-OLDEST-1001',
        'status' => 'received',
        'placedAt' => '2026-08-20T10:00:00+00:00',
    ],
]
```

Assert 7/30 range validation, Staff cannot receive global audit events, Admin
receives at most five safe recent events, query counts remain bounded, and no
credential/provider metadata is loaded or serialized.

- [ ] **Step 5: Run overview tests and verify RED**

Expected: query/presenter/controller/routes do not exist.

- [ ] **Step 6: Implement bounded aggregate query**

Use conditional aggregates rather than one query per KPI. Captured revenue sums
`captured_halalah` only for paid/refunded payment states in the requested date
window. Oldest unresolved order selects only public ID, number, status, and
placed/created timestamp; this plan emits no detail URL because the Orders route
belongs to the next plan. Recent audit metadata is projected from an explicit
safe key allowlist and only when `audit.view` is allowed.

- [ ] **Step 7: Implement AdminShell permission-filtered navigation**

Return actor display name/role, locale-safe home/logout URLs, exact permission
strings allowed for the actor, and navigation only for implemented routes:
Overview and MFA Security. Do not add Orders, Customers, Wallet, Catalog,
Governance, or Settings links until their routes exist. No full User model or
private attributes.

- [ ] **Step 8: Implement controller/routes and verify private props**

`AdminOverviewPage` composes the shell/query projections and exact range options
for 7 and 30 days with relative localized URLs and active state. Controller
validates `range` with `Rule::in([7, 30])`, authorizes `dashboard.view`, delegates
to the presenter, and renders `admin/overview`. Privacy test inspects Inertia
props/HTML for secret field names and raw provider metadata.

- [ ] **Step 9: Run Task 4 focused gates and commit**

```powershell
php artisan test tests/Feature/Admin/AdminOverviewTest.php tests/Feature/Admin/AdminPropPrivacyTest.php tests/Feature/Database/DomainSchemaTest.php
vendor\bin\pint --test app database routes tests
php vendor\bin\phpstan analyse --memory-limit=1G
git add app database lang routes tests
git commit -m "feat(admin): add bounded operational overview"
```

---

### Task 5: Arab UT Admin Shell and Overview UI

**Files:**

- Create: `resources/js/layouts/admin-layout.tsx`
- Create: `resources/js/pages/admin/overview.tsx`
- Create: `resources/js/components/admin/admin-sidebar.tsx`
- Create: `resources/js/components/admin/admin-mobile-navigation.tsx`
- Create: `resources/js/components/admin/admin-kpi-strip.tsx`
- Create: `resources/js/components/admin/admin-work-queue.tsx`
- Modify: `resources/js/types/admin.ts`
- Create: `resources/js/__tests__/admin/admin-shell.test.tsx`
- Create: `resources/js/__tests__/admin/admin-overview.test.tsx`
- Modify: `resources/js/lib/page-layout.ts`
- Modify: `resources/js/layouts/chat-root-layout.tsx`
- Modify: `resources/css/app.css`
- Modify: `resources/views/app.blade.php`
- Modify: `tests/Browser/storefront-smoke.spec.ts`

**Interfaces:**

- Consumes: Task 4 `adminUi`, `adminIdentity`, `adminNavigation`, `permissions`,
  and `overview` safe props; existing logo, tokens, Thmanyah fonts, UI
  primitives, Inertia Links, and ChatRootLayout.
- Produces: `usesAdminLayout(name: string): boolean`, Admin layout resolution,
  a responsive permission-filtered shell, and the real overview screen.

Task 5 consumes this exact safe TypeScript contract:

```ts
type AdminMoney = { amountMinor: string; currency: 'SAR' };
type AdminTranslations = {
    brand: string;
    navigation: {
        overview: string;
        security: string;
        open: string;
        close: string;
    };
    common: { logout: string; retry: string; noData: string };
    mfa: {
        headTitle: string;
        title: string;
        description: string;
        enable: string;
        confirmCode: string;
        confirm: string;
        showRecoveryCodes: string;
        regenerateRecoveryCodes: string;
        recoveryWarning: string;
        configured: string;
        setupPassword: string;
        failed: string;
    };
    overview: {
        headTitle: string;
        title: string;
        description: string;
        range7: string;
        range30: string;
        receivedOrders: string;
        inProgressOrders: string;
        waitingForCustomer: string;
        pendingPayments: string;
        failedPayments: string;
        failedRefunds: string;
        capturedRevenue: string;
        oldestUnresolved: string;
        recentAudit: string;
        noUnresolved: string;
        noAudit: string;
    };
    statuses: Record<string, string>;
};
type AdminNavigationItem = {
    key: 'overview' | 'security';
    label: string;
    url: string;
};
type AdminOverviewPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: { name: string; role: 'admin' | 'staff' };
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    overview: {
        rangeDays: 7 | 30;
        orders: {
            received: number;
            inProgress: number;
            waitingForCustomer: number;
        };
        payments: { pending: number; failed: number };
        refunds: { failed: number };
        capturedRevenue: AdminMoney;
        oldestUnresolvedOrder: null | {
            id: string;
            number: string;
            status: string;
            placedAt: string;
        };
        recentAuditEvents: null | Array<{
            id: string;
            action: string;
            createdAt: string;
        }>;
    };
    rangeOptions: Array<{
        days: 7 | 30;
        label: string;
        url: string;
        active: boolean;
    }>;
    logoutUrl: string;
};
```

- [ ] **Step 1: Load every required UI/review skill and inspect references**

Read `frontend-design`, `ui-ux-pro-max`, `arrange`, `typeset`, `clarify`,
`adapt`, `polish`, `vercel-react-best-practices`,
`vercel-composition-patterns`, and `web-design-guidelines`. Read `.impeccable.md`,
inspect the live WordPress storefront identity, current account/auth shells,
local assets, typography, and interaction states. Record any unavoidable
WordPress deviation in the task report; do not silently choose one.

- [ ] **Step 2: Write failing layout-resolution and shell tests**

Assert `admin/*` resolves `[ChatRootLayout, AdminLayout]`, auth/store/account
resolution is unchanged, `ChatRootLayout` omits the customer ChatWidget for
`admin/*`, and navigation contains only supplied entries with correct
current/keyboard states.

- [ ] **Step 3: Run shell tests and verify RED**

Expected: Admin layout/components do not exist and resolver returns only the
root layout.

- [ ] **Step 4: Implement the responsive Admin shell**

Desktop/tablet sidebar and mobile sheet use the same navigation data and
semantic labels. Preserve near-black/deep-brown surfaces, warm ink, restrained
gold active/primary states, local Thmanyah typography, 44px controls, logical
properties, tabular numerals, and visible focus. Do not nest cards or add
decorative charts.

In `app.blade.php`, derive `$isAdminRoute` from an `admin/` component prefix,
add an `admin-document` HTML class, and give it the same pre-CSS near-black
background used by the Admin shell so initial paint does not flash white. Do not
change the established `store-document` behavior.

For `admin/*`, render children without the customer ChatWidget. This avoids
representing the separately planned operator inbox or applying account mobile
offsets inside the privileged surface.

- [ ] **Step 5: Write failing overview component tests**

Cover Arabic/English text/direction, literal KPI values, oldest unresolved order
identity/status without a broken detail link, Admin-only audit section, Staff
absence of that section, 7/30 range links, empty queues, loading navigation
progress, and no chart/credential content.

- [ ] **Step 6: Run overview tests and verify RED**

Expected: overview components/page absent.

- [ ] **Step 7: Implement the overview page and styles**

Use one compact KPI strip plus operational work queues. The first reading order
answers “what needs action now?” and “what money failed?” before revenue. Use
text/icon/status, not color alone. Keep safe line lengths and density across all
breakpoints.

- [ ] **Step 8: Add authenticated Admin browser acceptance**

Extend the existing fixture without creating production accounts. Cover Arabic
and English at 320/390/768/1440, sidebar/sheet behavior, current navigation,
permissions, exact values, 44px targets, keyboard/focus/Escape restoration,
reduced motion, 200% zoom, safe-area/overflow, hit testing, and request/console
errors.

- [ ] **Step 9: Run UI skills' final review passes**

Run `web-design-guidelines`, React best-practices, composition review, `adapt`,
and final `polish`; fix Critical/Important findings under TDD and record exact
viewport evidence.

- [ ] **Step 10: Run Task 5 focused gates and commit**

```powershell
npm run test -- resources/js/__tests__/admin/admin-shell.test.tsx resources/js/__tests__/admin/admin-overview.test.tsx resources/js/__tests__/page-layout.test.ts
npm run types:check
npm run types:e2e
npm run lint:check
npm run format:check
npm run build
npm run test:e2e -- --grep "admin"
git add resources tests/Browser
git commit -m "feat(admin): add branded operations overview"
```

---

### Task 6: Foundation Verification, Documentation, and Next-Slice Handoff

**Files:**

- Modify: `.agents/skills/arab-ut-admin/references/architecture.md` only if
  implemented interfaces differ from the planned names
- Modify: `.agents/skills/arab-ut-admin/references/security.md` only if verified
  implementation details add a non-obvious invariant
- Modify: `.agents/skills/arab-ut-admin/references/testing.md` only if the final
  gate command changes
- Create: `docs/admin/STATUS.md`
- Test: full repository suites

**Interfaces:**

- Consumes: all previous task commits and reports.
- Produces: truthful Admin foundation status and a clean dependency boundary for
  the Orders plan. Does not claim Orders/Customers/Wallet/Catalog/Governance are
  implemented.

- [ ] **Step 1: Write the Admin status evidence boundary**

Record exact implemented routes, permissions, MFA requirements, overview
queries, UI verification, deployed/not-deployed state, and explicit next slice.
Avoid placeholders and future-tense claims presented as current behavior.

- [ ] **Step 2: Run documentation/skill guards**

Validate links, symbols, routes, middleware, permissions, endpoint names,
translation parity, skill frontmatter, no stale `planned` claims for implemented
foundation, and no claim that deferred modules exist.

- [ ] **Step 3: Run fresh full backend gates**

```powershell
php C:\temp\arabut-composer\composer.phar validate --strict --no-check-publish
vendor\bin\pint --test
php vendor\bin\phpstan analyse --memory-limit=1G
php artisan test
```

Expected: zero failures/errors.

- [ ] **Step 4: Run exact migration/MariaDB gates**

Use the repository's isolated MariaDB workflow contract:

```powershell
php -d extension=pdo_mysql artisan migrate:fresh --force
php -d extension=pdo_mysql artisan migrate:rollback --force
php -d extension=pdo_mysql artisan migrate --force
```

Run the Admin MFA/permission/overview tests plus existing schema and concurrency
tests under `phpunit.mariadb.xml`. Expected: all applicable tests pass; only
documented non-MariaDB/OS-specific skips remain.

- [ ] **Step 5: Run fresh full frontend/browser gates**

```powershell
php artisan wayfinder:generate --with-form
npm run ci:check
npm run test:e2e
```

Expected: all Vitest files, lint, formatting, application/e2e types, production
build, and all Chromium scenarios pass.

- [ ] **Step 6: Run final reviews and commit**

Apply Clean Code Guard, Test Guard, Docs Guard, security review, React
best-practices, composition, web guidelines, and final polish. Resolve all
Critical/Important findings, rerun covering gates, then:

```powershell
git add .agents docs
git commit -m "docs(admin): record secure foundation evidence"
```

- [ ] **Step 7: Prepare the next plan without implementing it**

The next plan is `Admin Orders and Credentials`: server-driven list/detail,
legal transitions, explicit credential reveal, and existing Paylink refund
integration. It starts only after this plan's final whole-branch review and
finishing workflow.
