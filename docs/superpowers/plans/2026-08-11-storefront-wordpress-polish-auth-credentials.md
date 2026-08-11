# Storefront WordPress Polish, Authentication, and EA Credentials Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved refined-WordPress storefront polish, state-preserving currency switching, Google/WhatsApp authentication, three-code EA entry, and owner-only persistent credential management.

**Architecture:** Existing Laravel actions, guest-cart ownership, catalog read models, and Inertia shell remain the foundations. Focused React components add motion and client continuity; new Laravel auth and credential endpoints own every third-party or sensitive boundary. Plain EA credentials are never part of page props and are only returned by explicit owner-scoped no-store JSON requests.

**Tech Stack:** PHP 8.3+, Laravel 13, Fortify, Socialite, MariaDB/SQLite, React 19, TypeScript, Inertia 3, authored CSS, Pest, Vitest, Testing Library, Vite.

## Global Constraints

- Arabic default, English under `/en`, full RTL/LTR parity.
- Refined WordPress visual direction, warm black/gold, local Thmanyah Serif Display for large headings and Thmanyah Sans for UI text.
- Exactly three distinct eight-digit EA backup codes.
- EA credentials remain encrypted and have no automatic deletion or expiry.
- Plain credentials never enter HTML/Inertia props, storage, URLs, logs, analytics, or cacheable responses.
- Existing server-authoritative prices, cart ownership, claim, idempotency, and locking stay intact.
- Google and Whapi secrets are environment-only.
- TDD RED before every production change; reduced motion and 44px controls are mandatory.

---

### Task 1: Homepage motion and responsive parity

**Files:**
- Modify: `resources/js/components/store/service-rail.tsx`
- Modify: `resources/js/pages/store/home.tsx`
- Modify: `resources/js/components/store/faq-section.tsx`
- Modify: `resources/css/app.css`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Test: `resources/js/__tests__/store/store-service-rail.test.tsx`
- Test: `resources/js/__tests__/store/store-home-content.test.tsx`

**Interfaces:**
- Service rail keeps the current props and adds no public configuration flags.
- A focused `StoreHeroStats` component renders the existing translated values and owns one IntersectionObserver lifecycle.

- [ ] Write behavior tests for autoplay/pause/reduced motion, exact card copy, one-row metrics, count completion, SVG FAQ chevron, and mobile navigation parity.
- [ ] Run focused Vitest and capture failures caused by the missing behaviors.
- [ ] Implement transform-only rail motion, visibility/focus/pointer pausing, count-up display, decorative coin semantics, and responsive CSS.
- [ ] Run focused tests, lint, types, and build; verify AR/EN at required widths.
- [ ] Commit the independently green homepage slice.

### Task 2: Refined SBC category and cart feedback

**Files:**
- Modify: `resources/js/pages/store/category.tsx`
- Modify: `resources/js/components/store/catalog/catalog-card.tsx`
- Modify: `resources/js/lib/catalog-cart-api.ts`
- Modify: `resources/css/app.css`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Test: `resources/js/__tests__/store/store-category.test.tsx`
- Test: `resources/js/__tests__/store/catalog-cart-api.test.ts`

**Interfaces:**
- Existing category props and authoritative variant IDs remain unchanged.
- Successful catalog add returns the existing safe cart response and dispatches one local cart-success UI state.

- [ ] Write failing tests for WordPress hierarchy, contained imagery, real Add to Cart labels, optimistic disabled/loading state, count update, and success feedback.
- [ ] Capture focused RED.
- [ ] Implement the refined layout and transform/opacity-only success motion without changing catalog trust boundaries.
- [ ] Run focused tests and AR/EN browser checks for search, filters, sort, add, keyboard, reduced motion, and empty states.
- [ ] Commit the independently green SBC slice.

### Task 3: State-preserving currency and password placement

**Files:**
- Modify: `resources/js/components/store/store-preferences.tsx`
- Modify: `resources/js/components/password-input.tsx`
- Modify: `resources/css/app.css`
- Test: `resources/js/__tests__/store/store-preferences.test.tsx`
- Test: `resources/js/__tests__/auth/auth-storefront.test.tsx`

**Interfaces:**
- Currency links become client navigation using the existing server-authored URLs and Inertia `preserveState`/`preserveScroll`.
- PasswordInput keeps its current public props.

- [ ] Write failing tests proving no document reload, configurator inputs survive, URL/display currency update, and reveal control never overlaps in RTL/LTR.
- [ ] Capture focused RED.
- [ ] Implement Inertia navigation and logical-side input padding/button placement.
- [ ] Run focused and browser verification, including a partially completed credential form.
- [ ] Commit the independently green continuity slice.

### Task 4: Google and WhatsApp authentication

