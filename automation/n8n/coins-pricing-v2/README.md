# Coins Pricing v2 (n8n → Laravel automation)

Production n8n workflow "ArabUT Coins Pricing v2.1 - Adaptive FFT + UTT Carry
Forward", exported verbatim from the owner's instance on 2026-08-21
(`versionId 4205de05-f101-4dfd-a522-154b866784de`). Publishes signed pricing
runs to `POST /api/automation/v1/pricing/coins/runs`.

Provider secrets stay in n8n environment variables (`FFT_API_USER`,
`FFT_API_KEY`, `UTT_API_KEY`, `N8N_PRICING_SECRET`, `OPS_WHATSAPP_TARGET`);
this file contains none.

## REQUIRED SYNC: 0.1-SAR display grain (2026-08-21)

Laravel now rounds coins prices at a **0.1-SAR grain** instead of whole riyals
(`CoinsPriceCalculator::DISPLAY_GRAIN_HALALAH = 10`). This workflow carries its
own JavaScript copy of the same formula for schedule validation, and that copy
still uses the old whole-riyal math. Until the nodes below are patched, the
validator can approve a rate set whose true Laravel-served schedule dips
between adjacent 10K quantities by up to 0.09 SAR — an inversion invisible at
whole-riyal granularity.

Patch the two code nodes **before or immediately with** the next Laravel
deploy. Applying the n8n patch first is safe against both old and new Laravel;
running new Laravel against unpatched n8n is the only unsafe combination.

### Node "Prepare Coins Snapshot" — replace four functions

```js
const DISPLAY_GRAIN_HALALAH = 10;

function formulaHalalah(rule, quantity) {
    const rate = rule.group === 'console_normal'
        ? rule.flat_rate_halalah_per_million
        : tierRateFor(quantity, rule.tier_rates_halalah_per_million);
    const multiplier = multiplierFor(rule, quantity);
    const rateNumerator = BigInt(quantity) * BigInt(rate) * BigInt(multiplier);
    const feeGap = Math.max(0, 1_000_000 - quantity);
    const feeNumerator =
        BigInt(rule.service_fee_halalah) * BigInt(feeGap) * 10_000n;
    const denominator =
        1_000_000n * BigInt(rule.discount_divisor_basis_points);
    return Math.max(
        Number(roundHalfUpBigInt(rateNumerator + feeNumerator, denominator)),
        100,
    );
}

function roundToDisplayGrain(halalah) {
    return Number(roundHalfUpBigInt(BigInt(halalah), 10n)) * DISPLAY_GRAIN_HALALAH;
}

function percentageFloor(normalHalalah) {
    return roundToDisplayGrain(
        Number(roundHalfUpBigInt(BigInt(normalHalalah) * 105n, 100n)),
    );
}

function perMillionFloor(normalHalalah, quantity) {
    return roundToDisplayGrain(Number(roundHalfUpBigInt(
        BigInt(normalHalalah) * 1_000_000n + BigInt(quantity) * 500n,
        1_000_000n,
    )));
}
```

Then inside `displayedSar()` use, for every group:

```js
const base = roundToDisplayGrain(formulaHalalah(rule, quantity));
if (group !== 'console_fast') return base;

const normal = roundToDisplayGrain(formulaHalalah(rules.console_normal, quantity));
return Math.max(base, percentageFloor(normal), perMillionFloor(normal, quantity), normal + 100);
```

Keep returning values divided by 100 where the current code treats them as
SAR units, or switch the monotonicity loop to compare halalah directly — either
works as long as all comparisons use one unit. The non-decreasing override
writer (`safe * 100`) stays valid: whole-riyal overrides remain a legal subset
of Laravel's new `>= 10 && % 10 === 0` override contract.

### Node "Validate Snapshot" — mirror the same three floor functions

Apply the identical replacements so its full 4,188-price sweep checks the
schedule Laravel will actually serve. Its exact-override acceptance check
(`value < 100 || value % 100 !== 0` rejects) may stay stricter than Laravel.

## Known hygiene notes (non-blocking)

- Mixed self-version labels: file name says v2.1, node notes say v2.2, and the
  UTT probe sends `User-Agent: ArabUT-Coins-Pricing/2.1` while FFT sends 2.2.
  Unify on the next edit.
- `tierCapsK` (6 cost rows) vs `tierUpperBoundsK` (5 bounds + open sixth rate)
  matches Laravel's six-rate contract; do not trim either list independently.
- **Override-grid pairing rule:** Laravel accepts exact overrides at any
  positive multiple of 0.1 SAR (`>= 10 && % 10 === 0`), but this workflow's
  validator still requires whole riyals (`% 100 === 0`) — a deliberate strict
  subset, safe because `Prepare Coins Snapshot` only ever writes whole-riyal
  overrides. Anyone relaxing the writer to sub-riyal overrides MUST relax the
  validator's acceptance check in the same edit, or hourly runs fail closed
  with `exact override is invalid`.
