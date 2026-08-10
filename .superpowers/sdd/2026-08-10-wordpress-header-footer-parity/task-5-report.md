# Task 5 implementer report: secure Coins cart backend

## Outcome and scope

Implemented the authenticated Coins add-to-cart backend only. The implementation adds encrypted one-to-one cart secrets, a database-enforced active-owner key, authoritative `QuoteCoins` repricing, keyed idempotency, safe cart reads, login resume validation, retention purging, localized routes, and focused database/feature/unit/command/concurrency tests.

Payment, checkout, order creation, fulfillment submission, staff secret access, credential UI, and cart UI were not implemented. The cart read controller continues to render the existing `store/simple-page` component with new safe `cart` props so Task 6 owns the visual implementation.

## Official documentation consulted before production edits

All pages below were fetched from the official Laravel 13 documentation and returned HTTP 200:

- Laravel encrypted casts: <https://laravel.com/docs/13.x/eloquent-mutators#encrypted-casting>
- Laravel encryption and key rotation: <https://laravel.com/docs/13.x/encryption#gracefully-rotating-encryption-keys>
- Laravel array validation and allowed keys: <https://laravel.com/docs/13.x/validation#validating-arrays>
- Laravel request trimming/normalization: <https://laravel.com/docs/13.x/requests#input-trimming-and-normalization>
- Laravel route authentication: <https://laravel.com/docs/13.x/authentication#protecting-routes>
- Laravel rate limiting: <https://laravel.com/docs/13.x/rate-limiting>
- Laravel database transactions: <https://laravel.com/docs/13.x/database#database-transactions>
- Laravel scheduling: <https://laravel.com/docs/13.x/scheduling#defining-schedules>

Confirmed against the installed `laravel/framework ^13.17` project:

- encrypted casts need a `TEXT`-sized or larger column and are not queryable;
- Laravel encryption uses authenticated encryption, while `APP_PREVIOUS_KEYS` supports graceful key rotation;
- the `array:allowed,keys` rule constrains nested keys;
- JSON validation returns 422 without redirecting/flashing old input;
- current route middleware names are `auth` and `throttle:<limiter>`;
- transactions can retry deadlocks;
- schedules belong in `routes/console.php` in this application layout.

During static-analysis remediation, the official PHPStan identifier pages were also fetched successfully:

- <https://phpstan.org/error-identifiers/assign.propertyType>
- <https://phpstan.org/error-identifiers/property.notFound>

## TDD evidence

### Schema/security RED and GREEN

The default Scoop PHP runtime initially failed before reaching assertions because `pdo_sqlite` was disabled (`could not find driver`). That run was rejected as invalid RED evidence. All later test commands explicitly enabled required PHP extensions.

Genuine RED:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Database/CartSecuritySchemaTest.php --stop-on-failure
```

Result: 1 test ran, 1 failed, 1 assertion. The expected failure was `Schema::hasColumns('cart_item_secrets', ...)` returning false.

Initial schema/model GREEN:

```powershell
php -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Database/CartSecuritySchemaTest.php
```

Result: 4 passed, 18 assertions.

Clean Code Guard found that idempotency fields were hidden but not guarded from ordinary mass assignment. A new RED assertion failed because `request_hash` was still persisted. After guarding `request_hash` and `response_body`, the focused GREEN result was 1 passed, 4 assertions.

### Endpoint/resume/read RED and GREEN

Genuine endpoint RED:

```powershell
php -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --stop-on-failure
```

Result: first test failed as expected because `POST /cart/items/coins` returned 404 instead of the required guest 401.

Initial endpoint GREEN after implementation:

```powershell
php -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php
```

Result: 20 passed, 208 assertions.

The Test Guard later added stronger normalization, missing-header, cross-user idempotency, purge/read-state, and leak assertions. A separate opaque-password RED proved Laravel's `required` rule rejected a whitespace-only external password. The request changed to `present|string|min:1|max:128`; the focused GREEN was 1 passed, 2 assertions, with the password preserved exactly.

Final Task 5 SQLite-focused result:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Database/CartSecuritySchemaTest.php tests/Feature/Store/CoinsCartTest.php tests/Feature/Console/PurgeCartItemSecretsTest.php tests/Integration/CoinsCartConcurrencyTest.php
```

Result before the final pure unit extraction: 30 tests, 29 passed, 1 MariaDB-only skip, 287 assertions. The complete final suite result below includes the added unit test.

