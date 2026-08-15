# Arab UT Customer My Account

**Status:** Approved by Mohamed on 2026-08-15 with architecture amendments
**Product name:** `حسابي` / `My Account`
**Complexity:** Ambitious
**Direction:** Refined WordPress parity on the existing Laravel storefront

## Outcome

Replace the starter `dashboard` placeholder and generic settings shell with a complete customer account area inside the Arab UT storefront. Customers can review live and migrated orders, follow current fulfillment, inspect wallet history and loyalty progress, maintain their identity and security settings, and reach support without leaving the established bilingual store experience.

The customer-facing name is always `حسابي` in Arabic and `My Account` in English. `Dashboard` is not used as the product or navigation label.

## Existing foundation

The implementation extends the current application rather than creating a second account system:

- Laravel 13 owns authentication, authorization, customers, live orders and items, status history, payments, receipts, wallet accounts and entries, loyalty tiers, phone verification, and support configuration.
- React 19, Inertia 3, TypeScript, Tailwind CSS 4, and the existing `StoreLayout` own the customer interface.
- The existing order owner check in `App\Http\Controllers\Store\OrderController` remains the minimum authorization contract for every account order read.
- The existing `ProfileController`, `SecurityController`, WhatsApp verification actions, Fortify flows, and bilingual storefront translations are reused or adapted instead of duplicated.
- The historical Salla migration architecture approved in `2026-08-09-salla-history-import-design.md` is implemented behind a separate rollout boundary because the current branch contains its design and plan but not its migrations, models, command, or committed archive. It is a prerequisite for archived history, not for the current-data account experience.

## WordPress parity baseline

The former WordPress account implementation in the `wordpress-public-html-20260809` export remains the first visual and interaction reference. The Laravel account keeps these recognizable elements:

- a welcome panel using the customer name and useful order context;
- warm near-black and deep-brown surfaces with restrained gold emphasis;
- Thmanyah typography and the existing Arab UT crest and coin artwork;
- a persistent, clearly selected account navigation;
- prominent active-order access and a short recent-orders list;
- readable order cards rather than a dense desktop-only table;
- direct profile editing, WhatsApp phone verification, password management, and explicit logout;
- fast navigation with correct browser history and focus behavior.

Parity is structural and branded, not a copy of WordPress weaknesses. Misleading metrics, guessed Coins totals, duplicated hero/profile content, brittle AJAX HTML replacement, Arabic-only strings, and inaccessible interaction details are not carried forward.

## Information architecture and routes

The canonical account routes are authenticated and no-store:

| Destination | Arabic route | English route |
| --- | --- | --- |
| Overview | `/my-account` | `/en/my-account` |
| Orders | `/my-account/orders` | `/en/my-account/orders` |
| Live order detail | `/my-account/orders/{publicId}` | `/en/my-account/orders/{publicId}` |
| Archived order detail | `/my-account/orders/archive/{legacyOrder:public_id}` | `/en/my-account/orders/archive/{legacyOrder:public_id}` |
| Wallet | `/my-account/wallet` | `/en/my-account/wallet` |
| Profile | `/my-account/profile` | `/en/my-account/profile` |
| Security | `/my-account/security` | `/en/my-account/security` |
| Support | `/my-account/support` | `/en/my-account/support` |

The existing `/dashboard` route redirects to the locale-appropriate canonical account overview. Existing `/settings/*` routes either redirect to their account equivalents or remain internal aliases until all callers and tests move. The shared header's authenticated account URL points to the canonical overview.

The account navigation order is Overview, Orders, Wallet, Profile, Security, and Support. Logout is a visually separate POST button, not a navigation destination. Arabic and English use the same information architecture. Desktop uses a persistent side navigation when space permits. Phone widths use a compact horizontal account navigation that preserves the WordPress interaction model without hiding destinations behind an additional hamburger menu.

## Rollout controls and route middleware

Two independent configuration flags limit rollout blast radius:

- `my_account_enabled` controls the new account destinations and storefront entry point;
- `legacy_history_enabled` controls archive linking, the unified feed, and archived detail routes without disabling live orders, wallet, profile, security, or support.

The account may ship with current Laravel data while the importer is rehearsed with `legacy_history_enabled` off. The archive flag is enabled only after a committed import reconciles. It can be turned off again without deleting archive rows or making the rest of My Account unavailable.

All account GET routes use `auth`, active-user enforcement, `NoStore`, and `inertia::encrypt`. Account mutations additionally use action-specific throttles and recent authentication where required. Verified-email middleware is never applied to the whole account because phone-only and imported customers must retain access. Inactive sessions are rejected even if the user became inactive after login.

