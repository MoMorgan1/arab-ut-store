# Paylink checkout operations

Do not paste any production credential into chat, GitHub, n8n code nodes, screenshots, or logs. Add values directly in Hostinger's environment-variable UI and Paylink Portal.

## 1. Configure test mode first

Add these Hostinger environment values:

```dotenv
APP_URL=https://store.arab-ut.com
PAYLINK_ENV=test
PAYLINK_API_ID=<Paylink test API ID>
PAYLINK_SECRET_KEY=<Paylink test secret key>
PAYLINK_WEBHOOK_TOKEN=<locally generated random 32-byte-or-longer token>
```

Generate the webhook token in a trusted terminal and copy it directly to Hostinger and Paylink Portal:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Optional original-method refunds additionally need the Partner API values issued by Paylink:

```dotenv
PAYLINK_PARTNER_PROFILE_NO=
PAYLINK_PARTNER_API_KEY=
PAYLINK_MERCHANT_LOOKUP_KEY=accountNo
PAYLINK_MERCHANT_LOOKUP_VALUE=
```

Do not invent those four values from the merchant checkout credentials. Without them, payments still work and refunds fail closed.

The paid-order n8n publisher additionally needs:

```dotenv
N8N_ORDER_PAID_URL=https://n8n.example/webhook/...
N8N_ORDER_PAID_KEY=<non-secret publisher identity>
N8N_ORDER_PAID_SECRET=<random 32-byte-or-longer shared secret>
```

Whapi login and checkout-phone verification require the already established `WHAPI_BASE_URL` and `WHAPI_TOKEN` values.

## 2. Apply and verify

From the application release directory:

```powershell
php artisan migrate --force
php artisan optimize:clear
php artisan route:list --path=paylink
php artisan schedule:list
```

Hostinger cron must run Laravel's scheduler every minute:

```text
* * * * * cd /path/to/store && php artisan schedule:run >> /dev/null 2>&1
```

Verify that the route list includes customer checkout/callback routes, the bearer-authenticated webhook, and the admin refund route. Verify the scheduler includes `orders:publish-paid-events`.

## 3. Configure Paylink Portal

In My Paylink Portal, configure Payment Webhook v2:

- `https://store.arab-ut.com/api/payments/paylink/webhook`
- header `Authorization`
- value `Bearer <PAYLINK_WEBHOOK_TOKEN>`

Do not place `PAYLINK_API_ID` or `PAYLINK_SECRET_KEY` in that webhook header.

## 4. Controlled pilot

1. Keep `PAYLINK_ENV=test`.
2. Use a dedicated verified customer account and a non-sensitive test EA fixture.
3. Confirm totals are at least SAR 5.00, place one order, and verify the browser leaves only for `https://payment.paylink.sa/pay/...`.
4. Temporarily test an unavailable provider response and verify the resulting pending order shows an owner-only Paylink retry button that reuses the order after reload.
5. Complete the Paylink test payment.
6. Verify the returned order is `received`, the payment is `paid`, captured halalah matches, only one received-history row exists, and one `order.paid` event exists.
7. Repeat the callback and webhook; verify no duplicate order, history, payment, or event.
8. Verify Whapi login and checkout phone codes arrive, expire, and remain rate-limited.
9. If Partner refund credentials are configured, refund the pilot order once and verify the local order/payment/items become refunded.

Only after that pilot passes should Hostinger receive the production Paylink API ID/secret and `PAYLINK_ENV=production`. Run one low-value production pilot before opening checkout publicly.

## Failure handling

- Missing/invalid Paylink configuration: checkout returns a truthful `503`; no fake success.
- Provider timeout after order placement: the local order stays pending; use the retry control on its owner-only order page.
- Callback/webhook mismatch: no payment state changes.
- n8n unavailable: the outbox event remains pending with bounded backoff and no response body stored.
- Refund timeout/mismatch: refund remains failed/manual-review; never repeat blindly.
