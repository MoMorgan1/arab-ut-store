# n8n Catalog Snapshot API v1

The Arab UT storefront at `store.arab-ut.com` accepts complete catalog snapshots from the approved n8n workflow. Laravel and MariaDB remain authoritative after a snapshot is accepted.

## Endpoints, source scope, and authentication

`POST /api/automation/v1/catalog/snapshots`

This backward-compatible generic endpoint reconciles only the catalog source
`n8n-products`. It accepts the documented catalog service types.

`POST /api/automation/v1/catalog/sbc/snapshots`

This is the production endpoint for the SBC workflow. It accepts only products
whose `serviceType` is `sbc` and reconciles only the catalog source `n8n-sbc`.
A complete SBC snapshot can archive omitted `n8n-sbc` rows, but it cannot hide,
archive, deactivate, or overwrite rows belonging to `n8n-products`, another
automation source, or the manual catalog. The SBC workflow must never publish
through the generic endpoint. It uses the dedicated `N8N_SBC_CATALOG_KEY` and
`N8N_SBC_CATALOG_SECRET`; generic catalog credentials are not accepted here.

Send JSON with these headers:

- `Content-Type: application/json`
- `Accept: application/json`
- `X-ArabUT-Key`: `N8N_CATALOG_KEY` on the generic route or the separately
  scoped `N8N_SBC_CATALOG_KEY` on the SBC route.
- `X-ArabUT-Timestamp`: exactly 10 Unix timestamp digits, within 300 seconds of the server.
- `X-ArabUT-Event`: the same ULID supplied as `eventId` in the JSON body.
- `X-ArabUT-Signature`: lowercase hex HMAC-SHA256 of the route's canonical
  string, using `N8N_CATALOG_SECRET` on the generic route or
  `N8N_SBC_CATALOG_SECRET` on the SBC route.

The generic endpoint retains its v1 canonical string unchanged:

```text
{X-ArabUT-Timestamp}\n{X-ArabUT-Event}\n{exact raw request body}
```

The SBC endpoint domain-separates its signature with the fixed server source:

```text
{X-ArabUT-Timestamp}\n{X-ArabUT-Event}\nn8n-sbc\n{exact raw request body}
```

Even if operators accidentally assign the same credential values to both
routes, a signature generated for one route is invalid on the other.

Do not reformat or re-encode the JSON after calculating the signature. Secrets belong in n8n credentials/environment configuration, never a workflow export or query string.

## Exact request body

The top-level object permits only:

```json
{
  "schemaVersion": 1,
  "eventId": "01K2EXAMPLE000000000000001",
  "runId": "01K2EXAMPLE000000000000002",
  "generatedAt": "2026-08-11T12:00:00.000000Z",
  "completeSnapshot": true,
  "categories": [],
  "products": []
}
```

- `eventId` and `runId`: distinct stable ULIDs; each value is replay-protected.
- `generatedAt`: UTC with exactly six fractional digits and `Z`, within 300 seconds.
- `completeSnapshot`: the JSON boolean `true`; partial snapshots are rejected.
- `categories`: 0–50 entries.
- `products`: 0–2,000 entries.

Each category permits only `externalId` (unique string, max 120), `slug` (unique string, max 255), `name` (`ar` and `en`, each max 255), `description` (`ar` and `en`, nullable, each max 2,000), `sortOrder` (integer >= 0), and `visible` (boolean).

Each product permits only:

- `externalId` (unique string, max 120), `categoryExternalId` (nullable and must reference this snapshot), `slug` (unique, max 255), and `serviceType` (`sbc`, `objectives`, `rivals`, or `fut_champions` on the generic route; exactly `sbc` on the SBC route).
- `name.ar` / `name.en` (required, max 255) and nullable `description.ar` / `description.en` (max 5,000).
- `sortOrder` (integer >= 0), `visible` (boolean), `variants` (1–10), and `media` (0–5).

Each variant permits only:

- `externalId` (unique string, max 120), `sku` (unique string, max 255), `platform` (the current `Platform` enum), and its matching `market`.
- `currency` exactly `SAR`; `priceMinor` integer >= 0; nullable `salePriceMinor` integer >= 0; `priceVersion` integer >= 1.
- nullable localized `name.ar` / `name.en` (max 255), `active` boolean, and `configuration` object.

`priceVersion` remains required for v1 request compatibility, but it is not
authoritative. Laravel stores version `1` for a newly imported variant,
preserves the stored version when both effective price fields are unchanged,
and increments the stored version exactly once when `priceMinor` or
`salePriceMinor` changes. Producer regressions or jumps are ignored.

Each media entry permits only `url` (HTTPS, max 2,048), nullable `alt.ar` / `alt.en` (max 255), and `sortOrder` integer >= 0. The final host and every redirect host must be in `N8N_CATALOG_MEDIA_HOSTS`. Laravel accepts JPEG, PNG, or WebP up to 5 MiB, verifies the declared/detected MIME type, and mirrors the file locally. A failed media refresh keeps the previous product media.

## Responses and retry behavior

Success is HTTP 201 with `Cache-Control: no-store`:

```json
{"data":{"runId":"01K2EXAMPLE000000000000002","status":"completed","applied":12,"archived":2}}
```

- 401 `invalid_signature`: key/signature missing or invalid.
- 409 `stale_snapshot`: signature timestamp outside the five-minute window.
- 409 `catalog_snapshot_replayed`: the event or run already committed.
- 422: exact-shape, relationship, timestamp, enum, or bound validation failed.
- 429: automation rate limit reached.
- 500: unexpected failure; the database transaction is rolled back.

For connection loss or 5xx, retry the exact same raw body and headers while the timestamp is fresh. A 201 means it committed. A replay 409 for the same event/run means the earlier attempt already committed and must be treated as idempotent completion, not sent again with invented IDs. For validation/authentication failures, correct the source and submit a new event/run.

Accepted complete snapshots upsert automation-owned categories/products/variants inside the endpoint's fixed server-side source. Missing rows are hidden, archived, or deactivated only within that source. The source is selected by the route and cannot be supplied in the request body. Manual rows and rows from another catalog source are never overwritten or archived by this snapshot. Validation, media preparation, or transaction failure leaves the last public snapshot intact.

External IDs are source-scoped, so identical category, product, and variant
external IDs may exist in `n8n-products` and `n8n-sbc`. Public slugs and SKUs
remain globally unique; each producer must therefore assign route-appropriate
slugs/SKUs without relying on external-ID uniqueness outside its source.

## Historical review archive

Reviews use a separate one-time archive contract. Configure `N8N_REVIEWS_URL` only for the operator-run `reviews:import-salla-archive --from-config` command. The command projects only allowlisted public fields into the local database and supports a count-only dry run before `--apply`. Storefront requests never call n8n, and the retired `reviews:refresh` command is not scheduled. Failed or malformed imports leave the local archive unchanged.
