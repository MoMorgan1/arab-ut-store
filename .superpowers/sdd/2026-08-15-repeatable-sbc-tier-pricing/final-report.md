# Repeatable SBC Tier Pricing — Final Verification Report

Date: 2026-08-15 (Africa/Cairo)

## Outcome

The repeatable-SBC bundle contract is implemented and verified locally from the n8n transformation package through Laravel catalog validation, the public AR/EN selector, secure cart insertion, checkout revalidation, order configuration, and the enlarged add-to-cart notice.

The code is **not deployed** in this verification step. Production rollout is blocked because the published n8n workflow is still emitting the previous one-completion contract, and the safe manual dry-run attempt from the updated editor draft was rejected by n8n with `Unauthorized` before an execution started. A subsequent same-URL reload redirected to n8n's sign-in page, proving that the editor session had expired; this was an n8n session boundary failure, not a workflow-node, pricing-signature, or Laravel failure. No `Publish`, production webhook `apply`, or catalog snapshot was triggered during this verification.

## Commits

- `405cbd4 feat(automation): price repeatable sbc bundles`
- `4276730 feat(catalog): validate sbc completion tiers`
- `de2210a feat(store): secure sbc bundle checkout`
- `2b72ee5 feat(store): select repeatable sbc bundles`
- `afedb5c fix(store): enlarge cart addition notice`
- `4deee74 test(checkout): isolate sqlite rollback fixture`

## Contract delivered

- Unlimited repeatable SBCs expose 5/10/15/20/30/40/50/75/100 completion bundles.
- Limited repeatable SBCs expose only the approved counts, including an appended maximum when required.
- PlayStation/Xbox and PC totals are calculated independently.
- The service fee is charged once per selected bundle.
- The browser sends only `variantId`, `completionCount`, and credentials; it never supplies a price or discount.
- Cart and order item quantity remains one while safe configuration stores `completion_count`.
- Catalog ingestion validates the complete tier declaration and increments `price_version` when effective tier totals change.
- Checkout revalidates the selected tier and its version before order or Paylink creation.
- Legacy one-completion catalog rows remain purchasable through the compatibility fallback.

## Aggregate repository gate

Command:

```powershell
composer ci:check
```

Result: exit 0.

- Composer strict validation: passed.
- Pint: passed.
- PHPStan: 0 errors.
- Pest: 613 total, 610 passed, 3 expected skips, 30,867 assertions.
- Vitest: 269/269 passed.
- ESLint, Prettier, TypeScript, and Vite production build: passed.

## Workflow package gate

Command:

```powershell
npm --prefix automation/n8n/sbc-catalog-v1 test
```

Result: 82/82 passed. Export freshness, independent PS/PC bundle arithmetic, limited/unlimited tier matrices, exact validation, and non-repeatable compatibility are covered.

## MariaDB 12.3.2 gate

An isolated disposable MariaDB 12.3.2 instance ran on `127.0.0.1:33323`.

- Critical catalog/cart/checkout/concurrency selection: 120 total, 117 passed, 3 expected skips, 836 assertions.
- Full migration rollback: all 17 migrations returned to Pending.
- Remigration: all 17 migrations returned to Ran.
- Post-remigration critical selection: 45 total, 42 passed, 3 expected skips, 261 assertions.
- The owned MariaDB server was stopped and the disposable data/log/archive files were removed.

## Browser verification

A disposable local SQLite catalog fixture contained one repeatable and one non-repeatable SBC with independent PlayStation/Xbox and PC totals.

Matrix: Arabic and English at 320, 390, 768, and 1440 CSS pixels.

- Correct locale direction (`rtl`/`ltr`) and no horizontal overflow at every viewport.
- Repeatable default: PlayStation/Xbox plus 5 completions.
- Non-repeatable product: platform selector only; no completion-tier selector.
- Switching from 5 to 10 completions updated the PlayStation/Xbox total synchronously to SAR 108.30.
- Switching to PC retained 10 completions and updated the total synchronously to SAR 119.70.
- Keyboard focus reached the completion group with a visible 3px focus outline.
- Add-to-cart created one bundle line with `completion_count=5`, showed product image, selection copy, cart action, dismiss action, and progress element.
- At 390px the notice width was 354.19px, top safe gutter 10.39px, image size 72px, with no overflow.
- The cart showed the selected completion count and exact SAR 57.00 bundle total.
- Browser console warnings/errors: zero.

The normal PHP 8.5 CLI server was unsuitable in this Windows environment because dynamically loaded extension functions were resolved incorrectly by the CLI-server SAPI. Verification used a disposable Node-to-`php-cgi.exe` bridge against the same built application. The bridge, router, temporary PHP ini, local database, listener, and related files were removed after verification; the temporary database was moved to the Recycle Bin.

## n8n production inspection

Workflow: `SBC Catalog v1 - Signed Laravel Snapshot` (`xfoD5dzj4HqWrXza`) on n8n 2.6.3.

Read-only inspection found:

- The workflow is currently active and its schedule has been executing every two hours.
- The latest inspected successful scheduled execution was `#436423` at 2026-08-15 03:00:35, duration 25.059 seconds.
- That execution used `mode=apply`, reached the catalog-signing/publish path, and emitted `formulaVersion=legacy-sbc-one-completion-v1` with no `completionPricing` bundle contract.
- The editor has unpublished changes (`Publish` was available), so the published runtime and the locally verified export are not the same version.

Controlled safe check:

- The editor trigger was explicitly changed from `SBC Catalog Production Trigger` to `Run SBC Catalog Now`.
- The resulting action label confirmed `Execute workflow from Run SBC Catalog Now`.
- Starting that manual dry run returned `Problem running workflow — Unauthorized` before a workflow execution began.
- The browser console showed the same HTTP authorization failure for both `runWorkflow` and the read-only `fetchExecutions` request.
- Reloading the exact workflow URL redirected to `/signin?redirect=...`, confirming an expired n8n login session as the root cause.
- No publish button, production webhook, apply trigger, or snapshot endpoint was invoked by this check.

## Rollout state and exact blocker

Local code and tests are ready for rollout, but production is not ready to claim the new tier behavior.

Required next production steps:

1. Sign back in to n8n through the existing browser tab without sharing credentials in chat.
2. Import or reconcile the verified workflow export and confirm its draft contains `legacy-sbc-repeat-bundle-v1` plus `completionPricing`.
3. Run the authenticated production webhook with the exact body `{ "mode": "dry_run" }` and verify counts/tier totals with `publishAttempted: false`.
4. Only after that evidence is green, publish/deploy the application and workflow, run one signed complete SBC snapshot, require HTTP 201, and verify a live repeatable and non-repeatable purchase path.
5. Confirm the guarded two-hour schedule remains active after the verified publish.

No production deployment, n8n publish, Paylink request, order, or signed catalog apply was performed in this step.
