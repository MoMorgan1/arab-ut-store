# Task 6 — Coins credentials and real cart UI report

## Status

Complete. The WordPress-first Coins flow now continues from the reviewed Task 5 backend through authenticated EA credential entry, a secret-free review step, secure add-to-cart submission, and a real read-only cart. Payment, checkout, order creation, fake checkout controls, and unsupported cart mutations remain out of scope and are absent.

## Sources audited before implementation

### WordPress visual and interaction authority

The following files were read from `work/wordpress-public-html-20260809`:

- `wp-content/themes/arabut-child/templates/homepage.php` — the platform, conditional delivery, amount, account details, and summary/add hierarchy.
- `wp-content/themes/arabut-child/assets/css/homepage.css` — progress rail, selection cards, account form, summary, warm black/gold surface treatment, and responsive behavior.
- `wp-content/themes/arabut-child/assets/js/configurator.js` — step transitions, PC delivery skip, amount controls, validation, and summary interaction.
- `wp-content/plugins/arabut-core/arabut-core.php` — the legacy WordPress request/credential behavior that the secure Laravel contract supersedes.

The React/Inertia implementation keeps the five-decision WordPress hierarchy. PC exposes four progress decisions and omits delivery visibly and semantically; console exposes all five. The WordPress three-optional-code behavior and browser/session credential handling were deliberately not copied because the approved secure contract requires exactly five distinct codes and memory-only client state.

### Reviewed Laravel/React surface

Before editing, the audit covered the existing Coins reducer, platform/delivery/amount components, quote request hook, store home/cart controllers, Inertia shared props, route registration, translations, cart action, request middleware, Task 5 tests, `coins-credentials-cart-audit.md`, and every Task 5 report fix round.

### Official current documentation consulted

All pages returned HTTP 200 on 2026-08-10:

- React: `https://react.dev/learn/sharing-state-between-components`
- React: `https://react.dev/learn/preserving-and-resetting-state`
- Inertia.js: `https://inertiajs.com/shared-data`
- Inertia.js: `https://inertiajs.com/manual-visits`
- Laravel 13: `https://laravel.com/docs/13.x/csrf`

These sources were used only for the installed React 19/Inertia 3/Laravel 13 integration points: ordinary component state, reset/unmount behavior, shared props, `router.visit`, and the `X-CSRF-TOKEN` request header.

## Design and skill decisions

The required `frontend-design`, `ui-ux-pro-max`, `arrange`, `typeset`, `clarify`, `adapt`, and `polish` passes were applied against the approved WordPress source rather than starting a new discovery exercise. The TDD, systematic-debugging, verification-before-completion, Clean Code Guard, Test Guard, Docs Guard, and React best-practices instructions were also read and applied.

UI Pro Max was run with:

```text
python C:\Users\hp\.codex\skills\ui-ux-pro-max\scripts\search.py "Arabic-first gaming ecommerce secure account credentials premium dark warm gold" --design-system -p "Arab UT Coins"
```

Its Liquid Glass, blue/green palette, and Noto font recommendations were rejected because they contradict the WordPress authority, the supplied Thmanyah fonts, and Arab UT's warm black/gold identity. Its useful form guidance was retained: visible labels, inline errors, exact invalid-field focus, explicit loading/retry states, 44px targets, and reduced-motion support.

Final design characteristics:

- Thmanyah Sans for body/UI and Thmanyah Serif Display for headings.
- Arabic-default RTL and intentional English LTR, including LTR credential fields.
- Gulf-light Arabic copy and `كوينز` terminology.
- WordPress card/progress/account/summary hierarchy with tighter spacing, clearer trust copy, stronger focus treatment, and responsive backup-code/cart grids.
- No Liquid Glass addition, generic blue/green redesign, fake social proof, fake checkout, or payment control.

## Implemented behavior

### Safe selection and authentication boundary

- Guests can configure platform, conditional delivery, and quantity.
- The guest continuation is the reviewed login-resume endpoint and contains only `platform`, optional `delivery`, and `quantity`.
- Credential fields are never mounted for guests.
- The authenticated home controller rehydrates only a server-validated safe selection at the credentials step.
- A resume request containing `credentials`, `ea_email`, `ea_password`, or `backup_codes` is rejected and rehydrates nothing.

