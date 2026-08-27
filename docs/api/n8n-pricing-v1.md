# n8n Coins Pricing v1 API / v2 workflow

Laravel receives one complete, signed Coins pricing snapshot from n8n. Laravel remains the authority for persisted pricing and customer quotes; n8n observes FFT and UTT, builds a complete rule set, validates every legal quantity, and applies a new version.

The HTTP payload contract remains schema version `1`. The importable n8n implementation is now **ArabUT Coins Pricing v2 — Adaptive FFT + UTT Highest-Available Fallback**.

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

The HMAC secret remains configured as `N8N_PRICING_SECRET` on the n8n host and is not included in the exported workflow.

## Snapshot shape

The body contains:

- `schemaVersion: 1`
- ULID `eventId` and `runId`
- UTC `generatedAt` with six fractional digits
- `mode: apply`
- `serviceType: coins`
- `legalRanges` for `console_normal`, `console_fast`, and `pc`
- exactly three rules: `console_normal`, `console_fast`, and `pc`
- sanitized `observations` with no provider credentials or raw auth headers

Each rule contains five strictly increasing tier upper bounds, quantity multipliers, the tapered small-order fee, a discount divisor, exact whole-SAR overrides when needed, and either one flat rate for normal delivery or six tier rates for fast and PC delivery.

The active legal ranges are:

```json
{
  "console_normal": {"minimum": 50000, "maximum": 2000000, "increment": 10000},
  "console_fast": {"minimum": 50000, "maximum": 20000000, "increment": 10000},
  "pc": {"minimum": 50000, "maximum": 20000000, "increment": 10000}
}
```

## Workflow v2 provider probing

All editable provider, economics, quantity-curve, and stability values currently live in the n8n `Config` node. There is no Coins-pricing admin page in this implementation.

### FFT cycle

Cycle pricing no longer depends on a historical fixed floor or ceiling. For PS and PC separately, the workflow:

1. Reads the seller `avgFillPrice` or `priceMin` only as a hint.
2. Verifies buyer coverage at the configured order size.
3. Expands the price exponentially until `coverage=enough` is found.
4. Uses binary search at the configured precision to find the lowest clearing price.
5. Rechecks the selected price before accepting it.

This works both in a low-price late-game market and in a high-price launch market without changing `$0.20` or `$2.00` constants.

### FFT targeted

Targeted probing starts from the first live FFT distribution bracket containing stock, not a hard-coded `€0.50` minimum. It searches only the configured tier quantities and caches repeated price checks.

The targeted calculation uses:

```text
riskLevel = 10
customerBalance = 20,000,000
```

The risk level must remain aligned with the fulfillment workflow.

### UTT native multi-price prediction

The workflow does not add individual UTT capacities manually. It groups the public stock sections by price and asks UTT to calculate the cumulative executable capacity itself:

```json
{
  "orderMethod": "public",
  "stockSection": "[\"285,0.25\",\"359,0.26\"]",
  "startCoins": 20000000
}
```

For each price ceiling, UTT returns `maxTransferable`, card-limit data, and cooldown data. The workflow selects the lowest cumulative price ceiling that covers each configured tier.

## Tier selection and missing-quantity rule

Configured tier caps are:

```text
1M, 2M, 5M, 10M, 15M, 20M
```

For a directly covered tier, the workflow chooses the lower verified raw USD-per-million cost from FFT targeted or UTT, then applies the platform economics.

When a requested tier is not directly available, the owner-approved rule is:

> Use the supplier price at the **highest quantity currently possible below the requested tier**.

The implementation therefore compares each provider's maximum verified capacity and uses the price that first reaches the greatest available capacity below the target. For example, if PC reaches 8.2M but does not reach 10M, the 10M, 15M, and 20M supplier basis uses the verified 8.2M price. If provider-level highest-capacity data is unavailable, the previous configured tier is carried forward.

This fallback keeps the larger quantity for sale. It does not claim immediate availability; fulfillment may finish after a provider limit or cooldown resets. The larger quantity still receives its own commercial uplift, so its customer price is not merely the smaller-tier total multiplied mechanically.

A platform cycle cost is also applied as a floor, and tier costs are forced to remain non-decreasing.

## Pricing formula

For each group:

```text
sell USD/M =
    ((raw supplier USD/M + operational markup USD/M) / margin divisor)
    + commission USD/M

rate SAR/M = sell USD/M × effective USD/SAR
```

Current default economics:

| Group | Margin divisor | Operational markup USD/M | Commission USD/M |
|---|---:|---:|---:|
| Console normal | 0.83 | 1.00 | 1.00 |
| Console fast | 0.82 | 1.50 | 1.00 |
| PC | 0.65 | 1.50 | 2.00 |

`effectiveUsdToSar` is `3.85`.

## Quantity commercial curve

One million Coins is the promotional, lowest-uplift point. Smaller orders retain a stronger contribution for fixed operating and acquisition costs. Larger orders receive a modest liquidity/guarantee uplift.

Default anchors:

| Quantity | Multiplier |
|---:|---:|
| 50K | 1.10 |
| 100K | 1.06 |
| 150K | 1.05 |
| 250K | 1.03 |
| 500K | 1.02 |
| 1M | 1.00 |
| 2M | 1.015 |
| 5M | 1.025 |
| 10M | 1.035 |
| 15M | 1.04 |
| 20M | 1.05 |

