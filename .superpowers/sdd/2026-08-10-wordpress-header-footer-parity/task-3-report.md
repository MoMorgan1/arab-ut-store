# Task 3 Report: WordPress-Parity Footer

## Outcome

Implemented the storefront footer as the page's single `contentinfo` landmark. The React hierarchy, content, destinations, payment marks, responsive behavior, and warm black/gold/cream styling reproduce the authoritative WordPress footer while preserving the existing storefront design system.

Only the scoped footer implementation and its integration fixtures changed. The concurrent edit to `docs/plans/2026-08-10-wordpress-header-footer-parity.md` was left untouched and is not part of this task.

## Sources and design decisions

Authoritative implementation sources:

- `C:/Users/hp/Documents/Codex/2026-08-08/hi/work/wordpress-source/wp-content/themes/arabut-child/footer.php`
- `C:/Users/hp/Documents/Codex/2026-08-08/hi/work/wordpress-source/wp-content/themes/arabut-child/assets/css/footer.css`
- Project `AGENTS.md` and `.impeccable.md`
- Current `StoreLayout`, `StoreShellConfig`, `StoreShellTranslations`, storefront translations, and server-owned shell configuration

The required frontend-design, UI/UX Pro Max, arrange, adapt, typeset, and polish reviews were applied after WordPress parity. Generic UI Pro Max recommendations such as Liquid Glass, unrelated fonts, and a replacement palette were rejected because they conflict with the authoritative WordPress source and the project's Arabic-first Thmanyah warm-black/navy/gold identity. Safe refinements were limited to semantic landmarks, visible keyboard focus, 44 px targets, wrapping, logical properties, responsive reflow, explicit image dimensions, and reduced-motion behavior.

Official current documentation consulted before implementation:

- React common DOM component attributes: https://react.dev/reference/react-dom/components/common
- Testing Library priority and role queries: https://testing-library.com/docs/queries/byrole/
- Testing Library scoped queries with `within`: https://testing-library.com/docs/dom-testing-library/api-within/
- Vitest guide and Vite integration: https://vitest.dev/guide/

These sources supported the semantic built-in element approach, accessible names, user-facing role queries scoped to the footer, and the focused Vitest workflow.

## TDD evidence

Focused RED was captured before production changes:

```text
npm test -- resources/js/__tests__/store/store-footer.test.tsx resources/js/__tests__/store-layout.test.tsx
1 failed test file, 1 failed footer test, 5 existing tests passed
Expected failure: Unable to find an accessible element with role "contentinfo"
```

The minimal implementation then rendered the footer once from `StoreLayout`. The first GREEN attempt exposed a duplicate accessible logo name shared with the header; the footer crest received the distinct accessible name `brand — home`, preserving an informative label without weakening the test. Focused GREEN passed with 2 files and 8 tests.

The footer tests cover three content columns, five legal links, exact WhatsApp/email/social destinations, omission of TikTok and Snapchat, absence of placeholder `#` links, four configured payment images, copyright-year substitution, LTR disclaimer rendering, English and Arabic content, safe external-link attributes, and single-layout integration. They use role/name queries and `within`, with no mocks or snapshots for footer behavior.

The full suite initially revealed that two existing StoreShell UI fixtures omitted the now-required typed `footer` translation tree. With explicit integration authorization, `coins-home.test.tsx` and `store-simple-page.test.tsx` received only the same mechanical footer fixture object; no assertions or production behavior changed.

## Implementation evidence

- Exact WordPress hierarchy: brand, important-links navigation, customer service, bottom legal row, and EA disclaimer.
- Exactly one X link (`https://x.com/fut_fi`) and one Instagram link (`https://www.instagram.com/arabutcoins/`). No TikTok or Snapchat links.
- Email is `mailto:info@arab-ut.com`; external social and WhatsApp links use `target="_blank"` with `rel="noopener noreferrer"`.
- Payment data comes from `StoreShellConfig`; images are lazy-loaded with explicit configured dimensions.
- The current year is calculated once.
- Responsive layout is one column through 35 rem, two columns through 53.75 rem with the brand spanning the first row, and three columns above that.
- Focus outlines are visible, interactive targets are at least 44 by 44 px, long text wraps, and reduced motion removes hover transforms.

Verified destination SHA-256 hashes:

```text
public/images/store/payments/mada.png
e1885eaaf226de64a8cc346d2531b096be20b3b6158291dfbba2525b3e36a1ce  2138 bytes

public/images/store/payments/visa.png
bb3bde9760062092d460ee1fb1bae038dcca3c419dc79ec475fb34464e49c272  2479 bytes

public/images/store/payments/mastercard.png
2cf822a50d4c6e277de630bf9827d831dde087601365d15495007d458692e86f  2883 bytes

public/images/store/payments/apple-pay.png
6e8a9fddb817b21293a3225b0749d2ebe18004b6d96b7aa4f40a1a68157d2314  1826 bytes
```

## Browser verification

Browser verification used the local Laravel application at `127.0.0.1:8015` and the database-independent privacy routes. The local PHP runtime lacks `pdo_sqlite`, so `SESSION_DRIVER=array` was used only for the verification server; this did not change project files or product behavior.

Required matrix completed for both Arabic and English at 320, 390, 768, and 1440 px (8 cases):

