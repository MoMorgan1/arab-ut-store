# SBC Catalog v1

This package is the versioned source and inactive n8n export for the Arab UT SBC catalog. It reads the two authoritative one-million Coins quotes from Laravel, reads one bounded EasySBC page, validates every source record, maps eligible challenges into a complete `n8n-sbc` catalog snapshot, and either reports a dry run or publishes one signed snapshot back to Laravel.

It has no external storefront mutation, translation model, or product-platform dependency. Laravel and MariaDB remain authoritative after an accepted snapshot.

## v1 behavior

- Manual Trigger plus a two-hour Schedule Trigger.
- Default `Config.settings.mode` is `dry_run`; dry runs have no graph path to the catalog POST.
- EasySBC request: `GET https://api-fc26.easysbc.io/sbc-sets?page=1&limit=200`.
- Fails closed below 20 source records, at 200 records (pagination ambiguity), on duplicate IDs, or on any malformed complete source record.
- Categories: `players`, `upgrades` (source categories 2, 3, and 6), `icons`, and `foundations`.
- Eligibility: active, more than two hours before expiry, no Bronze/Silver names, PlayStation coins at least 1,500, non-repeatable PlayStation coins at least 20,000, and a positive PC coin value.
- Exactly one PlayStation and one PC variant per product. v1 prices one completion even when the source challenge is repeatable; repeatability metadata is retained in `configuration`, but there is no repeat selector.
- Images are optional. A supplied image must be HTTPS on `assets.easysbc.io`.
- Source names remain exact in English. Until an approved translation source exists, Arabic fields use the honest deterministic form `تحدي SBC: {source name}` rather than fabricated translations.

## Audited pricing formula

For each platform, Laravel returns its current one-million Coins total in halalah. Convert that base to SAR and calculate one completion:

```text
M(c) = 1.15 when c < 50,000
       1.10 when 50,000 <= c < 900,000
       1.00 when 900,000 <= c <= 1,000,000
       1.025 when c > 1,000,000

SAR = round(challengeCount*2 + coins*M(c)*1.02*(baseSar/1,000,000) + 2) + 3
priceMinor = SAR*100
```

The workflow sends `priceVersion: 1` for contract compatibility. Laravel owns stored price-version increments.

## Credentials and host environment

Create these HTTP Custom Auth credentials with the exact exported names:

1. `ArabUT SBC Pricing Read API` — adds `X-ArabUT-Key` with the pricing-read public key.
2. `ArabUT SBC Catalog API` — adds `X-ArabUT-Key` with the separately scoped SBC catalog public key.
3. `Whapi Alerts` — adds the Whapi authorization header; alerts run only when `OPS_WHATSAPP_TARGET` is configured.

Set secrets only on the n8n host:

- `N8N_SBC_PRICING_READ_SECRET`
- `N8N_SBC_CATALOG_SECRET`
- optional `OPS_WHATSAPP_TARGET`
- `NODE_FUNCTION_ALLOW_BUILTIN=crypto`

The exported JSON contains placeholder credential IDs/names and no secret values.

## Build and test

```powershell
cd automation/n8n/sbc-catalog-v1
npm run build
npm test
```

`workflow.json` is generated from `nodes/*.js`; `npm test` fails when the export is stale.

## Safe rollout

1. Deploy and verify the signed Laravel pricing-read and SBC snapshot endpoints.
2. Import `workflow.json`; keep it inactive and keep `mode: 'dry_run'`.
3. Attach the three credentials and set the host environment values.
4. Run manually. Review source count, eligible count, exact products/variants, prices, and the explicit `publishAttempted: false` summary.
5. Change the versioned `Config` mode to `apply`, rebuild/re-import, then run one controlled apply.
6. Require HTTP 201 with the same `runId` and `status: completed`; an exact replay 409 is treated as the already-committed request. HTTP 422 or 5xx fails closed and keeps the last accepted catalog.
7. Verify the Laravel catalog and storefront, then activate the two-hour schedule.

## Official n8n references checked

- [Schedule Trigger](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.scheduletrigger/)
- [HTTP Request](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest/)
- [Code node cookbook](https://docs.n8n.io/code/cookbook/code-node/)
- [HTTP Request credentials](https://docs.n8n.io/integrations/builtin/credentials/httprequest/)
- [Security audit](https://docs.n8n.io/hosting/securing/security-audit/)