### Credential form

- EA email is visibly labelled and validated.
- EA password is opaque, bounded, not trimmed, and has an accessible show/hide control.
- Exactly five distinct 8-digit ASCII backup codes are required.
- Backup codes are sanitized to ASCII digits and capped at eight characters.
- The exact first invalid field receives focus and every error is connected with `aria-describedby`.
- Password and code controls disable autocomplete/password-manager capture hints where the browser supports them.
- Credentials live only in ordinary React component memory and a transient ref used for submission.
- Explicit cancel replaces the credential object and returns to amount; success replaces it before navigation; component cleanup clears the transient refs.

### Submission boundary

- `resources/js/lib/coins-cart-api.ts` rejects cross-origin endpoints before constructing a credential request.
- The helper sends same-origin JSON with `credentials: same-origin`, `cache: no-store`, `Accept`, `Content-Type`, `Idempotency-Key`, and `X-CSRF-TOKEN` sourced from the Blade CSRF meta tag.
- The idempotency key is generated in memory, blocks double submission, is reused only after an inconclusive transport failure, and is replaced after a conclusive response or changed/new submission.
- A 201 response is accepted only when its cart count, ULID item id, and cart URL pass the safe response contract.
- Successful submission clears credentials, updates the header count, and visits the returned same-origin cart URL.
- Transport, validation, conflict, unavailable, and generic errors expose localized safe copy without embedding request data.

### Real read-only cart

- `store/cart` renders authenticated safe cart lines from the reviewed backend projection.
- Each line shows the Coins service, validated platform/delivery facts, Coins quantity, authoritative SAR snapshot, masked email, five-code status, and retention expiry.
- Missing safe-projected configuration fields render an em dash instead of inventing PS/Xbox, PC delivery, or a zero quantity.
- Expired, purged, missing, or invalid credential summaries show only the re-entry state.
- No credential plaintext/ciphertext, checkout, payment, order creation, remove button, or unsupported mutation is rendered.

## TDD evidence

### Initial frontend RED

Command:

```text
npm test -- resources/js/__tests__/store/coins-cart-api.test.ts resources/js/__tests__/store/coins-credentials-flow.test.tsx resources/js/__tests__/store/store-cart.test.tsx
```

Result: exit 1. Three files failed. The API/cart imports did not exist and all six credentials-flow scenarios failed because amount had no continuation/auth gate/credential UI.

Initial GREEN after the first production slice: three files and 11 tests passed. The focused home plus new UI regression subsequently passed four files and 63 tests.

### Initial backend shared-prop/cart RED

