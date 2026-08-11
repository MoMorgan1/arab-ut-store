# Task 7 Report — Bilingual Storefront Final Polish

## Status

Complete. The bilingual storefront shell, simple pages, footer, header, and Fast-delivery suggestion are polished and browser-verified. The observed large Fast quote failure was traced to transiently empty cached display-exchange-rate state after an unsuccessful scheduled refresh; it was not a quantity, pricing-rule, or frontend defect. No pricing/conversion contract was changed. The configured provider later recovered, the real refresh command populated fresh official rows, and both reported combinations returned and rendered successful quotes.

## Source-of-truth audit

Before changing UI, I re-read the current React/CSS implementation and the public WordPress source at `work/wordpress-public-html-20260809`:

- `wp-content/themes/arabut-child/header.php` and `assets/css/header.css`: nav order, WhatsApp's visually compact 34px pill, icons, warm-black/gold treatment, and Arabic copy.
- `footer.php` and `assets/css/footer.css`: main legal list, redundant bottom legal links, EA copy, provider attribution, and payment-badge geometry (`4px 8px` padding; `height: 22px; width: auto; object-fit: contain`).
- `templates/homepage.php`, `assets/css/homepage.css`, and `assets/js/configurator.js`: the gold-tinted Fast hint, 1.5M display threshold, one switch action, Fast selection, amount-limit widening, and delivery-step return.
- Current Laravel/React quote action, request, session currency, product/variant/rule state, rate converter, refresh command, schedule, shell routes, translations, tests, and built output.

WordPress/Thmanyah/warm-black-gold remained authoritative. Generic design-system suggestions for a light background, Liquid Glass, Noto typography, portfolio/grid presentation, and decorative novelty were rejected because they conflict with the audited storefront.

## Quote failure diagnosis and root cause

### Exact failing reproduction

With `exchange_rates` empty and the English session set to the selected display currency, the direct preview server reproduced the screenshots exactly:

| Request | Result |
| --- | --- |
| `GET /en/coins/quote?platform=playstation&delivery=fast&quantity=14180000` with USD selected | `503`, `Cache-Control: no-store, private`, `{"error":{"code":"coins_pricing_unavailable","message":"Coins pricing is temporarily unavailable."}}` |
| Same endpoint with `quantity=18210000` and EUR selected | same safe `503` envelope |

The response and server log exposed no domain exception, rate, provider response, or other internal detail.

### Boundary-by-boundary evidence

- Selected values were `platform=playstation`, `delivery=fast`, and quantities `14,180,000` / `18,210,000`.
- The active console Fast variant and active Fast pricing rule covered both values: 10,000 increment and 20,000,000 maximum. Both quantities are valid increments.
- `QuoteCoins` succeeded independently and produced authoritative totals of 283,600 halalah (SAR 2,836) and 364,200 halalah (SAR 3,642).
- SAR display conversion succeeded. USD/EUR conversion failed only at the fresh-rate boundary with `A fresh display exchange rate is unavailable.`
- Initial server state had zero `exchange_rates` rows. `schedule:list` showed the daily `currency:refresh-display-rates` job and hourly secret purge. The first refresh attempt returned exit 1 with `The display exchange-rate provider could not be reached.`
- Isolated fresh diagnostic USD/EUR rows made the two exact HTTP requests return 200, proving the quote request, rule selection, arithmetic, controller, and frontend contract were healthy. Those diagnostic rows were deleted immediately.

### Final recovered state

During final verification the real configured provider recovered. `currency:refresh-display-rates` succeeded and wrote three fresh rows from `exchange-rate-api-open-access` at `2026-08-10T08:03:38Z`: USD `0.26666700`, EUR `0.23080100`, and GBP `0.19774600`.

The exact real-provider requests then returned:

| Request | Final result |
| --- | --- |
| 14.18M, PlayStation, Fast, USD | `200`; authoritative SAR `283600`; display USD `75627`; `Cache-Control: no-store, private` |
| 18.21M, PlayStation, Fast, EUR | `200`; authoritative SAR `364200`; display EUR `84058`; `Cache-Control: no-store, private` |

