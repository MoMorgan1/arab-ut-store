# Arab UT MVP Implementation Plan

> **For implementer:** REQUIRED SUB-SKILL: Use `superpowers:test-driven-development` for every implementation task. Use `superpowers:verification-before-completion` before claiming any milestone complete.

**Goal:** Replace WordPress/WooCommerce with a tested bilingual Arab UT ecommerce application that runs on the current Hostinger PHP/MariaDB environment and integrates safely with the existing n8n workflows.

**Architecture:** One Laravel 13 monolith owns commerce state and renders React 19/TypeScript pages through Inertia 3. MariaDB stores authoritative records; database-backed jobs/outbox provide durable background work on shared hosting; n8n remains the external supplier and WhatsApp orchestrator. Every order item has independent configuration, temporary encrypted secrets, and fulfillment state. Automation uses scoped versioned APIs and stable IDs.

**Approved target tech stack:** PHP 8.3, Laravel 13, Laravel Fortify, planned Task 3 integration of Laravel Socialite, MariaDB, React 19, TypeScript, Inertia 3, Tailwind CSS 4, Pest, Vite, GitHub Actions, Hostinger, and the existing n8n/Whapi/FFT/UTT services. Task 6 will select and lock a compatible `tecnickcom/tc-lib-pdf` version with an embedded Arabic-capable font.

**Status:** Approved by Mohamed on 2026-08-09 and in execution. A live payment adapter remains a separately authorized milestone.

## Build rules

- At the start of each task, recheck the current official documentation and installed package versions; pin compatible versions in lock files.
- Read the files being changed before editing them.
- Write the smallest failing test first, run it to confirm the intended failure, implement the behavior, and run the focused test again.
- Run the full backend, frontend type, lint, and build checks before every milestone handoff.
- Use integer halalas for authoritative money; never floating-point SAR values.
- Never place account credentials, provider keys, personal contacts, or production identifiers in fixtures, logs, screenshots, Git history, or documentation.
- Do not enable real supplier charges, WhatsApp customer messages, analytics trackers, or payment calls from local/staging tests.
- Commit after each task with the indicated narrow commit message.

## Planned core contracts

The names below are planned interfaces, not claims about files that already exist.

```php
enum ServiceType: string
{
    case Coins = 'coins';
    case Sbc = 'sbc';
    case Objectives = 'objectives';
    case Rivals = 'rivals';
    case FutChampions = 'fut_champions';
}

enum Platform: string
{
    case PlayStation = 'playstation';
    case Xbox = 'xbox';
    case Pc = 'pc';

    public function market(): Market
    {
        return $this === self::Pc ? Market::Pc : Market::Console;
    }
}
```

```php
interface PaymentGateway
{
    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoice;
    public function getInvoice(string $transactionNo): PaymentInvoice;
    public function cancelInvoice(string $transactionNo): void;
    public function refund(string $orderNumber, string $reason): RefundResult;
}
```

Mohamed authorized Paylink on 2026-08-14. `PaylinkPaymentGateway` is now the concrete hosted-checkout adapter; missing or invalid credentials still fail closed, and production acceptance remains gated on direct Hostinger configuration plus controlled pilots.

```php
interface FulfillmentEventPublisher
{
    public function publishReady(FulfillmentJob $job): void;
}

interface OrderItemSecretVault
{
    public function store(OrderItem $item, array $validatedFields): void;
    public function revealForAuthorizedFulfillment(OrderItem $item, User $actor): array;
    public function purge(OrderItem $item, string $reason): void;
}
```

## Task 1: Create the repository and verified application foundation

**Files:**

- Create: private GitHub repository `momorgan1/arab-ut-store`
- Create: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`
- Create: `.env.example`
- Create: `.editorconfig`, `.gitattributes`, `.gitignore`
- Create: `phpunit.xml`
- Create: `app/Http/Middleware/SetLocale.php`
- Create: `app/Http/Middleware/SetDisplayCurrency.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Modify: `resources/js/app.tsx`
- Create: `resources/js/layouts/store-layout.tsx`
- Create: `resources/js/styles/tokens.css`
- Create: `lang/ar/ui.php`, `lang/en/ui.php`
- Test: `tests/Feature/Foundation/HealthTest.php`
- Test: `tests/Feature/Foundation/LocaleTest.php`
- Test: `resources/js/__tests__/store-layout.test.tsx`

