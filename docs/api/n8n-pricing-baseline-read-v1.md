# n8n Coins Pricing Baseline Read v1

This read-only endpoint tells the Coins pricing workflow what the store last published, so a run that has nothing of its own to carry forward can still price a platform whose supplier went quiet.

It exists because of how n8n scopes workflow memory: `$getWorkflowStaticData` is populated for **scheduled** executions only, and n8n hands a **manual** execution an empty object. A run started with Execute therefore had no published rates to carry, and reported a perfectly healthy workflow as broken - which meant the only way to see a pricing run was to wait for the hour. Asking the store also means the carry-forward survives an n8n restart or a re-imported workflow.

## Endpoint

```text
GET /api/automation/v1/pricing/coins/baseline
```

Production URL:

```text
https://store.arab-ut.com/api/automation/v1/pricing/coins/baseline
```

Exact `GET` only, with no query parameters and no body. `POST` and every other method are refused with `405`.

## Signature

Required headers:

```text
X-ArabUT-Timestamp: <10-digit Unix seconds>
X-ArabUT-Signature: <lowercase hex HMAC-SHA256>
```

The canonical signature input is exactly, including the final newline:

```text
timestamp + "\nGET\n/api/automation/v1/pricing/coins/baseline\n"
```

The secret is `N8N_PRICING_SECRET` - the same one the publish route uses.

**There is no `X-ArabUT-Key` on this route, deliberately.** The publish route checks one, and n8n keeps it in an HTTP credential that only the HTTP Request node can send. This route is called from the `Prepare Coins Snapshot` Code node, which can read `$env.N8N_PRICING_SECRET` and nothing else, so the signature is the whole credential here. It covers the method and the path, so a signature minted for another route cannot be replayed against this one, and the route is read-only.

The timestamp must be within 300 seconds of the store's clock, or the request is refused with `409 stale_baseline_read`.

## Response

```json
{
  "schemaVersion": 1,
  "runId": "01M2QQ945KRHD19E2XQMNEB3FS",
  "pricingVersion": 7,
  "appliedAt": "2026-09-17T13:00:00+00:00",
  "rates": {
    "console_normal": 72861,
    "console_fast": [168050, 168050, 172254, 176500, 180000, 184000],
    "pc": [1777, 1836, 1836, 2132, 3968, 3968]
  },
  "cyclePSUsdPerM": 150.1,
  "cyclePCUsdPerM": null
}
```

`rates` are halalah per million coins, exactly as the run published them, and the three fields map one to one onto what the workflow keeps in its own memory as `lastSuccessfulRates`, `lastCyclePSUsdPerM` and `lastCyclePCUsdPerM`. The two must stay interchangeable: a run reads this only when its memory is empty.

A cycle cost the applied run did not carry comes back as `null` rather than as zero.

## Failures

| Status | Code | Meaning |
| ------ | ---- | ------- |
| `401` | `invalid_signature` | The signature does not match, or no pricing secret is configured. |
| `405` | `invalid_baseline_read_method` | Anything but `GET`. |
| `409` | `stale_baseline_read` | The timestamp is outside the 300-second window. |
| `422` | `invalid_baseline_read` | The request carried a query string or a body. |
| `404` | `coins_pricing_baseline_unavailable` | No applied run exists, or the newest one carries no readable rates. A store that has never published has no baseline, and that is a fact rather than a fault. |
| `429` | - | Ten requests a minute, keyed on the signature. |

Every response carries `Cache-Control: no-store`.

## How the workflow uses it

`Prepare Coins Snapshot` calls this endpoint on **every** run and prefers its answer to the workflow's own memory, falling back to the memory only when the read fails. A failure here is never fatal: a scheduled run with its own memory must not start failing because the endpoint had a bad minute.

Preferring the store is not belt-and-braces. n8n does not persist static data written during a manual execution, so a manual run publishes a price the workflow immediately forgets, and the next scheduled run compares against whatever the last *scheduled* publish left behind. On 2026-09-17 that read as `سريع 1M: 1681.01 ← 725.73 (-56.8%)` and asked the owner to approve a collapse, against a store already selling at 725.73. What the store is charging is what a price move is measured against; the memory is a cache of it.

The run records which one it used in `pricingAudit.baselineSource` (`store`, `memory`, or `null`), and `Assess Price Move` compares against `pricingAudit.baselineRates` rather than reading the memory again - otherwise a manual run would find no baseline, no large move, and publish a season-turn price with nobody asked.
