# SBC Workflow, Footer Pages, and Storefront Parity — Final Report

## Outcome

The approved SBC storefront and automation release is live on `store.arab-ut.com`. Laravel/MariaDB remains the source of truth; the active n8n workflow publishes a complete, signed, SBC-only snapshot through isolated credentials and cannot reconcile the shared catalog source.

## Production automation

- Active workflow: `SBC Catalog v1 - Signed Laravel Snapshot` (`xfoD5dzj4HqWrXza`).
- Schedule: every two hours.
- Legacy workflow `SBC Sync — WC` (`BYexIi-KCthNA5whRa1Ve`) remains inactive.
- A real dry run completed with 56 source records, 39 eligible products, 78 variants, four categories, and no publish attempt.
- Controlled apply run `01KZTFCJCJ22WCY543BT97PK4V` completed with 39 applied products and zero archives.
- Category-field follow-up run `01KZTFW05HKXSWED9DW6W30MZ7` completed with 39 applied products and zero archives.
- Durable workflow state contains the 39 successful identities, 56/39 source and eligible baselines, and 39 approved translations.
- Catalog and pricing secrets are stored only in Hostinger/n8n environment or credential stores. They are not committed or printed in this report.
- The workflow fails closed on partial/replaced source identities, unexpected omissions, incomplete translations, invalid media, signing failures, and non-successful Laravel responses.

## Live catalog evidence

- Catalog source: `n8n-sbc`.
- Four categories, 39 visible products, 78 active variants, and 39 mirrored media records.
- Live filter counts: players 9, icons 4, upgrades 26, foundations 0.
- Latest catalog sync status: completed, with no failed run.
- Current signed pricing bases used by the workflow: PlayStation fast 1M = 3,900 halalah (SAR 39); PC 1M = 5,200 halalah (SAR 52).
- Example live product `Ayden Heaven`: SAR 8 PlayStation and SAR 9 PC, price version 1.

## Storefront and credential journey

- `/sbc` and `/en/sbc` provide localized, responsive search, category counts, sorting, pagination, real product media, and product links.
- Product pages provide platform selection and authoritative prices.
- SBC add-to-cart requires an EA email, password, and exactly three distinct eight-digit backup codes.
- Credentials are encrypted at rest, excluded from safe cart metadata and logs, retained without automatic expiry per the approved product decision, and viewable/editable only through the verified cart-owner boundary.
- The generic catalog add endpoint rejects SBC items so an SBC line cannot be created without credentials.
- Idempotency, CSRF, owner isolation, retry behavior, locked in-flight inputs, error association, and post-error focus were independently reviewed. Final scoped verdict: SPEC PASS / QUALITY PASS.

## Informational pages

- Privacy, returns, warranty, terms, and EA backup-code guidance are available in Arabic and English.
- The typed document renderer validates every block, safe external link, optional value, heading level, list type, divider, and notice variant before rendering.
- Privacy text truthfully describes indefinite encrypted credential retention, verified-owner access, authorized fulfillment access, and deletion with the cart item or account.
- The EA guide follows current official navigation and does not tell customers to share credentials or claim unsupported single-use behavior.

## Browser verification

- Arabic SBC at 390px: RTL, 12 first-page cards, loaded media, accurate counts, and no horizontal overflow.
- Arabic product page: platform prices, email/password controls, accessible reveal control, exactly three backup-code fields, and 47–48px controls.
- English product route `/en/sbc/sbc-ayden-heaven-1340`: localized LTR page, three backup-code fields, working add-to-cart action, and no overflow.
- Arabic privacy at 320px: correct RTL, truthful retention copy, and no overflow.
- Final live `/sbc` console: zero warnings and zero errors.
- Verification intentionally did not create a real production cart item.

## Verification and deployment

- Aggregate application gate: Pest 506 total / 503 passed / 3 skipped / 29,954 assertions; Vitest 232/232; Composer validation, Pint, PHPStan, ESLint, Prettier, TypeScript, and production build passed.
- Task 5 final review gates: focused Vitest 15/15; focused Pest 31/31 with 197 assertions; SPEC PASS / QUALITY PASS.
- GitHub Actions run `31576754334`: CI and MariaDB jobs passed.
- Hostinger deploy run `31576954702`: passed.
- Verified production release before this documentation-only closeout: `b3a7f47f2552284e6005c73bd9c377b9c1ea12f7`.
- Relevant production commits include `b84c2c6`, `5f470e2`, `302a4f4`, `c6fc751`, `7493fe6`, `13401e2`, `a517d11`, `655a0db`, `37a6e4a`, `caaaf76`, `f6836f3`, `c14876b`, `05e7dd49`, `06ce5bd`, `c6781e9`, `c519272`, `cc39700`, and `b3a7f47`.

## Operational notes

- `assets.easysbc.io` is the intentionally allowlisted workflow media host.
- The new workflow is guarded by durable identity state and must not be replaced with the legacy imperative Woo/Salla flow.
- GitHub emitted only the upstream Node runtime deprecation warning for artifact actions; both required jobs passed.
- No password, API key, HMAC secret, provider credential, or raw EA credential is present in source control or this report.