Command:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/HomeCoinsConfiguratorTest.php tests/Feature/Store/CoinsCartTest.php --filter="exact localized Coins contract|rehydrates only|cart reads expose safe" --stop-on-failure
```

Result: exit 1 after the first test, 14 assertions; `coinsCart.addUrl` was missing.

Focused GREEN: four tests, 170 assertions. The two full Coins feature files later passed 58 tests and 706 assertions.

### Security and honesty RED/GREEN rounds

- Resumed credentials quote gate: frontend RED was one failure out of seven because Continue was enabled before the authoritative quote; GREEN was seven of seven.
- Credential-bearing resume rejection: backend RED was one failed test/28 assertions because the safe selection still rehydrated; GREEN was one test/28 assertions.
- Real-cart route regression: full Pest exposed the obsolete `store/simple-page` expectation; the test now asserts the real `store/cart` safe contract.
- Missing projected facts: cart UI RED was one failure out of two because an empty safe configuration invented `PS / Xbox`; GREEN was two of two with em-dash fallbacks.

## Security verification

- Source grep found no Coins credential use of `localStorage`, `sessionStorage`, Inertia remember, console, analytics, logging, hidden props, or URL serialization.
- Browser guest resume URL was exactly `/en/cart/items/coins/resume?platform=pc&quantity=50000`; no credential field was mounted.
- Browser authenticated resume URL contained only `platform=pc`, `quantity=50000`, and `step=credentials`.
- Browser storage during credential entry contained only the pre-existing `appearance` local-storage key; session storage was empty.
- Browser summary checks returned false for the entered email, password sentinel, and first backup code in body text; the URL was unchanged.
- After 201, password inputs were unmounted and the cart body contained none of the entered email, password, or backup-code sentinels.
- The cart showed only masked email, password-present status, code count, and expiry.
- Offline browser submission produced `Try adding again`; retry succeeded after restoring the network. The focused test proves the two transport attempts use the same UUID and double-click produces one request.
- API tests prove a cross-origin endpoint is rejected before fetch and malformed success data fails closed.
- Backend feature tests prove safe JSON error/response bodies and Inertia cart props contain no synthetic credential sentinels.
- No application console errors or page errors were reported in the final browser pass.

## Responsive browser matrix

Chromium was driven through `agent-browser` against the built Vite assets and a local Laravel 13 preview. Windows PHP 8.5's CLI-server SAPI required a temporary `config:cache` to avoid its configuration-directory discovery issue; the cache, preview server, router, logs, user, and session state were removed after verification.

| Locale | Width | Direction | Horizontal overflow | Result |
|---|---:|---|---|---|
| Arabic | 320 | RTL | No | Pass |
| English | 320 | LTR | No | Pass |
| Arabic | 390 | RTL | No | Pass |
| English | 390 | LTR | No | Pass |
| Arabic | 768 | RTL | No | Pass |
| English | 768 | LTR | No | Pass |
| Arabic | 1440 | RTL | No | Pass |
| English | 1440 | LTR | No | Pass |

Additional browser checks:

- 320px English home and 390px Arabic cart received visual inspection.
- All measured configurator buttons were 44–48px high.
- PC displayed four semantic decisions and no delivery step; the console hierarchy remains five decisions.
- Keyboard/error behavior focused `coins-ea-email` on the empty form.
- Five accessible backup-code textboxes were exposed.
- The 320px viewport covers the effective narrow layout expected at 200% desktop zoom without overflow.
- Reduced-motion emulation produced `0s` animation and transition duration.
- The real Arabic cart had no checkout/payment text or control and remained overflow-free.

## Final gates

### Frontend

```text
npm run ci:check
```

Passed: 11 Vitest files, 136 tests; ESLint; Prettier; TypeScript; and the production Vite build.

Vite retained its existing informational warnings for absolute `/fonts/...` and `/images/...` runtime URLs. The real browser preview loaded those public assets correctly.

### Backend

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest --stop-on-failure
php -d extension=mbstring vendor\bin\pint --parallel --test
php -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpstan analyse --no-progress --memory-limit=1G
php -d extension=openssl -d extension=mbstring C:\Users\hp\Documents\Codex\2026-08-08\hi\work\tools\composer.phar validate --strict --no-check-publish
```

Passed: 281 Pest tests, 3 skipped, 2,687 assertions; Pint; PHPStan with zero errors; Composer validation.

`php artisan test` was not used as final evidence because this Windows PHP installation launches Pest child processes without the command-line SQLite extension flags. Direct `vendor\bin\pest` with the required extensions is the repository's working full-suite invocation and passed.

### Repository checks

- `git diff --check`: passed.
- No preview router/log/cache/test-user residue remains.
- No unrelated worktree files were modified.

## Guard self-review

### Clean Code Guard

- Names describe security and domain intent; no speculative interface/factory/feature flag was added.
- Submission catches only recoverable transport `TypeError`; response parsing fails closed; no broad error swallowing or hardcoded production success exists.
- Endpoint, CSRF, Inertia, React, and fetch calls were checked against installed packages/current official documentation.
- React components keep credential entry, review, cart rendering, and request serialization in separate modules. Large JSX blocks match the existing component convention; business helpers remain focused and cyclomatic complexity stays below the guard ceiling.
- No unused symbol or dead branch remains according to ESLint, TypeScript, PHPStan, and source review.

### Test Guard