A rule publishes the curve in **exactly one** of two shapes.

`multiplier_anchors_basis_points` is an **anchor table**. Laravel interpolates
linearly between the two bracketing anchors at read time and rounds a half
upward on both slopes, matching the expansion the workflow used to publish.
Every entry is load-bearing, so Laravel never drops one. Publish the eleven
anchors above and nothing else.

Outside the published range the curve clamps, and each end is bounded at ingest:

- The **last anchor must reach the group maximum** - 2,000,000 for
  `console_normal`, 20,000,000 for the other two. Clamping at the top would
  price a twenty million order at the two million rate.
- The **first anchor must sit at or below the store minimum, or carry the
  highest rate on the table**. Clamping at the bottom is only safe when there is
  nothing to clamp or when the first anchor is the dearest rate. The curve dips
  at one million and climbs again, so this is checked rather than assumed: a set
  such as `{5M: 1.035, 20M: 1.05}` would otherwise clamp every order below five
  million down to 1.035 and undercharge a 100K order by roughly 2.4%.

Below the first anchor the band is priced at the first anchor's rate rather than
on the curve. The owner accepted this on 2026-08-27: the band is small orders at
a low absolute price, and the first anchor is already the rate intended for
them. It is a deliberate trade for decoupling - requiring an anchor at or below
the admin minimum would put the minimum back in n8n's hands, which is the
coupling this design removes. The only bound is the one above: the first anchor
must be at or below the minimum, or be the dearest rate on the table.

`multipliers_basis_points` is a **threshold map**. Laravel answers with the last
entry at or below the quantity, so an entry repeating the one before it is
already implied and is dropped before storing - the same prices, roughly a fifth
of the rows. It cannot answer below its first entry, so that entry must sit at
the range minimum.

`legalRanges.*.minimum` is compared for equality only for a threshold map. An
anchor table derives its floor from the live admin quantity settings, so
lowering the minimum in the admin no longer requires a matching edit in n8n -
which is the whole reason the anchor shape exists. `maximum` and `increment` are
compared for equality in both shapes.

## Small-order fee

`service_fee_halalah = 300` is intentionally retained. In the current Laravel calculator it is a tapered small-order operating fee:

```text
50K  -> 2.85 SAR
100K -> 2.70 SAR
500K -> 1.50 SAR
1M+  -> 0 SAR
```

It supports fixed payment, advertising, support, and operating costs while preserving 1M as the promotional point.

## Stability and validation

After a successful n8n baseline exists:

- confirmed price increases apply immediately;
- very small decreases are held;
- larger decreases are partially applied;
- the maximum decrease per run is capped by the Config settings.

Before signing, the workflow simulates every legal quantity using the same whole-SAR arithmetic and fast-delivery floors as Laravel. A higher quantity may never produce a lower total. Exact whole-SAR overrides are generated only when rounding would otherwise create a descending point.

Supplier request errors fail closed after retry. The previous Laravel rule set stays active.

## Laravel behavior

- `apply` validates first, then atomically deactivates the previous global Coins rules, activates exactly three new rule groups, and increments active Coins variant `price_version` once.
- Duplicate `runId` or `eventId` is rejected with `409`.
- A duplicate `runId` returns `coins_pricing_run_replayed`; a duplicate `eventId` returns `coins_pricing_event_replayed`.
- The workflow treats a same-event replay caused by its own HTTP retry as confirmation that the original transaction committed.
- Invalid signature, stale timestamp, missing credentials, malformed groups, unsupported fields, or a failed transaction leave the previous active rules intact.

## Retention

`pricing-history:prune` runs daily and deletes pricing runs, and the global Coins
rules a later run superseded, once they have been out of service longer than
`COINS_PRICING_RETENTION_DAYS` (30). The window is measured from when a row last
changed, not from when it was written, so a rule that served prices through a long
stall gets its full window from the day it stopped serving. Variant-scoped rules and
rules for other services are never touched: inactive is not the same claim as
superseded. Two things are
never deleted whatever their age: the **active** rules, which are what the storefront
prices from, and the **newest run plus the newest applied run**, which are the record
of where the live prices came from. If pricing ever stalls past the window - n8n down,
credentials expired - the last thing that worked survives.

Pruning cannot weaken replay protection: a pricing request carries a signed timestamp
and is refused above five minutes old, so a request old enough to be pruned here never
reaches the `runId`/`eventId` checks.

Successful response:

```json
{
  "data": {
    "runId": "01...",
    "status": "applied",
    "pricingVersion": 5,
    "groups": ["console_normal", "console_fast", "pc"]
  }
}
```

## Operations

1. Import the v2 workflow JSON into n8n.
2. Reconnect the `ArabUT Pricing API` and optional `Whapi Auth` credentials if n8n does not map them automatically.
3. Keep `N8N_PRICING_SECRET` configured on the n8n host.
4. Run `Run Coins Pricing Now`; manual execution applies directly.
5. Inspect `Success Summary.pricingAudit`, especially `fallbackTiers`, before enabling the hourly schedule.
6. Change business or provider settings only in `Config` until a future admin settings surface is deliberately implemented.

FFT and UTT read credentials are currently stored in the operator-managed `Config` node by explicit owner decision. Do not copy their values into documentation or logs. The Laravel signing secret remains outside the export.