### Purge RED and GREEN

RED:

```powershell
php -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Console/PurgeCartItemSecretsTest.php --stop-on-failure
```

Result: the command test errored because `cart-secrets:purge` did not exist, and the schedule assertion failed because no hourly event existed.

GREEN:

```powershell
php -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Console/PurgeCartItemSecretsTest.php
```

Result: 2 passed, 13 assertions.

### Fingerprint unit RED and GREEN

RED:

```powershell
php -d extension=openssl -d extension=mbstring vendor\bin\pest tests/Unit/Security/CoinsCartFingerprintTest.php --stop-on-failure
```

Result: 1 error, 0 assertions because `App\Security\CoinsCartFingerprint` did not exist.

GREEN:

```powershell
php -d extension=openssl -d extension=mbstring vendor\bin\pest tests/Unit/Security/CoinsCartFingerprintTest.php
```

Result: 1 passed, 3 assertions.

## Implemented security and data boundaries

- `cart_item_secrets.encrypted_payload` is nullable `LONGTEXT`, encrypted through Laravel's `encrypted:array` cast, hidden from serialization, and guarded from ordinary mass assignment.
- `masked_summary` contains only a masked email, `has_password`, and `backup_code_count`; purge nulls both ciphertext and masked PII and sets `deleted_at`.
- `cart_item_id` is unique and cascading, enforcing one secret per line and removing the secret with its cart item.
- `carts.active_owner_key` is nullable and unique. Only the active authenticated SAR cart receives `user:<id>`, so concurrent first additions cannot create two authoritative active carts.
- Every valid submission creates a distinct `quantity = 1` line because one credential set belongs to one service line.
- `cart_items.configuration` contains only service type, platform, market, delivery, Coins quantity, quote timestamp, and server price version. Cart and line authority remain SAR.
- The POST routes are web/CSRF routes with `auth`, named per-user `throttle:coins-cart`, and `Cache-Control: no-store`. Exception finalization adds no-store to auth, throttle, and CSRF failures on the credential route.
- The request excludes the credential route from automatic trimming, then normalizes only email and backup codes. The EA password is opaque and remains unchanged.
- The request accepts exactly five distinct `/\A[0-9]{8}\z/D` backup codes and rejects unknown top-level/nested fields and all client price/product/variant authority.
- The action calls the existing `QuoteCoins` inside the same transaction as idempotency claim, cart resolution, safe item creation, encrypted secret creation, and safe response persistence.
- The idempotency fingerprint is HMAC-SHA-256 over canonical user ID and every accepted request field, keyed by the application key. Same user/key/payload replays the stored safe response; a different payload or user returns 409.
- `IdempotencyKey.request_hash` and `response_body` are both hidden and guarded. The action uses constrained raw/forced writes; response JSON never contains credentials.
- Resume validation runs before `auth` in middleware priority, so an unsafe query is rejected before Laravel can store it as `url.intended`. Only platform, delivery, and quantity are allowed.
- Cart reads never decrypt the secret payload. They inspect raw ciphertext presence and expose only safe line data plus `requiresCredentials`.
- `cart-secrets:purge` is idempotent, leaves safe cart lines intact, defaults to a 24-hour TTL, and is scheduled hourly with overlap protection.

## SQLite migration lifecycle

Used an isolated SQLite file under the workspace, then removed it after verification:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate:fresh --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate:rollback --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate:status
```

Exact result: fresh migration succeeded for all migrations; rollback succeeded for all migrations; remigration succeeded; final status reported every migration, including `2026_08_10_000001_add_secure_cart_storage`, as `Ran`.

## MariaDB lifecycle and concurrency

Started an isolated local MariaDB 12.3.2 instance on `127.0.0.1:3307` with an ephemeral database and password. The password is intentionally omitted from this repository report.

With `DB_CONNECTION=mysql`, host `127.0.0.1`, port `3307`, database `arab_ut_task5`, and the ephemeral credentials:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql artisan migrate:fresh --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql artisan migrate:rollback --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql artisan migrate --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql artisan migrate:status
```

Exact lifecycle result: all migrations migrated fresh, all rolled back, all remigrated, and final status reported every migration as `Ran`. MariaDB schema/security tests separately passed 4 tests and 18 assertions during the lifecycle verification.

Concurrency was exercised with two independent PHP processes adding the first Coins line for the same user with different idempotency keys. Exact result: 1 test passed, 5 assertions; one active cart, two distinct cart items, and two secret records.