## Customer overview

The overview answers the customer's most likely questions without becoming an analytics dashboard:

1. Welcome and account context.
2. Current actionable order, if one exists.
3. Wallet balance in SAR.
4. Open-order count.
5. Completed-order count across live and linked archived orders.
6. Current loyalty tier and progress toward the next active tier.
7. Three most recent orders across both sources.
8. Shortcuts to buy again, view all orders, inspect the wallet, or contact support.

Only authoritative values are displayed. Historical Coins quantity is not derived from product-name parsing because the approved migration audit established that most dynamic Coins rows lack a reliable selected quantity.

If summary data fails, the page preserves navigation and identity context, explains which section could not load, and provides a retry action. It does not replace the entire account with an error screen.

## Unified order history

Customers see one chronological order history backed by two separate stores:

- live `orders` and `order_items` retain operational behavior, payments, item-level fulfillment, receipts, and current status history;
- `legacy_orders` and `legacy_order_items` retain the migrated source record, original currency, original totals, mapped status, and original source label without becoming fulfillable live orders.

A presentation DTO normalizes only the fields needed for shared order cards: stable public reference, source kind, display number, created date, localized summary, mapped display status, original currency and total, item count, and detail URL. It never erases source-specific meaning.

The default list combines both sources and supports status/source filtering and bounded pagination. The unified feed is produced at the database/read-model boundary with a normalized `UNION ALL` projection. Independent live and archive paginators are never merged in PHP. v1 uses deterministic offset pagination ordered by `occurred_at DESC`, `source_kind ASC`, then opaque `stable_public_id DESC`; cursor pagination can replace it later without changing the projection if volume requires it.

`legacy_orders.live_order_id` is nullable and unique. When an imported row is linked to a live order, the live order is canonical and the pair appears once in the feed, recent orders, and completed count. Current actionable order and open-order count use live operational orders only. Historical status never makes an archived order actionable.

Each archived order is visibly labeled `طلب أرشيفي` / `Archived order`. Its opaque ULID is used in customer URLs; Salla identifiers stay internal. The controller loads it through the authenticated user's archive relationship or an equivalent owner-scoped query so cross-owner identifiers return not found. Archived details are read-only and always expose `actionable = false`, `supports_payment_recovery = false`, `supports_credentials_update = false`, `supports_cancel = false`, and `supports_refund = false`. Live order actions remain limited by current order and payment state; customers cannot initiate cancellation or refunds in v1.

Live mixed orders display a canonical `OrderStatus` and item-level `OrderItemStatus` values so one completed item cannot hide another item that is waiting or failed. The actionable-order priority is `waiting_for_customer`, failed payment that can be retried, `pending_payment`, `in_progress`, then the most recent non-terminal live order. A versioned legacy mapping retains every original label and fails committed imports on unknown values. Archived mappings are display-only regardless of their name. The existing direct `/orders/{order}` URLs continue to work and redirect or render through the canonical account detail without weakening owner checks.

Order pages show data current at request time. Non-terminal live details provide an explicit `تحديث الحالة` / `Refresh status` action using an Inertia partial reload. v1 does not poll in the background. Mutations that affect an order reload the active order, metrics, and recent orders together so partial props do not remain stale. EA credentials are never returned in page props; account projections expose only safe booleans and timestamps such as `credentials_present` and `credentials_updated_at`.

## Historical migration prerequisite

Implement the approved Salla archive boundary before wiring archived orders into My Account:

- `legacy_import_runs` records source, schema version, input hashes, mode, status, redacted counts, and reconciliation totals.
- `legacy_customers` preserves source identities and optional safe links to `users`.
- `legacy_orders` preserves source order identity, optional `user_id`, mapped and original statuses, payment state, original currency and decimal totals, timestamps, encrypted raw payload, and payload hash.
- `legacy_order_items` preserves the unique source item reference, parent order, source SKU/name, quantity, exact decimal amounts, deterministic archival classification, encrypted raw payload, and payload hash.
- Imported users receive no invented email, password, or verification timestamp.
- Wallet opening balances become deterministic immutable ledger entries in SAR as specified by the approved migration design.
- Dry-run is the default. Committing requires the explicit command flag defined by the migration implementation plan.
- Real exports, converted CSV files, names, emails, phones, notes, and raw rows never enter Git, command logs, committed reports, test fixtures, or chat.

