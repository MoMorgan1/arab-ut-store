# Task 4 Report: Live Coins Quote Refinements

## Outcome

Coins quotes now preserve the authoritative SAR total while returning a session-derived display total from fresh cached rates. Foreign conversion is fixed-point integer arithmetic with half-up rounding, a 30-hour hard freshness limit, and fail-closed behavior for missing, stale, overflowing, invalid, or zero-output rates. The request path never contacts the exchange-rate provider.

The daily `currency:refresh-display-rates` command is the only provider HTTP path. It validates a complete SAR response, quantizes decimal tokens without floats, and atomically updates USD, EUR, and GBP. The quote request still rejects currency input, and rejected query parameters cannot mutate the session.

The configurator requests every valid change immediately, aborts superseded work, ignores stale responses, retains the old price while refreshing, displays grouped Latin digits while editing, formats exact minor units, and offers the WordPress Fast-delivery action for Normal amounts at or above 1.5M. The action selects Fast and returns focus to delivery. The Arabic hero badge is exactly `كل اللي تحتاجه في FC 27 بمكان واحد`.

The footer discreetly includes the provider-required link `Rates By Exchange Rate API` to `https://www.exchangerate-api.com`. Checkout authority remains SAR; foreign totals are display-only.

## Authoritative sources and current documentation

Repository sources inspected before implementation:

- `.superpowers/sdd/2026-08-10-wordpress-header-footer-parity/display-currency-audit.md`
- WordPress `inc/currency.php`
- deployed `configurator-2.3.24.js`
- current Laravel quote/configurator implementation and tests
- `AGENTS.md` and `.impeccable.md`

Official documentation rechecked on 2026-08-10:

- Laravel 13 HTTP client: https://laravel.com/docs/13.x/http-client
- Laravel 13 scheduling: https://laravel.com/docs/13.x/scheduling
- ExchangeRate-API Open Access: https://www.exchangerate-api.com/docs/free
- supported currencies: https://www.exchangerate-api.com/docs/supported-currencies
- provider terms: https://www.exchangerate-api.com/terms

The existing WordPress provider decision was retained. Open Access requires attribution, updates once daily, permits cached end-use, and is informational/display-only. The implementation therefore schedules one daily refresh, persists rates locally, performs no request-path HTTP, and never uses a foreign display amount for checkout.

## TDD evidence

### Initial backend RED

Run before production edits with the required SQLite runtime:

```text
$env:PHPRC='C:\Users\hp\Documents\Codex\2026-08-08\hi\work\tools\php.ini'
$env:PHP_INI_SCAN_DIR=''
php artisan test tests/Unit/Pricing/ConvertDisplayMoneyTest.php tests/Feature/Console/RefreshDisplayExchangeRatesTest.php tests/Feature/Store/CoinsQuoteTest.php tests/Feature/Foundation/LocaleTest.php --compact

79 tests: 57 passed, 10 failed, 12 errors, 257 assertions
```

Expected failures were the absent converter and refresh command/schedule, absent `displayTotal`, non-SAR missing/stale rates returning 200 instead of 503, and rejected quote currency mutating the session. There were no SQLite or harness failures.

### Initial frontend RED

```text
npx vitest run resources/js/__tests__/store/coins-api.test.ts resources/js/__tests__/store/coins-quantity.test.ts resources/js/__tests__/store/coins-home.test.tsx

3 files failed; 92 tests: 76 passed, 16 failed
```

Expected failures covered the absent/unchecked display-total contract, missing exact minor-unit formatter, zero immediate requests because of the 250 ms debounce, ungrouped editing, missing EUR display, and missing Normal-to-Fast action.

### Zero-output audit RED/GREEN

An additional invariant was captured after review:

```text
RED: ConvertDisplayMoneyTest — 10 tests, 9 passed, 1 failed
Expected DomainException was not thrown for 1 halalah × 0.00100000.

GREEN: ConvertDisplayMoneyTest — 10/10 passed, 10 assertions.
```

The converter now rejects a positive foreign amount that rounds to zero minor units.

### Focused GREEN

```text
Backend: 80/80 tests passed, 285 assertions.
Frontend: 4 files, 97/97 tests passed.
```

The backend set includes converter, refresh command/schedule, quote, and locale/session tests. The frontend set includes API contract, amount/reducer, homepage interactions, and footer attribution.

## Implementation evidence

- `CoinsQuote.total` remains unchanged SAR halalah; `displayTotal` is an additional response-only `{amountMinor, currency}` field.
- `ConvertDisplayMoney` parses the persisted eight-decimal string into a scaled integer, checks multiplication overflow, rounds half-up, and performs no float division.
- Non-SAR quote conversion uses only the exact `SAR → session currency` row and fails at `fetched_at <= now() - 30 hours`.
- `SetDisplayCurrency` accepts currency only on GET storefront HTML navigation; quote routes and JSON requests cannot mutate it.
- `CoinsQuoteRequest` remains the authority for its exact input keys, so `currency` stays prohibited.
- The refresh command uses Laravel HTTP timeouts/retries only from the command, validates success/base/completeness/raw decimal tokens before entering a database transaction, and updates all configured foreign rows together.
- `routes/console.php` contains exactly one daily schedule for `currency:refresh-display-rates`.
- Frontend API parsing requires a positive safe-integer `displayTotal.amountMinor` and an exact match to the page-selected currency. Currency is never appended to the quote URL.
- The debounce was removed. Every valid quantity transition starts a request synchronously; AbortController plus a monotonically increasing request version protects against stale responses.
- Existing successful quote state becomes `refreshing`, preserving the previous displayed total until replacement succeeds.
- Minor-unit display uses BigInt major/remainder parts and Intl parts formatting; no binary `/ 100` conversion is used.
- The provider attribution is always present in the storefront footer, discreetly adjacent to the existing disclaimer.

