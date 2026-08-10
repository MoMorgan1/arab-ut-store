# Task 3 report — secure guest cart ownership

## Outcome

Implemented the approved guest-cart ownership boundary without adding guest endpoints or changing the configurator UI. Anonymous ownership now starts from 32 bytes of server-generated randomness kept only in Laravel's server-side session. The database receives only a lowercase HMAC-SHA-256 owner key. Authenticated users always take precedence.

SQLite and MariaDB now derive `active_owner_key` from cart state and enforce one active SAR cart for either `user:<id>` or `guest:<hmac>`. The new migration supports upgrade, full rollback, and remigration while restoring the previous authenticated-only invariant on `down()`.

## Implemented contracts

- `CartOwner` is final, readonly, and exposes only `databaseKey()`, nullable `userId()`, nullable HMAC `sessionKey()`, and `idempotencyScope()`.
- `ResolveCartOwner` stores the raw random token only under `coins_guest_owner_token` in the server session and returns an opaque HMAC-backed owner. When Laravel has configured previous application keys, the resolver locks an existing active cart under a previous HMAC and transactionally rekeys it to the current HMAC.
- `Cart::activeForOwner()` scopes every lookup by state, currency, derived owner key, and the matching user/HMAC column. The authenticated compatibility scope delegates to it.
- `CoinsCartFingerprint` has an explicit opaque guest-owner canonical branch while preserving the exact legacy authenticated `user_id` integer canonicalization for rolling-deploy idempotency replay.
- Migration `2026_08_10_000003_expand_active_cart_invariant_to_guests` rejects malformed non-HMAC legacy owner keys and duplicate active owners before DDL, derives owner keys in both engines, replaces the historical global session-key unique constraint with a lookup index, and restores the user-only invariant on safe rollback.
- `AcquireActiveCart` is the production acquisition boundary for authenticated and guest owners. The existing authenticated add action and the independent-process guest concurrency test both call this boundary.
- The pinned MariaDB CI suite includes `CartOwnerTest`, `CartOwnerRotationTest`, `ActiveCartAcquisitionTest`, and the existing schema, upgrade, and concurrency guest coverage.
- A real independent-process guest cart acquisition test proves simultaneous first acquisition resolves to one active row and one public cart identity.

No route, controller, guest add-to-cart action, cart read, authentication claim, checkout, payment, or UI behavior was added in this task.

## Research and compatibility checks

- Read the prior secure-cart implementation and its Task 5 report before edits, including its existing SQLite expression-index/trigger and MariaDB generated-column decisions.
- Verified the current official MariaDB release index lists 12.3.2 as stable and downloaded the official Windows ZIP from `https://mirror.mariadb.org/mariadb-12.3.2/winx64-packages/`.
- Verified the downloaded archive against the official `sha256sums.txt` before execution.
- Checked PHPStan's current `argument.type` guidance before correcting the fingerprint caller to pass the canonical owner string rather than relying on implicit integer coercion.

## TDD evidence

### SQLite RED

Before production edits:

```text
php vendor/bin/pest tests/Unit/Cart/CartOwnerTest.php tests/Feature/Database/CartSecuritySchemaTest.php tests/Integration/ActiveCartInvariantUpgradeTest.php tests/Unit/Security/CoinsCartFingerprintTest.php --compact
```

Result: 15 tests; 7 passed; 3 assertion failures and 5 expected missing-contract errors. The failures named the missing owner value/resolver, guest derivation, `activeForOwner`, migration 000003, and owner-key fingerprint.

### SQLite GREEN

Final focused result including concurrency guards:

```text
20 tests; 16 passed; 4 MariaDB-only skips; 64 assertions.
```

The aggregate gate later passed the complete backend suite: 323 tests, 320 passed, 3 expected skips, and 17,592 assertions.

During final security review, an additional RED proved SQLite's historical unique index on the writable derived column could reject a guest insert before its trigger corrected a colliding caller value:

```text
1 error; UNIQUE constraint failed: carts.active_owner_key.
```

Migration 000003 now removes that redundant writable-column index while the guest invariant is installed and relies on the authoritative unique expression index. The focused GREEN passed 1 test and 2 assertions. Rollback recreates the former column index before migration 000002 is restored.

