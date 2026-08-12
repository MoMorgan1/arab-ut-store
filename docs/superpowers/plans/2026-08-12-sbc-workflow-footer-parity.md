# SBC Workflow, Footer Pages, and Storefront Parity Plan

> **Execution:** use `superpowers:subagent-driven-development` task by task, strict RED/GREEN for behavior changes, review each task before moving on, then run a final release review.

**Goal:** Publish a production-safe, automated SBC catalog into the Laravel store, reproduce the approved WordPress informational-page and SBC storefront experience, and verify the complete customer journey on `store.arab-ut.com`.

**Architecture:** Laravel/MariaDB remains the only catalog/cart source of truth. A versioned n8n workflow reads the SBC source, obtains authoritative SAR prices from a signed Laravel pricing boundary, builds one complete SBC-only snapshot, signs the exact JSON body, and publishes it to an SBC-scoped catalog source. WordPress is a visual/content reference only. Secrets remain in Hostinger/n8n credentials or environment variables and never enter source control.

**Complexity:** Ambitious. The work spans a signed automation contract, cross-system deployment, catalog reconciliation, structured legal content, responsive React UI, and the secure SBC cart journey.

## Global constraints

- Preserve the existing Coins catalog, pricing, guest-cart, claim, and credential-security contracts.
- Never publish an SBC-only snapshot through the shared `n8n-products` source.
- Never copy Salla/WooCommerce mutation nodes or embed provider credentials in an exported workflow.
- Dry run must not call the catalog mutation endpoint. Activation happens only after inspecting a complete real snapshot.
- All catalog prices are integer SAR minor units; workflow prices come from Laravel's authoritative Coins pricing logic, not Salla.
- The SBC workflow is the sole publisher for source `n8n-sbc`; missing rows in its complete snapshot are archived only inside that source.
- WordPress legal copy is archival source material explicitly approved by the product owner. Keep its structure, update obsolete FC 26 naming to FC 27, and do not add new claims beyond the supplied source.
- EA backup-code page content is limited to verified guidance and an official EA Help link because no WordPress body exists.
- Use Thmanyah Serif Display for display headings and Thmanyah Sans for body/control text.
- Preserve AR/EN, RTL/LTR, keyboard access, 44px controls, reduced motion, no horizontal overflow, and safe external-link behavior.
- Work on the product owner's active `main` deployment branch as explicitly authorized; preserve unrelated `.superpowers/brainstorm/` files.

## Task 1 — Isolate and harden the SBC automation contract

**Status: complete.** The SBC-scoped catalog and signed pricing boundaries are implemented, reviewed, and live.

**Files:**

- Modify: `routes/api.php`
- Modify: `app/Actions/Catalog/SyncCatalogSnapshot.php`
- Modify: `app/Http/Controllers/Automation/CatalogSnapshotController.php`
- Add: `app/Http/Controllers/Automation/SbcCatalogSnapshotController.php`
- Add: `app/Http/Requests/Automation/SbcCatalogSnapshotRequest.php`
- Add/modify: signed internal Coins pricing request/controller/action as required by the final transformation audit
- Modify: `tests/Feature/Automation/CatalogSnapshotTest.php`
- Add: focused SBC snapshot/pricing tests
- Modify: `docs/api/n8n-catalog-v1.md`

1. Add failing tests proving an SBC snapshot cannot reconcile another automation source and rejects non-SBC products.
2. Add the smallest source-key-aware synchronization boundary; keep the generic v1 route backward compatible.
3. Add an SBC-only signed route backed by source key `n8n-sbc`.
4. Add a signed, batched, read-only pricing boundary only if the workflow cannot use an existing authoritative action safely.
5. Make price-version handling server-authoritative when effective prices change; producer values cannot regress or arbitrarily jump stored versions.
6. Run focused tests, static analysis, and diff review.

## Task 2 — Build, dry-run, review, and activate the SBC n8n workflow

**Status: complete.** The guarded workflow is imported, bootstrapped, applied successfully, and active on its two-hour schedule; the legacy Woo/Salla flow remains inactive.

**Files:**

- Add: `automation/n8n/sbc-catalog-v1/README.md`
- Add: `automation/n8n/sbc-catalog-v1/workflow.json`
- Add: deterministic transformation/signing modules and tests under `automation/n8n/sbc-catalog-v1/`
- Add: `.superpowers/sdd/2026-08-12-sbc-workflow-footer-parity/task-2-report.md`

