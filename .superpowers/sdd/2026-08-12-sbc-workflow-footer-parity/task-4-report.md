# Task 4 — SBC listing parity report

## Scope

- Made every filtered SBC product reachable with explicit server pagination controls.
- Added server-owned filter counts after localized search and before category filtering.
- Disabled genuinely empty category chips and exposed counts in their accessible names.
- Matched the approved WordPress hierarchy with a compact image-first hero, compact discovery toolbar, responsive four-card desktop grid, blue platform prices, equal media/body rhythm, and detailed trust assurances.
- Preserved real localized product names, media, platform variants, detail URLs, add-to-cart behavior, RTL/LTR, and reduced-motion support.

## RED evidence

- Backend pagination/count test failed because `catalog.filterCounts` did not exist.
- Frontend count test failed because chips had no counts or disabled states.
- Frontend pagination test failed because the page had no catalog navigation.

## GREEN evidence

- Full frontend suite: 24 files / 222 tests passed.
- `resources/js/__tests__/store/store-category.test.tsx`: 5 tests passed.
- `tests/Feature/Store/StoreCatalogRoutesTest.php`: 11 tests / 201 assertions passed using PHP 8.5 with SQLite/OpenSSL extensions explicitly enabled.
- `tests/Feature/Store/StoreTranslationParityTest.php`: 8 tests / 865 assertions passed.
- TypeScript passed.
- Scoped ESLint passed.
- Scoped PHPStan passed with zero errors using a 1 GB memory limit.
- Scoped Pint passed with mbstring explicitly enabled.
- Prettier check passed for owned frontend/CSS files.
- `git diff --check` passed.

## WordPress reference

- `../wordpress-source/wp-content/themes/arabut-child/page-sbc.php`
- `../wordpress-source/wp-content/themes/arabut-child/assets/css/sbc.css`
- `../wordpress-source/wp-content/themes/arabut-child/assets/js/sbc-listing.js`
- `../../sbc-wp-parity-1075.png`

## Visual verification note

A local browser smoke check was attempted with an isolated migrated SQLite database, but the long-running development server continued resolving the repository database path, whose local file has no application tables. The browser run was therefore not counted as verification; route/component tests and the production build are the authoritative checks for this task.

## Concurrency note

Unrelated concurrent n8n workflow and footer-page edits were preserved and were not included in this task commit.
