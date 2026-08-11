# n8n Coins Pricing v1

Laravel receives one complete, signed Coins pricing snapshot from n8n. Laravel remains the authority for persisted pricing and customer quotes; n8n only observes suppliers and proposes/applies a new rule version.

## Endpoint

```text
POST /api/automation/v1/pricing/coins/runs
```

Production URL:

```text
https://store.arab-ut.com/api/automation/v1/pricing/coins/runs
```

Required headers:

```text
X-ArabUT-Key: <public key stored in the ArabUT Pricing API credential>
X-ArabUT-Timestamp: <unix seconds>
X-ArabUT-Event: <same value as eventId>
X-ArabUT-Signature: <hex HMAC-SHA256>
```

The signature input is exactly:

```text
timestamp + "\n" + eventId + "\n" + raw request body
```

The HMAC secret is configured as `N8N_PRICING_SECRET` on the n8n/Hostinger host. It is never included in an exported workflow.

## Snapshot shape

The body contains:

- `schemaVersion: 1`
- ULID `eventId` and `runId`
- UTC `generatedAt` with six fractional digits
- `mode: dry_run|apply`
- `serviceType: coins`
- `legalRanges` for `console_normal`, `console_fast`, and `pc`
- exactly three rules: `console_normal`, `console_fast`, and `pc`
- sanitized `observations` with no provider credentials or raw auth headers

Each rule contains five strictly increasing tier upper bounds, configurable quantity multipliers, a halalah service fee, a discount divisor, and either one flat rate (normal) or six tier rates (fast and PC). The six-rate shape covers the 20M tier.

The v1 legal ranges are:

```json
{
  "console_normal": {"minimum": 50000, "maximum": 2000000, "increment": 10000},
  "console_fast": {"minimum": 50000, "maximum": 20000000, "increment": 10000},
  "pc": {"minimum": 50000, "maximum": 20000000, "increment": 10000}
}
```

## Laravel behavior

- `dry_run` stores a proposed `PriceRun` and leaves active rules and variant versions untouched.
- `apply` validates first, then atomically deactivates the previous global Coins rules, activates exactly three new rule groups, and increments active Coins variant `price_version` once.
- Duplicate `runId` or `eventId` is rejected with `409`.
- A duplicate `runId` returns `coins_pricing_run_replayed`; a duplicate `eventId` returns `coins_pricing_event_replayed`. Both identifiers are durably stored, and `event_id` is database-unique.
- Invalid signature, stale timestamp, or missing credentials is rejected before persistence.
- Malformed groups, invalid tier order, unsupported fields, invalid multipliers, or a failed transaction leave the previous active rules intact.

Successful response:

```json
{
  "data": {
    "runId": "01...",
    "status": "proposed|applied",
    "pricingVersion": 5,
    "groups": ["console_normal", "console_fast", "pc"]
  }
}
```

## Operations

Rotate FFT/UTT credentials in their provider dashboards and replace the values only in n8n Credentials. Rotate the Laravel public key/HMAC secret in both systems through the secure Hostinger/n8n configuration path. Never request or paste a password, API key, or secret into chat or a workflow export.

The last successfully applied Laravel rule set remains active when a supplier probe or publish run fails. Retries must reuse the same `runId` and `eventId`; a committed replay is intentionally rejected so operators can inspect the original run instead of creating a second version.
