# Repeatable SBC Tier Pricing — Final Verification Report

Date: 2026-08-15 (Africa/Cairo)

## Outcome

The repeatable-SBC bundle contract is implemented, deployed, and verified from the n8n transformation package through Laravel catalog validation, the public AR/EN selector, secure cart insertion, checkout revalidation, order configuration, and the enlarged add-to-cart notice.

GitHub CI and the Hostinger deployment completed successfully for `b54cfec23af...`. The final n8n workflow version was published, its authenticated production webhook completed a safe dry run, and a fresh signed apply completed with 29 products, 58 variants, and zero archives. Live storefront verification confirmed the updated catalog and independent PlayStation/Xbox and PC completion tiers.

## Commits

- `405cbd4 feat(automation): price repeatable sbc bundles`
- `4276730 feat(catalog): validate sbc completion tiers`
- `de2210a feat(store): secure sbc bundle checkout`
- `2b72ee5 feat(store): select repeatable sbc bundles`
- `afedb5c fix(store): enlarge cart addition notice`
- `4deee74 test(checkout): isolate sqlite rollback fixture`
- `142161a fix(store): omit SBC delivery from cart`

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
- Vitest: 269/269 passed in the original aggregate gate; after the cart presentation regression was added, the refreshed frontend gate passed 270/270.
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
- SBC cart rows omit the Coins-only delivery fact while retaining platform, completion count, and total.
- Browser console warnings/errors: zero.

The normal PHP 8.5 CLI server was unsuitable in this Windows environment because dynamically loaded extension functions were resolved incorrectly by the CLI-server SAPI. Verification used a disposable Node-to-`php-cgi.exe` bridge against the same built application. The bridge, router, temporary PHP ini, local database, listener, and related files were removed after verification; the temporary database was moved to the Recycle Bin.

## n8n production inspection

Workflow: `SBC Catalog v1 - Signed Laravel Snapshot` (`xfoD5dzj4HqWrXza`) on n8n 2.6.3.

Initial read-only inspection found:

- The workflow is currently active and its schedule has been executing every two hours.
- The latest inspected successful scheduled execution was `#436423` at 2026-08-15 03:00:35, duration 25.059 seconds.
- That execution used `mode=apply`, reached the catalog-signing/publish path, and emitted `formulaVersion=legacy-sbc-one-completion-v1` with no `completionPricing` bundle contract.
- The editor initially had unpublished changes (`Publish` was available), so the published runtime and the locally verified export were not the same version.

Controlled safe check:

- The two changed Code nodes were updated in place so the existing workflow ID, credentials, and durable workflow state were preserved.
- The editor trigger was explicitly set to `Run SBC Catalog Now`; the action label confirmed `Execute workflow from Run SBC Catalog Now`.
- The manual dry run completed with `status=dry_run`, `publishAttempted=false`, 4 categories, 29 products, 58 variants, source count 55, eligible count 29, source floor 47, and eligible floor 16.
- The validated output contains `formulaVersion=legacy-sbc-repeat-bundle-v1` and `completionPricing` with the approved 5/10/15/20/30/40/50/75/100 unlimited bundle matrix and independent platform totals.
- The sample output included bundle multipliers 10000/9500/9200/9000/8700/8500/8200/7800/7600 basis points.
- No catalog POST was attempted; create/update/archive preview remains unavailable because Laravel intentionally exposes no authenticated current-snapshot read endpoint.
- An attempted whole-workflow import appended duplicate nodes in the editor, so it was immediately undone before any execution or publish. The final draft contains no duplicated imported nodes.

Production rollout:

- GitHub test run `31881957996` passed, including the MariaDB schema job and the aggregate CI job.
- Hostinger deploy run `31882093287` completed successfully at `b54cfec23af...`; `https://store.arab-ut.com/up` returned HTTP 200.
- The cleaned n8n workflow was published as `Repeatable SBC pricing - production verified` and remains active.
- An authenticated production dry run returned `status=dry_run`, `publishAttempted=false`, 55 source records, 29 eligible products, and 58 variants.
- Fresh signed apply run `01M02NF93HC0550DKCG77JK0GD` returned `status=completed`, `applied=29`, and `archived=0`; n8n recorded production execution `#436779` as successful.
- The temporary credential-backed caller workflow was permanently deleted after verification; the credential value was never displayed, copied, logged, or added to the repository.
- Live `/sbc` displayed 29 products across 3 pages. The repeatable Provisions Upgrade exposed the 5/10/15/20/30/40/50/75/100 selector.
- Live independent pricing was confirmed: at 15 completions, PlayStation/Xbox displayed SAR 20.00 and PC displayed SAR 21.00.

## Rollout state

The application release, n8n workflow publish, authenticated production dry run, signed catalog apply, and live storefront verification are complete. The guarded two-hour schedule remains active on the final published workflow version.

No Paylink request or customer order was created during rollout verification. The cart presentation regression is covered by the deployed frontend test: SBC rows omit the Coins-only delivery field, while Coins rows retain it.
