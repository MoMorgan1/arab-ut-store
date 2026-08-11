# n8n Catalog Snapshot API v1

The Arab UT storefront at `store.arab-ut.com` accepts complete catalog snapshots from the approved n8n workflow. Laravel and MariaDB remain authoritative after a snapshot is accepted.

## Endpoint and authentication

`POST /api/automation/v1/catalog/snapshots`

Send JSON with these headers:

- `Content-Type: application/json`
- `Accept: application/json`
- `X-ArabUT-Key`: the scoped `N8N_CATALOG_KEY` value.
- `X-ArabUT-Timestamp`: exactly 10 Unix timestamp digits, within 300 seconds of the server.
- `X-ArabUT-Event`: the same ULID supplied as `eventId` in the JSON body.
- `X-ArabUT-Signature`: lowercase hex HMAC-SHA256 of the canonical string below, using `N8N_CATALOG_SECRET`.

```text
{X-ArabUT-Timestamp}\n{X-ArabUT-Event}\n{exact raw request body}
```

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

- `externalId` (unique string, max 120), `categoryExternalId` (nullable and must reference this snapshot), `slug` (unique, max 255), and `serviceType` (`sbc`, `objectives`, `rivals`, or `fut_champions` for this catalog workflow).
- `name.ar` / `name.en` (required, max 255) and nullable `description.ar` / `description.en` (max 5,000).
- `sortOrder` (integer >= 0), `visible` (boolean), `variants` (1–10), and `media` (0–5).

Each variant permits only:

- `externalId` (unique string, max 120), `sku` (unique string, max 255), `platform` (the current `Platform` enum), and its matching `market`.
- `currency` exactly `SAR`; `priceMinor` integer >= 0; nullable `salePriceMinor` integer >= 0; `priceVersion` integer >= 1.
- nullable localized `name.ar` / `name.en` (max 255), `active` boolean, and `configuration` object.

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

Accepted complete snapshots upsert automation-owned categories/products/variants. Missing automation-owned products are hidden and archived; missing variants are deactivated. Manual rows are never overwritten or archived by this source. Validation, media preparation, or transaction failure leaves the last public snapshot intact.

## Review refresh

Reviews use a separate pull contract. Configure `N8N_REVIEWS_URL`; Laravel runs `reviews:refresh` hourly and projects only public review fields into the local database. Storefront requests never call n8n. Failed or malformed refreshes preserve the last-good public review snapshot.