**Steps:**

- [x] Create the private repository after confirming Mohamed is signed into `momorgan1`.
- [ ] Enable branch protection after the first passing GitHub workflow.
- [x] Generate Laravel 13 with the official React/Inertia starter and Pest, then record the exact resolved versions in both lock files.
- [x] Write failing tests for a healthy application response, Arabic default locale/RTL metadata, English locale/LTR metadata, and persisted display-currency preference.
- [x] Implement the locale/currency middleware, bilingual route prefix behavior, base store layout, and the first dark store tokens; Task 5 later refined them to the approved WordPress-continuous warm palette.
- [x] Add scripts for `composer test`, PHP formatting/static checks selected during bootstrap, TypeScript checking, frontend tests, lint, and `npm run build`.
- [x] Run the focused tests, full test suites, and production asset build.
- [x] Commit: `chore: bootstrap bilingual Arab UT application`

## Task 2: Establish the authoritative schema and domain vocabulary

**Files:**

- Create: `app/Enums/ServiceType.php`, `Platform.php`, `Market.php`, `ProductAuthority.php`
- Create: `app/Enums/OrderStatus.php`, `OrderItemStatus.php`, `PaymentStatus.php`, `FulfillmentStatus.php`
- Create: `app/Enums/WalletEntryType.php`, `NotificationStatus.php`, `UserRole.php`
- Create: `app/Support/Money.php`
- Create: `database/migrations/2026_08_08_000001_create_identity_tables.php`
- Create: `database/migrations/2026_08_08_000002_create_catalog_tables.php`
- Create: `database/migrations/2026_08_08_000003_create_commerce_tables.php`
- Create: `database/migrations/2026_08_08_000004_create_fulfillment_tables.php`
- Create: `database/migrations/2026_08_08_000005_create_marketing_content_tables.php`
- Create: models under `app/Models/` matching the tables below
- Test: `tests/Unit/Support/MoneyTest.php`
- Test: `tests/Unit/Enums/PlatformTest.php`
- Test: `tests/Feature/Database/DomainSchemaTest.php`

**Required tables:**

- Identity: `users`, `social_accounts`, `phone_verifications`, `personal_access_tokens`, `staff_audit_logs`.
- Catalog: `categories`, `products`, `product_variants`, `product_media`, `catalog_sources`, `catalog_sync_runs`, `catalog_sync_items`, `price_rules`, `price_runs`, `price_proposals`, `price_history`.
- Commerce: `carts`, `cart_items`, `coupons`, `coupon_redemptions`, `orders`, `order_items`, `order_discounts`, `payments`, `refunds`, `wallet_accounts`, `wallet_entries`, `loyalty_tiers`, `order_status_history`, `receipts`.
- Fulfillment: `order_item_secrets`, `secret_access_logs`, `fulfillment_jobs`, `fulfillment_attempts`, `integration_events`, `notification_deliveries`, `idempotency_keys`.
- Content: `reviews`, `faq_entries`, `exchange_rates`.

**Steps:**

- [x] Write failing unit tests proving SAR arithmetic uses integer minor units and PlayStation/Xbox map to `console` while PC maps to `pc`.
- [x] Write a failing schema test for required foreign keys, unique source/SKU constraints, item-scoped fulfillment uniqueness, wallet ledger immutability support, and indexed lookup columns.
- [x] Implement backed enums, `Money`, migrations, casts, relationships, factories, and seed only non-sensitive reference data.
- [x] Store Arabic and English content in explicit `*_ar` and `*_en` columns; use public ULIDs alongside internal numeric primary keys.
- [x] Add database constraints for idempotency key uniqueness, `(source_id, external_id)` uniqueness, nonnegative money, and one wallet account per customer.
- [x] Run a clean migration, rollback/re-migrate, focused tests, and the full suite.
- [x] Commit: `feat: establish commerce domain schema`

## Task 3: Implement customer authentication, WhatsApp verification, and roles

**Files:**

