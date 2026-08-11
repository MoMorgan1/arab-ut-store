# Storefront services, reviews, and FAQ — final report

Date: 2026-08-11
Branch: `main`

## Delivered scope

- Added a signed, replay-safe n8n catalog snapshot boundary with atomic reconciliation and safe media mirroring.
- Added bilingual internal storefront routes for SBC, Objectives, FUT Champions, and Division Rivals.
- Added real guest-cart submission for every eligible catalog product. Product/category surfaces use **Add to cart** only; they do not expose placeholder details or contact actions.
- Added the equal-size five-card services rail after Coins. All cards retain horizontal snap/arrow movement at mobile, tablet, and desktop sizes. Sell Coins points exactly to `https://sell.arab-ut.com/`.
- Added SBC and Objectives category/product pages with bounded search/filter/sort and responsive cards. FUT Champions and Rivals use focused product pages.
- Added a safe reviews importer and hourly last-good refresh from the configured n8n review endpoint. Only the approved public review fields are stored/rendered; contact data and contact-like text are rejected.
- Added the bilingual reviews preview, reviews page, and the four approved Arab-ut.com FAQ entries using native disclosure controls.
- Corrected the production storefront hostname to `store.arab-ut.com` and documented the n8n contract, required environment variables, and Hostinger scheduler cron.

## TDD and review fixes

- Catalog signature, freshness, exact-schema, replay, reconciliation, archive, media, bilingual route, public-projection, and cart behaviors were developed from focused RED tests.
- Review migration/import/refresh/last-good/PII boundaries were developed from focused RED tests.
- Homepage rail, category/product UI, reviews, and FAQ were developed from focused React RED tests.
- Browser verification found one integration-only defect that mocked router tests did not catch: the category form submits `q=` when the search is blank, Laravel normalizes it to `null`, and the server rejected it. A focused feature regression failed first; `CategoryController` now accepts a nullable bounded query and the real browser filter succeeds.
- Clean Code, Test, and Docs guard passes found no remaining actionable production or test issue in this slice.

## Automated verification

Final aggregate command:

```text
php C:\Users\hp\Documents\Codex\2026-08-08\hi\work\tools\composer.phar ci:check
```

Result: PASS.

- Composer validation: valid
- Pint: pass
- PHPStan: 0 errors
- Pest: 395 total, 392 passed, 3 expected skips, 18,223 assertions
- Vitest: 23 files, 211 tests passed
- ESLint: pass
- Prettier: pass
- TypeScript: pass
- Vite production build: pass, 2,336 modules transformed
- Focused post-browser catalog/documentation gate: 12/12, 191 assertions
- Final diff check: clean

The Vite build retains the repository's existing runtime-resolved public asset/font notices. The exact assets rendered successfully in the browser matrix.

## MariaDB 12.3.2 verification

- Official Windows archive size: 103,730,527 bytes
- SHA-256: `67347c129eb9c5923d002ea34fbfa27c60eb95d36dd73b85af2651cdeceecac5`
- Isolated server: `127.0.0.1:3341`
- `migrate:fresh`: all 13 migrations ran
- Focused catalog/review/schema/routes selection: 96/96 tests, 500 assertions
- Full rollback: all 13 migrations became Pending
- Remigrate/status: all 13 migrations Ran in batch 1
- Post-remigrate focused selection: 96/96 tests, 500 assertions
- Server stopped; PID and port cleared; archive, data, and logs moved to the Recycle Bin

## Browser verification

Verified the production build against an isolated SQLite preview with representative safe catalog/review data.

- Matrix: Arabic and English at 320, 390, 768, and 1440 CSS pixels (8/8)
- Correct `lang` and RTL/LTR direction in every case
- No positive document horizontal overflow
- Five service cards in every case; identical measured width/height within each viewport
- Service rail overflow/movement present at every width, including desktop
- Exact Sell Coins external URL present
- No product **View details** or **Contact us** placeholder action
- Homepage contains three safe review cards and the exact four FAQ disclosures
- Native FAQ interaction opens the answer
- Rail arrow changes horizontal scroll position
- SBC, Objectives, FUT Champions, Rivals, Reviews, SBC product, and Objectives product pages render in their expected locale with Thmanyah Serif Display headings
- Every eligible catalog/category/product surface exposes Add to cart
- Real SBC filter interaction changes the URL to `filter=icons&q=&sort=recommended` and narrows the list to the Icon product
- Reduced motion matches and removes decorative animation/smooth scrolling
- Browser console: zero warnings/errors

## Operational handoff

Before production data can appear, deployment must set:

- `N8N_CATALOG_KEY`
- `N8N_CATALOG_SECRET`
- `N8N_CATALOG_MEDIA_HOSTS`
- `N8N_REVIEWS_URL`

Hostinger must run from the deployed application directory:

```cron
* * * * php artisan schedule:run >> /dev/null 2>&1
```

Catalog data remains push-based through the signed snapshot endpoint. Reviews refresh hourly and retain the last-good snapshot on upstream failure. Empty source data is shown honestly; no catalog product or review is fabricated.

## Commit ledger

- `dd451dc` — ingest storefront catalog snapshots
- `54f09c6` — expose bilingual service catalog routes
- `a950426` — add catalog products to guest cart
- `e1c8ef5` — add equal storefront service rail
- `e9d3068` — build service catalog pages
- `797ee45` — import honest storefront reviews
- `b7052b7` — add reviews and FAQ storefront sections
- `13c4d1f` — reject review contact data

This report accompanies the final `docs: finalize storefront catalog integration` commit.
