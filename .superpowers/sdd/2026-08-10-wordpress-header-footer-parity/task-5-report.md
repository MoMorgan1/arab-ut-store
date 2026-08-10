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
