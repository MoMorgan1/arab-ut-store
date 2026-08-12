# n8n SBC Coins Pricing Read v1

This read-only endpoint lets the SBC catalog workflow obtain authoritative one-million-Coin SAR bases from Laravel. It calls the same `QuoteCoins` action used by the storefront; n8n does not reproduce the Coins formula.

## Endpoint

```text
GET /api/automation/v1/pricing/coins/sbc-bases
```

Production URL:

```text
https://store.arab-ut.com/api/automation/v1/pricing/coins/sbc-bases
```

The request must use the exact `GET` method, with no query parameters and no body. Laravel's implicit `HEAD` handling is explicitly rejected, as are `POST` and every other method.

Required headers:

```text
X-ArabUT-Key: <dedicated SBC pricing read key>
X-ArabUT-Timestamp: <10-digit Unix seconds>
X-ArabUT-Signature: <lowercase hex HMAC-SHA256>
```

The canonical signature input is exactly, including the final newline:

```text
timestamp + "\nGET\n/api/automation/v1/pricing/coins/sbc-bases\n"
```

The dedicated Hostinger/n8n credentials are `N8N_SBC_PRICING_READ_KEY` and `N8N_SBC_PRICING_READ_SECRET`. They must not be reused by the mutable Coins-pricing or catalog routes and must never appear in a workflow export.

## Response

```json
{
  "schemaVersion": 1,
  "pricingVersion": 7,
  "pricedAt": "2026-08-12T12:00:00+00:00",
  "quotes": {
    "playstation_fast": {
      "platform": "playstation",
      "delivery": "fast",
      "quantity": 1000000,
      "totalHalalah": 7000
    },
    "pc": {
      "platform": "pc",
      "delivery": null,
      "quantity": 1000000,
      "totalHalalah": 9000
    }
  }
}
```

`pricingVersion` is the common positive server-owned `price_version` of the active PlayStation and PC Coins variants. A mismatch fails closed rather than presenting two bases as one coherent version. `pricedAt` is one shared UTC timestamp for both returned bases.

## Failure behavior

- Invalid key or signature: `401 invalid_signature`.
- Any method other than exact `GET`: `405`; `HEAD` cannot execute the pricing action.
- Timestamp older or newer than five minutes: `409 stale_sbc_pricing_read`.
- Any query or body input: `422 invalid_sbc_pricing_read`.
- Missing/ambiguous active Coins product, variant, or required pricing rule, or inconsistent variant versions: `503 sbc_pricing_unavailable`.
- More than ten authenticated requests per minute per credential identity: `429 sbc_pricing_read_rate_limited`.

Signature authentication executes before the rate limiter. Invalid signatures, including attempts that rotate arbitrary public-key headers, cannot consume or bypass the authenticated credential's limiter bucket. All responses, including `429`, use `Cache-Control: no-store`. No customer data or provider credentials are returned.

## Laravel 13 implementation references

- [Middleware](https://laravel.com/docs/13.x/middleware): route-specific middleware classes inspect/reject requests and middleware priority can be extended with `prependToPriorityList` when execution order is security-sensitive.
- [Routing and rate limiting](https://laravel.com/docs/13.x/routing#rate-limiting): named rate limiters are attached with the `throttle` middleware.
- [Configuration](https://laravel.com/docs/13.x/configuration#configuration-caching): environment values are read only in configuration files so cached production configuration remains valid.
