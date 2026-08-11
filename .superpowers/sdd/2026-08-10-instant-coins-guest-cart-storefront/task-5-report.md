# Task 5 report — transactional guest-cart claim

## Outcome

Successful sign-in and registration now claim the existing server-session guest cart through one discovered `Illuminate\Auth\Events\Login` listener. A guest-only cart is converted in place. When an active user cart already exists, every guest cart-item row moves to it without changing cart-item IDs or touching the one-to-one encrypted-secret rows, and only the empty guest cart is deleted.

The action is one idempotent database transaction. It locks the guest and user active carts in deterministic `active_owner_key` order, retries transaction conflicts up to three times, and lets every failure propagate. The listener forgets `coins_guest_owner_token` through `DB::afterCommit`, so an outer rollback retains the token and all original ownership for retry.

## Implemented contracts

- `ClaimGuestCart::execute(string $guestSessionHmac, User $user): void` validates the HMAC through `CartOwner`, derives the user owner through the same value object, and uses only active SAR owner keys.
- `ResolveCartOwner::existingGuestForRequest()` reads only an already-present valid server-session token. It exposes no raw token and reuses the existing current/previous application-key derivation and rekey boundary.
- `ClaimGuestCartAfterLogin` returns for non-application users, missing sessions, and missing/invalid guest tokens. Claim failures are not caught or converted into success.
- Laravel 13 event discovery registers exactly one `ClaimGuestCartAfterLogin@handle` listener. A guard review caught and removed a duplicate manual registration before commit.
- Guest-only conversion sets `user_id` and clears `session_key`; SQLite triggers or the MariaDB generated column derive `user:<id>`.
- Merge uses a bulk `cart_items.cart_id` update. It never selects, casts, decrypts, serializes, logs, or mutates `cart_item_secrets.encrypted_payload`.
- Repeated and concurrent claims leave one active user cart, no active guest cart, and every original item and secret relation exactly once.
- Canonical and localized login/registration redirects are unchanged; the localized intended `/en/cart` destination remains intact.

No authentication UI, checkout, payment, order, or credential-projection behavior changed.

## Official-framework verification

- Laravel 13 event documentation confirms `Login` listener discovery/registration and the synchronous listener contract.
- The installed Laravel 13.17 `SessionGuard::login()` updates the session and dispatches `Login`; Fortify 1.37.2 uses that same guard path for both login and registration.
- The installed database transaction manager confirms `DB::afterCommit` runs immediately with no open transaction and defers to the root commit when a transaction is active.

## TDD evidence

### Genuine SQLite RED

Before production edits:

```text
10 tests; 0 passed; 5 expected behavior failures; 1 missing-contract error;
4 MariaDB-only skips; 19 assertions.
```

The failures named the absent action/listener: the token remained after login, registration, merge, and the cross-session no-op; the rollback failure path was never reached; and the container could not resolve `ClaimGuestCart`.

### SQLite GREEN

- Focused claim/concurrency selection: 10 tests, 6 passed, 4 expected MariaDB-only skips, 51 assertions.
- Full backend: 335 tests, 332 passed, 3 expected skips, 17,705 assertions.

The focused suite covers guest-only conversion, existing-cart merge, ciphertext/ID retention, query-log proof of no secret access, repeat idempotency, outer rollback with token retention, cross-session isolation, localized login, localized registration, and concurrent claims.

## Real MariaDB verification

The isolated official MariaDB 12.3.2 Windows ZIP was checked against the official archive checksum before execution:

```text
67347c129eb9c5923d002ea34fbfa27c60eb95d36dd73b85af2651cdeceecac5
```

Results on MariaDB 12.3.2 bound only to `127.0.0.1:3323`:

- Task 5 plus all four independent-process cart races: 10/10 passed, 82 assertions.
- Extended auth/cart/schema/rotation/upgrade selection: 99 tests, 95 passed, 4 expected engine-specific skips, 806 assertions.
- Full migration rollback, remigration, and status: every migration through `2026_08_10_000003` reported `Ran`.
- Post-remigration Task 5/two-process rerun: 10/10 passed, 82 assertions.

The simultaneous claim processes merged the guest and existing user carts into one active user cart with both original item IDs and both original secret IDs exactly once. The disposable server shut down cleanly, port 3323 closed, and its database, binaries, archive, checksum, logs, and PID file were removed.

## Security and leak gates

- The claim query log contains no `encrypted_payload`, `cart_item_secrets`, or `secret_access_logs` query.
- Raw ciphertext read before and after guest-only conversion and two-cart merge is byte-for-byte identical.
- Production claim code imports no secret model and has no logging, response, session-write, browser-storage, or serialization path for credentials.
- The listener receives only a `CartOwner` HMAC from `ResolveCartOwner`; the raw token remains confined to Laravel's server session and is forgotten only after commit.
- The two-process helper receives only the irreversible guest HMAC and numeric user ID.

## Guard verdicts

- Clean Code: no swallowed production error, speculative abstraction, duplicated HMAC logic, secret access, or dead listener registration. The action was split into lock, convert, and merge operations with deterministic ownership semantics.
- Test Guard: real migrated SQLite and MariaDB databases, real Fortify login/registration, no application mocks, one scenario per test, and behavior/state assertions rather than internal-call assertions.
- Docs Guard: every symbol, signature, test count, migration, version, event, and cleanup claim above was checked against current source or fresh command output.
- Composer strict validation, Pint, PHPStan, and `git diff --check` pass in the final gate.

## Owned files

Created:

- `app/Actions/Cart/ClaimGuestCart.php`
- `app/Listeners/ClaimGuestCartAfterLogin.php`
- `tests/Feature/Auth/GuestCartClaimTest.php`
- `tests/Support/ConcurrentGuestCartClaim.php`
- this report

Modified:

- `app/Actions/Cart/ResolveCartOwner.php`
- `tests/Integration/CoinsCartConcurrencyTest.php`

`AppServiceProvider` was deliberately left unchanged because Laravel 13 event discovery already registers the listener; manual registration would execute it twice.