- Modify: `app/Actions/Fortify/CreateNewUser.php`, `ResetUserPassword.php`
- Create: `app/Http/Controllers/Auth/GoogleAuthController.php`
- Create: `app/Http/Controllers/Auth/WhatsAppOtpController.php`
- Create: `app/Services/Auth/WhatsAppOtpService.php`
- Create: `app/Services/Auth/WhapiWhatsAppOtpService.php`
- Create: `app/Policies/AdminPolicy.php`, `OrderPolicy.php`, `OrderItemSecretPolicy.php`
- Create: `app/Http/Middleware/RequireVerifiedWhatsApp.php`
- Create: `resources/js/pages/auth/login.tsx`, `register.tsx`, `verify-whatsapp.tsx`, `forgot-password.tsx`, `reset-password.tsx`
- Modify: `routes/auth.php`, `routes/web.php`
- Test: `tests/Feature/Auth/RegistrationTest.php`, `GoogleAuthTest.php`, `WhatsAppOtpTest.php`, `PasswordResetTest.php`, `AuthorizationTest.php`

**Steps:**

- [ ] Write failing tests for first/last name, unique normalized email/phone, password hashing, Google account linking, OTP expiry/attempt limits, existing-phone OTP login, and checkout blocking until WhatsApp verification.
- [ ] Add rate-limit tests for login, OTP request, OTP verification, and password reset before implementing endpoints.
- [ ] Implement Fortify actions, Socialite Google callbacks, Whapi behind an interface, safe generic auth errors, session regeneration, and intended-checkout return.
- [ ] Implement `customer`, `staff`, and `admin` policies; prove staff can only read orders and cannot reveal secrets.
- [ ] Add imported-customer activation through the password-reset path without migrating passwords.
- [ ] Run auth/security tests with provider fakes, then the full suite.
- [ ] Commit: `feat: add customer authentication and role policies`

## Task 4: Build catalog, variants, media, pricing, and automation APIs

**Files:**

- Create: `app/Http/Controllers/Admin/CategoryController.php`, `ProductController.php`, `ProductVariantController.php`
- Create: `app/Http/Controllers/Api/V1/N8n/CatalogSyncController.php`, `PriceRunController.php`
- Create: `app/Http/Requests/Admin/SaveProductRequest.php`, `SaveVariantRequest.php`
- Create: `app/Http/Requests/Api/V1/CatalogSyncRequest.php`, `PriceRunRequest.php`
- Create: `app/Actions/Catalog/ReconcileCatalogSnapshot.php`, `ApplyPriceRun.php`, `ArchiveAutomatedProduct.php`
- Create: `app/Services/Media/ValidatedRemoteImageImporter.php`
- Create: `app/Http/Middleware/VerifyIntegrationSignature.php`
- Modify: `routes/api.php`, `routes/admin.php`
- Create: `resources/js/pages/admin/catalog/` pages and forms
- Test: `tests/Feature/Admin/CatalogManagementTest.php`, `AutomationOwnershipTest.php`
- Test: `tests/Feature/Api/N8n/CatalogSyncTest.php`, `PriceRunTest.php`, `IntegrationAuthenticationTest.php`

**Steps:**

- [ ] Write failing admin tests for bilingual category/product/variant CRUD, image validation, status, sort order, manual price changes, and the warning/confirmation when a SKU becomes automation-owned.
- [ ] Write failing API tests for signed requests, timestamp freshness, replay rejection, complete snapshot requirement, idempotent upsert by source ID, stable variant IDs, per-item results, optimistic price versions, and archived missing products.
- [ ] Implement catalog actions and scoped n8n authentication. Automation may overwrite synchronized fields; manual products without a linked source remain untouched.
- [ ] Validate remote images by HTTPS, allowed host, MIME type, dimensions, byte limit, and content hash before storing a controlled copy.
- [ ] Add sync-run and price-history admin views, including held anomalies and item failures. Hard delete remains a deliberate admin action after archive.
- [ ] Run catalog/API tests, media failure tests, full suite, and frontend build.
- [ ] Commit: `feat: add catalog administration and automation APIs`

## Task 5: Build the bilingual storefront and persistent service configurators

**Files:**

