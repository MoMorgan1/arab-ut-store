# Task 2 Report — Instant Browser Schedule Lookup

## Outcome

The homepage now validates the server-published Coins quote schedules once at the Inertia prop boundary and performs exact O(1) indexed lookups in the browser. Valid platform, delivery, text, range, keyboard-range, chip, and adjustment changes replace the displayed total synchronously and make zero quote requests. Temporarily invalid text editing alone retains the last exact total until commit normalization.

The existing GET quote parser and `useCoinsQuoteRequest` compatibility hook remain in the repository, but the homepage configurator no longer mounts the hook. No pricing, FX, tier, override, or rounding formula was duplicated in TypeScript.

## TDD and debugging evidence

Focused RED was captured before production edits:

```powershell
npx vitest run resources/js/__tests__/store/coins-home.test.tsx -t "shows exact schedule totals immediately without requesting another quote"
```

Result: failed because the homepage had no validated schedule price to render synchronously. Inspection then confirmed the root cause: `CoinsConfigurator` mounted `useCoinsQuoteRequest`, which dispatched loading/refreshing state and issued a GET whenever a valid selection changed.

The first focused GREEN covered the parser/index lookup and homepage behavior. The completed focused gate was:

```powershell
npx vitest run resources/js/__tests__/store/coins-schedule.test.ts resources/js/__tests__/store/coins-home.test.tsx resources/js/__tests__/store/coins-credentials-flow.test.tsx
```

Result: 80/80 passed. Coverage includes minimum/middle/maximum exact lookup, illegal quantities, strict metadata and array validation, per-mode fail-closed behavior, all homepage controls including keyboard range, invalid text editing, currency selection, unavailable schedules, resume flow, and zero homepage quote fetches.

## Verification

Final all-in-one frontend gate:

```powershell
npm run ci:check
```

Results:

- Vitest: 13 files, 162/162 tests passed.
- ESLint: passed with zero errors.
- Prettier: all checked files matched.
- TypeScript: `tsc --noEmit` passed.
- Vite production build: passed.
- `git diff --check`: passed.

The build repeated existing warnings for runtime-resolved `/images/store/*` and `/fonts/thmanyah/*` URLs. No new build error or warning category was introduced by Task 2.

## Changed files

- `resources/js/lib/coins-quote-schedule.ts`
- `resources/js/types/coins.ts`
- `resources/js/pages/store/home.tsx`
- `resources/js/components/configurator/coins/coins-configurator.tsx`
- `resources/js/components/configurator/coins/amount-step.tsx`
- `resources/js/__tests__/store/coins-schedule.test.ts`
- `resources/js/__tests__/store/coins-home.test.tsx`
- `resources/js/__tests__/store/coins-credentials-flow.test.tsx` — mechanical fixture/assertion migration to the schedule contract.

## Clean Code and Test Guard review

- Schedule parsing is isolated from React and split into named validation helpers; indexed lookup performs no I/O and no pricing calculation.
- Runtime validation rejects missing or additional root keys, malformed bounds/identity/timestamps, wrong currency or tuple metadata, unsafe/non-positive totals, and array-length mismatches. A malformed mode cannot leak another mode's price.
- The homepage receives only validated `CoinsQuoteSchedules`; consumers do not cast untrusted page props.
- Amount navigation now also requires a currently valid input, preventing a retained invalid-edit total from enabling Continue.
- The tests assert user-visible prices and network absence rather than implementation details. Runtime-invalid fixtures use narrow casts only where deliberate corruption cannot be represented by the valid TypeScript type.
- The large existing homepage test was reduced by removing obsolete debounce/loading/stale-network cases and replacing them with direct schedule-contract cases; no duplicate pricing formula is used in expectations.

## Concerns and boundaries

- `CoinsQuote.priceVersion` remains optional because the legacy GET parser is intentionally preserved unchanged for compatibility; every schedule-derived quote contains the validated positive version.
- Task 2 does not change the guest-cart/login flow, checkout behavior, styles, or UI polish. Those remain for their owning tasks.

## Review fix round

The review found two fail-closed gaps and one lost compatibility-test surface. Focused RED was captured before the parser fix:

```powershell
npx vitest run resources/js/__tests__/store/coins-schedule.test.ts -t "undeclared field|timestamps differ"
```

Result: 2/2 failed for the expected reasons. A schedule containing an undeclared field was accepted, and three schedules with different `pricedAt` values were returned as a usable snapshot.

The parser now requires the exact 13-field schedule wire contract. An extra field closes only that malformed mode. When all three otherwise-valid schedules do not share one exact server timestamp, the complete snapshot closes rather than mixing quote generations.

The preserved `useCoinsQuoteRequest` compatibility path now has its own reducer-backed harness instead of depending on obsolete homepage-network tests. It covers loading to success, 422 validation, 503 unavailable, invalid 200 responses, unmount abort, stale-response suppression after a quantity change, and explicit invalidation. The homepage still does not mount this hook.

ULID and UTC timestamp validation moved to `resources/js/lib/wire-validators.ts`. Both the legacy GET parser and schedule parser consume the same functions. The shared validator matrix covers valid ULID boundaries, forbidden ULID characters, `Z`/`+00:00` UTC forms, fractional seconds, non-UTC offsets, malformed offsets, and invalid calendar dates; the existing API and schedule contract tests protect both consumers.

Focused review gate:

```powershell
npx vitest run resources/js/__tests__/store/coins-schedule.test.ts resources/js/__tests__/store/coins-api.test.ts resources/js/__tests__/store/coins-quote-request.test.tsx resources/js/__tests__/store/wire-validators.test.ts
```

Result: 74/74 passed.

Final review gate:

```powershell
npm run ci:check
```

Result: 15 Vitest files and 184/184 tests passed; ESLint, Prettier, TypeScript, and the Vite production build passed. The build repeated only the previously documented runtime asset/font URL warnings.

### Partial-snapshot timestamp follow-up

A final review identified that the timestamp check only ran when all three modes parsed successfully. RED was captured with a malformed PC schedule plus individually valid Fast and Normal schedules carrying different timestamps:

```powershell
npx vitest run resources/js/__tests__/store/coins-schedule.test.ts -t "remaining valid schedules have different timestamps"
```

Result: 1/1 failed as expected because both console schedules remained usable. The timestamp invariant now compares every non-null schedule. Any disagreement closes the complete snapshot even when another mode was already malformed. The complete schedule suite then passed 30/30.