1. Capture RED tests for source validation, category mapping, eligibility, price lookup, exact JSON signing, dry-run no-publish, and fail-closed behavior.
2. Build a secret-free workflow: Manual + two-hour Schedule → fetch → validate/dedupe → enrich/translate → batch price → build strict snapshot → validate → dry-run summary or sign/publish → verify response/alert.
3. Configure new n8n/Hostinger catalog credentials through secure environment/credential stores without printing values.
4. Import inactive; execute a real dry run; inspect counts, samples, prices, categories, media hosts, and destructive reconciliation set.
5. Switch to apply, publish one complete snapshot, verify the Laravel sync run/storefront, then activate the schedule and observe another successful run.
6. Keep the legacy Woo/Salla workflows inactive.

## Task 3 — Reproduce the footer informational pages

**Status: complete.** The truthful typed AR/EN information pages are implemented, reviewed, and deployed.

**Files:**

- Modify: `lang/ar/ui.php`, `lang/en/ui.php`
- Modify: `app/Http/Controllers/Store/SimpleStorePageController.php`
- Modify: `resources/js/types/store-shell.ts`
- Modify: `resources/js/pages/store/simple-page.tsx`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/Store/StoreShellRoutesTest.php`
- Modify: `resources/js/__tests__/store/store-simple-page.test.tsx` and fixture trees

1. Add RED tests for a typed structured document contract, AR/EN parity, breadcrumbs, updated date, headings/lists/notices, and safe support/EA actions.
2. Convert the four available WordPress documents (privacy, refund, warranty, terms) into typed translation data without raw HTML injection.
3. Add a truthful EA backup-code guide using only verified steps and an official external help link.
4. Recreate the WordPress standard-page shell: compact hero, 780px prose measure, gold hierarchy/rules/bullets, notice cards, and footer continuity.
5. Verify every route in AR/EN at mobile and desktop widths.

## Task 4 — Match the SBC listing and discovery page

**Status: complete.** The responsive SBC discovery experience, filters, pagination, and product links are implemented and deployed.

**Files:**

- Modify: `app/Actions/Catalog/StoreCatalogReader.php`
- Modify: `resources/js/pages/store/category.tsx`
- Modify: `resources/js/types/store-content.ts`
- Modify: `lang/ar/store.php`, `lang/en/store.php`
- Modify: `resources/css/app.css`
- Modify: category/backend tests

1. Add RED coverage for 13+ items, filter counts/disabled empty filters, compact toolbar, and no inaccessible catalog pages.
2. Match the WordPress SBC hero scale, compact category/sort controls, empty state, trust subtitles, cyan price accent, and responsive image-first grid.
3. Preserve Laravel-owned navigation/cart semantics; do not reintroduce Woo/Salla behavior.
4. Verify 320/390/768/1440, RTL/LTR, keyboard/focus, media loading, and no overflow.

## Task 5 — Complete the SBC product/cart credential journey

**Status: complete.** The secure SBC add-to-cart journey with exactly three backup codes and owner-only credential viewing/editing is implemented and independently reviewed.

**Files:**

- Modify/add SBC-specific product/cart request, action, controller, React detail/cart UI, translations, CSS, and focused security tests.

1. Add RED end-to-end coverage: visible SBC → platform/price choice → exactly three distinct eight-digit EA backup codes → encrypted cart secret → editable cart credentials → safe display contract.
2. Reuse the reviewed encrypted cart-secret storage; never place raw credentials in configuration, URL, Inertia props outside the explicit authenticated edit boundary, local/session storage, logs, analytics, or idempotency responses.
3. Match the approved WordPress credential hierarchy and mobile layout while retaining Laravel validation, CSRF, idempotency, and owner isolation.
4. Verify add-to-cart animation, cart count, credential edit, retry/failure paths, guest/auth behavior, and no checkout/payment expansion.

## Task 6 — Release verification and production deployment

**Status: complete.** Aggregate CI, MariaDB coverage, production deployment, live workflow checks, and AR/EN browser verification are complete.

1. Run focused and aggregate PHP/JS gates, PHPStan/Pint/Composer, ESLint/Prettier/TypeScript/build, secret scans, and `git diff --check`.
2. Run real MariaDB migration/lifecycle/concurrency coverage for changed catalog/cart boundaries where applicable.
3. Request independent spec and quality review; address all P0–P2 findings with RED/GREEN evidence.
4. Commit intentionally, push/deploy through the existing Hostinger workflow, and verify health, catalog sync, routes, assets, console, overflow, and the full AR/EN SBC customer path.
5. Record exact production evidence in the task ledger/reports and leave legacy workflows inactive.