Final MariaDB focused command:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql vendor\bin\pest tests/Feature/Database/CartSecuritySchemaTest.php tests/Feature/Store/CoinsCartTest.php tests/Feature/Console/PurgeCartItemSecretsTest.php tests/Feature/Store/CoinsQuoteTest.php tests/Integration/CoinsCartConcurrencyTest.php tests/Unit/Security/CoinsCartFingerprintTest.php
```

Final result: 82 tests, 81 passed, 1 skipped, 425 assertions. The single MariaDB skip is the deliberately SQLite-specific trigger used to force a secret-write failure; MariaDB instead runs the real independent-process concurrency test. SQLite runs the rollback trigger test and skips only the MariaDB locking test.

## Security and leak checks

- Production synthetic-sentinel scan across `app`, `bootstrap`, `config`, `database`, `lang`, and `routes`: **0 matches**.
- Task 5 credential-path scan for `localStorage`, `sessionStorage`, `withInput`, direct session `put`/`flash`, `Log::`, and `logger(...)`: **0 matches**.
- Feature assertions prove synthetic password/code/email sentinels are absent from guest/auth/validation/conflict responses, session serialization, cart configuration, Inertia response content, exception messages, raw ciphertext, and persisted idempotency response bodies.
- Raw database assertions prove encrypted payload ciphertext does not contain password or backup-code fragments.
- Route inspection reports both base and localized POST routes with `web`, `NoStore`, `auth`, and `throttle:coins-cart`; resume routes report `NoStore`, `ValidateCoinsCartResume`, then `auth`.
- Schedule inspection reports `0 * * * * php artisan cart-secrets:purge`.
- `git diff --check` produced no output.

## Final PHP gates

The shell did not have Composer on `PATH`, so Composer 2.10.2 was fetched temporarily from the official `getcomposer.org` distribution, used only for validation, and removed.

```powershell
php composer-task5.phar validate --strict --no-check-publish
```

Result: `./composer.json is valid`.

```powershell
php -d extension=mbstring vendor\bin\pint --test
```

Result: passed.

```powershell
php -d extension=mbstring vendor\bin\phpstan analyse --memory-limit=1G
```

Result: passed, 0 errors.

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest
```

Final result: 260 tests, 257 passed, 3 skipped, 2,350 assertions. The skips are environment/feature gated; Task 5's SQLite skip is the MariaDB-only concurrency test.

## Guard passes and self-review

### Clean Code Guard

- Re-read all production changes and neighboring repository patterns.
- Removed duplicated Coins selection validation by sharing `CoinsSelectionRules` across quote, cart, and resume.
- Split quote summary, fingerprinting, claim completion, and request quoting into named functions; all functions have at most four parameters.
- Replaced generic identifiers with domain-specific names and removed a redundant Cart cast.
- Catches remain limited to recoverable `IdempotencyConflict`, pricing `DomainException|ValueError`, and replay JSON corruption `JsonException`; storage failures propagate and roll back.
- No speculative payment/checkout/fulfillment abstractions or mock fallbacks were added.

### Test Guard

- Tests assert behavior and real database state rather than internal calls.
- No internal service mocks are used. SQLite and MariaDB schema behavior use real migrations and databases.
- Mode and validation variants use Pest datasets.
- Added focused assertions for exact allowlists, opaque password preservation, five-code ASCII/distinct rules, user-bound idempotency, server repricing, no-store errors, transaction rollback, purge re-entry state, and secret leakage.
- The external-process concurrency test is limited to the MariaDB system boundary it exists to verify.

### Docs Guard

- Every route name, command name, config key, middleware name, migration, class, file path, and test command in this report was checked against the final source or fresh command output.
- No performance, compatibility, or production-readiness claim is made beyond observed evidence.
- Upstream Laravel behavior is linked to official documentation instead of reproduced as a local tutorial.

## Owned-file scope

Created:

