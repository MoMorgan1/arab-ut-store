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
