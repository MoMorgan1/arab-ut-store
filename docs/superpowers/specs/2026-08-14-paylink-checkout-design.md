# Paylink Checkout Design

## Purpose

Add a real, bilingual checkout to Arab UT at `store.arab-ut.com`. Authenticated customers convert their current SAR cart into an immutable order, pay on Paylink's hosted payment page, and return to an order result page. The application accepts a payment only after a server-to-server Paylink invoice lookup proves that the merchant order number, transaction number, status, currency, and amount match.

## Approved product decisions

- Provider: Paylink.
- Merchant display name: Arab UT / عرب التيميت.
- Paylink account: fully activated.
- Settlement and authoritative checkout currency: SAR.
- Installment methods are excluded until the merchant activates and approves them.
- The hosted Paylink payment page owns card entry; Arab UT never receives PAN, CVV, or cardholder authentication data.
- Original-method refunds use an API boundary. Paylink's documented refund endpoint requires Partner credentials in addition to the merchant payment API ID and secret; refund requests must fail closed until those optional credentials are configured.
- Mohamed adds all secrets directly to Hostinger environment settings. No secret is committed, printed, returned to the browser, or pasted into chat.

## Current-state constraints

- The repository already has carts, encrypted cart-item credentials, orders, order items, payments, refunds, status history, integration events, and notification delivery tables.
- It does not currently have checkout routes, an order-placement action, a live payment adapter, a payment callback/webhook, an order confirmation page, or a payment-driven fulfillment notification.
- Login is required for checkout. Paylink requires a client name and mobile number. Checkout therefore requires a non-empty user phone; verified WhatsApp remains the source of that number.
- Display currencies are estimates only. The order and Paylink invoice remain SAR.

## Architecture

### 1. Provider-neutral payment boundary

`PaymentGateway` exposes create-invoice, fetch-invoice, cancel-invoice, and refund operations. `PaylinkPaymentGateway` is the only live implementation and uses Laravel's server-side HTTP client. `PaymentManager` returns the configured adapter and refuses live checkout when required Paylink configuration is absent.

The Paylink access token is cached for less than its documented lifetime. All requests use HTTPS, JSON, connect/read timeouts, and bounded retries only for connection failures and safe reads. Responses are parsed into strict value objects before business code can use them.

### 2. Transactional order placement

`PlaceOrder` accepts the authenticated user, locale, and an idempotency key. Inside a retryable database transaction it:

1. locks the active user cart and its items;
2. rejects an empty cart, missing credentials, missing verified phone, non-SAR cart, inactive/missing variant, or changed authoritative price;
3. snapshots product names, SKU, platform, configuration, and exact SAR amounts into order items;
4. copies each encrypted credential payload into an order-item secret without serializing it into logs or responses;
5. creates a pending Paylink payment record and pending status history;
6. marks the cart converted only after the entire snapshot succeeds.

The idempotency key maps one authenticated customer request to one order. An exact retry returns the same pending order; a conflicting payload is rejected.

### 3. Payment initiation

After the order transaction commits, `StartPaylinkPayment` creates the Paylink invoice server-side with:

- the immutable order number and SAR total;
- customer name, email, and verified mobile;
- digital product summaries without EA passwords or backup codes;
- Arabic/English callback and cancel URLs;
- no installment brands forced in the payload, so only methods activated in the Paylink merchant account appear.

The browser receives only Paylink's HTTPS payment URL and redirects there. The Paylink transaction number is stored on the payment row. Retrying a known pending payment reuses its saved URL while valid; it never creates a second local order.

### 4. Callback and webhook verification

The browser callback is not proof of payment. Both callback and Payment Webhook converge on `ConfirmPaylinkPayment`, which calls Paylink `GET /api/getInvoice/{transactionNo}` and requires:

- exact transaction number;
- exact Arab UT order number;
- `Paid` status;
- exact SAR amount at halalah precision.

The action locks the payment and order, then idempotently moves payment to `paid`, captured amount to the exact total, order/items to `received`, and records paid timestamps/history. Mismatched or pending responses never release fulfillment.

The webhook is a CSRF-exempt API route protected with a high-entropy merchant-configured authorization header and rate limiting. It acknowledges duplicate valid events with 200 and does not expose whether unrelated order numbers exist.