**Files:**
- Modify: `composer.json`
- Modify: `config/services.php`
- Modify: `.env.example`
- Create: `database/migrations/2026_08_11_000002_add_google_identity_to_users.php`
- Create: `database/migrations/2026_08_11_000003_create_phone_login_codes.php`
- Create: `app/Actions/Auth/SendWhatsAppLoginCode.php`
- Create: `app/Actions/Auth/VerifyWhatsAppLoginCode.php`
- Create: `app/Http/Controllers/Auth/GoogleAuthController.php`
- Create: `app/Http/Controllers/Auth/WhatsAppLoginController.php`
- Create: `app/Http/Requests/Auth/SendWhatsAppCodeRequest.php`
- Create: `app/Http/Requests/Auth/VerifyWhatsAppCodeRequest.php`
- Modify: `routes/web.php`
- Modify: `resources/js/pages/auth/login.tsx`
- Modify: `resources/js/types/auth.ts`
- Modify: `lang/ar/auth_ui.php`
- Modify: `lang/en/auth_ui.php`
- Test: `tests/Feature/Auth/GoogleLoginTest.php`
- Test: `tests/Feature/Auth/WhatsAppLoginTest.php`
- Test: `resources/js/__tests__/auth/auth-storefront.test.tsx`

**Interfaces:**
- `SendWhatsAppLoginCode::execute(E164Phone $phone): void` sends one localized OTP through the configured Whapi boundary.
- `VerifyWhatsAppLoginCode::execute(E164Phone $phone, string $code): User` consumes one valid code for an existing verified phone-linked user.
- Socialite callback links by stable Google subject and verified unique email, then logs in through the existing guard and guest-cart claim event.

- [ ] Install the exact current Socialite version through Composer and verify the installed API.
- [ ] Write failing migration, route, rate-limit, OTP lifecycle, Socialite fake, locale, and UI tests.
- [ ] Capture RED on SQLite without any real network request.
- [ ] Implement migrations, request validation, actions, controllers, environment config, country picker, and Google button.
- [ ] Run focused/full SQLite, static analysis, and real migration lifecycle; use boundary fakes for Google/Whapi.
- [ ] Verify AR/EN auth flows at mobile and desktop widths; commit the independently green auth slice.

### Task 5: Three-code persistent EA credentials and owner-only cart editing

**Files:**
- Modify: `config/coins.php`
- Modify: `app/Http/Requests/Store/CoinsCartRequest.php`
- Modify: `app/Actions/Cart/AddCoinsToCart.php`
- Modify: `app/Http/Controllers/Store/CartController.php`
- Create: `app/Http/Controllers/Store/CartItemCredentialsController.php`
- Create: `app/Http/Requests/Store/UpdateCartItemCredentialsRequest.php`
- Modify: `app/Console/Commands/PurgeCartItemSecrets.php`
- Modify: `routes/web.php`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/js/types/store-shell.ts`
- Modify: `resources/js/components/configurator/coins/credentials-step.tsx`
- Modify: `resources/js/lib/coins-cart-api.ts`
- Create: `resources/js/lib/cart-credentials-api.ts`
- Modify: `resources/js/pages/store/cart.tsx`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `resources/css/app.css`
- Test: `tests/Feature/Store/CoinsCartTest.php`
- Test: `tests/Feature/Store/CartCredentialsTest.php`
- Test: `resources/js/__tests__/store/coins-credentials-flow.test.tsx`
- Test: `resources/js/__tests__/store/store-cart.test.tsx`

**Interfaces:**
- GET owner route returns `{data:{eaEmail:string,eaPassword:string,backupCodes:[string,string,string]}}` with `no-store` only after cart ownership and active-secret checks.
- PUT owner route accepts exact email/password/three-code keys, re-encrypts transactionally, and returns the same no-store payload.
- Safe Cart Inertia projection exposes only `credentialsAvailable: boolean` and no expiry/plaintext.

- [ ] Write failing tests for exactly three codes, no expiry copy, no automatic purge, owner read/update, cross-owner fail-closed behavior, no-store headers, no response/session/log leakage, and React reveal/edit flow.
- [ ] Capture backend and frontend RED.
- [ ] Implement the minimal persistent encrypted contract, remove automatic secret deletion, and build the owner-only cart editor.
- [ ] Run focused SQLite plus real MariaDB lifecycle/security tests and frontend interaction tests.
- [ ] Run secret-sink scans and verify plaintext never enters initial HTML, URLs, browser storage, logs, or analytics.
- [ ] Commit the independently green credential slice.

### Task 6: Release verification

**Files:**
- Modify: `docs/superpowers/plans/2026-08-11-storefront-wordpress-polish-auth-credentials.md`
- Create: `.superpowers/sdd/2026-08-11-storefront-wordpress-polish-auth-credentials/final-report.md`

- [ ] Run Clean Code Guard and fix every actionable production finding.
- [ ] Run Test Guard and fix behavior/fixture/mocking violations.
- [ ] Run full Composer/Pest/PHPStan/Pint and npm Vitest/ESLint/Prettier/TypeScript/Vite gates.
- [ ] Run SQLite and disposable MariaDB fresh/rollback/remigrate plus critical cart/auth tests.
- [ ] Browser verify AR/EN at 320/390/768/1075/1440, 200% zoom, reduced motion, keyboard, touch-like rail, currency preservation, OTP/Google boundary flows, credentials edit, and clean console.
- [ ] Review the whole branch, commit the tracked report/checked plan, then deploy only after all release gates pass.