- HTTP/fetch, Inertia navigation, randomness, and browser storage are the only mocked system boundaries.
- Backend persistence tests use the real migrated SQLite database.
- Tests assert observable URLs, DOM, focus, request contract, retries, redirect, header event, safe props, and rendered cart content rather than internal helper calls.
- Each new scenario covers a distinct security or interaction failure; variants already covered by backend data providers were not duplicated.

### Docs Guard

- Every path, route, command, endpoint, status, request header, response field, component, and behavior named in this report was checked against the current diff, route/controller/action definitions, installed manifests, CLI output, or recorded browser/test output.
- Commands in this report are the exact invocations run in this worktree.
- No unverified performance, compatibility, or production-readiness claim is made.

## Concerns

No product blocker remains. The only environmental caveats are the Windows PHP CLI-server configuration-cache workaround used for preview and Vite's existing absolute-public-asset build warnings; both were verified not to affect the rendered application, and no workaround artifact remains in the commit.

## Fix round 1 — credential and cart hardening

### Finding 1 — generated masked-email shape

Test file: `tests/Feature/Store/CoinsCartTest.php`.

RED command:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter="cart reads reject plaintext email" --stop-on-failure
```

RED result: exit 1; one failed test and nine assertions. A valid plaintext sentinel in `masked_summary.email` was projected with `requiresCredentials: false`.

GREEN command: the same focused Pest command.

GREEN result: one passed test and 12 assertions. `CartController` now accepts only one ASCII alphanumeric leading character, the literal `***@`, and a dot-qualified ASCII domain with valid label boundaries; every other stored summary becomes `null`. The plaintext sentinel is absent from both props and response content.

### Finding 2 — safe 422 credential recovery

Test file: `resources/js/__tests__/store/coins-credentials-flow.test.tsx`.

RED command:

```text
npx vitest run resources/js/__tests__/store/coins-credentials-flow.test.tsx -t "returns a rejected 422"
```

RED result: exit 1; one failed test and eight skipped. The summary remained mounted after the conclusive 422, so the credential heading and rejected fields were unavailable.

GREEN command: the same focused Vitest command.

GREEN result: one passed test and eight skipped. The JSON helper reads only exact allowlisted validation keys (`credentials`, EA email/password, the backup-code collection, and indices zero through four), maps them to the fixed UI field union, and discards response messages and values. The configurator remounts credentials without invalidating the successful quote, renders only localized field copy, and focuses the first allowlisted rejected field. Unknown, out-of-range, hostile-message, password, and code sentinels never enter visible body text, URL state, or error objects; the regression also verifies empty local and session storage.

### Finding 3 — authoritative cart count on simple pages

Test file: `resources/js/__tests__/store/store-simple-page.test.tsx`.

RED command:

```text
npx vitest run resources/js/__tests__/store/store-simple-page.test.tsx
```

RED result: exit 1; one failed test. The shared count was seven while the simple-page header rendered zero.

GREEN command: the same focused Vitest command.

GREEN result: one passed test. `cartCount` is required by `StoreLayout`, `StoreHeader`, and `SimpleStorePageProps`; every direct layout/header test supplies it, and `SimpleStorePage` passes the shared authoritative value through without a silent default.

### Finding 4 — service-derived cart presentation

Test file: `resources/js/__tests__/store/store-cart.test.tsx`.

Initial missing-service RED command:

```text
npx vitest run resources/js/__tests__/store/store-cart.test.tsx -t "does not invent cart facts"
```

Initial RED result: exit 1; one failed test and two skipped because the missing service still rendered the FC 27 Coins title.

Unsupported-service RED command:

```text
npx vitest run resources/js/__tests__/store/store-cart.test.tsx -t "another safe service type"
```

The first unsupported-service RED failed one test with three skipped because the FC 27 Coins title was invented. The Clean Code Guard boundary check then added the Coins-specific quantity assertion; its RED failed one test with three skipped because `Coins quantity` remained visible.

GREEN command:

```text
npx vitest run resources/js/__tests__/store/store-cart.test.tsx
```

GREEN result: four passed tests. Only `configuration.service_type === "coins"` can render the Coins title, official coin asset, or Coins-specific quantity label/value. Missing and other safe service types render an em dash for the service and no invented Coins presentation. `StoreCartConfiguration.service_type` now matches every value in the backend `ServiceType` enum so unsupported UI variants remain explicit rather than erased by TypeScript.

### Finding 5 — narrow LTR isolation

Test files: `resources/js/__tests__/store/coins-credentials-flow.test.tsx` and `resources/js/__tests__/store/store-cart.test.tsx`.

RED commands:

```text
npx vitest run resources/js/__tests__/store/coins-credentials-flow.test.tsx -t "keeps Arabic credential labels RTL"
npx vitest run resources/js/__tests__/store/store-cart.test.tsx -t "keeps the Arabic masked-email label RTL"
```

RED results: the credential command failed one test with eight skipped because a code label inherited `dir="ltr"` from the whole grid. The cart command failed one test with two skipped because no isolated masked-email value element existed and the whole localized sentence was LTR.

GREEN command:

```text
npx vitest run resources/js/__tests__/store/coins-credentials-flow.test.tsx resources/js/__tests__/store/store-cart.test.tsx -t "Arabic"
```

GREEN result: two passed tests and 11 skipped. The backup-code container inherits locale direction, individual credential inputs remain LTR, and the cart keeps the localized label/sentence in page direction while rendering only the masked address in `<bdi dir="ltr">`.

### Fix-round browser evidence

The production build was served through the same direct Windows PHP preview harness with explicit SQLite/mbstring/OpenSSL extensions and temporary `config:cache`. A synthetic local account and its cart, idempotency rows, logs, server, and config cache were removed afterward; final counts were zero users for the synthetic email and zero carts, port 8136 had no listener, and `bootstrap/cache/config.php` was absent.

- English 320px home: LTR, meaningful content, no framework overlay, `innerWidth` 320 and `scrollWidth` 305.
- A real Laravel 422 was produced with `player@example..com`, which passes the intentionally lightweight client precheck but fails Laravel `email:rfc`. The UI returned from summary to credentials, focused `coins-ea-email`, showed only `Enter a valid EA email.`, kept the URL exactly `/en`, and body text contained none of the password, first backup code, or backend validation wording.
- The corrected submission returned 201 and opened the real English cart. The header count was one, the recognized Coins line had exactly one product asset/title, password inputs were unmounted, body text contained no password/code sentinel, and the 320px page remained overflow-free (`305 <= 320`).
- English `/en/privacy` preserved the authoritative nonzero header count of one at 320px.
- Arabic 320px cart: root RTL, `scrollWidth` 305, masked sentence inherited RTL, and only the masked value was a `BDI` with LTR direction.
- Arabic 320px credentials: code label inherited RTL, EA email/code inputs were LTR, action targets measured 44–50px, and the visual screenshot showed the warm black/gold narrow layout without clipping.
- Final browser developer logs contained no errors or warnings; the overlay query returned false and body content was non-empty.
- Storage mutation remains covered by the focused Vitest regression using real `Storage` objects; it asserts zero local/session entries after 422. The browser-control safety contract prohibits inspecting a user's browser storage directly, so the live pass verified the URL and rendered DOM while the automated boundary test verified storage.

### Fix-round final gates and guards

```text
npm run ci:check
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest --stop-on-failure
php -d extension=mbstring vendor\bin\pint --parallel --test
php -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpstan analyse --no-progress --memory-limit=1G
php -d extension=openssl -d extension=mbstring C:\Users\hp\Documents\Codex\2026-08-08\hi\work\tools\composer.phar validate --strict --no-check-publish
```

Final results: frontend CI passed 11 Vitest files and 140 tests, ESLint, Prettier, TypeScript, and the production Vite build. Backend Pest passed 282 tests with three skipped and 2,699 assertions. Pint passed, PHPStan reported zero errors, and Composer validation passed. Vite retained only the previously documented absolute public-asset runtime warnings.

Clean Code Guard found and drove the extra unsupported-service quantity RED described above; the final production diff has intent-revealing names, exact allowlists/unions, no broad error swallowing, no speculative abstraction, and no dead symbols. Test Guard found no violations: network is mocked only at the HTTP boundary, backend persistence uses migrated SQLite, tests assert user-visible behavior/focus/props rather than internal calls, and each regression names a distinct break. Docs Guard verified every fix-round path, command, count, field name, status, enum value, browser metric, and cleanup claim against source or recorded output. `git diff --check` passed.

## Fix round 2 — mask and validation-recovery contracts

### Finding 1 — shared accepted-email mask grammar

Test file: `tests/Feature/Store/CoinsCartTest.php`.

RED command:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests\Feature\Store\CoinsCartTest.php --filter="accepted punctuation initials" --stop-on-failure
```