### 5. Fulfillment and WhatsApp

The first successful paid transition writes a durable `order.paid` integration event in the same database transaction. A queued publisher posts a secret-free order envelope to the configured n8n endpoint using an HMAC signature and idempotency key. n8n owns downstream fulfillment and customer WhatsApp order messaging. Existing Whapi login-code delivery remains unchanged and covered by its current tests.

The paid event contains public order/item IDs, service/platform/quantity, SAR totals, customer locale, and a scoped reference for later authorized secret retrieval. It never contains EA passwords or backup codes.

### 6. Checkout UI

The cart gains a gold Checkout action when it has eligible items. `/checkout` and `/en/checkout` use the existing black/gold storefront shell and Thmanyah typography. The page shows:

- customer name, email, and verified WhatsApp number;
- immutable SAR line items and total;
- separate underlined Terms and Warranty links;
- required policy consent;
- a Pay securely with Paylink button;
- clear validation, unavailable-provider, retry, redirecting, cancel, and paid states.

No card fields are rendered by Arab UT. Controls are at least 44px, keyboard operable, RTL/LTR safe, responsive at 320px and above, and motion respects `prefers-reduced-motion`.

### 7. Refund boundary

An authenticated admin-only JSON route creates one refund request against a paid Paylink payment. It validates the amount against captured minus already-refunded funds and uses deterministic idempotency. Successful Paylink confirmation updates the refund, payment, order, and item statuses consistently. Without Partner refund credentials the route returns a stable `503 refund_provider_unavailable`; it never marks money refunded locally.

## Configuration

Required for payments:

```text
PAYLINK_ENV=testing|production
PAYLINK_API_ID=
PAYLINK_SECRET_KEY=
PAYLINK_WEBHOOK_TOKEN=
```

Optional, required only for original-method refund API:

```text
PAYLINK_PARTNER_PROFILE_NO=
PAYLINK_PARTNER_API_KEY=
PAYLINK_MERCHANT_LOOKUP_KEY=email|mobile|accountNo|cr|freelancer
PAYLINK_MERCHANT_LOOKUP_VALUE=
```

Required for paid-order handoff:

```text
N8N_ORDER_PAID_URL=
N8N_ORDER_PAID_SECRET=
```

## Failure and recovery rules

- Missing Paylink config disables payment initiation with a truthful 503; it never falls back to fake success.
- A Paylink timeout leaves the local order pending and retryable.
- A callback/webhook mismatch is recorded as a sanitized error and returns no fulfillment side effect.
- Duplicate callbacks, webhooks, and paid events are idempotent.
- Paylink's documented 24-hour invoice expiry is represented as a cancelled payment; the customer can request a fresh payment attempt for the same still-pending order.
- Logs and provider metadata contain transaction/status/error codes only, never API secrets, authorization tokens, customer credentials, or full webhook authorization headers.

## Verification

- Pest feature tests cover checkout ownership, snapshot accuracy, credentials copying, idempotency, price changes, missing phone, provider-unavailable behavior, Paylink request/response contracts, callback/webhook mismatch, duplicate paid confirmation, refund gating, and secret redaction.
- Vitest covers the real checkout page flow, AR/EN copy, consent, error focus, double-submit locking, redirect behavior, and mobile layout contracts.
- Existing WhatsApp login-code tests remain green.
- Full Composer and frontend CI, migration lifecycle, route inspection, and a browser matrix for AR/EN at 320/390/768/1440 are required before deployment.

## External references

- Paylink authentication: https://developer.paylink.sa/docs/authentication
- Paylink add invoice: https://developer.paylink.sa/docs/add-invoice
- Paylink get invoice: https://developer.paylink.sa/docs/get-invoice
- Paylink payment processing: https://developer.paylink.sa/docs/payment-processing
- Paylink payment webhook: https://developer.paylink.sa/docs/payment-webhook
- Paylink refund endpoint: https://developer.paylink.sa/docs/refund-an-order
- Laravel 13 HTTP client: https://laravel.com/docs/13.x/http-client
- Laravel 13 database transactions: https://laravel.com/docs/13.x/database#database-transactions
- Laravel 13 CSRF webhook guidance: https://laravel.com/docs/13.x/csrf#excluding-uris-from-csrf-protection
