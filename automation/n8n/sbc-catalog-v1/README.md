# SBC Catalog v1

This package is the versioned source and inactive n8n export for the Arab UT SBC catalog. It reads the two authoritative one-million Coins quotes from Laravel, reads one bounded EasySBC page, validates every source record, maps eligible challenges into a complete `n8n-sbc` catalog snapshot, and either reports a dry run or publishes one signed snapshot back to Laravel.

It has no external storefront mutation or product-platform dependency. Laravel and MariaDB remain authoritative after an accepted snapshot. The approved Gemini credential is used only to enrich missing Arabic SBC names; exact validated translations are cached in workflow static data.

## v1 behavior

- Manual Trigger plus a two-hour Schedule Trigger.
- Default `Config.settings.mode` is `dry_run`; dry runs have no graph path to the catalog POST.
- EasySBC request: `GET https://api-fc26.easysbc.io/sbc-sets?page=1&limit=200`.
- Bootstrap is pinned to the manually reviewed 56-source/39-eligible observation captured at `2026-08-12T05:46:36.701Z`, including every eligible source ID, exact source name, and expiry.
- After an exact fresh HTTP 201 completion, workflow static data replaces that bootstrap with the accepted snapshot's public source IDs, names, expiries, and counts. Dry runs, failures, and replay responses never mutate this durable safety state; no credentials or customer data are stored there.
- Every later run fails closed when the source falls below 85% of the last successful source count or when a previously accepted ID disappears unexpectedly. A prior item may leave only when its stored expiry is inside the configured lead window or its current source record is present and deterministically ineligible. New IDs are allowed, while same-count replacement/churn and source-name changes are rejected. After permitted departures, the remaining eligible count must retain at least 80% of the adjusted prior set.
- Also fails closed at 200 records (pagination ambiguity), on duplicate IDs, or on any malformed complete source record.
- Categories: `players`, `upgrades` (source categories 2, 3, and 6), `icons`, and `foundations`.
- Eligibility: active, more than two hours before expiry, no Bronze/Silver names, PlayStation coins at least 1,500, non-repeatable PlayStation coins at least 20,000, and a positive PC coin value.
- Exactly one PlayStation and one PC variant per product. v1 prices one completion even when the source challenge is repeatable; repeatability metadata is retained in `configuration`, but there is no repeat selector.
- Images are optional. A supplied image must be HTTPS on `assets.easysbc.io`.
- English names remain byte-for-byte exact. Missing Arabic names are sent in one exact ID/name batch through `Google Gemini(PaLM) Api account 2` with temperature `0` and `maxOutputTokens: 8192`; output must contain the same IDs, count, order-independent source names, Arabic script, no Latin letters, and at most 120 characters. Validation is atomic: any missing, extra, mismatched, mixed-language, or malformed entry fails the run and leaves the cache unchanged.
- Every invalid branch ends in `Stop And Error` after the optional Whapi attempt, so a failed scheduled execution is visibly nonzero.
- Dry-run reports source/eligible safety counts. It reports create/update/archive as unavailable—not fabricated—because Laravel does not yet expose an authenticated current `n8n-sbc` snapshot-read endpoint.

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

Create these credentials with the exact exported names:

1. `ArabUT SBC Pricing Read API` — adds `X-ArabUT-Key` with the pricing-read public key.
2. `ArabUT SBC Catalog API` — adds `X-ArabUT-Key` with the separately scoped SBC catalog public key.
3. `Whapi Alerts` — adds the Whapi authorization header; alerts run only when `OPS_WHATSAPP_TARGET` is configured.
4. `Google Gemini(PaLM) Api account 2` — existing Google PaLM/Gemini credential used only for missing Arabic-name enrichment.

Set secrets only on the n8n host:

- `N8N_SBC_PRICING_READ_SECRET`
- `N8N_SBC_CATALOG_SECRET`
- optional `OPS_WHATSAPP_TARGET`
- `NODE_FUNCTION_ALLOW_BUILTIN=crypto`

The exported JSON contains placeholder credential IDs/names and no secret values.

Before apply, set this Laravel/Hostinger environment value and refresh configuration cache:

```text
N8N_CATALOG_MEDIA_HOSTS=assets.easysbc.io
```

This is required so the catalog endpoint can mirror the approved EasySBC image host instead of rejecting the snapshot.

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
3. Attach the four credentials and set the host environment values. On Hostinger, set `N8N_CATALOG_MEDIA_HOSTS=assets.easysbc.io` before any apply.
4. Run manually in dry-run. Confirm the source still matches the versioned bootstrap observation: 56 source records and the exact 39 eligible IDs, names, and expiries captured at `2026-08-12T05:46:36.701Z`. Review products/variants, prices, translation cache results, expected departures/new IDs, and the explicit `publishAttempted: false` summary. Update `Config.settings.approvedBaseline` only after manually reviewing a new complete source observation.
5. Change the versioned `Config` mode to `apply`, rebuild/re-import, then run one controlled apply.
6. Require a fresh HTTP 201 with the same `runId` and `status: completed` before advancing the durable identity/count state. An exact replay 409 is treated as the already-committed request but does not mutate state. HTTP 422 or 5xx fails closed and keeps the last accepted catalog and guard state.
7. Verify the Laravel catalog and storefront, and verify mirrored media counts match products with supplied EasySBC images. Only then activate the two-hour schedule.

The current dry-run cannot calculate an absolute `wouldArchive` count because there is no authenticated Laravel read endpoint for the current `n8n-sbc` snapshot. Keep the workflow inactive until the approved baseline, translation cache, and mirrored-media checks are complete. Adding that read endpoint is the follow-up needed for exact create/update/archive previews.

## Official n8n references checked

- [Schedule Trigger](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.scheduletrigger/)
- [HTTP Request](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest/)
- [Code node cookbook](https://docs.n8n.io/code/cookbook/code-node/)
- [Workflow static data](https://docs.n8n.io/code/cookbook/builtin/get-workflow-static-data/)
- [Google Gemini Chat Model](https://docs.n8n.io/integrations/builtin/cluster-nodes/sub-nodes/n8n-nodes-langchain.lmchatgooglegemini/)
- [Stop And Error](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.stopanderror/)
- [HTTP Request credentials](https://docs.n8n.io/integrations/builtin/credentials/httprequest/)
- [Security audit](https://docs.n8n.io/hosting/securing/security-audit/)