RED result: exit 1; the first data set failed (one failed test, nine assertions). An accepted `_player@example.test` submission was written as `_***@example.test`, but the cart reader rejected it and projected `requiresCredentials: true`. The same regression covers `_`, `+`, and `!` initials through a Pest data set.

GREEN command:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests/Feature/Store/CoinsCartTest.php --filter="accepted punctuation initials|plaintext email"
```

GREEN result: four passed tests and 48 assertions. `App\Security\CoinsMaskedEmail` is now the single writer/reader contract: the writer takes the first character and the domain after the final `@`, while the reader requires exactly one safe leading character, literal `***@`, and a domain accepted by the same Laravel `email:rfc`/length grammar used by `CoinsCartRequest`. It deliberately applies the length rule to a neutral one-character local part so every request-accepted address can still produce the existing one-character mask without exposing more local-part content. The existing plaintext-poison regression remains fail-closed and proves the sentinel is absent from props and response content.

### Finding 2 — mapped-field-gated 422 recovery

Test file: `resources/js/__tests__/store/coins-credentials-flow.test.tsx`.

RED command:

```text
npx vitest run resources/js/__tests__/store/coins-credentials-flow.test.tsx -t "keeps a non-credential 422"
```

RED result: exit 1; one failed test and ten skipped. A 422 containing only `platform`, `quantity`, `request`, and `idempotency_key` errors removed the summary and navigated to credentials even though no credential field was mapped.

GREEN command:

```text
npx vitest run resources/js/__tests__/store/coins-credentials-flow.test.tsx -t "422"
```

GREEN result: three passed tests and eight skipped. Credential-only and mixed 422 responses still remount credentials, attach only allowlisted localized field errors, and focus the first rejected field. A non-credential-only 422 remains on summary and shows only the safe localized validation error. Backend messages, values, unknown keys, credential values, password, and backup-code sentinels are never rendered.

### Fix-round browser proof

The current production build was served with the direct Windows PHP preview harness at `127.0.0.1:8136`, explicit OpenSSL/mbstring/SQLite extensions, and temporary configuration caching. A synthetic local account submitted only synthetic browser credentials. The tab, server, account, account-owned cart, account-owned idempotency row, server logs, and config cache were removed afterward; port 8136 was closed, the synthetic-user count was zero, cart count was zero, and `bootstrap/cache/config.php` was absent.

- English 320px non-credential 422: the POST boundary returned only synthetic `quantity` and `request` validation fields. The UI retained `Review and add`, displayed only `Review the highlighted EA details.`, kept `/en` unchanged, left credential inputs unmounted, reflected none of the backend/password/code sentinels, and remained overflow-free (`scrollWidth` 305 at `innerWidth` 320).
- The next submission used the restored real same-origin endpoint and reached `/en/cart`. The stored `_browser@example.test` appeared only as `_***@example.test`; no password input was mounted, neither password nor first backup code appeared in body text, no framework overlay was present, and the 320px cart remained overflow-free (`305 <= 320`).
- Arabic 320px `/cart`: the document was RTL, the same mask was isolated in `bdi[dir="ltr"]`, no password/code sentinel appeared, no framework overlay was present, and `scrollWidth` remained 305.
- Browser developer logs contained zero warnings or errors. The narrow Arabic screenshot visually confirmed the warm black/gold cart without clipping.

### Leak checks, gates, and guards

The mixed and non-credential-only Vitest cases use real `Storage` objects and assert zero local/session entries, while all three 422 cases prove credential values and backend text stay out of the rendered DOM. The live pass checked URL and visible DOM; browser-control policy prohibits inspecting a user's browser storage directly. A production-source scan over the configurator, request helper, cart controller, and mask contract found no local/session storage, Inertia remember, console, or credential-query sink. `git diff --check` passed.

```text
npm run ci:check
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest --stop-on-failure
php -d extension=mbstring vendor\bin\pint --parallel --test
php -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpstan analyse --no-progress --memory-limit=1G
php -d extension=openssl -d extension=mbstring C:\Users\hp\Documents\Codex\2026-08-08\hi\work\tools\composer.phar validate --strict --no-check-publish
```

Final results: frontend CI passed 11 Vitest files and 142 tests, ESLint, Prettier, TypeScript, and the production Vite build. Backend Pest passed 285 tests with three skipped and 2,735 assertions. Pint passed, PHPStan reported zero errors, and Composer validation passed. Vite retained only the previously documented absolute public-asset runtime warnings.

PHPStan initially reported one `argument.type` error because PHP types `strrchr` as `string|false`; the implementation was corrected to the installed Laravel `Str::afterLast` API, focused tests remained green, and the full backend/static gates were rerun. The official PHPStan explanation consulted was `https://phpstan.org/error-identifiers/argument.type`.

