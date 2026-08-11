# Task 5 report — transactional guest-cart claim

## Outcome

Successful sign-in and registration claim the server-session guest cart through one auto-discovered `Illuminate\Auth\Events\Login` listener. A guest-only cart is converted in place. Existing user and guest carts are merged without changing cart-item IDs or reading encrypted secret rows.

The review fix adds a durable `guest_cart_claims` ownership marker. `AcquireActiveCart` and `ClaimGuestCart` lock the same HMAC marker before touching carts. A request carrying an old guest HMAC after a successful claim is routed to that user's cart and cannot recreate an orphan guest cart.

## Implemented contracts

- `ResolveCartOwner::existingGuestCandidatesForRequest()` derives current and configured previous-key HMAC owners without writing the database. Anonymous browsing retains the existing transactional rekey behavior; login passes every candidate into the root claim transaction.
- `ClaimGuestCart::execute(list<CartOwner>, User)` locks claim markers and active cart owner keys in deterministic string order. It converts one guest cart when no user cart exists, moves every other guest cart item into the target, deletes only emptied guest carts, and marks every candidate claimed in the same transaction.
- `LockGuestCartClaims` performs a locking read before inserting missing markers, then locks the complete sorted marker set. This avoids racing an uncommitted claim insert while retaining root-transaction deadlock retries for simultaneous first acquisition.
- `AcquireActiveCart` resolves a guest HMAC through the locked durable marker. Unclaimed HMACs remain guest-owned; claimed HMACs acquire the mapped active user cart.
- A marker already claimed by a different user fails closed with no cart or marker mutation.
- `ClaimGuestCartAfterLogin` catches a propagated claim failure only to log the just-authenticated guard back out, then rethrows. It retains the guest token and all session state for retry. The token is forgotten only through `DB::afterCommit` after a successful claim.
- Laravel event discovery registers exactly one listener. Login and registration share this path; there is no second registration implementation.
- Production claim and marker-lock code never imports or queries `CartItemSecret`, `cart_item_secrets`, `encrypted_payload`, or secret-access logs.

## TDD evidence

### Initial RED/GREEN

The original Task 5 RED established the absent listener/action behavior. Its final implementation commit was `db39ce9` (`feat: claim guest carts after authentication`).

### Review RED

Before review production edits, the focused SQLite regression run produced 5 tests: 3 passed, one expected assertion failure, one missing-contract error, and 34 assertions. The real SQLite `BEFORE DELETE` trigger aborted the claim through the real Fortify login route; the failure showed that the user remained authenticated. The new multi-candidate action contract also failed because it did not yet exist.

MariaDB stress then exposed a real intermittent `DeadlockException` in the claim-first test helper. The helper had accidentally wrapped the production add action in an outer transaction merely to signal process start, preventing `AddCoinsToCart` from owning and retrying its root transaction. The corrected helper wraps only the side deliberately held by a barrier.

### Review GREEN

- Focused SQLite auth/rotation/concurrency selection: 18 tests, 10 passed, 8 expected MariaDB-only skips, 78 assertions.
- Full SQLite backend after final review fixes: 338 tests, 335 passed, 3 expected skips, 17,728 assertions.
- The injected claim failure proves exception propagation, unauthenticated guard state, retained raw guest token, unchanged carts/items, and a successful retry after removing the trigger.
- Key-rotation coverage proves current and previous HMAC carts remain unchanged until the root claim transaction and then merge into one user cart.
- Cross-user coverage proves a claimed HMAC fails closed for a different user.

## Real MariaDB verification

MariaDB 12.3.2 ran locally on isolated `127.0.0.1:3324`.

- Fresh migration plus focused claim/all independent-process races: 16 tests, 15 passed, one expected SQLite-only skip, 109 assertions.
- Pinned MariaDB CI selection after the final concurrency correction: 171 tests, 166 passed, 5 engine-specific skips, 1,079 assertions.
- Full rollback, remigration, and status succeeded; migration `2026_08_11_000001_create_guest_cart_claims_table` reported `Ran`.
- Post-remigration claim/concurrency selection: 17 tests, 16 passed, one expected SQLite-only skip, 114 assertions.
- Claim-first stress: 15 consecutive two-process runs passed, 105 assertions.
- Add-first stress: 10 consecutive two-process runs passed, 70 assertions.

The MariaDB cases directly exercise production `ClaimGuestCart`, `AddCoinsToCart`, and `AcquireActiveCart` boundaries for guest-only conversion, two guest HMAC carts claimed by one user, simultaneous repeated claims, add-before-claim, and claim-before-stale-add.

## Lifecycle, static, and security gates

- An isolated SQLite file completed fresh migration, full rollback, remigration, and status, then was removed.
- MariaDB completed fresh migration, latest-migration down/up, full rollback/remigration, and post-remigration behavior tests.
- The disposable MariaDB server shut down cleanly, port 3324 closed, and its database, PID, and log directory were removed.
- Pint passed; PHPStan passed with zero errors; strict Composer validation passed; `git diff --check` passed.
- Query-log regression found no `encrypted_payload`, `cart_item_secrets`, or `secret_access_logs` access.
- Production claim/marker sink scan found zero secret-model, decryption, logging, or secret-table matches.
- Raw-token scan found zero session-token references in `ClaimGuestCart` and `LockGuestCartClaims`.
- Event listing contains exactly one `ClaimGuestCartAfterLogin@handle` entry.

## Files changed in the review fix

Created:

- `app/Actions/Cart/LockGuestCartClaims.php`
- `database/migrations/2026_08_11_000001_create_guest_cart_claims_table.php`
- `tests/Support/ConcurrentGuestCoinsCartAdd.php`

Modified:

- `app/Actions/Cart/AcquireActiveCart.php`
- `app/Actions/Cart/ClaimGuestCart.php`
- `app/Actions/Cart/ResolveCartOwner.php`
- `app/Listeners/ClaimGuestCartAfterLogin.php`
- `tests/Feature/Auth/GuestCartClaimTest.php`
- `tests/Integration/CoinsCartConcurrencyTest.php`
- `tests/Support/ConcurrentGuestCartClaim.php`
- `.github/workflows/tests.yml`

No authentication UI, checkout, payment, order, or credential-projection behavior changed.