- Create: `app/Http/Controllers/Store/HomeController.php`, `CatalogController.php`, `ConfiguratorController.php`, `CartController.php`
- Create: `app/Actions/Cart/AddConfiguredItem.php`, `RepriceCart.php`
- Create: `app/Services/Configuration/ServiceConfigurationSchema.php`
- Create: `resources/js/pages/store/home.tsx`, `coins.tsx`, `sbc-index.tsx`, `sbc-show.tsx`, `objectives.tsx`, `rivals.tsx`, `fut-champions.tsx`, `cart.tsx`
- Create: `resources/js/components/configurator/`, `catalog/`, `cart/`, `shared/`
- Create: `resources/js/lib/i18n.ts`, `money.ts`, `direction.ts`
- Modify: `routes/web.php`
- Test: `tests/Feature/Store/ServiceAvailabilityTest.php`, `SbcCatalogTest.php`, `CartTest.php`, `CartRepricingTest.php`
- Test: frontend tests under `resources/js/__tests__/store/`

**Steps:**

- [ ] Write failing tests for the exact platform matrix, PlayStation/Xbox customer labels, shared console market, SBC search/filter mapping, Arabic/English content fallback, and hidden/unavailable variants.
- [ ] Write failing cart tests for multiple service items, durable configuration, edit/remove, server-authoritative repricing, stale-price notice, and login round-trip preservation.
- [ ] Implement config-driven service schemas so Coins, SBCs, Objectives, Rivals, and FUT Champions share primitives without becoming one generic form.
- [x] Build the bilingual Coins-first homepage and server-authoritative Coins quote configurator using the approved WordPress-continuous dark design system, with one combined `PS / Xbox` choice and one `PC` choice.
- [x] Add keyboard/focus/error/loading/unavailable states, RTL/LTR tests, responsive checks, and reduced-motion support for the Coins slice.
- [x] Run the Coins store and pricing tests, frontend tests, type checking, lint, and production build.
- [ ] Build the remaining SBC, Objectives, Rivals, FUT Champions, catalog, and cart experiences, then apply the same accessibility and verification gates to those slices.
- [ ] Commit: `feat: build storefront configurators and cart`

## Task 6: Implement checkout, orders, wallet, discounts, loyalty, and receipts

**Approved payment slice update (2026-08-14):** Paylink hosted checkout, verified callbacks/webhooks, reload-safe pending-order retry, full original-method refunds, Whapi checkout phone verification, and the signed `order.paid` outbox are implemented. Wallet, discounts, loyalty, receipts, customer tracking, and the broader admin experience remain incomplete, so Task 6 as a whole is still open.

**Files:**

- Create: `app/Contracts/Payments/PaymentGateway.php`
- Create: `app/Services/Payments/FakePaymentGateway.php`, `PaymentManager.php`
- Create: `app/Actions/Checkout/PlaceOrder.php`, `ConfirmPayment.php`
- Create: `app/Actions/Wallet/CreditWallet.php`, `DebitWallet.php`, `RefundToWallet.php`
- Create: `app/Services/Discounts/DiscountEngine.php`, `LoyaltyTierResolver.php`
- Create: `app/Services/Receipts/TcLibPdfReceiptRenderer.php`
- Create: `app/Http/Controllers/CheckoutController.php`, `PaymentWebhookController.php`, `ReceiptController.php`
- Create: `resources/js/pages/store/checkout.tsx`, `order-confirmation.tsx`
- Create: `resources/views/receipts/order.blade.php`
- Test: `tests/Feature/Checkout/PlaceOrderTest.php`, `MixedCartTest.php`, `PaymentBoundaryTest.php`
- Test: `tests/Feature/Wallet/WalletLedgerTest.php`, `DiscountTest.php`, `LoyaltyTest.php`, `ReceiptTest.php`

**Steps:**

- [ ] Write failing transaction tests proving one order stores every item/configuration, authoritative SAR snapshots, display currency/rate, discounts, wallet debit, and payment remainder atomically.
- [ ] Write failing tests for duplicate submit protection, price revalidation, failed order rollback, fake paid event idempotency, customer ownership, and disabled production checkout without a live gateway.
- [ ] Implement the payment contract and fake adapter only; do not create a real provider adapter or accept real payments.
- [ ] Implement an immutable wallet ledger with balance checked inside a database transaction and auditable admin credit/refund entries.
- [ ] Implement coupons, automatic quantity/cart discounts, configurable loyalty thresholds, and snapshots on orders so later rule edits do not rewrite history.
- [ ] Render bilingual non-VAT PDF receipts through `tecnickcom/tc-lib-pdf` with local fonts/assets only; test Arabic shaping/direction, SAR totals, and absence of VAT claims.
- [ ] Run commerce tests, PDF inspection fixture, full suite, and frontend build.
- [ ] Commit: `feat: add transactional checkout and commerce ledger`