- `app/Actions/Cart/AddCoinsToCart.php`
- `app/Console/Commands/PurgeCartItemSecrets.php`
- `app/Exceptions/IdempotencyConflict.php`
- `app/Http/Controllers/Store/CartController.php`
- `app/Http/Controllers/Store/CoinsCartController.php`
- `app/Http/Controllers/Store/CoinsCartResumeController.php`
- `app/Http/Middleware/NoStore.php`
- `app/Http/Middleware/ValidateCoinsCartResume.php`
- `app/Http/Requests/Store/CoinsCartRequest.php`
- `app/Models/CartItemSecret.php`
- `app/Security/CoinsCartFingerprint.php`
- `app/Validation/CoinsSelectionRules.php`
- `database/migrations/2026_08_10_000001_add_secure_cart_storage.php`
- `tests/Feature/Console/PurgeCartItemSecretsTest.php`
- `tests/Feature/Database/CartSecuritySchemaTest.php`
- `tests/Feature/Store/CoinsCartTest.php`
- `tests/Integration/CoinsCartConcurrencyTest.php`
- `tests/Support/ConcurrentCoinsCartAdd.php`
- `tests/Unit/Security/CoinsCartFingerprintTest.php`
- `.superpowers/sdd/2026-08-10-wordpress-header-footer-parity/task-5-report.md`

Modified:

- `.env.example`
- `app/Http/Requests/Store/CoinsQuoteRequest.php`
- `app/Models/CartItem.php`
- `app/Models/IdempotencyKey.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `config/coins.php`
- `lang/ar/store.php`
- `lang/en/store.php`
- `routes/console.php`
- `routes/web.php`

No historical migration, frontend component, payment, order, fulfillment, or staff-secret-access file was edited.

## Concerns and handoff notes

- The local Scoop PHP runtime has OpenSSL, mbstring, SQLite PDO, and MySQL PDO DLLs installed but disabled by default. Verification commands therefore enabled them explicitly with `-d extension=...`. Deployment PHP must have Laravel's required extensions enabled normally.
- Task 6 must consume the safe cart props, add credential/cart UI, and clear client credential memory after successful submission. No browser credential persistence exists in Task 5.
- A future cart-closing/checkout action must clear `active_owner_key` when an active cart becomes historical and must implement the separately scoped secret handoff; neither behavior belongs to Task 5.
- No current implementation blocker or unverified database claim remains.

---

## Fix round 1/5: secure boundary hardening

### Outcome

Addressed all four review findings without expanding Task 5 into UI, checkout, payment, fulfillment, order creation, or staff secret access:

- the one-active-authenticated-SAR-cart invariant is now derived and enforced by each supported database rather than trusted from application input;
- both Coins POST paths require JSON before authentication and always use safe, no-store JSON exception rendering;
- cart configuration is projected through an explicit safe-key allowlist;
- two real MariaDB processes now verify same-key replay as well as the existing different-key first-cart race.

### Official documentation consulted in the fix round

The following current primary documentation was fetched and checked against the implementation:

- Laravel 13 error handling, including `shouldRenderJsonWhen` and exception response finalization: <https://laravel.com/docs/13.x/errors>
- Laravel 13 request content types and JSON input: <https://laravel.com/docs/13.x/requests>
- Laravel 13 middleware ordering: <https://laravel.com/docs/13.x/middleware>
- MariaDB generated columns, including `STORED`, indexed generated columns, and explicit-value behavior: <https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns>
- SQLite indexes on expressions: <https://www.sqlite.org/expridx.html>
- SQLite `CREATE TRIGGER`, `NEW`/`OLD`, `WHEN`, and the recommendation to prefer `AFTER` triggers: <https://www.sqlite.org/lang_createtrigger.html>

These sources support the chosen mechanisms: a MariaDB indexed `STORED GENERATED` column, a SQLite unique expression index plus row triggers, JSON exception selection independent of the request `Accept` header, and explicit middleware priority before authentication.

### Finding 1: database-derived active-cart invariant

Covering tests:

- `tests/Feature/Database/CartSecuritySchemaTest.php`
- `tests/Integration/ActiveCartInvariantUpgradeTest.php`
- the constrained application lookups remain exercised by `tests/Feature/Store/CoinsCartTest.php` and `tests/Integration/CoinsCartConcurrencyTest.php`

Focused schema RED:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Database/CartSecuritySchemaTest.php --filter "database derives" --stop-on-failure
```

Exact result: 1 test, 0 passed, 1 assertion. The directly inserted active SAR cart retained `NULL`; the test expected canonical `user:1`.