The 14.18M browser flow automatically requested `platform=playstation&quantity=14180000&delivery=fast`, returned 200, rendered `USD 756.27`, and showed no alert. A separate healthy 1.5M Normal browser flow automatically rendered `SAR 270.00`.

Root cause: transient operational absence of the cached display rate after an unsuccessful provider refresh. The existing fail-closed behavior was correct for that genuine missing-data state. No request-path provider HTTP, stale/fabricated fallback, quantity exception, or pricing/conversion source change was added.

## RED / characterization / GREEN

### Before production changes

- PHP translation RED: 4 of 5 tests passed; the shell contract failed because Arabic `header.fut_champions` was `FUT Champions` instead of exact `فوت تشامبيونز`.
- Frontend RED: 68 of 71 tests passed. The footer failed the one-navigation/merged-line contract and the Fast suggestion lacked the named complementary callout. The third failure was a test-query false negative: the SBC link's accessible name includes its visible “Most requested” badge; the query was corrected to `^SBC` before production changes.
- Existing header/layout behavior otherwise characterized GREEN and was not rewritten.
- Rendered 807px RED evidence: Arabic `/sbc` h1 was 16px `Thmanyah Sans`; the Arabic nav still read `FUT Champions`; the footer contained two navs and two standalone disclaimer rows; Mada and Visa (64.38px and 67.69px) protruded from fixed 64px wrappers; the WhatsApp green visual occupied the full approximately 48px target.

### After the minimal fixes

- Focused frontend: 5 files, 76 tests passed.
- Translation parity: 5 tests, 536 assertions passed.
- Full frontend: 11 files, 142 tests passed.
- Full backend: 289 tests, 286 passed, 3 skipped, 2,761 assertions.

The implementation-coupled footer test that parsed `app.css` was deleted. DOM tests now cover legal structure and asset metadata; actual intrinsic ratio, wrapper width, padding, clipping, and protrusion are proved by rendered browser geometry below.

## Implemented polish

### Header and typography

- Arabic nav is exactly `فوت تشامبيونز`; English remains `FUT Champions`.
- WhatsApp retains a 44px anchor target while its inner green pill is 34px tall, matching the smaller WordPress visual proportions.
- Simple-page h1 typography is now an intentional display treatment. Computed Arabic homepage and simple-page h1 styles are `"Thmanyah Serif Display", "Thmanyah Sans", sans-serif`; body and controls compute to `"Thmanyah Sans", Tahoma, Arial, sans-serif`.
- After `document.fonts.ready`, the browser confirmed loaded Serif 700/900 and Sans 400/700 faces.

### Footer and payment marks

- The main five-link legal navigation remains.
- The duplicate bottom Privacy/Returns navigation was removed.
- Copyright, EA independence text, and the required linked `Rates By Exchange Rate API` attribution now form one restrained legal line.
- The two standalone disclaimer/attribution rows were removed.
- Payment wrappers are auto-width with WordPress's 8px inline padding. Images remain 22px high, auto-width, and intrinsically proportioned.

Rendered 807px geometry:

| Mark | Wrapper | Image | Ratio delta | Protrudes |
| --- | --- | --- | --- | --- |
| Mada | 80.38 × 30 | 64.38 × 22 | 0.0007 | no |
| Visa | 83.69 × 30 | 67.69 × 22 | 0.0002 | no |
| Mastercard | 51.19 × 30 | 35.19 × 22 | 0.0006 | no |
| Apple Pay | 68.80 × 30 | 52.80 × 22 | 0.0001 | no |

The same wrappers remain non-protruding at 320px. No zoom, crop, or fixed-width clipping remains.

### Fast suggestion

- Normal delivery at 1.5M or more now renders one named `<aside>` with one 44px action, a restrained lightning icon, WordPress-derived gold tint/border, and no nested-card clutter.
- At 390px it measures 313 × 121.22px, wraps cleanly, and contains exactly one button.
- Keyboard focus is visible. Activating it selects Fast and returns focus to the delivery heading; the tested button height is exactly 44px.

