# Paylink checkout v1

Status: implemented; production remains fail-closed until the Hostinger environment is configured and a controlled Paylink pilot succeeds.

## Customer flow

1. The authenticated customer reviews an active SAR cart and accepts the store policies.
2. A customer without a verified mobile number verifies it with a six-digit Whapi WhatsApp code.
3. `POST /checkout/paylink` (or `/en/checkout/paylink`) snapshots the cart, its current authoritative prices, and the encrypted item credentials into one immutable pending order.
4. The server creates a Paylink hosted invoice and returns only an HTTPS `payment.paylink.sa` URL. Arab UT never receives card number, CVV, or cardholder-authentication data.
5. If Paylink is unavailable after the order is placed, the owner can resume that same pending payment from the order page; no second order or browser-stored credential is required.
   The owner can also cancel the unpaid order from that page (`POST /my-account/orders/{orderNumber}/cancel`), which asks Paylink `getInvoice` first (a paid invoice makes the order `received` instead), then closes the Paylink invoice at the gateway and only after that cancels locally and releases the wallet balance and coupon. If Paylink cannot be reached or refuses to close the invoice, nothing changes. An order still unpaid three hours after placement is handled the same way by the hourly `checkouts:expire-abandoned` job (owner decision, 2026-09-10). Starting a payment re-checks under the row lock that the order is still pending; an invoice raised for an order cancelled meanwhile is closed again and never attached.
6. The browser returns through `/payments/paylink/callback`. The callback is not payment proof: the server calls Paylink `getInvoice` and verifies transaction, order number, amount, currency, and status before accepting payment.
7. Paylink may also call `POST /api/payments/paylink/webhook`. That route authenticates the configured bearer token, then performs the same server-to-server invoice verification.
8. The first verified paid transition creates one secret-free `order.paid` outbox event for n8n. Duplicate callbacks and webhooks do not create a second event.

The checkout total and Paylink invoice are always SAR integer halalah. Display currency estimates never enter the payment contract. The application does not send `supportedCardBrands`, so it does not force or advertise installments; the methods enabled in the Paylink merchant account remain authoritative.

## Routes

| Method | Route | Access | Purpose |
|---|---|---|---|
| POST | `/checkout/phone/code` | authenticated | Send a Whapi verification code for the checkout phone |
| POST | `/checkout/phone/verify` | authenticated | Verify the code and persist the verified E.164 phone |
| POST | `/checkout/paylink` | authenticated | Place/replay the current order and start/reuse its Paylink invoice |
| POST | `/orders/{orderNumber}/payments/paylink` | pending-order owner | Start/reuse an existing order's invoice after a reload or provider outage |
| GET | `/payments/paylink/callback` | authenticated | Reconcile a returned Paylink transaction and show the order |
| GET | `/payments/paylink/cancel` | authenticated | Reconcile cancellation/pending state and show the order |
| GET | `/orders/{orderNumber}` | owner only | Safe order result; no EA credentials are rendered |
| POST | `/my-account/orders/{orderNumber}/cancel` | pending-order owner | Cancel an unpaid order, release wallet and coupon, close the invoice |
| POST | `/api/payments/paylink/webhook` | Paylink bearer token | Acknowledge only after authoritative invoice lookup |
| POST | `/admin/api/orders/{orderNumber}/refund` | admin (`can:orders.refund`) | One full original-method Paylink refund |

Localized customer routes use the same paths under `/en`. Sensitive JSON responses and order pages are `no-store`.

Order routes address an order by its public order number (`AUT-…`, or an imported `UT-…`). A legacy 26-character ULID in the same segment still resolves, and a safe (GET) request is permanently redirected (301) to the order-number URL; POST routes resolve either handle without a redirect. The internal `public_id` is never emitted in a generated order URL.

The checkout POST body is empty and requires an `Idempotency-Key` header containing 1–128 safe characters. Exact retries reuse the stored order/invoice; a changed request under the same key returns `409`.

The refund API accepts:

```json
{
  "amountHalalah": 1250,
  "reason": "Customer request."
}
```

Only the entire captured amount is accepted. A successful response exposes the local public refund ID, status, and amount, not the Partner API refund identifier. An operational equivalent is available as:

```powershell
php artisan payments:refund-paylink ORDER_PUBLIC_ID --reason="Customer request." --actor="admin@arab-ut.com"
```

Provider ambiguity or a mismatch is quarantined for manual review and is never automatically retried as a second refund.

## Paylink webhook contract

Configure the Paylink Portal Payment Webhook as:

- URL: `https://store.arab-ut.com/api/payments/paylink/webhook`
- Version: v2
- Custom header name: `Authorization`
- Custom header value: `Bearer <the same random PAYLINK_WEBHOOK_TOKEN stored on Hostinger>`

The webhook body may announce a state, but the application trusts only the subsequent Paylink `getInvoice` response. Unknown valid transaction numbers are acknowledged without revealing whether a local order exists.

## Paid-order n8n event

The scheduler publishes pending `order.paid` rows to `N8N_ORDER_PAID_URL`. Since
2026-09-14 the body is the **placement request** (schema version 2): the order's
identifiers plus one entry per automated item awaiting a supplier, each with its
configuration, the purchase budget, and the EA account. The full contract - body,
headers, signature, retry and acknowledgement rules - is in
[`n8n-fulfillment-v1.md`](n8n-fulfillment-v1.md) under *The placement request*.

Two things this file used to say no longer hold. The payload **does** carry the
customer's EA email, password and backup codes, by owner decision recorded in
`docs/decisions/2026-09-12-ea-credentials-in-placement-payload.md`; and no separate
credential boundary is planned. The outbox row in `integration_events` still holds
none of it - the account is read and decrypted at send time only.

## Official Paylink references

- [Authentication](https://developer.paylink.sa/docs/authentication)
- [Add invoice](https://developer.paylink.sa/docs/add-invoice)
- [Get invoice](https://developer.paylink.sa/docs/get-invoice)
- [Payment processing](https://developer.paylink.sa/docs/payment-processing)
- [Payment webhook](https://developer.paylink.sa/docs/payment-webhook)
- [Refund an order](https://developer.paylink.sa/docs/refund-an-order)
