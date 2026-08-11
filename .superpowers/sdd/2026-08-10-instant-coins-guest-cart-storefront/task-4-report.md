# Task 4 report — secure guest add-to-cart and cart read

## Outcome

Guests can now complete the approved Coins flow from Amount to EA credentials to Summary, add the server-requoted item, and read the resulting safe cart without being sent to login. The only authentication removal is on the canonical and localized Coins POST routes. Login remains available for the future checkout boundary; this task adds no checkout or payment behavior.

The backend resolves one `CartOwner` for add, read, shared count, idempotency fingerprinting, active-cart acquisition, and rate limiting. Guest carts persist `user_id = null` plus only the HMAC session key established by Task 3. Raw guest tokens and EA credentials are not placed in URLs, database cart configuration, response bodies, Inertia props, logs, or browser storage.

## Implemented contracts

- `AddCoinsToCart::execute()` accepts `CartOwner` and preserves the existing transaction, locked cart acquisition, authoritative SAR re-quote, encrypted one-to-one secret, retention expiry, safe response, and idempotency replay behavior.
- `CoinsCartController`, `CartController`, and the shared Inertia `cartCount` use `ResolveCartOwner` for both users and guests.
- `CoinsCartRequest` authorizes the public route while retaining the exact validation rules. Canonical and localized POST routes retain the `web` CSRF boundary, `NoStore`, `RequireCoinsCartJson`, and owner-keyed `throttle:coins-cart` middleware.
- Rate-limit buckets use the opaque cart-owner scope, so one guest session cannot exhaust another session's allowance.
- The deleted resume controller, validator middleware, routes, and `resumeUrl` prop no longer form a dead login gate. Amount Continue is always a button that enters the in-memory credentials step.
- A successful guest submission clears credential component state, updates the shared cart count, and navigates only to the server-returned localized cart URL.
- Guest cart reads reuse the existing allowlisted `safeConfiguration()` and `safeCredentials()` projections and remain read-only with no checkout/payment controls.
- Task 2's synchronous indexed price schedule is unchanged; no quote-fetching or client formula was introduced.

## TDD evidence

### Genuine backend RED

Before production edits, the focused backend suite returned 9 expected behavior failures:

```text
69 tests; 60 passed; 9 failed; 776 assertions.
```

Guest POST requests received `401` instead of `201`/`419`, obsolete resume routes still redirected instead of returning `404`, and homepage props still exposed `resumeUrl`.

### Genuine frontend RED

Before production edits:

```text
2 test files; 15 tests; 13 passed; 2 failed.
```

The guest Amount action was still a login/resume link rather than entering EA credentials, and the guest success/retry contract could not complete the in-memory flow.

### Focused GREEN

- Backend: 70/70 passed, 924 assertions.
- Frontend: 2 files, 15/15 passed.
- Extended cart/home/auth/concurrency selection: 80 passed, 3 expected MariaDB-only skips, 1,187 assertions.

Guest coverage includes create/replay, different-session collision and cart isolation, guest cart read/count, canonical/localized routes, JSON-only rejection, real CSRF rejection, validation, safe encrypted persistence, no session/configuration/response leakage, owner rate limiting, and safe read-only UI.

The CSRF regression deliberately runs the request outside Laravel's unit-test CSRF bypass and receives `419`; this verifies the real `web` middleware boundary rather than only inspecting route metadata.

## Real MariaDB verification

Docker was unavailable, so an isolated official MariaDB 12.3.2 Windows server was downloaded from the MariaDB archive. Its SHA-256 matched the official archive checksum exactly:

```text
67347c129eb9c5923d002ea34fbfa27c60eb95d36dd73b85af2651cdeceecac5
```

The disposable server bound only to `127.0.0.1:3321`. `migrate:fresh` succeeded, and the targeted guest endpoint/schema/acquisition/concurrency selection passed:

```text
67 tests; 63 passed; 4 expected engine-specific skips; 642 assertions.
```

A full rollback, remigration, and migration-status check then passed with every migration through `2026_08_10_000003` reported as `Ran`. The server was shut down, port 3321 was confirmed closed, no `mariadbd` process remained, and the verified archive, binaries, database, PID, and logs were removed.

## Security and leak gates

- The exact POST route listing shows only `web`, `NoStore`, `RequireCoinsCartJson`, and `throttle:coins-cart`; `auth` is absent only from these two approved write routes.
- The resume-route listing returns no matching route. Production scans find no resume controller, middleware, route name, or `resumeUrl` caller.
- Credential sink scan found no EA secret flow into sessions, logs, browser storage, arbitrary cart configuration, query values, or response projections. The sole production query-related match is `HomeController::queryContainsCredentials()`, which checks only whether forbidden credential key names are present and rejects the deep link without reading or storing their values.
- Frontend regression tests assert local/session storage remain empty and guest Continue is not a login link.
- Guest persistence tests assert only the HMAC owner key reaches the cart row; the raw server-session token is absent from database rows and responses.
- Cart response tests prove owner keys, HMACs, encrypted payloads, email/password/codes, client-authoritative fields, and compound/invalid configuration values are not projected.
- JSON-only, CSRF, no-store, per-owner throttle, server re-quote, rollback, secret expiry, safe idempotency replay, and generic error protections remain covered.

## Aggregate verification

The final `composer ci:check` passed end to end:

- strict Composer validation;
- Pint;
- PHPStan with zero errors;
- Pest: 329 total, 326 passed, 3 expected skips, 17,654 assertions;
- Vitest: 15 files and 185 tests passed;
- ESLint;
- Prettier;
- TypeScript;
- Vite production build: 2,328 modules.

The build retained only the existing informational absolute-public-asset and font resolution warnings. `git diff --check` passed. Clean Code guard found the owner resolution centralized and the removed gate fully deleted; Test guard found behavior-level guest/security assertions rather than implementation-only mocks; Docs guard found this report consistent with the executable evidence and no user-facing documentation change required.

## Files changed

Production boundaries:

- `app/Actions/Cart/AddCoinsToCart.php`
- `app/Http/Controllers/Store/CartController.php`
- `app/Http/Controllers/Store/CoinsCartController.php`
- `app/Http/Controllers/Store/HomeController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Http/Requests/Store/CoinsCartRequest.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `routes/web.php`
- `resources/js/components/configurator/coins/amount-step.tsx`
- `resources/js/components/configurator/coins/coins-configurator.tsx`
- `resources/js/pages/store/home.tsx`
- `resources/js/types/coins.ts`

Deleted dead gate:

- `app/Http/Controllers/Store/CoinsCartResumeController.php`
- `app/Http/Middleware/ValidateCoinsCartResume.php`

Regression coverage and concurrency compatibility:

- `tests/Feature/Store/CoinsCartTest.php`
- `tests/Feature/Store/HomeCoinsConfiguratorTest.php`
- `tests/Feature/Auth/LocalizedAuthTest.php`
- `tests/Support/ConcurrentCoinsCartAdd.php`
- `resources/js/__tests__/store/coins-credentials-flow.test.tsx`
- `resources/js/__tests__/store/coins-home.test.tsx`
- `resources/js/__tests__/store/store-cart.test.tsx`

## Handoff

Task 5 may claim the HMAC guest cart after authentication, but must not recreate a login gate before cart creation and must forget the raw guest owner token only after a successful transactional claim. Task 6/checkout must continue using the same safe cart projection and server authority. This task intentionally does not implement either boundary.
