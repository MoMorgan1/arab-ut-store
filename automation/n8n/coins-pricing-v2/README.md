# Coins Pricing v2 (n8n → Laravel automation)

Production n8n workflow "ArabUT Coins Pricing v2.1 - Adaptive FFT + UTT Carry
Forward", exported verbatim from the owner's instance on 2026-08-21
(`versionId 4205de05-f101-4dfd-a522-154b866784de`). Publishes signed pricing
runs to `POST /api/automation/v1/pricing/coins/runs`.

Provider secrets stay in n8n environment variables (`FFT_API_USER`,
`FFT_API_KEY`, `UTT_API_KEY`, `N8N_PRICING_SECRET`, `OPS_WHATSAPP_TARGET`);
this file contains none.

## Superseded: 0.1-SAR display grain and deleted exports (2026-08-21)

Laravel rounds coins prices at a **0.1-SAR grain** instead of whole riyals
(`CoinsPriceCalculator::DISPLAY_GRAIN_HALALAH = 10`). The original export
`workflow.json` carried the old whole-riyal math, and `workflow-v2.3.json` carried
pre-expanded quantities that fail on lower coin minimums. Both superseded exports
have been deleted from this directory to prevent accidental imports.

All exports in this directory use placeholder credential identifiers
(`CONFIGURE_ARABUT_PRICING_API_CREDENTIAL_ID`, `CONFIGURE_TELEGRAM_CREDENTIAL_ID`)
and environment expressions (`$env.OPS_TELEGRAM_CHAT_ID`).

Import `workflow-v3.0.json` instead; its steps are at the end of this file.

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

## v2.8: the storefront is sold a share of the pool (2026-09-17)

**`workflow-v3.0.json` is the current artifact.** `Config`, `Probe FFT` and
`Prepare Coins Snapshot`.

`observations.availableCoins` is now **half the provider's cycle pool**
(`probe.cyclePoolSellableBps`), not the per-buy fill size the price is found
against. Two different readings from the same endpoint, and the distinction
matters:

| Field | Role | What it is |
| --- | --- | --- |
| `cycleTotalCoins` | `seller` | Coins sitting in the cycle accounts. 312,929 across 12 accounts on 2026-09-17. |
| `estimatedCoinsAtPrice` | `buyer` | What one buy at that price would actually fill. 18,000, from 8 willing accounts needing 34 senders. |

The buyer figure is bounded by who is free right now, so it read 45,000 and
18,000 within an hour while the pool stayed hundreds of thousands deep - and it
did not move between 26 and 40 USD, so price was never its constraint.
Fulfilment buys repeatedly over time, so the pool is the honest measure of what
can be shipped; the fill size only sets the price.

**Known narrowing.** The ceiling counts the cycle pool alone. FFT's targeted
book and UTT's lots are separate supply with their own capacities
(`maxK`, `maxTransferable`) and are not added in. Both were empty on
2026-09-17, so this changes nothing today - revisit it against real numbers the
first time either carries stock, rather than shipping a summing rule no data
has ever exercised.

## v2.7: one dead platform does not stop the other (2026-09-17)

**`workflow-v3.0.json` is the current artifact.** It is v2.6 with edits in one
Code node, `Prepare Coins Snapshot`.

v2.6 got past the price and stopped at "A supplier cost basis could not be
built for every platform". That was true and it was PC: FFT's targeted book was
uncovered, UTT's lots were empty, and a cycle price is deliberately not a valid
floor for a targeted-priced tier. Console had a real market the whole time and
was stopped with it.

Each platform now carries its own last published rates forward when no supplier
quotes it, and only a platform with nothing to carry can stop the run. The
carried rates are not smoothed and are not a price signal - they are the last
thing known, held steady so fulfilment keeps a budget.

**The rule that makes that safe:** a group publishing carried rates publishes
`availableCoins: 0`. A price no supplier stands behind today reaches fulfilment
and never reaches a customer. `console_normal` goes dark with `console_fast`,
because both are priced from the same PlayStation book.

## v2.6: the price comes from the fill size, not the word (2026-09-17)

**`workflow-v3.0.json` is the current artifact.** It is v2.5 with edits in two
Code nodes - `Probe FFT` and `Prepare Coins Snapshot` - and nothing else.

### Why

Every run from 2026-09-05 to 2026-09-17 failed at "FFT PS cycle cost is
unavailable", and v2.5's carry-forward kept the store selling at FC26 rates
throughout. The cause was structural, not a threshold: the cycle ladder climbs
until FFT answers `coverage: "enough"` for a fixed two million coins, and a
season that opens with the whole PlayStation pool under a million can never say
it. Measured on 2026-09-17, coverage stayed `"limited"` at every offer up to
eighty dollars per 100K, because the smallest amount the endpoint can be asked
about is 100,000 coins and only 45,000 existed.

### What v2.6 does

`Probe FFT` reads `estimatedCoinsAtPrice` - how many coins the offer would
actually fill - instead of the coverage word. It walks up from the provider's
hint, takes the cheapest price that fills the target, and when the fill size
plateaus below the target it takes where the plateau begins and reports that
size. A deep market behaves exactly as before. An unreadable fill size is still
refused rather than read as "offer more", for the reason v2.4 gave.

`Prepare Coins Snapshot` publishes the result as
`observations.availableCoins` per group, clamped to the configured ceiling and
never above it. Zero is published as zero. This cannot travel in `legalRanges`:
Laravel checks those three ranges for equality against the active Coins
quantity settings, so they can only restate what the store already knows.

