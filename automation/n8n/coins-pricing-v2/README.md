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

The workflow expands the eleven commercial anchors into one entry per legal
quantity - 3,991 per group for `pc` and `console_fast` - and publishes the
expansion. An expansion is bound to the range it was computed for, so lowering
the Coins minimum in the admin on 2026-08-26 invalidated it. Every hourly run
since has been rejected with a 422, and quantities between 10,000 and 45,000
stopped pricing on the storefront entirely.

**`workflow-v2.4.json` is the ready-to-import artifact.** Import it and skip the
rest of this section; the detail below records what changed and why.

Laravel now interpolates. **Two nodes had to change, not one.** Editing only
"Prepare Coins Snapshot" leaves the run failing inside n8n, before it ever
reaches Laravel, with no 422 to notice.

### 1. Prepare Coins Snapshot

- Stop expanding the anchors.
- Emit them under `multiplier_anchors_basis_points` instead of
  `multipliers_basis_points`. Send exactly the eleven anchors.
- Leave `legalRanges.*.minimum` as it is. Laravel no longer compares it for an
  anchor curve, though `maximum` and `increment` must still match.

### 2. Validate Snapshot

This node re-checks the payload before signing, and every one of these rejects
an anchor table:

| Check in the node | Why it fails |
| --- | --- |
| `exactKeys(rule, expectedKeys)` | The list names `multipliers_basis_points` and has no entry for the anchor field. |
| `!isPlainObject(rule.multipliers_basis_points)` | The field is now absent. |
| `Object.keys(...).length !== ((max - min) / increment) + 1` | Demands one entry per legal quantity - the expansion this change removes. |
| `expectedRanges` hard-codes `minimum: 50_000, increment: 10_000` | Both are admin-managed values that already drifted. |

Replace the multiplier checks with anchor equivalents: the field is a plain
object of at least two entries; keys are positive integers; the largest key
reaches the group maximum; the smallest key is at or below the smallest quantity
preset. Drop the length check entirely - an anchor table has no expected length.

**`increment` is a second, independent drift.** The node hard-codes `10_000`
while the live admin setting is `5_000`. Read both `minimum` and `increment`
from the snapshot the Prepare node built rather than restating them, or this
node will keep disagreeing with Laravel every time either value is edited.

### What v2.4 changed

| Node | Change |
| --- | --- |
| Prepare | `commonRule()` emits `multiplier_anchors_basis_points: anchorTable()` |
| Prepare | `anchorTable()` replaces `multiplierMap()`; the expansion is gone |
| Prepare | `multiplierFor()` interpolates instead of reading the expansion |
| Validate | allowlist names the anchor field |
| Validate | `multiplierFor()` mirrors Laravel's `CoinsMultiplierCurve` |
| Validate | anchor table shape check: at least two, positive integer pairs |
| Validate | the length check became a top-coverage check |
| Validate | `minimum` and `increment` come from the snapshot, not hard-coded |

The last one matters beyond this change: the node pinned `minimum: 50_000` and
`increment: 10_000` while the live settings were 10,000 and 5,000. Only the
per-group `maximum` is still pinned, because nothing upstream bounds it.

The eleven anchors, the pricing formula, the fast-delivery floors, the
descending-price guard, the exact overrides and the signing are untouched.

Verified before publishing this file: every code node parses, no reference to
`multipliers_basis_points` remains, and Laravel accepts the exact payload
`anchorTable()` produces - a 10,000-coin order prices at 11,000 bp, a 200,000
order at 10,400, and a 20,000,000 order at 10,500.

### 3. Then

Trigger "Run Coins Pricing Now" manually rather than waiting for the hour, and
confirm the run was **accepted** rather than merely sent. The launch section of
`docs/plans/2026-08-27-coins-multiplier-anchors.md` has the exact commands.

Both shapes are accepted on the Laravel side, so this can land before or after
the Laravel release without a coordinated deploy. Nothing recovers until it
lands, though.
