# Instant Coins guest-cart storefront final report

## Outcome

The approved storefront journey is complete on `agent/instant-coins-guest-cart` and ready for the authorized local merge into `main`.

- Coins totals now come from one server-built, exact, versioned schedule and update synchronously through O(1) browser lookups. Amount changes issue no quote GET and never show an updating-price delay.
- Guests can complete all five configurator steps and add Coins to a secure owner-scoped cart without authenticating first. Add-to-cart always re-quotes on the server.
- EA credentials remain encrypted in the temporary secret record; raw credentials are absent from URLs, Inertia props, cart output, browser storage, logs, and idempotency responses.
- Guest carts are claimed transactionally after login or registration, including key rotation and add/claim races. Claim markers have bounded retention.
- Authentication pages use the shared storefront header/footer, Arabic and English routing, Thmanyah typography, responsive two-panel layouts, and keyboard-accessible controls.
- The WordPress-faithful hero/header/footer polish includes exact approved copy, live `#coins` navigation state, correct Arabic bidi composition, floating Coins, reduced-motion fallbacks, and provider attribution inside the currency preferences dialog.

## Review and fix ledger

Clean Code, Test, and Docs guard passes were completed for every task and again across the branch. Task-level independent reviews and one whole-branch review were resolved through bounded RED/GREEN fix rounds.

Final review fixes included:

- rejecting mixed or partially malformed pricing snapshots;
- strict guest-owner migration validation, stable authenticated idempotency fingerprints, active-only uniqueness, real production acquisition races, and key-rotation continuity;
- fail-closed cart projections and credential validation recovery;
- durable claim markers, logout on claim failure, deterministic race locking, and retention cleanup;
- localized password confirmation inside the storefront auth shell;
- cold direct `#coins` hydration and restrained coin rotation;
- cross-database pricing tests using the active grammar and MariaDB's strict DECIMAL failure boundary.

The final most-capable whole-branch review returned **SPEC PASS / QUALITY PASS** with no actionable P0, P1, or P2 finding after these fixes.

## Final aggregate gate

Fresh final command:

```powershell
$commonGitDir = (Resolve-Path (git rev-parse --git-common-dir)).Path
$toolsDir = (Resolve-Path (Join-Path $commonGitDir '..\..\tools')).Path
$env:PHPRC = Join-Path $toolsDir 'php.ini'
$env:PHP_INI_SCAN_DIR=''
php (Join-Path $toolsDir 'composer.phar') ci:check
```

Result: exit 0 in 76.4 seconds.

- Composer validation: passed.
- Pint: passed.
- PHPStan: 0 errors.
- Pest: 344 tests; 341 passed, 3 expected skips; 17,813 assertions.
- Vitest: 16 files; 196/196 passed.
- ESLint, Prettier, TypeScript: passed.
- Vite production build: passed, 2,328 modules.
- `git diff --check`: passed.

The first aggregate attempt was stopped only by its 180-second command-wrapper timeout before producing a test failure. The same command immediately completed green under the corrected 600-second wrapper.

## MariaDB lifecycle and concurrency

Final rerun used the verified official MariaDB 12.3.2 Windows archive on isolated `127.0.0.1:33321`.

- `migrate:fresh`: all 12 migrations ran.
- Broad 19-file pricing/cart/auth selection: 269 tests; 264 passed, 5 expected skips; 16,005 assertions.
- Both portability regressions passed: grammar-aware query counting and MariaDB strict DECIMAL rejection.
- Full rollback: all 12 migrations became Pending.
- Remigration: all 12 returned to Ran.
- Critical six-file concurrency selection: 31 tests; 30 passed, 1 expected SQLite-only skip; 176 assertions.
- Graceful shutdown completed; port, PID, disposable database, logs, and archive were removed.

During intentionally adversarial concurrency/lifecycle cases MariaDB logged three error-123 row-change messages. All concurrency assertions passed, both affected tables returned `CHECK TABLE ... status OK`, the post-remigration critical gate passed, and no warning remained. This is recorded as operational evidence rather than a failed product contract.

## Browser contract

The final isolated preview used a copied disposable SQLite database, seeded catalog/rules and fresh local display-rate rows, and the production Vite build. The server, config cache, scripts, database copy, and browser tabs were removed afterward.

- Arabic and English matrix at 320, 390, 768, and 1440 CSS px: correct `lang`/`dir`, exact copy, Thmanyah Serif Display headings, Thmanyah Sans body, no horizontal overflow, and no console warnings/errors.
- Direct/click/Back `#coins` behavior: one correct current navigation item, including cold direct loads.
- Amount controls: `50,000 / SAR 4.00` -> `500,000 / SAR 28.00` -> `600,000 / SAR 33.00` -> typed `750,000 / SAR 42.00`, all in the same render path with zero refreshing copy. Focused frontend tests prove zero quote GETs for presets, adjustments, typed input, and keyboard range changes.
- Guest flow: all five steps completed without login; the safe summary contained platform, delivery, quantity, and authoritative total only.
- Add-to-cart: one server-requoted POST created one guest cart line and one encrypted secret record. The cart displayed the safe 750,000-Coin line, SAR 42.00 total, expiry, and backup-code count.
- Registration claim: after creating a disposable account, `/en/cart` retained exactly one line and cart count 1; no raw EA email, password, or backup code appeared.
- Arabic 320px cart: RTL, Thmanyah Serif Display heading, cart count 1, safe line present, document width 305 within a 320px viewport.
- Reduced motion: all three decorative coin animations computed to `none`; page remained overflow-free.
- URL, DOM, summary, and cart leak probes: no synthetic password or backup-code value found. Browser console: 0 warnings/errors.

## Provider attribution

ExchangeRate-API attribution is a required, linked item inside the language/currency preferences dialog. It is intentionally absent from the footer. Display exchange rates remain illustrative only; SAR is authoritative for server quoting and cart storage. Missing or stale non-SAR rates fail closed.

## Commit range

Merge base: `7ecbf7836181735f97e658404fb860a6b47ba0eb`.

The completed range contains Tasks 1-7, review fixes, and final cross-database test hardening through `d90eb55bfeb15f203037e46f0aecd95027709e59`. Task reports are stored under `.superpowers/sdd/2026-08-10-instant-coins-guest-cart-storefront/`.

## Release boundary

- Authorized finish: merge locally into `main`.
- Not performed: push, deployment, checkout, or payment activation.
- Payment marks remain labeled as methods planned for launch; checkout/payment controls are intentionally absent.

## Local merge verification

`main` fast-forwarded from `7ecbf78` to the completed branch at `0eab6ae`. The merged feature worktree and branch were then removed.

The first root-level frontend run honestly exposed a repository-harness issue: Vitest discovered tests inside a different nested `.worktrees/task-2-domain` worktree and loaded a second React copy. A focused RED reproduced the failure. The permanent fix follows current Vitest guidance by constraining `test.dir` to `resources/js/__tests__`; ESLint likewise ignores `.worktrees` explicitly.

After that fix, the original unmodified aggregate command ran from `main` and exited 0 in 71 seconds: Composer/Pint/PHPStan passed, Pest remained 341 passed plus 3 expected skips (17,813 assertions), Vitest passed 196/196, and ESLint/Prettier/TypeScript/Vite all passed. No push or deployment followed.