## Task 7: Isolate and protect temporary account credentials

**Files:**

- Create: `app/Services/Secrets/EncryptedOrderItemSecretVault.php`
- Create: `app/Actions/Secrets/PurgeTerminalOrderSecrets.php`
- Create: `app/Http/Controllers/Admin/OrderItemSecretController.php`
- Create: `app/Jobs/PurgeExpiredOrderItemSecrets.php`
- Create: `app/Logging/RedactSensitiveContext.php`
- Modify: `routes/admin.php`, `routes/console.php`, `bootstrap/app.php`
- Create: `resources/js/components/orders/secret-reveal-panel.tsx`
- Test: `tests/Feature/Security/OrderItemSecretTest.php`, `SecretAuthorizationTest.php`, `SecretPurgeTest.php`, `LogRedactionTest.php`

**Steps:**

- [ ] Write failing tests proving plaintext values never appear in normal order serialization, database-readable casts, queue payloads, application logs, validation exceptions, analytics events, or staff responses.
- [ ] Write failing tests for admin/fulfillment-only reveal, password reconfirmation, one-time reason capture, access audit, and terminal-state purge.
- [ ] Implement per-item encrypted payloads behind the vault; keep schemas conditional by service/platform and send only the fields needed by the claimed fulfillment job.
- [ ] Add scheduled purge and immediate purge on completed/cancelled/refunded terminal transitions, retaining only field names and deletion audit metadata.
- [ ] Add masked admin UI and a prominent audit trail without exposing secrets to client page props until an authorized reveal request succeeds.
- [ ] Run security tests and inspect redacted logs/failed-job storage with synthetic values.
- [ ] Commit: `feat: protect temporary fulfillment credentials`

## Task 8: Implement durable fulfillment and n8n event contracts

**Files:**

- Create: `app/Actions/Fulfillment/CreateItemJobs.php`, `AggregateCoinAndSbcJobs.php`, `ProgressFulfillmentJob.php`, `DeriveOrderStatus.php`
- Create: `app/Jobs/PublishIntegrationEvent.php`, `RecoverStaleFulfillmentJobs.php`
- Create: `app/Http/Controllers/Api/V1/N8n/FulfillmentJobController.php`, `IntegrationEventController.php`
- Create: `app/Http/Resources/Api/V1/FulfillmentJobResource.php`
- Create: `docs/api/n8n-v1.md`
- Modify: `routes/api.php`, `routes/console.php`
- Test: `tests/Feature/Fulfillment/ItemJobCreationTest.php`, `MixedOrderStatusTest.php`, `CoinSbcAggregationTest.php`
- Test: `tests/Feature/Api/N8n/FulfillmentApiTest.php`, `WebhookReplayTest.php`, `OutboxRecoveryTest.php`

**Steps:**

- [ ] Write failing tests for one job per eligible item, unique supplier reference, deliberate Coins+SBC aggregation only when rules permit, and manual-service attention records.
- [ ] Write failing mixed-order tests for partial progress, waiting-for-customer, failure/retry, completion derivation, and no first-item assumptions.
- [ ] Define and test the signed versioned n8n contract: event ID, schema version, idempotency key, item/job IDs, explicit service/platform/market, money, customer language, scoped secret retrieval, and canonical result codes.
- [ ] Implement transactional outbox creation with orders, durable claims, replay-safe callbacks, bounded retry metadata, and stale-job recovery visibility.
- [ ] Adapt copies of the four n8n workflows against staging contracts; remove Salla IDs, Supabase primary writes, localized matching, and Google Sheets dependencies while retaining provider business logic.
- [ ] Exercise contract fixtures for duplicates, out-of-order callbacks, timeouts, partial supplier failure, Xbox-to-console mapping, and mixed carts without real supplier charges.
- [ ] Commit: `feat: add item scoped fulfillment contracts`