The storefront caps its quantity slider at this figure (store PR #197): the
rail keeps its full span, the stretch nobody can deliver is dimmed, and the
thumb stops at the last reachable stop.

## v2.5: FFT down carries the last rates forward (2026-09-15)

**`workflow-v2.5.json` is the current artifact.** It is v2.4 with edits in four
Code nodes and nothing else. Owner decision of 2026-09-15: FFT being down is not
a reason to stop the store.

### Why

From 2026-09-05 FFT answered every cycle probe with `coverage: "none"`,
`cycleTotalCoins: 0` on both platforms (game transition). v2.4 stops the run at
"FFT PS cycle cost is unavailable", so for ten days no run reached Laravel:
storefront prices froze, and - since B4 - no `observations.tierCosts` reached
the store, so fulfilment had no budget and no paid order could be placed even
though UTT was selling normally.

### What v2.5 does

When the FFT PS cycle cost is unavailable and the workflow remembers a
published run (`lastSuccessfulRates` in the workflow's static data):

- the three rate groups are published **unchanged** - no smoothing, no
  candidate; a UTT-only basis with no cycle floor is a fulfilment budget, not a
  price signal;
- `observations.tierCosts` is still built, from UTT (and FFT targeted when it
  answers), so the store's supplier cost table stays fresh;
- `observations.cyclePSUsdPerM` / `cyclePCUsdPerM` carry the last costs FFT
  really answered, remembered by "Evaluate Publish Result", so slow console
  delivery keeps a budget;
- `observations.source` is `utt+carry-forward-v2.5`, and `carryForward` /
  `carryForwardReason` are on the observations, the audit and the summary.

With nothing remembered, the run stops exactly as v2.4 did, naming the reason.

### Edits

| Node | Change |
| --- | --- |
| Prepare Coins Snapshot | the FFT guard becomes the carry-forward branch; `finalRates` and `candidateRates.console_normal` read the remembered rates in that branch; observations carry the remembered cycle costs and the flags |
| Evaluate Publish Result | remembers `lastCyclePSUsdPerM` / `lastCyclePCUsdPerM` from a run FFT answered (a carry-forward run keeps the remembered ones) |
| Success Summary | `carryForward`, `carryForwardReason` |
| Config | version comment |

### Apply it IN PLACE - never import as a new workflow

The carry-forward reads the rates from this workflow's **static data**, which
belongs to the workflow entity. A fresh import has empty static data and would
stop on the first FFT-down run with "no published rates exist to carry
forward". Open the live workflow, paste the four nodes' code from
`workflow-v2.5.json` over the existing nodes (Config, Prepare Coins Snapshot,
Evaluate Publish Result, Success Summary), save, done. The static data already
holds `lastSuccessfulRates` from the last run that succeeded (2026-09-05); the
cycle costs get remembered from the first run where FFT answers again - until
then a carry-forward run publishes `cyclePSUsdPerM: null`, which the store
reads as "no slow-delivery budget", the honest answer.

`npm test` in this directory runs the v2.5 Code nodes out of the JSON with FFT
up, FFT down without memory, and FFT down with memory.

## v2.4 (superseded by v2.5): publish anchors, not the expansion (2026-08-27)

**`workflow-v2.4.json` is the ready-to-import artifact.** It is derived from
`workflow-v2.3.1-current.json`, the owner's live export of 2026-08-27
(`versionId d9c8c908`), with seven edits across two code nodes plus one stale
comment corrected in Config, and nothing else touched. Import it and skip the
rest of this section; the detail below records what changed and why.

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
| Validate | a bottom-coverage check: the lowest anchor must reach the range minimum |

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
descending-price guard, the exact overrides, the throw-based `fail()` routing,
and the signing.

### One price change the owner should know about

Above 2,000,000 the `console_fast` floor moves. The old workflow carried
`console_normal`'s terminal multiplier - 10,150 at its 2M maximum - forward when
comparing a fast price against the normal formula. v2.4 follows the curve
instead, and the curve keeps rising past 2M: 10,250 at 5M, 10,500 at 20M.

Wherever a normal-derived floor binds for `console_fast` between 2M and 20M, it
is now up to 3.45% higher. Laravel's anchored curve does the same thing, so the
two agree with each other; the change is against the previous workflow.

This is arguably the more correct reading - a fast order at 15M should not be
floored against a rate the curve left behind at 2M - but it can move large-order
fast prices the first time an anchored run lands.

### A guarantee that narrowed

Prepare's override pass and Validate's simulation both walk from each group's
declared minimum, which is 50,000. Laravel now serves from the live admin floor
of 10,000 by clamping to the first anchor, so the eight quantities from 10,000
to 45,000 are priced but never simulated for non-descent, and can never receive
an exact override.

With current rates the schedule stays ascending there - the fixed 300-halalah
fee term dominates, and a descent would need a rate under about 2.7 SAR per
million - so this is a gap in what the run proves, not a live defect. Lower the
first anchor to the admin floor if that guarantee is wanted back.

### Verification before this file was published

- Every code node parses.
- No reference to `multipliers_basis_points` remains anywhere in the export.
- The node set is identical - 18 nodes, same names, same connections - and
  three code nodes differ from the live export: Prepare and Validate for the
  seven functional edits, and Config for a one-line comment that said the
  anchors are "linearly interpolated every 10K". That was stale twice over: the
  grain is 5,000, and the workflow no longer interpolates at all.
- `tests/Feature/Automation/CoinsPricingWorkflowExportTest.php` reads the eleven
  anchors out of this export's own Config node, posts them through the live
  contract, and asserts Laravel accepts them - a 10,000-coin order prices at
  11,000 bp, a 200,000 order at 10,400, a 20,000,000 order at 10,500. It also
  walks all 4,000 buyable quantities and asserts the export's interpolation and
  `CoinsMultiplierCurve` agree at every one. Editing the anchors in Config
  without re-running the suite will now fail the build rather than pass against
  a stale constant.

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