### MariaDB RED

The verified MariaDB 12.3.2 instance migrated fresh, then only migration 000003 was rolled back. The independent-process same-guest test failed as intended against the former authenticated-only invariant:

```text
1 failed test; 2 assertions.
```

Both processes could not resolve a derived guest owner. This proves the test detects the prior production behavior rather than only exercising the final implementation.

### MariaDB GREEN

- Owner/schema/upgrade/concurrency focus: 18 passed, 88 assertions.
- Explicit generated-column down/up/remigration plus all three process races: 8 passed, 40 assertions.
- Extended pinned CI selection: 146 tests, 143 passed, 3 expected skips, 895 assertions.
- Final schema/cart/upgrade/concurrency/fingerprint selection after the canonical fingerprint call-site correction: 71 tests, 68 passed, 3 expected skips, 617 assertions.

The guest concurrency contract uses two independent PHP processes. Both returned the same non-empty public cart identity, and the database contained exactly one active `guest:<hmac>` row.

## Review fix round

The first spec/quality review identified five important gaps. Each gap received a focused failing regression before its production fix:

- A malformed non-HMAC legacy `session_key` was promoted into an active guest owner. SQLite failed because the migration did not throw, and MariaDB 12.3.2 failed the same pre-DDL expectation. The migration now streams every non-null legacy key through strict lowercase 64-hex validation before any DDL.
- Authenticated fingerprints had changed from the deployed `user_id` integer payload to an `owner_key` string. A literal legacy-hash regression failed before the canonical authenticated branch was restored. Guest fingerprints use a separate literal-tested branch and reject malformed owner strings.
- The original global unique index on `carts.session_key` prevented the same HMAC from acquiring a new active cart after a historical cart. SQLite failed with the global unique constraint. Upgrade now replaces it with `carts_session_key_index`; the derived active-owner unique invariant alone governs active SAR carts. Rollback checks for reused session keys before DDL and fails closed rather than losing history.
- The first concurrency helper duplicated insert/query logic. A feature test failed because no production acquisition boundary existed. `AcquireActiveCart` now owns the insert-or-ignore plus locked lookup, the authenticated action delegates to it, and the same-guest process test invokes it directly.
- Rotation of `APP_KEY` orphaned an active guest cart. A real database test failed to find the old cart under the new owner. The resolver now derives current and configured previous-key HMAC candidates, locks matching active rows in deterministic owner-key order, prefers an already-current cart, and transactionally rekeys one previous active cart. It does not add a new secret or persist the raw token.

### Review RED evidence

- Fingerprint focus: 2 tests, 2 expected failures.
- SQLite owner/migration/acquisition focus: 15 tests, 8 passed, 1 expected assertion failure, 5 expected missing-contract/constraint errors, and 1 MariaDB-only skip.
- Dedicated key-rotation focus: 1 expected database lookup error.
- MariaDB 12.3.2 lifecycle focus: 1 expected failure because malformed legacy ownership was accepted.

### Review GREEN evidence

- SQLite cart/fingerprint/upgrade/acquisition/rotation and authenticated action focus: 71 tests, 70 passed, 1 expected MariaDB-only skip, 617 assertions.
- MariaDB owner/schema/upgrade/acquisition/rotation/concurrency focus: 27 tests, 26 passed, 1 expected SQLite-only skip, 122 assertions.
- MariaDB upgrade suite after the streaming-validation refactor: 10 passed, 45 assertions.
- Full SQLite backend: 327 tests, 324 passed, 3 expected skips, 17,604 assertions.
- Full frontend: 15 Vitest files and 185 tests passed; ESLint, Prettier, TypeScript, and the production Vite build passed.
- Aggregate `composer ci:check`: Composer validation, Pint, PHPStan, Pest, Vitest, ESLint, Prettier, TypeScript, and Vite all exited successfully.

### Review lifecycle and security evidence