Importer uniqueness is enforced for source customer, order, and item identifiers. A lock prevents concurrent import runs, every imported row records its `legacy_import_run_id`, and runs move through `pending`, `running`, `failed`, `reconciled`, and `committed`. Re-running identical input is a no-op and never repeats wallet credit.

Committed mode fails closed when reconciliation finds count or per-currency total mismatches, duplicate source identifiers, orphan items, unknown statuses, invalid dates/money/currencies, unmatched records beyond the approved baseline, or ambiguous identity matches. Before customer exposure, one import run can be removed in isolation and `legacy_history_enabled` can be disabled without changing live orders. Sanitized encrypted payloads use allowlisted fields in `TEXT` or larger columns, omit credentials, OTPs, unnecessary notes, and provider secrets, and receive an explicit retention policy after reconciliation.

### Legacy identity linking and claim policy

Automatic linking is permitted only when a normalized verified phone or normalized verified email identifies one live user unambiguously. Names, malformed identifiers, duplicated identifiers, and fuzzy matches never link accounts. Conflicting matches remain unlinked and are reported with PII-free reason codes.

The v1 claim flow is in scope. A signed-in customer can claim eligible legacy history by proving possession of the historical phone through the existing WhatsApp OTP boundary or the historical email through a one-time verification link. Successful claims record `linked_at`, `link_method`, `linked_identifier_type`, and `linked_by_user_id`; OTP values and full identifiers never enter logs. Imported/passwordless customers may recover through a verified identity but receive no invented password or email.

The real import remains a controlled deployment operation after a backup and reconciliation. My Account code and fictional automated fixtures can be completed without placing production personal data in the development repository.

## Wallet and loyalty

The wallet page shows:

- current SAR balance;
- an immutable, newest-first ledger;
- localized entry type, amount, balance after entry, timestamp, and safe order reference when applicable;
- credit/debit semantics conveyed through text and icon as well as color;
- an explanation that wallet credit can be spent but cannot be topped up by the customer.

The customer cannot edit, delete, or create wallet entries. Pagination must not require loading the full ledger. Live monetary values are transferred as exact minor-unit strings plus a currency code; historical currencies use exact decimal strings. React formats values for display only and never performs balance, arithmetic, or FX calculations.

The existing unique wallet entry `reference` is the idempotency key. Each account also receives a monotonically increasing `sequence` with a unique `(wallet_account_id, sequence)` constraint. Credits, debits, refunds, and adjustments lock the wallet and append an entry in one database transaction; `wallet_accounts.balance_halalah` must equal the latest entry balance and `sum(credits + refunds + signed adjustments - debits)` must equal the account balance. Historical opening credit is committed before live wallet writes are enabled; it is not backdated into the middle of an active ledger.

Loyalty is based on net eligible completed SAR spend and the active thresholds stored in `loyalty_tiers`. A live order becomes eligible only after completion and successful settlement; its post-discount `total_halalah` counts whether payment used gateway funds, wallet credit, or both, then completed refunds reduce eligible spend. Taxes and payment fees are not separate fields in the current domain and therefore cannot be counted independently. Cancelled, failed, pending, chargeback-equivalent, and net-zero orders do not count.

A legacy order receives `loyalty_eligible_sar_minor` during import only when the source proves a completed, paid, non-refunded SAR order. Non-SAR orders and records without trustworthy paid/refund evidence remain null and are excluded; current exchange rates are never used to manufacture historical eligibility. Tier threshold edits intentionally recalculate customer tier and progress retroactively. The overview shows the current tier and exact remaining SAR amount to the next active tier. When no active tier configuration exists, the loyalty module is omitted with a controlled fallback rather than inventing thresholds.

## Profile and security

Profile includes first name, last name, email, phone, preferred language, and display currency where the existing domain supports them.

- Fields have persistent labels and server validation.
- Email changes are staged as `pending_email`; the current address remains active until a one-time link verifies the new address. Only then are `email` and `email_verified_at` changed atomically, and the old address receives a change notice. The flow implements or explicitly connects Laravel's `MustVerifyEmail` contracts where applicable without applying verified middleware to all account pages, and requires current password or recent trusted authentication.
- Phone changes are staged as `pending_phone`; the current number remains active until the customer proves the new number through the existing WhatsApp OTP boundary. OTP hashes expire, have bounded attempts and resend cooldown, and are rate-limited by user, candidate phone, and IP. The swap is atomic.
- The interface distinguishes verified, unverified, loading, expired-code, rate-limited, conflict, and provider-failure states.
- Successful updates return localized confirmation and preserve the customer's position.

