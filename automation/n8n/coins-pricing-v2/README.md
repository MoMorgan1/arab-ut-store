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