Clean Code Guard found no remaining violation: the shared contract owns one duplicated security rule used by both writer and reader, functions are small, installed APIs were verified in source, and there are no suppressions, broad catches, dead branches, or speculative types. Test Guard found no violation: the punctuation variants are data-driven, the three 422 scenarios have materially different response/UX contracts, HTTP is the only mocked frontend boundary, and backend projection uses the migrated database. Docs Guard verified every round-two path, command, count, test result, URL, browser metric, cleanup statement, and contract claim against source or recorded output.

No product blocker remains. Environmental caveats are unchanged: the Windows preview needs explicit PHP extensions/config caching, and Vite emits informational absolute-public-asset warnings; the rendered assets and tested flows were unaffected, and no preview artifact remains in the commit.

## Fix round 3 — remove email identity from cart reads

This section supersedes the round-one and round-two masked-email cart behavior. A syntactically valid email such as `a***@example.test` is indistinguishable from the former generated mask, so no local-part character or domain is now written to `masked_summary`, projected by `CartController`, typed in browser props, translated, or rendered. The encrypted payload remains unchanged and continues to contain the submitted EA email, opaque password, and five backup codes.

### Backend RED/GREEN

Test file: `tests/Feature/Store/CoinsCartTest.php`.

Poisoned legacy-row RED:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests\Feature\Store\CoinsCartTest.php --filter="collide with the former mask grammar|poisoned legacy summary emails" --stop-on-failure
```

Result: exit 1; one failed test and nine assertions. A legacy plaintext `masked_summary.email` caused `requiresCredentials: true` instead of being ignored while retaining the safe non-PII credential state.

Accepted-collision RED:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests\Feature\Store\CoinsCartTest.php --filter="collide with the former mask grammar" --stop-on-failure
```

