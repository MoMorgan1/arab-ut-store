# Arab UT Customer My Account

**Status:** Approved by Mohamed on 2026-08-15
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
- The historical Salla migration architecture approved in `2026-08-09-salla-history-import-design.md` is implemented as a prerequisite because the current branch contains its design and plan but not its migrations, models, command, or committed archive.

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
| Archived order detail | `/my-account/orders/archive/{sourceId}` | `/en/my-account/orders/archive/{sourceId}` |
| Wallet | `/my-account/wallet` | `/en/my-account/wallet` |
| Profile | `/my-account/profile` | `/en/my-account/profile` |
| Security | `/my-account/security` | `/en/my-account/security` |
| Support | `/my-account/support` | `/en/my-account/support` |

The existing `/dashboard` route redirects to the locale-appropriate canonical account overview. Existing `/settings/*` routes either redirect to their account equivalents or remain internal aliases until all callers and tests move. The shared header's authenticated account URL points to the canonical overview.

The account navigation order is Overview, Orders, Wallet, Profile, Security, Support, and Logout. Arabic and English use the same information architecture. Desktop uses a persistent side navigation when space permits. Phone widths use a compact horizontal account navigation that preserves the WordPress interaction model without hiding destinations behind an additional hamburger menu.

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

The default list combines both sources and supports status/source filtering and bounded pagination. Each archived order is visibly labeled `طلب أرشيفي` / `Archived order`. Archived details are read-only and never expose Pay, Retry, Cancel, Refund, credential, or fulfillment actions. Live order actions remain limited by the current order and payment state; customers cannot initiate cancellation or refunds in v1.

Live mixed orders display the conservative order status and item-level progress so one completed item cannot hide another item that is waiting or failed. The existing direct `/orders/{order}` URLs continue to work and redirect or render through the canonical account detail without weakening owner checks.

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

The real import remains a controlled deployment operation after a backup and reconciliation. My Account code and fictional automated fixtures can be completed without placing production personal data in the development repository.

## Wallet and loyalty

The wallet page shows:

- current SAR balance;
- an immutable, newest-first ledger;
- localized entry type, amount, balance after entry, timestamp, and safe order reference when applicable;
- credit/debit semantics conveyed through text and icon as well as color;
- an explanation that wallet credit can be spent but cannot be topped up by the customer.

The customer cannot edit, delete, or create wallet entries. Pagination must not require loading the full ledger.

Loyalty is based on completed lifetime spend and the active thresholds stored in `loyalty_tiers`. The overview shows the current tier and exact remaining SAR amount to the next tier. When no active tier configuration exists, the loyalty module is omitted with a controlled fallback rather than inventing thresholds.

## Profile and security

Profile includes first name, last name, email, phone, preferred language, and display currency where the existing domain supports them.

- Fields have persistent labels and server validation.
- Email changes clear `email_verified_at`. Because the current `User` model does not implement `MustVerifyEmail` and the current profile flow does not send a replacement verification link, the implementation must add or explicitly connect the account-area verification flow before any My Account destination relies on verified-email middleware.
- A phone change is not saved as verified until the customer proves the new number through the existing WhatsApp OTP boundary.
- The interface distinguishes verified, unverified, loading, expired-code, rate-limited, conflict, and provider-failure states.
- Successful updates return localized confirmation and preserve the customer's position.

Security includes password change for password-capable accounts and a clear password-setup/reset route for imported or social-only accounts. Sensitive fields are never remembered in browser history. Password confirmation, throttling, and current-password requirements continue to use Laravel/Fortify contracts.

The existing account deletion endpoint is not promoted as a normal v1 account action until order, payment, wallet, audit, and legal-retention behavior is separately approved. This avoids presenting a destructive control whose accounting consequences have not been specified.

## Support

The support page exposes the configured Arab UT WhatsApp and email destinations. When opened from an order, the support link may include the safe public order number and locale, but never credentials, raw provider identifiers, personal notes, or secrets. No ticketing database, live chat system, or third-party support SDK is added in this scope.

## Data loading and navigation

Each account destination is a real deep-linkable Inertia route. This preserves browser back/forward behavior, refresh, shareable internal URLs, and server authorization.

Summary modules may use deferred props when they are materially slower than identity and navigation. Same-page order filters and pagination use Inertia partial reloads and preserve the relevant filter state and scroll behavior. Loading placeholders reserve final layout space and rescued deferred failures provide an explicit retry path.

Account responses use `NoStore`. Controllers select explicit safe projections rather than serializing full Eloquent models or encrypted payload columns. Queries eager-load only relationships needed by the active destination and use bounded pagination for orders and wallet entries.

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

The final gate runs the relevant Pest and Vitest suites, TypeScript checks, ESLint, Prettier, PHPStan, Pint, the production frontend build, migration lifecycle tests, and browser verification at the required locales and viewports.

## Delivery stages

1. Historical archive schema, models, importer boundary, and fictional reconciliation tests.
2. Canonical bilingual routes, shared props, `MyAccountLayout`, translations, and redirects from the starter dashboard/settings URLs.
3. Overview, authoritative metrics, active order, unified recent orders, full order list, and live/archive detail pages.
4. Wallet ledger and loyalty progress.
5. Profile, WhatsApp phone verification, imported/social-account password behavior, and security.
6. Support, all empty/loading/error states, and deep-link behavior.
7. WordPress parity comparison, Impeccable refinement, final `polish`, and full verification.

Each stage must pass its focused automated checks before the next stage begins. Consequential deviations from the WordPress information hierarchy or the approved scope return to Mohamed for approval.

## Required access and operational inputs

No new frontend package, external customer account service, or new production credential is required for implementation.

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