Legacy-upgrade RED:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Integration/ActiveCartInvariantUpgradeTest.php --stop-on-failure
```

Exact result: 2 tests, 0 passed. The first errored because `2026_08_10_000002_enforce_active_cart_invariant.php` did not exist; the second did not receive the required duplicate-active-cart migration failure.

Focused GREEN across the invariant, JSON, and projection regressions:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Database/CartSecuritySchemaTest.php tests/Integration/ActiveCartInvariantUpgradeTest.php tests/Feature/Store/CoinsCartTest.php --filter="database derives|invariant migration|non JSON|unexpected debug|cart reads" --stop-on-failure
```

Exact result after adding canonical/localized symmetry coverage: 10 tests passed, 94 assertions.

Implementation policy and mechanism:

- upgrades first query for more than one authenticated `active` `SAR` cart per user and fail with `Cannot enforce active cart invariant: duplicate active authenticated SAR carts exist.` rather than silently selecting or deleting one;
- valid historical carts, guest carts, and non-SAR carts are preserved and receive a `NULL` active-owner key;
- SQLite backfills existing rows, enforces uniqueness through `carts_one_active_authenticated_sar`, and derives the compatibility key with `AFTER INSERT`/`AFTER UPDATE` triggers;
- MariaDB backfills existing rows and converts `active_owner_key` to an indexed `STORED GENERATED` expression over `user_id`, `status`, and `currency`;
- `Cart::activeForUser()` and both application readers constrain `user_id`, `status = active`, `currency = SAR`, and the canonical derived key.

### Finding 2: JSON-only, leak-safe credential endpoint

Covering test: `tests/Feature/Store/CoinsCartTest.php`.

Non-JSON RED:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter "non JSON" --stop-on-failure
```

Exact failure: the first guest data set returned a 302 HTML redirect instead of the required 415 JSON response.

Real-handler debug RED:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter "unexpected debug" --stop-on-failure
```

Exact failure: the real failing secret insert produced `text/html; charset=utf-8` under debug instead of generic JSON, exposing Laravel's debug rendering path to the submitted request.

GREEN is included in the 10-test/94-assertion focused command above. The regression runs both `/cart/items/coins` and `/en/cart/items/coins`, with `Accept: text/html`, against the real handler and a transaction-failing secret insert. Non-JSON rejection also covers guest and authenticated callers on both path forms.

Implementation details:

- `RequireCoinsCartJson` rejects non-JSON requests with 415 before `auth`, including guests, and adds `Cache-Control: no-store`;
- exception selection forces JSON for the two POST path shapes regardless of `Accept`;
- unexpected 5xx exceptions on those sensitive paths are replaced by a generic localized `{error: {code, message}}` response with no-store;
- ordinary auth, CSRF, throttle, validation, conflict, and non-JSON responses remain JSON/no-store without reflecting submitted values.

### Finding 3: safe cart configuration projection

Covering test: `tests/Feature/Store/CoinsCartTest.php`, in `cart reads expose safe line metadata without decrypting credentials`.

Projection RED:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter "cart reads" --stop-on-failure
```

Exact failure: synthetic poison keys (`ea_password`, `backup_codes`, and nested supplier debug data) were returned unchanged through the Inertia cart configuration.

GREEN is included in the 10-test/94-assertion focused command above. The controller now returns only `service_type`, `platform`, `market`, `delivery`, `coins_quantity`, `quoted_at`, and `price_version`; poison values are absent from the response body.

### Finding 4: same-key MariaDB concurrency

Covering files:

- `tests/Integration/CoinsCartConcurrencyTest.php`
- `tests/Support/ConcurrentCoinsCartAdd.php`

The regression was authored before changing the child fixture to emit its safe action response. The clean real-MariaDB RED was captured against a freshly migrated database:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql vendor\bin\pest -c phpunit.mariadb.xml tests/Integration/CoinsCartConcurrencyTest.php --filter="same-key" --stop-on-failure
```

Exact result: 1 test, 0 passed, 4 assertions. Failure: `Expecting '' not to be ''` because the independent processes did not expose responses that could be compared.

After emitting only the action's already-safe response and explicitly propagating the active MariaDB connection settings to each child, the same command passed 1 test and 10 assertions.

Final combined different-key and same-key command:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql vendor\bin\pest -c phpunit.mariadb.xml tests/Integration/CoinsCartConcurrencyTest.php
```

Exact result: 2 tests passed, 16 assertions. Two processes with different keys produced one user cart and two credential-bound lines. Two processes with the same key/payload produced byte-identical non-empty safe responses, one user cart, one line, one secret, and one matching idempotency claim. The response did not contain the synthetic password sentinel. The fixture exits nonzero with a generic message if the action throws, so exception output cannot be mistaken for success or leak submitted credentials.

### SQLite migration lifecycle

An isolated `database/task5-fix-lifecycle.sqlite` file was created, verified, and removed after the run:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate:fresh --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate:rollback --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate --force
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate:status
```