No checkout/payment control was added. Task 6 credential, cart, same-origin, idempotency, safe-error, no-storage, no-identity-projection, and encrypted-persistence contracts were not touched.

## Browser verification

### Route and viewport matrix

Production assets were exercised across 50 route/viewport cases:

- Routes: `/`, `/en`, `/cart`, `/en/cart`, `/sbc`, `/en/sbc`, `/fut-champions`, `/en/fut-champions`, `/privacy`, `/en/privacy`.
- Widths: 320, 390, 768, 807, and 1440px.

All 50 returned 200 and had header/main/footer landmarks, no document-level horizontal overflow, a Serif Display h1, no exact `href="#"`, no unsafe external-link attributes, no fake Pay/Checkout control, and no console warning/error.

Additional measured checks:

- Home matrix AR/EN: one footer nav, zero `.store-footer__disclaimer` rows, merged legal line present, exact FUT label, safe external links, 44px WhatsApp target/34px visual, and payment geometry above.
- Simple pages at 320/807: 36px/48.42px Serif Display h1, Sans body, correct active SBC nav, 45.19px back link, semantic header→main→footer order, and no overflow.
- `#coins` top remained below the sticky header at 320, 807, and 1440px. The 320px nav is intentionally horizontally scrollable; wider navs fit.
- Preferences opened as a dialog, reported expanded state, closed on Escape, restored trigger focus, and preserved `/en/sbc?currency=EUR#simple-page-title` exactly.
- Fourteen consecutive Tab presses at 320px produced fourteen unique, visible focus stops with visible outlines and no trap. All visible non-inline interactive targets measured at least 44px. The provider attribution is an inline legal-text link.
- 200% root zoom at 807px kept the document and legal line overflow-free on Arabic/English home and simple pages; the 320/390 responsive cases also cover the equivalent narrow reflow range.
- Reduced-motion emulation matched, hid the decorative hero overlay, removed stat animation, set scroll behavior to auto, and reduced transition duration to `0.01ms`.
- All 19 unique internal shell destinations returned 200. External HEAD checks returned WhatsApp 302 to the official API URL and X, Instagram, and ExchangeRate-API 200. Email remains `mailto:info@arab-ut.com`.
- Final browser console: zero errors and zero warnings.

## Design skills and guard results

- `frontend-design`: retained the distinctive WordPress-derived warm-black/gold storefront, real brand/payment art, sparse borders, and functional visual hierarchy.
- `ui-ux-pro-max`: the initial design-system search suggested light/Liquid Glass/Noto/portfolio patterns; all were rejected as conflicting. Its final UX search reinforced existing async feedback, restrained loading-only animation, and stacking-context discipline.
- `arrange`: consolidated the Fast hint into a single responsive row/action and simplified the footer's bottom rhythm.
- `typeset`: assigned large simple-page headings to Thmanyah Serif Display and retained Thmanyah Sans for reading/control copy.
- `clarify`: applied exact bilingual nav text and kept provider attribution explicit but discreet.
- `adapt`: added mobile callout stacking and verified the full 320–1440 matrix, 200% zoom, RTL/LTR, and nav overflow.
- `polish`: tightened WhatsApp visual size, legal-line spacing, payment fit, focus states, and small-screen alignment.
- `systematic-debugging` / TDD: followed the quote from exact HTTP input through request validation, rule selection, SAR quote, rate lookup, safe controller envelope, schedule, provider refresh, and final browser rendering before deciding against a contract change.
- Clean Code Guard: no speculative pricing branch, fallback, provider request path, broad catch, or unrelated abstraction was added. The initial report incorrectly claimed there was no dead symbol; fix round 1 below records and removes the obsolete `footer.legal_navigation` contract found in review.
- Test Guard: new tests assert accessible DOM, focus/selection behavior, translation output, and link contracts. No implementation CSS parsing remains; rendered geometry supplies visual evidence.
- Docs Guard: commands, counts, route/status values, geometry, filenames, decisions, and cleanup statements in this report were checked against current source or recorded output.

## Final gates