| Width | Layout                           |  AR  |  EN  |
| ----: | -------------------------------- | :--: | :--: |
|   320 | one column                       | pass | pass |
|   390 | one column                       | pass | pass |
|   768 | brand row plus two lower columns | pass | pass |
|  1440 | three columns                    | pass | pass |

Across all cases:

- Exactly one footer landmark rendered.
- Arabic used RTL, English used LTR, and the EA disclaimer remained LTR.
- Document and body horizontal overflow measured 0 px.
- No interactive target was smaller than 44 by 44 px.
- All four payment images loaded (`complete`, natural width 120) from the expected destinations.
- Social destinations were exactly X and Instagram; disallowed socials were absent.
- The computed storefront font was `"Thmanyah Sans", Tahoma, Arial, sans-serif`.
- No Vite error overlay appeared and browser console warnings/errors were empty.

Keyboard focus was visually and programmatically checked in Arabic and English: the X control showed a 2 px solid gold outline. Reduced-motion emulation matched `prefers-reduced-motion: reduce`; transitions/animations collapsed to the browser's minimal duration and document smooth scrolling was disabled. Visual checks at Arabic 320 px and English 1440 px confirmed wrapping, alignment, hierarchy, and payment rendering.

## Quality gates

Pre-report gates completed successfully:

```text
Focused Vitest: 2 files, 8 tests passed
Full Vitest: 8 files, 108 tests passed
npm run lint:check: passed
npm run format:check: passed
npm run types:check: passed
npm run build: passed (Vite 8.2.1, 2327 modules, 5.69 s)
```

The Vite build emitted only existing notices that public font, hero, and stadium asset paths remain runtime-resolved. Final gates are rerun after this report is written, and their fresh results are recorded in the commit handoff.

Fresh post-report verification also passed: focused Vitest 8/8, full Vitest 108/108, lint, Prettier, TypeScript, and the Vite production build (2327 modules, 10.81 s). `git diff --check` reported no whitespace errors, and all four destination asset hashes matched the authoritative values above.

Clean-code and test-guard reviews found no unused fallback paths, duplicated external configuration, mock-only assertions, snapshots, timing dependencies, or unrelated behavior changes. Component data flow remains typed and server-owned; private SVG components avoid adding dependencies.

## Review fix round 1: payment proportions

The P1 review finding identified that `.store-footer__payments img` combined the correct WordPress height (`1.375rem`, 22 px) with `max-width: 3rem`. Because the four source marks have different intrinsic ratios, that second-axis cap stretched Mada, Visa, and Apple Pay. The focused fix changes only the cap to `max-width: none`, retaining `width: auto`, the 22 px height, explicit HTML dimensions, and `object-fit: contain`.

The regression loads the real footer image declarations from `resources/css/app.css`, applies them through the test DOM's stylesheet engine, and checks every rendered payment image. It therefore fails if a fixed width or width cap is reintroduced rather than merely matching CSS source text.

Genuine focused RED before the production change:

```text
npm test -- resources/js/__tests__/store/store-footer.test.tsx
Test Files  1 failed (1)
Tests       1 failed | 3 passed (4)
Expected computed maxWidth: none
Received computed maxWidth: 3rem
```

Focused GREEN after the one-line production change:

```text
npm test -- resources/js/__tests__/store/store-footer.test.tsx
Test Files  1 passed (1)
Tests       4 passed (4)
```

Browser geometry was reverified in the explicitly capped matrix: Arabic and English at 390 and 1440 px (4 cases). Every case rendered exactly one footer, loaded all four marks, used the correct locale direction, and measured 0 px document/body horizontal overflow. Browser console warnings and errors were empty.

| Mark       | Natural size | Rendered size in all 4 cases | Expected intrinsic width at 22 px | Maximum ratio delta |
| ---------- | -----------: | ---------------------------: | --------------------------------: | ------------------: |
| Mada       |     120 × 41 |               64.375 × 22 px |                         64.390 px |            0.000693 |
| Visa       |     120 × 39 |               67.688 × 22 px |                         67.692 px |            0.000219 |
| Mastercard |     120 × 75 |               35.188 × 22 px |                         35.200 px |            0.000568 |
| Apple Pay  |     120 × 50 |               52.797 × 22 px |                         52.800 px |            0.000142 |

Computed CSS in all four browser cases was `height: 22px`, `max-width: none`, and the intrinsic auto width shown above. The small sub-pixel differences are browser pixel rounding; every ratio delta was below 0.001.

The UI review remained scoped to the authoritative WordPress payment treatment. UI/UX Pro Max's generic Liquid Glass, Inter, light slate, and red recommendations were rejected because they conflict with the WordPress-first gate and Arab UT's Thmanyah warm-black/navy/gold identity. The final adapt/arrange/typeset/polish pass made no unrelated visual changes.

Fresh final gates for review round 1:

```text
Focused Vitest: 2 files, 9 tests passed
Full Vitest: 8 files, 109 tests passed
npm run lint:check: passed
npm run format:check: passed
npm run types:check: passed
npm run build: passed (Vite 8.2.1, 2327 modules, 11.10 s)
```

The build retained only the previously documented runtime-resolution notices for existing font, hero, and stadium paths.
