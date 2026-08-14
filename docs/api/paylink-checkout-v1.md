# Paylink checkout v1

Status: implemented; production remains fail-closed until the Hostinger environment is configured and a controlled Paylink pilot succeeds.

## Customer flow

1. The authenticated customer reviews an active SAR cart and accepts the store policies.
2. A customer without a verified mobile number verifies it with a six-digit Whapi WhatsApp code.
3. `POST /checkout/paylink` (or `/en/checkout/paylink`) snapshots the cart, its current authoritative prices, and the encrypted item credentials into one immutable pending order.
4. The server creates a Paylink hosted invoice and returns only an HTTPS `payment.paylink.sa` URL. Arab UT never receives card number, CVV, or cardholder-authentication data.
5. If Paylink is unavailable after the order is placed, the owner can resume that same pending payment from the order page; no second order or browser-stored credential is required.
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
| POST | `/orders/{publicId}/payments/paylink` | pending-order owner | Start/reuse an existing order's invoice after a reload or provider outage |
| GET | `/payments/paylink/callback` | authenticated | Reconcile a returned Paylink transaction and show the order |
| GET | `/payments/paylink/cancel` | authenticated | Reconcile cancellation/pending state and show the order |
| GET | `/orders/{publicId}` | owner only | Safe order result; no EA credentials are rendered |
| POST | `/api/payments/paylink/webhook` | Paylink bearer token | Acknowledge only after authoritative invoice lookup |
| POST | `/admin/api/orders/{publicId}/refund` | admin or staff | One full original-method Paylink refund |

Localized customer routes use the same paths under `/en`. Sensitive JSON responses and order pages are `no-store`.

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

The scheduler publishes pending `order.paid` rows to `N8N_ORDER_PAID_URL`. It sends one raw JSON body with:

```json
{
  "eventId": "01...",
  "eventType": "order.paid",
  "schemaVersion": 1,
  "occurredAt": "2026-08-14T00:00:00Z",
  "data": {
    "order_public_id": "01...",
    "order_number": "AUT-...",
    "locale": "ar",
    "currency": "SAR",
    "total_halalah": 1250,
    "item_count": 1
  }
}
```

Headers:

- `X-ArabUT-Key`
- `X-ArabUT-Timestamp`
- `X-ArabUT-Event`
- `X-ArabUT-Signature`

The signature is lowercase hex HMAC-SHA256 of:

```text
timestamp + "\n" + eventId + "\n" + exactRawBody
```

n8n must deduplicate on `eventId` and respond with `{"data":{"acknowledged":true}}`. The payload deliberately excludes customer phone/email and all EA emails, passwords, and backup codes. Fulfillment-secret access must use a separate, authenticated, audited boundary.

## Official Paylink references

- [Authentication](https://developer.paylink.sa/docs/authentication)
- [Add invoice](https://developer.paylink.sa/docs/add-invoice)
- [Get invoice](https://developer.paylink.sa/docs/get-invoice)
- [Payment processing](https://developer.paylink.sa/docs/payment-processing)
- [Payment webhook](https://developer.paylink.sa/docs/payment-webhook)
- [Refund an order](https://developer.paylink.sa/docs/refund-an-order)