## Task 9: Implement status notifications, reviews, email, and support content

**Files:**

- Create: `app/Actions/Notifications/QueueOrderStatusNotifications.php`, `RecordWhatsAppDelivery.php`
- Create: `app/Notifications/OrderStatusMail.php`, `OrderReceiptMail.php`
- Create: `app/Http/Controllers/Api/V1/N8n/NotificationDeliveryController.php`, `ReviewSyncController.php`
- Create: `app/Http/Controllers/Store/ContentController.php`
- Create: `resources/js/pages/store/reviews.tsx`, `faq.tsx`, `legal.tsx`, `support.tsx`
- Create: bilingual notification/content files under `lang/ar/` and `lang/en/`
- Test: `tests/Feature/Notifications/OrderStatusNotificationTest.php`, `DeliveryCallbackTest.php`
- Test: `tests/Feature/Reviews/ReviewSyncTest.php`, `ReviewIntegrityTest.php`, `ContentLocalizationTest.php`

**Steps:**

- [ ] Write failing tests for one durable WhatsApp/email notification per canonical event, customer-language selection with Arabic fallback, retry after failure, and no localized status parsing.
- [ ] Implement n8n delivery callback storage with provider message ID/state and signed customer action URLs.
- [ ] Write failing review tests proving low ratings are not suppressed and `verified` requires source/order evidence.
- [ ] Implement idempotent n8n review import, storefront reviews, bilingual FAQ/policies/support, floating WhatsApp control, and email receipt/status notifications through a fake mailer in tests.
- [ ] Verify no secret fields enter any notification template or provider payload not explicitly required.
- [ ] Run notification/review/content tests and full suite.
- [ ] Commit: `feat: add honest reviews and durable notifications`

## Task 10: Complete customer and branded admin operations

**Files:**

- Create: `app/Http/Controllers/Account/OverviewController.php`, `OrderController.php`, `WalletController.php`, `ProfileController.php`
- Create: `app/Http/Controllers/Admin/DashboardController.php`, `OrderController.php`, `CustomerController.php`, `WalletAdjustmentController.php`, `CouponController.php`, `LoyaltyTierController.php`, `StaffController.php`
- Create: `app/Http/Controllers/Admin/ExchangeRateController.php`
- Create: `resources/js/layouts/account-layout.tsx`, `admin-layout.tsx`
- Create: pages under `resources/js/pages/account/` and `resources/js/pages/admin/`
- Create: `resources/js/components/orders/order-timeline.tsx`, `attention-badge.tsx`
- Modify: `routes/account.php`, `routes/admin.php`
- Test: `tests/Feature/Account/CustomerDashboardTest.php`, `OrderTrackingTest.php`, `ProfileSecurityTest.php`
- Test: `tests/Feature/Admin/DashboardTest.php`, `OrderOperationsTest.php`, `WalletAdjustmentTest.php`, `StaffAccessTest.php`

**Steps:**

- [ ] Write failing tests for customer ownership, overview metrics, order/item timeline, wallet history, receipt access, language/currency preferences, and support links.
- [ ] Write failing admin tests for revenue/status/customer metrics, attention queries, manual status transitions, refunds, customer history, wallet adjustment audit, coupon/loyalty configuration, editable display-currency rates, and read-only staff enforcement.
- [ ] Implement focused query/action classes so React pages receive deliberate view models instead of unrestricted model serialization. Keep supported display currencies configuration-driven and store manually approved rates with source/effective time; checkout accounting remains SAR.
- [ ] Build responsive Arabic/English customer and dark branded admin pages with loading, empty, success, validation, stale-data, and permission-denied states.
- [ ] Run authorization tests for every admin mutation and verify order-item secrets never enter initial page props.
- [ ] Run backend/frontend suites, type check, lint, production build, and browser smoke paths.
- [ ] Commit: `feat: complete customer and admin operations`

## Task 11: Add SEO, redirects, analytics boundaries, and historical importers

**Files:**