Security distinguishes password change for password-capable accounts, password setup for a signed-in imported/social account after trusted re-authentication, and logged-out recovery through a verified email or WhatsApp claim flow. Standard email reset is not offered to an account without a verified deliverable email. Sensitive forms exclude current password, password, confirmation, OTP, and verification codes from Inertia remembered state. Password confirmation, throttling, and current-password requirements continue to use Laravel/Fortify contracts.

The existing account deletion route is disabled, not merely hidden, until order, payment, wallet, audit, and legal-retention behavior is separately approved. This avoids retaining a destructive endpoint whose accounting consequences have not been specified.

## Support

The support page exposes the configured Arab UT WhatsApp and email destinations. When opened from an order, the support link may include the safe public order number and locale, but never credentials, raw provider identifiers, personal notes, or secrets. No ticketing database, live chat system, or third-party support SDK is added in this scope.

## Data loading and navigation

Each account destination is a real deep-linkable Inertia route. This preserves browser back/forward behavior, refresh, shareable internal URLs, and server authorization.

Summary modules may use deferred props when they are materially slower than identity and navigation. Same-page order filters and pagination use Inertia partial reloads and preserve the relevant filter state and scroll behavior. Loading placeholders reserve final layout space and rescued deferred failures provide an explicit retry path.

Account responses use `NoStore` and encrypted Inertia history. Logout, account switching, forced session termination, and account deletion clear Inertia history; client logout also flushes prefetched data. Security, wallet, and order-detail destinations are not prefetched. Controllers select explicit safe projections rather than serializing full Eloquent models or encrypted payload columns. Queries eager-load only relationships needed by the active destination and use bounded pagination for orders and wallet entries.

## Components and boundaries

- `MyAccountLayout`: storefront shell, account heading, responsive account navigation, active state, and logout placement.
- `AccountOverview`: orchestration of summary, active order, loyalty, and recent orders.
- `AccountMetric`: compact real-data summary with loading and unavailable states.
- `AccountOrderCard`: shared live/archive presentation without shared operational actions.
- `LiveOrderDetail`: current order/items, payment recovery, and item progress.
- `ArchivedOrderDetail`: read-only original historical facts.
- `WalletSummary` and `WalletLedger`: immutable balance and paginated entries.
- `AccountProfileForm`: identity and preference updates.
- `AccountPhoneVerification`: explicit WhatsApp OTP state machine.
- `AccountSecurityForm`: password setup/change behavior.
- `AccountSupport`: configured safe contact destinations.

Server-side query/action classes own summary calculation, live/archive order projections, loyalty resolution, and wallet projections. React components do not calculate prices, balances, tiers, fulfillment status, or ownership.

## UX writing and localization

Arabic is the default and is written natively for RTL in a clear, light Gulf tone. English receives complete equivalent copy and LTR layout. Copy is stored in the existing Laravel translation structure rather than duplicated inside components.

Customer statuses describe what is happening and the next available action. Errors state the cause when safe and explain recovery. Empty states provide a relevant next step. The public brand label remains `Arab UT` in English.

## Security and privacy

- Every destination requires authentication; verified-email middleware is used only where the existing product contract requires it and must not lock phone-only imported customers out of account recovery.
- Live and archived order queries always scope by the authenticated `user_id`; unrelated records return not found without revealing existence.
- Raw migrated payloads and account credentials are never passed to Inertia.
- Responses containing customer information use no-store headers.
- Profile and security mutations use CSRF protection, validation, throttling where applicable, and Laravel authorization.
- Logs, analytics, URLs, browser storage, and flash payloads contain no passwords, OTP values, EA credentials, raw migrated payloads, or unnecessary personal data.
- Logout remains a mutation using the existing authenticated session contract; it is visually separated from normal navigation.
- Logout rotates the Inertia history encryption key so browser Back cannot reveal the prior account. Account forms call `dontRemember()` for secrets and verification codes.

## Error and empty states

The implementation covers:

- new customer with no orders, wallet entries, or loyalty tier;
- live orders only, archived orders only, and both sources;
- pending payment and recoverable provider failure;
- waiting-for-customer and mixed item statuses;
- archived rows with unknown product classification or missing optional identity fields;
- unavailable summary, order list, wallet ledger, or support configuration;
- expired or rate-limited WhatsApp verification;
- long names, long translated labels, large monetary values, and 14 historical currencies;
- unauthenticated, unverified, inactive, and cross-owner requests.

## Visual and accessibility acceptance