Exact result: all 10 migrations migrated fresh, all 10 rolled back, all 10 remigrated, and status reported `2026_08_10_000002_enforce_active_cart_invariant` as `Ran` with every other migration.

The isolated SQLite legacy-upgrade tests separately passed both backfill/derivation and duplicate-detection scenarios.

### MariaDB lifecycle, upgrade, and duplicate evidence

An isolated MariaDB 12.3.2 server was initialized on `127.0.0.1:3307` with an ephemeral database and credential. The credential is intentionally omitted.

Lifecycle commands with the MariaDB environment and `pdo_mysql` enabled:

```powershell
php artisan migrate:fresh --force
php artisan migrate:rollback --step=1 --force
php artisan migrate --force
php artisan migrate:status
```

Exact result: all migrations migrated fresh; the invariant migration rolled back; it remigrated; final status reported every migration `Ran`, with the invariant migration in the new batch.

Legacy upgrade evidence was then captured by rolling back only the invariant migration, inserting one mismatched active SAR cart and one mismatched converted SAR cart for the same user, and remigrating. The final query returned:

```text
user_id  status     currency  active_owner_key
1        active     SAR       user:1
1        converted  SAR       NULL
```

`information_schema.COLUMNS.EXTRA` returned `STORED GENERATED` for `active_owner_key`.

For the duplicate policy, the invariant migration was rolled back again and a second active SAR cart with a `NULL` key was inserted for the same user. `artisan migrate --force` exited 1 and reported:

```text
Cannot enforce active cart invariant: duplicate active authenticated SAR carts exist.
```

The database was restored with `migrate:fresh` before the final MariaDB suites.

### Focused and full final test gates

SQLite Task 5-focused command:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Database/CartSecuritySchemaTest.php tests/Integration/ActiveCartInvariantUpgradeTest.php tests/Feature/Store/CoinsCartTest.php tests/Feature/Console/PurgeCartItemSecretsTest.php tests/Feature/Store/CoinsQuoteTest.php tests/Integration/CoinsCartConcurrencyTest.php tests/Unit/Security/CoinsCartFingerprintTest.php --stop-on-failure
```

Exact result: 92 tests, 90 passed, 2 MariaDB-only concurrency skips, 494 assertions.

MariaDB Task 5-focused command used `-c phpunit.mariadb.xml` and both PDO drivers so the isolated SQLite upgrade test could also run. Exact result: 92 tests, 89 passed, 3 skips, 486 assertions. The skips are the existing SQLite-only deterministic rollback trigger and the two SQLite transaction-safe debug-trigger data sets; both real MariaDB concurrency tests ran.

Full SQLite:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest
```

Exact result: 267 tests, 264 passed, 3 skipped, 2,419 assertions.