- Create: `config/legacy-redirects.php`, `config/analytics.php`, `config/currencies.php`
- Create: `app/Http/Middleware/LegacyRedirects.php`
- Create: `app/Services/Analytics/AnalyticsConsent.php`
- Create: `app/Console/Commands/ImportWordPressCatalog.php`, `ImportLegacyCustomers.php`, `ImportLegacyOrders.php`, `ImportLegacyWallets.php`
- Create: `app/Services/Migration/LegacyImportReport.php`
- Create: `resources/js/components/analytics/consent-manager.tsx`
- Create: `public/robots.txt`
- Create: sitemap route/action and canonical metadata components
- Test: `tests/Feature/Seo/RedirectTest.php`, `MetadataTest.php`, `SitemapTest.php`
- Test: `tests/Feature/Analytics/ConsentTest.php`
- Test: `tests/Feature/Migration/LegacyImportTest.php`, `ImportIdempotencyTest.php`

**Steps:**

- [ ] Export and inventory current WordPress routes, slugs, product/category URLs, page metadata, and media references read-only; classify preserve versus permanent redirect.
- [ ] Write failing redirect/canonical/sitemap/locale metadata tests and implement the route map without chains or loops.
- [ ] Write failing consent tests proving GA4, Meta Pixel, and TikTok Pixel scripts do not load before configured/allowed. Keep missing Meta/TikTok identifiers safely disabled.
- [ ] Build dry-run/idempotent import commands for products/media, customers without passwords, historical orders, and wallet opening entries; each outputs counts, rejects, reasons, and reconciliation totals.
- [ ] Run import rehearsals only on sanitized fixtures until Mohamed supplies the real export; never alter the current WordPress database.
- [ ] Commit: `feat: add migration seo and analytics boundaries`

## Task 12: Add CI, Hostinger staging deployment, verification, and cutover controls

**Files:**

- Create: `.github/workflows/ci.yml`
- Create: `.github/workflows/deploy-staging.yml`
- Create: `.github/workflows/deploy-production.yml`
- Create: `deploy/hostinger-release.ps1` or `deploy/hostinger-release.sh` according to the verified runner/host shell
- Create: `docs/operations/deployment.md`, `rollback.md`, `backup-restore.md`, `launch-checklist.md`
- Create: `tests/Browser/` critical-flow tests if Hostinger-compatible browser execution is available in CI
- Modify: `routes/console.php`

**Steps:**

- [ ] Make CI run Composer validation/security audit, backend tests, frontend tests, TypeScript, lint, and production asset build; upload only the verified build artifact.
- [ ] Implement staging deployment with environment-specific secrets, maintenance-safe migrations, release directories/symlink where Hostinger permits, scheduler/queue cron, health check, and failed-deploy abort.
- [ ] Implement a manually approved production workflow; never deploy production on every push.
- [ ] Document and rehearse database/files backup plus application rollback on staging. Schema changes that cannot roll back require a forward-fix rehearsal before production.
- [ ] Run browser acceptance in Arabic and English on mobile and desktop for browse, auth, configurator, cart, test checkout, customer tracking, admin catalog/order, and n8n test events.
- [ ] Run accessibility, performance, authorization, secret-redaction, duplicate-event, backup/restore, and redirect checks.
- [ ] Rehearse catalog/workflow migration with supplier writes and customer messages disabled; compare counts and totals.
- [ ] After Mohamed accepts staging and separately authorizes/configures the payment gateway, freeze WordPress writes, run final import, switch n8n endpoints, verify backups, change DNS/domain target, and monitor the first complete test order.
- [ ] Commit: `ci: add verified Hostinger delivery pipeline`

## Milestone acceptance gates

No milestone advances until all applicable checks pass:

- Focused and full automated tests are green.
- Type checking, linting, and production asset build are green.
- Mohamed can inspect the milestone on staging.
- Arabic RTL and English LTR paths work on mobile and desktop.
- Role and secret-access behavior is explicitly tested.
- No real external side effect occurred during tests.
- Documentation matches the implemented routes, schemas, commands, and environment variables.

## Final completion definition

The replacement is launch-ready only when the real payment provider has separately passed sandbox and signed-webhook testing, all four adapted n8n workflows pass duplicate/mixed-order/recovery tests, the historical import reconciles, rollback is rehearsed, production secrets are rotated, and Mohamed signs off on the bilingual storefront and admin workflows.
