# Paylink Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a complete authenticated Arab UT checkout that snapshots the SAR cart into an order, redirects to Paylink, verifies paid invoices server-to-server, emits a durable paid-order handoff, and supports safely gated original-method refunds.

**Architecture:** A provider-neutral payment contract isolates Paylink HTTP details from transactional checkout actions. Browser callback and authenticated webhook converge on one idempotent verifier; only a verified exact paid invoice changes order state and creates the n8n outbox event. React/Inertia renders a bilingual hosted-payment handoff without collecting card data.

**Tech Stack:** PHP 8.3+, Laravel 13.17 HTTP client/Eloquent/queues, MariaDB and SQLite tests, Inertia 3, React 19, TypeScript 5.7, Pest 4, Vitest 4.

**Spec:** `docs/superpowers/specs/2026-08-14-paylink-checkout-design.md`

## Global Constraints

- SAR is authoritative; Paylink and stored order/payment totals use integer halalah.
- No card fields or payment credentials enter Arab UT.
- No EA password or backup code enters logs, provider metadata, Paylink payloads, integration payloads, or page props.
- A callback or webhook is never payment proof without `getInvoice` verification.
- Missing secrets fail closed; tests use Laravel HTTP fakes and never call production Paylink.
- Installments are not forced or advertised.
- Work on the existing `main` checkout only because Mohamed explicitly requested local-main continuation.

---

### Task 1: Payment contracts and Paylink client

**Files:**
- Create: `app/Contracts/Payments/PaymentGateway.php`
- Create: `app/Payments/PaymentInvoice.php`
- Create: `app/Payments/PaymentInvoiceRequest.php`
- Create: `app/Payments/RefundResult.php`
- Create: `app/Services/Payments/PaylinkPaymentGateway.php`
- Create: `app/Services/Payments/PaymentManager.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Test: `tests/Feature/Payments/PaylinkPaymentGatewayTest.php`

**Interfaces:**
- `PaymentGateway::createInvoice(PaymentInvoiceRequest): PaymentInvoice`
- `PaymentGateway::getInvoice(string $transactionNo): PaymentInvoice`
- `PaymentGateway::cancelInvoice(string $transactionNo): void`
- `PaymentGateway::refund(string $orderNumber, string $reason): RefundResult`
- `PaymentManager::gateway(): PaymentGateway`

- [ ] Write HTTP-boundary tests with complete official Paylink response fixtures for auth, add invoice, get invoice, cancellation, malformed responses, timeout, and missing config.
- [ ] Run `php artisan test tests/Feature/Payments/PaylinkPaymentGatewayTest.php` and verify the missing classes/config fail for the expected reason.
- [ ] Implement strict DTO validation, token caching, exact test/production base URLs, server-side requests, and sanitized domain exceptions.
- [ ] Run the focused tests until green, then run scoped PHPStan and Pint.

### Task 2: Order snapshot and payment persistence

**Files:**
- Create: `app/Actions/Checkout/PlaceOrder.php`
- Create: `app/Actions/Checkout/CheckoutResult.php`
- Create: `app/Actions/Checkout/StartPaylinkPayment.php`
- Create: `app/Security/CheckoutFingerprint.php`
- Modify: `app/Models/Order.php`
- Modify: `app/Models/Payment.php`
- Test: `tests/Feature/Checkout/PlaceOrderTest.php`

**Interfaces:**
- `PlaceOrder::execute(User $user, string $locale, string $idempotencyKey): CheckoutResult`
- `StartPaylinkPayment::execute(Order $order): PaymentInvoice`

- [ ] Write transaction tests for exact cart snapshots, copied encrypted secrets, pending histories/payment, cart conversion, missing/invalid credentials, missing verified phone, price/version drift, ownership, empty cart, and exact/conflicting retry.
- [ ] Run the focused test and verify all new checkout scenarios are RED for missing behavior.
- [ ] Implement lock-first validation and one atomic snapshot transaction without external calls.
- [ ] Add payment initiation after commit and persist only safe Paylink identifiers/URLs/status metadata.
- [ ] Run the focused test until green and execute the existing cart/credential suites.

### Task 3: Payment confirmation, webhook, cancellation, and paid event

**Files:**
- Create: `app/Actions/Checkout/ConfirmPaylinkPayment.php`
- Create: `app/Actions/Checkout/CancelPaylinkPayment.php`
- Create: `app/Actions/Fulfillment/RecordPaidOrderEvent.php`
- Create: `app/Http/Controllers/Checkout/PaylinkCallbackController.php`
- Create: `app/Http/Controllers/Api/PaylinkPaymentWebhookController.php`
- Create: `app/Http/Middleware/VerifyPaylinkWebhook.php`
- Modify: `routes/web.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Checkout/PaylinkConfirmationTest.php`

**Interfaces:**
- `ConfirmPaylinkPayment::execute(string $orderNumber, string $transactionNo): Order`
- `RecordPaidOrderEvent::execute(Order $order): IntegrationEvent`

- [ ] Write RED tests for exact paid verification, pending/cancelled/mismatched amount/order/transaction, duplicate callbacks/webhooks, webhook authentication, no-store responses, and one durable event.
- [ ] Implement strict webhook middleware and shared confirmation action with row locks and idempotent transitions.
- [ ] Add browser callback redirect and JSON webhook acknowledgment without leaking order existence.
- [ ] Run focused confirmation tests, route inspection, and existing automation-security tests.

### Task 4: n8n paid-order publisher and Whapi login regression

**Files:**
- Create: `app/Jobs/PublishOrderPaidEvent.php`
- Create: `app/Actions/Fulfillment/BuildOrderPaidPayload.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `routes/console.php`
- Test: `tests/Feature/Fulfillment/PublishOrderPaidEventTest.php`
- Test: `tests/Feature/Auth/WhatsAppLoginTest.php`