Full MariaDB:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_mysql vendor\bin\pest -c phpunit.mariadb.xml
```

Exact result: 267 tests, 261 passed, 6 skipped, 2,395 assertions. The MariaDB concurrency file was then run explicitly and passed 2 tests/16 assertions as recorded above because the project XML suites enumerate Unit and Feature directories only.

Final mechanical PHP gates:

```powershell
php -d extension=openssl composer-task5-fix.phar validate --strict --no-check-publish
php -d extension=mbstring vendor\bin\pint --test
php -d extension=mbstring vendor\bin\phpstan analyse --memory-limit=1G
git diff --check
```

Exact results: Composer 2 validation reported `./composer.json is valid`; Pint passed; PHPStan passed with 0 errors; `git diff --check` produced no output. The temporary official Composer PHAR was removed after validation.

### Security and leak checks

Final fixed-string production scan across `app`, `bootstrap`, `config`, `database`, `lang`, and `routes` for every synthetic password/code/poison sentinel: `production_sentinel_matches=0`.

Final credential-path scan across the changed request/action/controller/middleware/model/bootstrap/routes for `localStorage`, `sessionStorage`, `withInput`, direct log calls, session `put`/`flash`, and `$request->all`: `credential_path_matches=0`.

Behavioral assertions additionally prove:

- guest and authenticated non-JSON responses are generic JSON, no-store, and contain no credential sentinel;
- debug-enabled real-handler failures on both path forms contain only a generic error code/message and roll back carts and claims;
- poisoned cart configuration cannot cross the Inertia boundary;
- same-key process output is identical and contains no password sentinel;
- ciphertext/idempotency response protections from the original Task 5 suite remain green.

### Guard passes

Clean Code Guard:

- reviewed every changed production path against the 23 imperatives and the AI failure-mode checklist;
- no production catch-all, mock fallback, speculative configuration, unused symbol, second database pattern, or credential logging/persistence path was introduced;
- the new middleware, model scope, safe projector, and migration methods each have a single security responsibility;
- external calls and signatures were verified against installed Laravel 13.24.0 by successful Pint/PHPStan/tests and current official docs.

Test Guard:

- each new test maps to one reported regression; locale/auth variants use Pest data sets;
- no internal mocks or framework-call assertions were added;
- schema/upgrade behavior uses real SQLite migrations and actual MariaDB 12.3.2 storage;
- concurrency uses two independent PHP processes and verifies observable response plus persisted cart, line, secret, and claim state;
- the child fixture now receives the exact selected database environment and converts unexpected exceptions to a generic nonzero process failure, preventing false-positive success.

Docs Guard:

- every class, middleware, scope, route shape, migration, test file, CLI flag, result count, database mechanism, and config/file path in this fix-round section was checked against source or fresh command output;
- official upstream behavior is linked rather than copied into a local tutorial;
- no compatibility, performance, or production-readiness claim extends beyond observed Laravel 13.24.0, SQLite, and MariaDB 12.3.2 evidence.

### Fix-round owned-file scope

Created:

- `app/Http/Middleware/RequireCoinsCartJson.php`
- `database/migrations/2026_08_10_000002_enforce_active_cart_invariant.php`
- `tests/Integration/ActiveCartInvariantUpgradeTest.php`

Modified:

- `app/Actions/Cart/AddCoinsToCart.php`
- `app/Http/Controllers/Store/CartController.php`
- `app/Models/Cart.php`
- `bootstrap/app.php`
- `lang/ar/store.php`
- `lang/en/store.php`
- `routes/web.php`
- `tests/Feature/Console/PurgeCartItemSecretsTest.php`
- `tests/Feature/Database/CartSecuritySchemaTest.php`
- `tests/Feature/Store/CoinsCartTest.php`
- `tests/Integration/CoinsCartConcurrencyTest.php`
- `tests/Support/ConcurrentCoinsCartAdd.php`
- `.superpowers/sdd/2026-08-10-wordpress-header-footer-parity/task-5-report.md`

No Task 6 UI, checkout, payment, order, fulfillment, staff secret-access, historical migration, or unrelated file was edited.

### Fix-round self-review and concerns

- The invariant upgrade intentionally refuses ambiguous duplicate legacy active carts. Operations must resolve such data before deployment; silently selecting one could discard a legitimate cart.
- The SQLite forced-debug regression is skipped on MariaDB because its failure trigger is deliberately transaction-safe SQLite syntax; MariaDB instead runs the real generated-column schema tests, full endpoint suite, lifecycle upgrade, duplicate rejection, and both process-concurrency tests.
- Local PHP still requires explicit `-d extension=...` flags. Deployment PHP must enable Laravel's required OpenSSL, mbstring, and PDO driver extensions normally.
- The ephemeral MariaDB password and all synthetic credentials remain absent from this report and production files.
- No implementation blocker remains.

---

## Fix round 2/5: constrain safe cart configuration reads

### Scope and contract

Closed the remaining nested-configuration boundary finding without changing writes, routes, UI, pricing authority, or credential storage. `CartController` no longer returns allowlisted values merely because their top-level key is approved. Each output field is independently projected into this exact safe contract:

- `service_type`, `platform`, `market`, and `delivery`: a scalar string matching the corresponding application enum, or explicit `null`;
- `coins_quantity`: an integer from the configured 50,000 minimum through the configured 20,000,000 global maximum, in configured 10,000 increments;
- `quoted_at`: a scalar string that strictly parses as `DateTimeInterface::ATOM`;
- `price_version`: a positive integer.

Missing or invalid fields are omitted. Arrays, objects, nested maps/lists, unknown enum strings, numeric strings, invalid timestamps, and non-positive versions are never copied recursively to Inertia.

### TDD RED evidence

Covering test file: `tests/Feature/Store/CoinsCartTest.php`.

Compound/nested poison RED:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter="compound values" --stop-on-failure
```