Result: exit 1; the first collision data set failed with 14 assertions because `cart.items.0.credentials.maskedEmail` was serialized. The data set covers `_***@example.test`, `+***@example.test`, `!***@example.test`, and `a***@example.test`, all accepted by the real `CoinsCartRequest` route.

Writer RED:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests\Feature\Store\CoinsCartTest.php --filter="supported Coins modes" --stop-on-failure
```

Result: exit 1; the first mode failed with 34 assertions because the persisted summary still contained `email: c***@example.test`.

GREEN command:

```text
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest tests\Feature\Store\CoinsCartTest.php --filter="supported Coins modes|collide with the former mask grammar|poisoned legacy summary emails"
```

GREEN result: eight passed tests and 207 assertions. New writes store only `has_password: true` and `backup_code_count: 5`; encrypted payload keys remain `ea_email`, `ea_password`, and `backup_codes`. Cart reads derive only `hasPassword`, `backupCodeCount`, and `retainedUntil`, ignore any legacy/poison `email` entry, keep the valid credential-received state, and never serialize the email entry or submitted collision value. The obsolete `App\Security\CoinsMaskedEmail` class was deleted because it has no safe consumer.

### Frontend RED/GREEN and bidi

Test file: `resources/js/__tests__/store/store-cart.test.tsx`.

The first attempted RED invocation used the unsupported Vitest flag `--stop-on-failure` and exited with a CLI option error; it is not counted as behavior evidence. The genuine RED command was:

```text
npx vitest run resources/js/__tests__/store/store-cart.test.tsx
```

RED result: exit 1; two failed and two passed. English still rendered `a***@example.com` in a `<bdi dir="ltr">`, and the Arabic credential card still contained the obsolete LTR identity isolate.

GREEN command: the same valid Vitest command.

GREEN result: four passed tests. `StoreCartItem`, `StoreCartTranslations`, both locale dictionaries, and the cart component no longer contain masked-email fields/copy/rendering. English shows only the secure-retention state and five-code count. Arabic keeps the entire credential state in inherited RTL with no nested LTR identity isolate.

### Browser and persistence proof

The production build was served at 320px through the same direct Windows PHP preview harness. A synthetic authenticated account submitted the accepted collision email `a***@collision.test`, a synthetic opaque password, and five synthetic codes through the real same-origin endpoint.

- English `/en/cart`: the credential card showed only `EA details`, the retention deadline, and `5 backup codes stored`. Neither the collision email/domain nor password/first code appeared; no `bdi` or nested LTR identity element existed; there was no overlay; and `scrollWidth` was 305 at `innerWidth` 320.
- Arabic `/cart`: the document and credential card inherited RTL, no identity isolate existed, the email/domain/password/code were absent, no overlay appeared, and `scrollWidth` remained 305.
- The persisted summary contained exactly `has_password` and `backup_code_count`; the encrypted payload retained exactly the three credential keys. A temporary legacy `email: legacy-poison-sentinel@example.test` was then added to the summary and the live Arabic cart reloaded: the non-PII credential state remained visible, re-entry stayed hidden, and the poison value/domain was absent.
- Browser developer logs contained zero warnings or errors. The screenshot confirmed the warm black/gold Arabic cart remained visually intact after removing the identity line.

The synthetic tab, server, user and user-owned cart/idempotency data, logs, and config cache were removed. Final checks showed port 8136 closed, zero synthetic users, zero carts, and no `bootstrap/cache/config.php`.

### Full gates, leak checks, and guards

```text
npm run ci:check
php -d extension=openssl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\pest --stop-on-failure
php -d extension=mbstring vendor\bin\pint --parallel --test
php -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpstan analyse --no-progress --memory-limit=1G
php -d extension=openssl -d extension=mbstring C:\Users\hp\Documents\Codex\2026-08-08\hi\work\tools\composer.phar validate --strict --no-check-publish
```

Final results: frontend CI passed 11 Vitest files and 142 tests, ESLint, Prettier, TypeScript, and the production Vite build. Backend Pest passed 286 tests with three skipped and 2,759 assertions. Pint passed, PHPStan reported zero errors, and Composer validation passed. Vite retained only the previously documented absolute public-asset runtime warnings.

Production source contains no `CoinsMaskedEmail`, `maskedEmail`, or `masked_email` reference and no local/session storage, Inertia remember, console, or credential-query sink in the changed cart path. Only the backend regression assertions name the removed `maskedEmail` property to prove it is absent. Live URL/DOM checks and the existing storage-boundary tests found no credential propagation outside ordinary component memory and encrypted persistence. `git diff --check` passed.

Clean Code Guard found no violation: the unsafe abstraction and its only call sites were deleted, no replacement abstraction or speculative compatibility branch was added, and the controller/action functions became smaller. Test Guard found no violation: the four collision variants are data-driven, persistence/projection use the real migrated database, and the English/Arabic UI tests assert rendered behavior and direction rather than component internals. Docs Guard verified the superseding contract, symbols, commands, counts, field names, persistence keys, browser metrics, and cleanup claims against the current implementation and recorded outputs.

No product blocker remains. The only environmental caveats remain the explicit Windows PHP extensions/config-cache preview setup and Vite's informational absolute-public-asset warnings; neither affected the verified cart.