**Interfaces:**
- `BuildOrderPaidPayload::execute(Order $order): array`
- `PublishOrderPaidEvent::handle(BuildOrderPaidPayload $builder): void`

- [ ] Write RED tests proving the signed n8n request is retryable/idempotent, contains only approved fields, omits all credential values, and records success/failure without losing the outbox event.
- [ ] Implement HMAC headers, bounded HTTP behavior, queue retry metadata, and stale-event schedule dispatch.
- [ ] Re-run WhatsApp login tests to prove Whapi codes still send and failures stay fail-closed.
- [ ] Run fulfillment/notification focused suites and scoped static checks.

### Task 5: Checkout and order-result UI

**Files:**
- Create: `app/Http/Controllers/Store/CheckoutController.php`
- Create: `app/Http/Controllers/Store/CheckoutPaymentController.php`
- Create: `app/Http/Controllers/Store/OrderConfirmationController.php`
- Create: `app/Http/Requests/Store/StartCheckoutRequest.php`
- Create: `resources/js/pages/store/checkout.tsx`
- Create: `resources/js/pages/store/order-confirmation.tsx`
- Create: `resources/js/lib/checkout-api.ts`
- Modify: `resources/js/pages/store/cart.tsx`
- Modify: `resources/js/types/store-shell.ts`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `resources/css/app.css`
- Modify: `routes/web.php`
- Test: `tests/Feature/Checkout/CheckoutRoutesTest.php`
- Test: `resources/js/__tests__/store/store-checkout.test.tsx`
- Test: `resources/js/__tests__/store/store-cart.test.tsx`

**Interfaces:**
- `POST /checkout/paylink` and `/en/checkout/paylink` accept JSON `{idempotencyKey, policyAccepted}` and return `{data:{paymentUrl, orderUrl}}`.
- Order confirmation is owner-only and exposes safe order/payment summaries.

- [ ] Write RED route/component tests for login, ownership, verified phone, cart link, totals, policy links/consent, double-submit lock, 422/503 focus, redirect URL validation, cancellation, and bilingual success/pending states.
- [ ] Implement safe Inertia props and the premium black/gold hosted-payment handoff using existing storefront tokens and Thmanyah fonts.
- [ ] Add responsive/RTL/LTR/reduced-motion CSS and ensure no card inputs exist.
- [ ] Run focused Pest/Vitest, TypeScript, ESLint, Prettier, and production build.

### Task 6: Admin refund API

**Files:**
- Create: `app/Actions/Payments/RefundPaylinkPayment.php`
- Create: `app/Http/Controllers/Admin/PaylinkRefundController.php`
- Create: `app/Http/Middleware/RequireAdmin.php`
- Create: `app/Http/Requests/Admin/RefundPaylinkRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Payments/PaylinkRefundTest.php`

**Interfaces:**
- `POST /admin/api/orders/{order}/refund` accepts JSON `{amountHalalah, reason}` under authenticated admin session and CSRF.
- The initial provider supports full original-method refunds only because Paylink's published partner endpoint refunds an order, not an arbitrary partial amount.

- [ ] Write RED tests for admin authorization, partner-config gating, unpaid/already-refunded orders, idempotency, provider failure rollback, and successful status transitions.
- [ ] Implement fail-closed Partner authentication/refund call and exact local reconciliation.
- [ ] Run focused payment/refund tests and role/security suites.

### Task 7: Documentation, migration lifecycle, and release verification

**Files:**
- Create: `docs/api/paylink-checkout-v1.md`
- Modify: `docs/product/discovery-record.md`
- Modify: `docs/product/v1-blueprint.md`
- Modify: `.env.example`

**Interfaces:**
- Hostinger receives only the documented environment keys; no real values enter Git or CI logs.

- [ ] Verify every documented route/config/status against source and `php artisan route:list`.
- [ ] Run checkout/payment/cart/auth focused suites, then full `composer ci:check` with the project PHP runtime.
- [ ] Run fresh migration, rollback/remigrate, and MariaDB payment/checkout selections.
- [ ] Run AR/EN browser checks at 320/390/768/1440 for checkout, callback result, keyboard focus, reduced motion, overflow, and console.
- [ ] Run secret scans, Clean Code/Test/Docs guards, `git diff --check`, and the requirement-by-requirement completion audit before commit/deploy.