The page first reproduces the approved WordPress hierarchy, assets, Thmanyah typography, and warm black/gold identity. Refinements then improve hierarchy, spacing, responsiveness, interaction states, and clarity without introducing a generic dashboard, glassmorphism, light SaaS palette, or unapproved typeface.

Before delivery, Arabic RTL and English LTR are verified at 320px, 390px, 768px, and 1440px. Acceptance also requires:

- no document-level horizontal overflow;
- all pointer/touch targets at least 44px;
- correct heading and landmark hierarchy;
- visible keyboard focus and logical focus order;
- focus movement after account route changes and validation failures;
- accessible names and selected/current/expanded/busy states;
- status meaning not conveyed by color alone;
- WCAG AA text contrast;
- 200% zoom usability;
- `prefers-reduced-motion` support;
- no layout shift from images or loading placeholders;
- no browser console errors or warnings.

## Automated and runtime verification

Implementation follows test-driven development and adds focused coverage for:

- canonical and localized account routes plus legacy redirects;
- authentication, inactive-user behavior, and owner-only live/archive access;
- summary calculations without historical Coins guessing;
- combined order ordering, filtering, pagination, archive labeling, and safe projections;
- live mixed-order and archived read-only behavior;
- wallet balance and immutable ledger projections;
- loyalty tier and next-threshold calculation;
- bilingual copy and RTL/LTR rendering contracts;
- profile, phone verification, password-capable, and imported-account recovery states;
- empty, loading, rescued-error, validation, success, and provider-failure states;
- responsive navigation, keyboard behavior, focus, touch targets, reduced motion, and overflow.

Browser-level coverage additionally proves logout then Back cannot reveal account props, user B cannot see user A's history after an account switch, cross-owner archive ULIDs return 404, filters survive refresh/back/forward correctly, route changes and validation failures move focus predictably, mobile navigation does not overflow, and page props contain none of `raw_payload`, `password`, `otp`, `credentials`, provider secrets, or internal notes.

The final gate runs the relevant Pest and Vitest suites, TypeScript checks, ESLint, Prettier, PHPStan, Pint, the production frontend build, migration lifecycle tests, and browser verification at the required locales and viewports.

## Delivery stages

1. Domain contracts and rollout controls: flags, active-user middleware, history encryption/clearing, money DTOs, status rules, ownership, wallet invariants, and claim policy.
2. Account foundation on current data: canonical bilingual routes, shared props, `MyAccountLayout`, overview, live orders, wallet, profile, security, support, and redirects.
3. Historical importer in isolation with the archive flag off: schema, dry-run, idempotency, reconciliation, sanitized payloads, rollback boundary, and fictional tests.
4. Legacy identity linking and claim flow: verified matches, ambiguous cases, audit trail, and passwordless recovery.
5. Unified database read model: deduplication, stable pagination/filtering, archived details, and historical metrics.
6. Loyalty eligibility and progress using the approved net-SAR rules.
7. WordPress parity comparison, Impeccable refinement, browser-level accessibility/security tests, final `polish`, production build, import rehearsal, reconciliation, and controlled archive activation.

Each stage must pass its focused automated checks before the next stage begins. Consequential deviations from the WordPress information hierarchy or the approved scope return to Mohamed for approval.

## Required access and operational inputs

No new production/runtime frontend package, external customer account service, or new production credential is required for implementation. A dev-only browser testing dependency may be added if the repository still has no equivalent automated browser harness when that acceptance stage begins.

The controlled historical import later requires the already audited private Salla customer/order/wallet exports and database backup access through the approved secure path. Existing Whapi, mail, Paylink, and deployment credentials remain environment-managed and are never requested or pasted in chat.

## Explicitly outside this scope

- an admin dashboard or staff account area;
- customer wallet top-ups;
- customer-initiated cancellation or refunds;
- editing or retrying archived orders;
- a support ticketing platform or live-chat SDK;
- loyalty points or automatic cashback;
- customer address management for this digital-service storefront;
- native mobile applications;
- speculative metrics derived from incomplete historical product names;
- a generic SaaS dashboard redesign.

## Official implementation references

- Laravel 13 documentation: <https://laravel.com/docs/13.x/documentation>
- Inertia 3 partial reloads: <https://inertiajs.com/docs/v3/data-props/partial-reloads>
- Inertia 3 deferred props: <https://inertiajs.com/docs/v3/data-props/deferred-props>
- Inertia 3 state and scroll management: <https://inertiajs.com/docs/v3/data-props/remembering-state> and <https://inertiajs.com/docs/v3/advanced/scroll-management>
- WooCommerce account behavior reference: <https://woocommerce.com/document/the-my-account-page/>