## Browser verification

An isolated temporary copy of the local SQLite database was used with a fresh EUR row. No repository database state was changed. The local provider request itself failed closed because outbound connectivity was unavailable and wrote zero rows; browser verification intentionally used the isolated cached row to exercise the request path.

Required locale/viewport matrix completed at 320, 390, 768, and 1440 px for both English and Arabic (8/8 cases):

| Locale | 320 | 390 | 768 | 1440 |
| --- | :---: | :---: | :---: | :---: |
| English/LTR | pass | pass | pass | pass |
| Arabic/RTL | pass | pass | pass | pass |

All cases had meaningful content, the correct direction, provider attribution, no error overlay, and zero document/body horizontal overflow. The Arabic badge matched the required no-comma string in every Arabic case.

Interaction pass: English, 390 × 844, EUR session, Slow 3G.

- PC 50K loaded `EUR 1.82`.
- A valid 1M → 500K change immediately rendered grouped `500,000`, retained `EUR 36.50`, showed `Refreshing price…`, and set `aria-busy=true` while the request was in flight.
- The completed result became `EUR 18.25`.
- Network evidence: 50K `200`, an intermediate 100K request `ERR_ABORTED`, 1M `200`, and 500K `200`. Every quote URL contained only platform/quantity; none contained currency.
- Step heading focus and amount-button keyboard focus were programmatically present.
- Reduced-motion emulation matched `prefers-reduced-motion: reduce`; the page loaded at 390 × 844 without overflow.
- Browser console warnings/errors were empty.

This confirms latency behavior, immediate dispatch, cancellation/stale protection, retained price, exact display currency, focus, reduced motion, overflow safety, and a clean console.

## Quality gates

Full PHP gate with the prescribed PHPRC:

```text
Laravel config clear: passed
Pint full: passed
PHPStan full: passed, 0 errors
Pest full: 229 tests; 226 passed, 3 skipped; 2,046 assertions
```

The first full Pest run exposed one legacy assertion expecting the Arabic hero comma. That assertion was updated to the newly mandated exact copy; the full rerun passed.

Full frontend CI:

```text
npm run ci:check
Vitest: 8 files, 122/122 tests passed
ESLint: passed
Prettier: passed
TypeScript: passed
Vite production build: passed, 2,327 modules
```

The Vite build emitted only existing notices for public runtime-resolved font, hero, and stadium paths.

MariaDB was not run because no local MariaDB server was configured (`127.0.0.1:3306` refused connections). Task 4 does not change schema; all database behavior was executed on mandatory SQLite, including atomic refresh, freshness, uniqueness-backed exact-pair lookup, and quote integration.

## Guard review

Clean-code review found a single conversion boundary, no request-path provider client, no fallback rates, explicit overflow/freshness failures, and no duplicated checkout calculation. Test review found behavior-level assertions for contract, atomicity, schedule, session immutability, cancellation, retained results, focus, and display formatting. Documentation review checked command names, counts, limitations, and the provider link against fresh command/browser evidence.

## Review fix round 1: caret stability and duplicate rate keys

Two review findings were reproduced before production edits.

Focused React RED:

```text
1 failed, 50 skipped
Middle insertion rendered 1,500,000 but selectionStart/selectionEnd moved to 9 instead of logical position 3.
```

Focused PHP RED with the prescribed PHPRC:

```text
1 failed
A payload containing duplicate USD keys unexpectedly completed successfully (status 0).
```

The amount input now captures selection endpoints as counts of preceding Latin digits before controlled-value normalization, then restores both endpoints against the newly grouped value in a layout effect. This preserves the logical caret without removing live commas or changing immediate quote dispatch.

The refresh command now lexically collects each configured currency token from the isolated raw `rates` object and requires exactly one occurrence before quantization. Missing or duplicate configured keys fail before the transaction, leaving all prior rows unchanged; JSON last-key-wins behavior can no longer disagree with the selected decimal token.

Focused GREEN:

```text
Caret regression: 1/1 passed
Duplicate-key regression: 1/1 passed, 2 assertions
Complete homepage suite: 51/51 passed
Complete refresh-command suite: 8/8 passed, 17 assertions
```

Fresh full gates:

```text
Pint: passed
PHPStan: passed, 0 errors
Pest: 230 tests; 227 passed, 3 skipped; 2,048 assertions
Vitest: 8 files, 123/123 tests passed
ESLint, Prettier, TypeScript: passed
Vite production build: passed, 2,327 modules
```

Browser verification used English and Arabic at 390 × 844. In both LTR and RTL, starting from `100,000` at caret 1, inserting `5` in the middle produced `1,500,000` with selection 3/3. Selecting the inserted digit at 2–3 and pressing Backspace returned `100,000` with selection 1/1; subsequent typing of `2` produced `1,200,000` with selection 3/3. The input remained focused, horizontal overflow was absent, and browser console warnings/errors were empty.