- `npm run ci:check`: passed — 142 Vitest tests; ESLint; Prettier; TypeScript; production Vite build.
- `php ... vendor\bin\pest`: passed — 289 total, 286 passed, 3 skipped, 2,761 assertions.
- Pint parallel check: passed.
- PHPStan: passed with zero errors.
- Composer strict validation: passed using the repository-local `work/tools/composer.phar` with explicit OpenSSL/mbstring extensions because bare `composer` is not on this Windows PATH.
- `git diff --check`: passed.
- Vite emitted only the already-documented absolute public-asset build-time resolution notices; the browser/server returned the referenced fonts and images successfully.

## Owned-file audit and cleanup

Task 7 owns only:

- `lang/ar/ui.php`
- `resources/css/app.css`
- `resources/js/components/store/store-header.tsx`
- `resources/js/components/store/store-footer.tsx`
- `resources/js/components/configurator/coins/amount-step.tsx`
- `resources/js/__tests__/store/store-footer.test.tsx`
- `resources/js/__tests__/store/store-simple-page.test.tsx`
- `resources/js/__tests__/store/coins-home.test.tsx`
- `tests/Feature/Store/StoreTranslationParityTest.php`
- `docs/superpowers/plans/2026-08-10-wordpress-header-footer-parity.md` (including the intentional incoming Task 6 evidence)
- this report

`store-header.test.tsx` and `store-layout.test.tsx` were run but did not require edits. No pricing, rate conversion, quote controller, cart, credential, route, migration, asset, dependency, build output, test database, preview cookie, or diagnostic fixture is staged. The diagnostic rate fixtures were deleted; final cached rows come only from the configured real provider. No push, merge, or deploy occurred.

The temporary browser screenshots, direct-preview logs, and verified local PHP preview listener were removed after verification.

## Self-review and concerns

No product-code blocker remains. The original failure mode remains intentionally fail-closed if a fresh display rate is genuinely unavailable; operations must continue running the registered daily refresh, particularly before first traffic on a new environment. The provider failure was transient in this local session and recovered without source changes. The local Windows preview still requires explicit PHP extensions, and the bare Composer executable is absent from PATH; both are environment concerns rather than storefront defects.

## Fix round 1 — obsolete footer navigation contract

Review found `footer.legal_navigation` still declared in `StoreShellTranslations`, both locale dictionaries, and seven typed test fixtures even though Task 7 removed the duplicate bottom legal `<nav>` and the production footer had no consumer. This contradicted the initial Clean Code Guard claim above.

Strict TDD evidence:

- RED: the shell translation contract added an absence assertion before production edits. The focused Pest run failed one test after 154 assertions with `Expecting […] not to have key 'legal_navigation'`.
- GREEN: after the minimal deletion, the same focused Pest case passed with 155 assertions. The seven affected Vitest files passed 91 tests and `tsc --noEmit` passed.
- The regression tests the observable bilingual shell payload: a label for a removed navigation landmark must not be shipped. It does not parse implementation source or mock an internal dependency.

Full fix-round gates passed: 11 Vitest files / 142 tests, ESLint, Prettier, TypeScript, production Vite build, 289 Pest tests (286 passed, 3 skipped) / 2,760 assertions, Pint, PHPStan with zero errors, and strict Composer validation. Vite retained only the previously documented absolute public-asset resolution notices.

Fix-round owned files:

- `resources/js/types/store-shell.ts`
- `lang/ar/ui.php`
- `lang/en/ui.php`
- `tests/Feature/Store/StoreTranslationParityTest.php`
- `resources/js/__tests__/store-layout.test.tsx`
- `resources/js/__tests__/store/coins-credentials-flow.test.tsx`
- `resources/js/__tests__/store/coins-home.test.tsx`
- `resources/js/__tests__/store/store-cart.test.tsx`
- `resources/js/__tests__/store/store-footer.test.tsx`
- `resources/js/__tests__/store/store-header.test.tsx`
- `resources/js/__tests__/store/store-simple-page.test.tsx`
- this report

After the change, `legal_navigation` appears under live source/tests only in the two intentional negative regression assertions. Two historical implementation-plan snippets still show the superseded Task 3 contract; they are retained as historical plan evidence and are not live types, dictionaries, or fixtures.