- An isolated SQLite file completed fresh migration, full rollback, re-migration, and reported every migration through `000003` as `Ran`.
- MariaDB 12.3.2 completed fresh migration, malformed-key and reused-HMAC upgrade coverage, all independent-process races, full rollback, re-migration, and reported every migration through `000003` as `Ran`.
- The disposable MariaDB process was shut down cleanly, port 3319 closed, and its database, binaries, logs, verified archive, checksum file, and SQLite lifecycle file were removed.
- Raw Laravel session-ID and logging/browser-storage/response sink scan: 0 matches in the owner path.
- Synthetic malformed-key, fingerprint-secret, and disposable-database fixture scan against production/config/resource/route paths: 0 matches.
- `git diff --check`, Clean Code guard, Test guard, and Docs guard found no remaining blocking issue.

## Migration lifecycle evidence

### SQLite

An isolated temporary SQLite file completed:

```text
migrate:fresh -> full rollback -> migrate -> status
```

Every migration through 000003 reported `Ran` after remigration. The temporary file was deleted.

### MariaDB 12.3.2

An isolated server bound to `127.0.0.1:3313` completed:

```text
migrate:fresh -> focused RED after 000003 rollback -> migrate
full rollback -> migrate -> status
generated-column down -> up -> down -> up
```

All migrations reported `Ran`, and the guest generated column recomputed `guest:<hmac>` on each upgrade and released it on each rollback. The disposable database, process, data directory, extracted binary, and downloaded archive were removed; port 3313 was closed.

## Security and leak gates

- Raw Laravel session-ID scan across the owner path: 0 matches.
- Logging/debug/browser-storage/response sink scan across the owner path: 0 matches.
- The resolver replaces missing or malformed session state with fresh server randomness and never exposes that token through `CartOwner`.
- Unit coverage proves the database key and JSON serialization do not contain the raw session token.
- Resolver errors and migration duplicate errors are generic and include no owner value.
- Concurrency process arguments contain only the irreversible HMAC owner key, never the raw server-session token or credentials.
- Database tests prove caller-supplied `active_owner_key` is ignored and re-derived on SQLite or rejected as an explicit generated-column write on MariaDB.
- SQLite also proves a deliberately colliding caller key cannot deny or redirect guest derivation; the expression index enforces the true owner instead.
- User ownership wins when both user and guest columns are present; converted carts release `active_owner_key`.

## Aggregate gates

`composer ci:check` passed:

- strict Composer validation;
- Pint;
- PHPStan with zero errors;
- full Pest result above;
- 15 Vitest files and 185 frontend tests;
- ESLint;
- Prettier;
- TypeScript;
- production Vite build (2,328 modules).

Vite retained only the existing informational absolute-public-asset warnings. `git diff --check` and the targeted leak scans passed.

## Owned files

Created:

- `app/Actions/Cart/AcquireActiveCart.php`
- `app/Actions/Cart/ResolveCartOwner.php`
- `app/ValueObjects/Cart/CartOwner.php`
- `database/migrations/2026_08_10_000003_expand_active_cart_invariant_to_guests.php`
- `tests/Support/ConcurrentGuestCartCreate.php`
- `tests/Feature/Cart/CartOwnerRotationTest.php`
- `tests/Feature/Database/ActiveCartAcquisitionTest.php`
- `tests/Unit/Cart/CartOwnerTest.php`
- this report

Modified:

- `app/Actions/Cart/AddCoinsToCart.php` to preserve the authenticated owner/fingerprint contract while delegating cart acquisition to `AcquireActiveCart`
- `app/Models/Cart.php`
- `app/Security/CoinsCartFingerprint.php`
- `tests/Feature/Database/CartSecuritySchemaTest.php`
- `tests/Integration/ActiveCartInvariantUpgradeTest.php`
- `tests/Integration/CoinsCartConcurrencyTest.php`
- `tests/Unit/Security/CoinsCartFingerprintTest.php`
- `.github/workflows/tests.yml`

## Handoff

Task 4 should inject `CartOwner` into add/read/count operations and remove the temporary user-only compatibility scope only after all authenticated callers migrate. Task 5 should reuse the same HMAC session key for claim and forget the raw server-session token only after a successful commit. Neither task should duplicate HMAC or owner-key construction.
