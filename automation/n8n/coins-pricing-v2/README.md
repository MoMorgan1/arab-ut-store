# Coins Pricing v2 (n8n → Laravel automation)

Production n8n workflow "ArabUT Coins Pricing v2.1 - Adaptive FFT + UTT Carry
Forward", exported verbatim from the owner's instance on 2026-08-21
(`versionId 4205de05-f101-4dfd-a522-154b866784de`). Publishes signed pricing
runs to `POST /api/automation/v1/pricing/coins/runs`.

Provider secrets stay in n8n environment variables (`FFT_API_USER`,
`FFT_API_KEY`, `UTT_API_KEY`, `N8N_PRICING_SECRET`, `OPS_WHATSAPP_TARGET`);
this file contains none.

## REQUIRED SYNC: 0.1-SAR display grain (2026-08-21)

Laravel rounds coins prices at a **0.1-SAR grain** instead of whole riyals
(`CoinsPriceCalculator::DISPLAY_GRAIN_HALALAH = 10`). The original export in
`workflow.json` carries the old whole-riyal math and must not be imported onto
the new backend.

**`workflow-v2.3.json` is the corrected, ready-to-import artifact.** It is
byte-identical to the production export except the pricing math inside the
"Prepare Coins Snapshot" and "Validate Snapshot" code nodes:

- All internal price computation moved to **halalah integers**, rounded
  half-up to the 10-halalah display grid (`roundToDisplayGrain`), floored at
  100 halalah.
- Fast-delivery floors mirror Laravel exactly: percentage floor ×105%, the
  per-million guarantee (`quantity * 500` term), and the visible gap
  (`normal + 100`).
- Exact overrides are now stored/validated as halalah multiples of 10
  (matching Laravel's `>= 10 && % 10 !== 0` contract), so the override-grid
  asymmetry noted below disappears once v2.3 is imported.

Import checklist:

1. Import `workflow-v2.3.json` into n8n (replaces the active workflow).
2. Confirm no executions are pinned to old code (remove any pin data).
3. Trigger "Run Coins Pricing Now" manually and confirm "Validate Snapshot"
   passes with `fullScheduleChecked: true`.
4. Verify one storefront quote lands on the 0.1-SAR grid.

Ordering rule: patched-n8n against old Laravel is safe; new-Laravel against
the unpatched workflow is the only unsafe combination.

## Known hygiene notes (non-blocking)

- Mixed self-version labels: file name says v2.1, node notes say v2.2, and the
  UTT probe sends `User-Agent: ArabUT-Coins-Pricing/2.1` while FFT sends 2.2.
  Unify on the next edit.
- `tierCapsK` (6 cost rows) vs `tierUpperBoundsK` (5 bounds + open sixth rate)
  matches Laravel's six-rate contract; do not trim either list independently.
- Override grids: Laravel and v2.3 both accept exact overrides at any positive
  multiple of 0.1 SAR (`>= 10 && % 10 === 0`). The pre-v2.3 validator required
  whole riyals — if you ever re-import `workflow.json`, restore that stricter
  pairing or hourly runs will fail closed with `exact override is invalid`.

## REQUIRED SYNC: publish anchors, not the expansion (2026-08-27)

**`workflow-v2.4.json` is the ready-to-import artifact.** It is derived from
`workflow-v2.3.1-current.json`, the owner's live export of 2026-08-27
(`versionId d9c8c908`), with seven edits across two nodes and nothing else
touched. Import it and skip the rest of this section; the detail below records
what changed and why.

### Why this changed

The workflow expanded the eleven commercial anchors into one entry per legal
quantity - 3,991 per group for `pc` and `console_fast` - and published the
expansion. An expansion is bound to the range it was computed for, so lowering
the Coins minimum in the admin on 2026-08-26 invalidated it. Every hourly run
since was rejected with a 422, and quantities between 10,000 and 45,000 stopped
pricing on the storefront entirely.

Laravel now interpolates, so the workflow publishes the anchors themselves.

### What v2.4 changed

| Node | Change |
| --- | --- |
| Prepare | `commonRule()` emits `multiplier_anchors_basis_points: anchorTable()` |
| Prepare | `anchorTable()` replaces `multiplierMap()`; the expansion is gone |
| Prepare | `multiplierFor()` interpolates instead of reading the expansion |
| Validate | the rule allowlist names the anchor field |
| Validate | `multiplierFor()` mirrors Laravel's `CoinsMultiplierCurve` |
| Validate | anchor table shape check: at least two entries, positive integer pairs |
| Validate | the length check became a top-coverage check |

Both nodes had to change. Editing only "Prepare Coins Snapshot" leaves the run
failing inside n8n, before it ever reaches Laravel, with no 422 to notice - its
"Validate Snapshot" allowlist, plain-object check and length check all reject an
anchor table.

### What v2.4 deliberately did NOT change

`expectedRanges` in "Validate Snapshot" stays restated rather than read from the
snapshot. The node documents itself as an independent guard that catches a
Prepare bug mangling `legalRanges` on the way through; reading the values it is
meant to check from the thing it is checking would defeat it.

Its `minimum` stays 50,000 while the live admin floor is 10,000, and that is
correct: the guard only needs n8n to agree with itself, and Laravel no longer
compares the minimum for an anchor curve. Its `increment` is already 5,000,
matching the live rounding unit.

Everything else is carried through untouched: the eleven anchors, the pricing
formula, the grain-first `percentageFloor`, the PS-only cycle floor, the
fast-delivery floors, the descending-price guard, the exact overrides, the
throw-based `fail()` routing, and the signing.

### Verification before this file was published

- Every code node parses.
- No reference to `multipliers_basis_points` remains anywhere in the export.
- The node set is identical - 18 nodes, same names - and only the two intended
  code nodes differ from the live export.
- Laravel accepts the exact payload `anchorTable()` produces: a 10,000-coin
  order prices at 11,000 bp, a 200,000 order at 10,400, a 20,000,000 order at
  10,500.

### After importing

1. Import `workflow-v2.4.json`; it replaces the active workflow.
2. Remove any pin data left on the code nodes.
3. Trigger "Run Coins Pricing Now" manually rather than waiting for the hour.
4. Confirm the run was **accepted**, not merely sent:

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' 'https://store.arab-ut.com/coins/quote?platform=pc&quantity=10000'
   ```

   Expected: `200`. It returns `503` until an anchored run lands, because the
   stored rules still start at 50,000.

Both shapes are accepted on the Laravel side, so this can land before or after a
Laravel release without a coordinated deploy. Nothing recovers until it lands,
though.