Exact result: 1 test ran, 0 passed, 8 assertions. The first data set failed because `cart.items.0.configuration.service_type` was present when expected missing. The data-driven regression contains a nested array/object/map/list poison case for every previously allowed key: `service_type`, `platform`, `market`, `delivery`, `coins_quantity`, `quoted_at`, and `price_version`.

Invalid scalar RED:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter="invalid scalar values" --stop-on-failure
```

Exact result: 1 test ran, 0 passed, 8 assertions. The first data set failed because the unknown scalar service type `unknown-service` was returned instead of omitted. The remaining data sets cover a non-string platform, unknown market/delivery, numeric-string Coins quantity, invalid timestamp, and non-positive version.

Preservation baseline captured before the production edit:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter="preserve valid nullable summaries" --stop-on-failure
```

Exact result: 2 tests passed, 18 assertions. A valid PC cart with `delivery = null` retained the complete safe summary on both `/cart` and `/en/cart`.

### GREEN and final gates

Focused projection GREEN:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter="cart reads" --stop-on-failure
```

Exact result: 17 tests passed, 175 assertions.

Complete Coins cart feature file:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --stop-on-failure
```

Exact result: 45 tests passed, 452 assertions.

Combined cart/schema security focus:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php tests/Feature/Database/CartSecuritySchemaTest.php --stop-on-failure
```

Exact result: 50 tests passed, 476 assertions.

Full relevant PHP gate:

```powershell
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest
```

Exact result: 283 tests, 280 passed, 3 environment-gated skips, 2,558 assertions.

Mechanical gates:

```powershell
php -d extension=mbstring vendor\bin\pint --test
php -d extension=mbstring vendor\bin\phpstan analyse --memory-limit=1G
git diff --check
```

The first PHPStan run correctly rejected three new bare-array return annotations with `missingType.iterableValue`. The current official identifier documentation was consulted at <https://phpstan.org/error-identifiers/missingType.iterableValue>. A guard refinement then proved an aggregate optional-key shape was narrower than PHPStan could establish for dynamically keyed enum projection; <https://phpstan.org/error-identifiers/return.type> was consulted and the aggregate now declares its concrete `int|string|null` value union while field helpers retain specific shapes. Final result: Pint passed, PHPStan passed with 0 errors, and `git diff --check` produced no output.

### Security and leak checks

- Production scan for `Nested Configuration Poison Sentinel`, existing password/code poison sentinels, and backup-code fragments across `app`, `bootstrap`, `config`, `database`, `lang`, and `routes`: 0 matches.
- Changed-path scan for credential persistence/logging primitives (`localStorage`, `sessionStorage`, `withInput`, log calls, session `put`/`flash`, and `$request->all`): 0 matches.
- Response assertions prove the nested poison sentinel never appears in serialized Inertia output, while valid bilingual summaries remain byte-safe scalar/null structures.

### Guard passes

Clean Code Guard:

- all new functions stay within the 20-line target, use at most three parameters, and have one projection responsibility;
- one shared enum-field projector serves four present enum fields without speculative interfaces or configuration flags;
- no catches, logging, fallback success, recursive serializer, or mutation of stored cart data was introduced;
- enum values come from installed application enums, integer bounds come from existing Coins configuration, and timestamp parsing uses the installed PHP runtime API.

Test Guard:

- regressions assert real persisted JSON and the actual Inertia boundary with no mocks;
- the seven compound and seven invalid-scalar variants use Pest data sets rather than duplicate test bodies;
- expected output is literal and independently derived; each case catches a distinct allowed-key mutation;
- the Arabic/English preservation data set exercises both real route forms and explicit nullable delivery behavior.

Docs Guard:

- every field name, enum class, config key, timestamp format, route, test path, command, and result count in this section was checked against final source or fresh command output;
- the PHPStan behavior is linked to its primary identifier documentation;
- no compatibility, performance, or production-readiness claim was added.

### Owned files and concerns

Modified only:

- `app/Http/Controllers/Store/CartController.php`
- `tests/Feature/Store/CoinsCartTest.php`
- `.superpowers/sdd/2026-08-10-wordpress-header-footer-parity/task-5-report.md`

No UI, migration, route, request, action, credential storage, checkout, payment, order, fulfillment, or unrelated file was changed. No implementation concern or blocker remains.
