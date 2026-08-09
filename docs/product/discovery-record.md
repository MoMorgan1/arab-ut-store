# Arab UT Full-Replacement Discovery Record

Status: Discovery complete. The Phase 2 blueprint was approved by Mohamed on 2026-08-09, and Phase 3 implementation is in progress.

Last updated: 2026-08-09

## Confirmed by Mohamed

- Discovery must stay MVP-focused. Ask only high-impact questions needed to define and build v1; defer low-probability incidents, advanced controls, and nonessential edge cases to a later backlog.
- Primary public brand name: Arab UT. Arab Ultimate may remain as the expanded brand name where context requires it.
- Primary audience: Arab customers, while allowing customers worldwide rather than restricting the storefront by country.
- v1 will support both Arabic (RTL) and English (LTR) from the beginning. Arabic is the default storefront language, with a language switch for English.
- SAR is the authoritative catalog and checkout currency. Customers may switch the storefront display to another supported currency, but checkout and the final payment charge remain in SAR. No special currency warning or banner is required; the normal checkout total still identifies its currency as SAR.
- Payment-gateway selection/integration is intentionally deferred. Plan the MVP around a provider-neutral payment boundary and do not begin gateway integration until Mohamed explicitly authorizes it.
- Checkout requires an authenticated customer account. Registration/checkout must collect first name, last name, email address, WhatsApp number, and a password when email/password registration is used.
- MVP authentication must include email/password, Google sign-in, and the existing WhatsApp OTP concept. The current WordPress implementations will be inspected for behavior only; secrets and provider-specific code must not be copied blindly.
- WhatsApp OTP retains the current MVP behavior: it signs in an existing phone-linked account rather than creating a new account. A customer who signs in through Google must add a WhatsApp number before checkout.
- New email/password and Google customers must verify their WhatsApp number before checkout. Separate email verification is not required for v1.
- After an order is created, the website sends the required order payload to n8n. The WhatsApp workflow owns delivery of the order details to the customer; the new website does not duplicate that messaging logic.
- The customer wallet is a non-top-up balance for store credit and refunds. Customers cannot add funds to it in v1. Available credit can be spent on new orders; when it does not cover the total, the customer may pay the remainder through the payment gateway.
- The full admin dashboard is for Mohamed. v1 also needs a staff role that can view orders only. Mohamed personally handles external player coordination and changes manual-order statuses; no worker assignment or player portal is required.
- Existing data migration must include customer names and emails without passwords, historical order records, and wallet balances when available and technically reliable. Mohamed has a separate export/list that will be reviewed later; no export work is needed during current Discovery.
- Customers migrated without passwords will need a secure account-activation or password-reset path before signing in.
- Customer order and status communication should use n8n-driven WhatsApp messages, with email notifications also included in the MVP when operational email delivery is configured.
- A cart/order may contain multiple services rather than being restricted to one service per checkout.
- Credentials are attached per order item, with a convenience control to reuse the same account details for additional eligible items in the same order.
- Required account identifier by service: Coins, SBCs, and Objectives use the EA email; console Rivals and FUT Champions use the PlayStation email. Exact additional fields will be mapped from Mohamed's workflows without reopening the agreed before-payment collection decision.
- Player rewards are part of the SBC catalog and are not a separate top-level service named Players.
- Coins, SBCs, and Objectives are offered on PlayStation, Xbox, and PC. PlayStation and Xbox share one internal console pricing/fulfillment group for those services, but the storefront displays both platform names normally rather than presenting a generic Console option.
- Rivals and FUT Champions are offered on PlayStation and PC only. PC customers choose EA app or Steam. Steam orders require the Steam username/password plus the EA credentials and EA backup codes. PlayStation orders require the EA credentials plus PlayStation backup codes.
- Coins and SBC pricing is automation-managed. Objectives, Rivals, and FUT Champions pricing is managed manually through the admin dashboard.
- Each service category uses a tailored guided configurator while sharing the common cart, checkout, order, customer, and admin foundations.
- Customers cannot initiate cancellations or refunds themselves in v1. Mohamed controls those actions from the admin dashboard.
- The accepted customer-visible MVP order statuses are Pending Payment, Received, In Progress, Waiting for Customer, Completed, Cancelled, and Refunded.
- Coupons and automatic quantity/cart discounts are included in the MVP.
- Reviews, FAQ content, floating WhatsApp support, loyalty levels, and the customer order-tracking timeline are included in the MVP.
- Reviews continue to come from n8n; manual review creation/editing in the ecommerce admin is not required.
- The customer dashboard includes Overview, Orders, Order Tracking, Wallet History, Profile/Security, and Support.
- The checkout removes country, street address, and city fields for the digital-service MVP unless the selected payment provider later makes a specific billing field mandatory.
- The admin dashboard overview includes revenue, orders grouped by status, customer count, recent orders, and orders requiring attention.
- Loyalty remains lean in v1: Silver, Gold, and Platinum are calculated from completed lifetime spend, customers see progress, and admins can target tiers with coupons/offers. Thresholds and benefits are configurable. A separate points or automatic-cashback engine is deferred to avoid duplicating the wallet and committing margin before real order economics are known.
- An authorized admin may choose between refunding to the original payment method or issuing wallet credit.
- Required EA account credentials are collected before payment. Despite the collection timing, EA passwords and backup codes are classified as sensitive secrets and must be encrypted, redacted from logs and analytics, access-controlled, and deleted after fulfillment or another terminal outcome.
- Target product: a complete custom ecommerce system replacing WordPress and WooCommerce, including the storefront, backend, database, customer authentication, customer accounts, order history, wallet, and an admin dashboard.
- FC 27 service catalog currently includes Coins, SBCs, Objectives, Rivals, and FUT Champions. Player-reward products are categorized within SBCs rather than represented as a separate service.
- Coins:
  - Pricing is automated.
  - Paid-order fulfillment is automated through n8n.
- SBCs:
  - Names, images, and related catalog data are automated through n8n.
  - Paid-order fulfillment is automated through n8n.
- The FC 27 catalog should include both player-reward SBCs and other eligible SBCs, separated through storefront filters.
- SBC category assignment is automated from the source API. For automation-managed SBC products, the next synchronization is authoritative and may replace a manual category change.
- The v1 storefront will preserve the current SBC filter behavior:
  - All is a virtual filter.
  - Players, Icons, Upgrades, and Foundations are visible filters.
  - Source category Challenges is displayed under Upgrades rather than as a separate filter.
  - Swaps is not displayed as a separate filter.
  - The original source category identifier is retained internally.
- Product management must support both channels:
  - Primary automated creation and synchronization through an authenticated API used by n8n.
  - Complete manual ecommerce catalog management through the admin dashboard, including products, categories, names, descriptions, prices, images, SKUs, visibility/status, and other required product data.
- For API-managed products, automation is authoritative. Manual changes to synchronized fields may be overwritten by the next successful sync.
- A manually created product whose SKU uses the current external-set convention, such as `SBC_<setID>`, is intentionally eligible for adoption by automation. The workflow may then update, hide, or delete it according to the source data.
- A product without an automation-linked SKU remains manually managed unless it is deliberately linked later.
- The admin interface must clearly warn an authorized user before saving a SKU that makes a product automation-managed.
- The existing Salla-targeted n8n workflow will be adapted and improved for the replacement backend rather than copied unchanged.
- Objectives, Rivals, and FUT Champions are fulfilled manually by human players/operators.
- The website will send order details to n8n. The workflow definitions and exact integration contracts will be provided later.
- Mohamed needs an admin dashboard for orders, customers, catalog/pricing operations, and other business management functions. Mohamed has full access; a staff role is limited to viewing orders. No player assignment portal is required for v1.
- The intended production storefront domain is `shop.arab-ut.com`.
- Mohamed's existing logo must be retained. Customer-facing pages will be reviewed and redesigned one by one rather than accepting the current UI wholesale; the broader visual system is still to be decided during page-level design Discovery.
- The redesign retains the current dark navy/gold gaming direction, refined into a more consistent production design system.
- The homepage leads with the Coins configurator before the broader service overview.
- Customer-facing design is mobile-first while remaining fully polished and responsive on desktop.
- The SBC catalog includes search alongside the agreed category filters and sorting.
- n8n remains the review source, but the replacement does not suppress lower ratings and only labels a review as verified when verification is supported by real source/order evidence.
- General admin editing for homepage banners, marketing copy, FAQ, legal pages, and navigation is deferred beyond the MVP. These elements may be implemented as version-controlled content/configuration for v1.
- The admin dashboard uses the Arab UT dark branded design rather than a neutral light back-office theme.
- The business is not VAT-registered for this MVP. Prices and invoices must not represent or separately charge Saudi VAT.
- Customers receive both an email receipt and a downloadable PDF invoice/receipt.
- The existing privacy, refund, warranty, and terms content will be used as source drafts, reviewed and improved, and published in Arabic and English.
- Customer support is available through both WhatsApp and email.
- MVP analytics includes Google Analytics 4, Meta Pixel, and TikTok Pixel. Account identifiers and consent configuration will be supplied later, and no trackers are integrated until that configuration is approved.
- Existing public URLs should be preserved where sensible, with permanent redirects for changed routes to protect bookmarks and search visibility.
- The source repository should be private and named `arab-ut-store`, owned by GitHub account `momorgan1`.
- The custom application must be hosted through Hostinger. The exact Hostinger plan/runtime will be verified before the technology stack is proposed.
- Mohamed's existing n8n instance remains in use. Provisioning or migrating n8n is not part of the website MVP.
- The current Whapi account remains the provider for WhatsApp OTP, and the current Google OAuth project/account should be reused. Their secrets must be transferred through protected environment configuration rather than copied into source control or documentation.
- The public support email is `info@arab-ut.com`.
- A Google Analytics account already exists. Meta Pixel and TikTok Pixel accounts/identifiers are not yet available and will be supplied later.
- Development should move quickly without an elaborate release process. Use a minimal separate development/staging target, then switch `shop.arab-ut.com` after acceptance.

## Verified current-system baseline

Verified through read-only SSH, WP-CLI, and source inspection on 2026-08-08:

- Current platform: WordPress 7.0.3 with WooCommerce 10.7.0.
- Current Hostinger runtime exposes PHP 8.3.30, Composer 2.9.8, and a MariaDB 11.8.8 client. Node.js is not available on the present hosting account.
- The current PHP runtime includes Laravel's documented required extensions, plus Redis and common image/database extensions.
- Hostinger's current official documentation reserves Node.js and PostgreSQL for self-managed VPS plans rather than ordinary Web/Cloud hosting. Staying on the present plan therefore favors a PHP/MariaDB application; choosing Node.js/PostgreSQL would require a Hostinger VPS.
- Custom runtime components: active `arabut-child` theme and active `arabut-core` plugin.
- Current catalog: 36 product posts. The non-empty product categories observed were SBC, Coins, and Objectives.
- Current data: 12 published pages, 10 users, 0 orders, and 4,193 media attachments.
- The current SBC storefront stores its automated display classification in product meta field `_sbc_category`.
- Published SBC filter distribution at inspection time: 10 Players, 2 Icons, and 19 Upgrades.
- The current category vocabulary in code includes Players, Icons, Upgrades, Challenges, and Foundations. The current listing JavaScript collapses Challenges into Upgrades, and Swaps is not represented.
- Existing code contains n8n-related pricing and review integrations.
- Current checkout requires the customer to be logged in and redirects guests to the account screen.
- Current authentication supports email/password registration and login, Google OAuth, and WhatsApp OTP login for an existing phone-linked account. WhatsApp OTP does not currently create a new account.
- Current email/password registration collects one full-name field rather than separate first and last names. Google-created accounts are prompted to add a phone number when one is missing.
- Current loyalty is display-only and uses completed lifetime spend: Silver by default, Gold from SAR 2,000, and Platinum from SAR 5,000.
- Current forced-login checkout does not reliably return the customer to checkout after authentication, and configurator progress is held only in browser memory. Both behaviors can interrupt purchase completion.
- The current review importer discards ratings below four stars, while the UI can label a five-star review as verified without linking it to a proven order. This review-integrity behavior must not be copied unchanged.
- Only Coins and SBCs currently receive tailored configuration experiences; the remaining service pages are inconsistent standard-product or external flows.
- Existing automated and manual fulfillment state transitions are not sufficiently documented to reproduce yet.
- Some existing SBC flows store EA account credentials in plaintext. This behavior is a security risk and is not approved for the replacement system.

### Attached Salla product workflow

Verified from `Salla Products (3).json` on 2026-08-08:

- It is an 18-node n8n workflow scheduled every two hours.
- It reads the Salla catalog and external SBC data, translates new product names through Gemini, calculates prices, and routes create, price-update, option-update, image-attach, publish, hide, unhide, and delete actions.
- Automated products use an `SBC_` SKU prefix based on the external SBC set identifier.
- The current workflow does not filter the catalog to player-reward SBCs. It imports every otherwise eligible SBC; a player reward only changes which image is selected.
- EasySBC's current application exposes numeric categories: `1` Players, `2` Upgrades, `3` Challenges, `4` Icons, `5` Foundations, and `6` Swaps. These identifiers must be reverified for FC 27 before implementation rather than assumed stable across game versions.
- Current source/image lookups contain FC 26-specific paths and must not be assumed valid for FC 27.
- Current automation can overwrite manual price, category, option, image, visibility, and deletion decisions for automation-owned products. Mohamed has selected automation as the authority for those products; the replacement system must make that ownership visible in the admin interface and audit history.
- The exported workflow contains a plaintext external API credential. Its value is intentionally excluded from this record and must be rotated before the workflow is reused.
- The workflow has material reliability risks around incomplete source snapshots, overlapping runs, partial product creation, hard deletion, stale cached metadata, and incomplete observability. These will be detailed in the integration audit after all workflows are supplied.

## Inputs intentionally deferred until their implementation stage

- Payment-provider selection, billing-field requirements, credentials, webhook format, and refund API behavior remain excluded until Mohamed explicitly authorizes gateway integration.
- The historical customer/order/wallet export will be inspected when migration work begins; the target model and password-reset path are included in the Phase 2 plan now.
- GA4, Meta Pixel, and TikTok Pixel identifiers and consent configuration will be connected only after Mohamed supplies and approves them.
- FC 27 source identifiers, supplier schemas, and current API capabilities will be reverified against official/provider documentation immediately before each integration is implemented.

## Interview log

### Decision 1: Replacement scope

Mohamed selected a full replacement: WordPress and WooCommerce will not remain as the production backend.

### Question 1: Business and fulfillment model

Recorded in the "Confirmed by Mohamed" section above. n8n workflow files remain an external dependency to be supplied and reviewed.

### Clarification 1: SBC players and catalog synchronization

Mohamed confirmed that the catalog includes both player-reward and other eligible SBCs, differentiated automatically by the source API and exposed through storefront filters. Products must be manageable through both n8n/API automation and the admin dashboard. Manual edits must remain possible; the exact conflict and field-ownership rules are not yet decided.

### Decision 2: SBC storefront filters

Mohamed selected the current storefront behavior for v1: Challenges are grouped under Upgrades, and Swaps is not shown separately. The raw source classification remains available internally.

### Decision 3: Automated product authority

Mohamed selected automation as the source of truth for API-managed products. The next successful synchronization may overwrite manual changes to synchronized fields.

### Decision 4: Manual product adoption by SKU

Mohamed confirmed that the admin dashboard must support normal ecommerce catalog creation and editing. Manual catalog additions will be uncommon. If an authorized user creates or edits a product with the current external-set SKU convention (`SBC_<setID>`), the synchronization workflow may adopt that product and subsequently update, hide, or delete it. Products not linked to an automation identifier remain manually managed. The dashboard must display this ownership clearly before the SKU is saved.

### Decision 5: Launch languages

Mohamed selected Arabic and English for v1, with Arabic as the default storefront language and an English language switch. The system must be internationalized from the beginning, including RTL/LTR layouts, translated interface text, and bilingual catalog content where applicable, instead of adding English after launch.

### Decision 6: Customer geography

Arab customers are the primary audience, but the MVP should allow purchases worldwide rather than imposing an Arab-region or Saudi-only restriction. Regional payment availability, currency presentation, and compliance details remain dependent on the selected payment provider and launch configuration.

### Decision 7: Currency behavior

Product prices and payment settlement use Saudi riyals (SAR). Customers may select another currency for approximate storefront display, while checkout uses SAR. Mohamed does not want a separate warning or banner; the standard checkout total will still include its SAR currency label. Orders must store the authoritative SAR amount and may also record the displayed currency and exchange rate used for transparency.

### Decision 8: Payment integration timing

Mohamed is working on the payment-gateway decision separately. Architecture and documentation may define a provider-neutral payment interface, but gateway-specific implementation and credential setup must wait until Mohamed explicitly authorizes integration work.

### Decision 9: Customer account and authentication

Checkout requires login. Customer registration and checkout collect first name, last name, email address, WhatsApp number, and a password when applicable. MVP authentication includes email/password, Google sign-in, and WhatsApp OTP. The current WordPress flows will be examined to preserve intended behavior without copying credentials or obsolete implementation details.

### Decision 10: Historical data migration

The replacement launch must include customer names and emails, historical order records, and wallet balances when available and reliable. Passwords will not be migrated, so imported customers require a secure activation/password-reset flow. Mohamed's export will be assessed later; it is deliberately out of scope for the current Discovery pass.

### Decision 11: WhatsApp and email order communication

The website sends order details to n8n, and the existing WhatsApp workflow delivers them to the customer. WhatsApp messaging logic should not be duplicated inside the ecommerce application. Email order and status notifications are also part of the intended MVP once operational email delivery is configured.

### Decision 12: Wallet scope

The wallet holds store credit and refunds only. Customers cannot top up the wallet in v1. They can apply existing credit to a new order and, when the balance is insufficient, pay the remainder through the payment gateway.

### Decision 13: Admin and manual fulfillment

Mohamed receives full admin access. A staff role may view orders only. Mohamed will take manual-service order details, coordinate with players outside the system, and change order statuses himself. Worker assignment, player accounts, and a fulfillment portal are not required for v1.

### Decision 14: Cart and order lifecycle

One order may contain multiple services. Customer-visible statuses are Pending Payment, Received, In Progress, Waiting for Customer, Completed, Cancelled, and Refunded. Coupons and automatic quantity/cart discounts are included in v1.

### Decision 15: Refund destinations

An authorized admin may choose per refund between the original payment method and wallet credit. Gateway-specific refund execution remains deferred with the payment integration.

### Decision 16: EA credential collection

Required service credentials are collected before payment and stored per order item, with an option to reuse the same details across eligible items. Coins, SBCs, and Objectives use the customer's EA email; console Rivals and FUT Champions use the PlayStation email. Exact additional fields will be mapped from each supplied workflow. EA passwords and backup codes must be encrypted in transit and at rest, excluded from routine logs and analytics, masked from unauthorized roles, and removed after fulfillment or another terminal outcome.

### Decision 17: Authentication verification

Email/password, Google sign-in, and WhatsApp OTP are retained. WhatsApp OTP signs in existing accounts. New email/password and Google customers must supply and verify a WhatsApp number before checkout. v1 does not block checkout on separate email verification.

### Decision 18: Service and platform taxonomy

Players are not a standalone service: player rewards belong within the SBC catalog. The top-level services are Coins, SBCs, Objectives, Rivals, and FUT Champions. Coins, SBCs, and Objectives support PlayStation, Xbox, and PC. PlayStation and Xbox share an internal console group for these services, but both names appear explicitly in the customer interface. Rivals and FUT Champions support PlayStation and PC only.

### Decision 19: Guided service configuration

Each service category receives a tailored guided configurator. All configurators feed the same cart, checkout, order, customer-account, and admin systems. An order may contain multiple service items.

### Decision 20: Customer cancellation and refunds

Customers cannot initiate cancellation or refund actions from their accounts in v1. Mohamed handles them from the admin dashboard and may choose original-payment or wallet-credit refunds.

### Decision 21: Existing feature scope

Reviews, FAQ content, floating WhatsApp support, loyalty levels, the order-tracking timeline, coupons, and automatic quantity/cart discounts are included in the MVP. Reviews remain n8n-managed; the ecommerce admin does not need manual review-management tools.

### Decision 22: Production domain

The intended storefront domain is `shop.arab-ut.com`.

### Decision 23: Visual redesign approach

The existing Arab UT logo and dark navy/gold gaming direction are retained and refined. Customer-facing pages will be evaluated and redesigned individually with mobile as the primary layout target. The current site is reference material, not an instruction to reproduce every layout or styling choice unchanged. The admin dashboard also uses a dark Arab UT-branded interface.

### Decision 24: Service credential variants

Rivals and FUT Champions use different fields by platform. PC customers choose EA app or Steam. The Steam route collects Steam username/password, EA credentials, and EA backup codes. The PlayStation route collects EA credentials and PlayStation backup codes. Exact payload field names will be taken from the fulfillment workflows when supplied.

### Decision 25: Pricing authority

Coins and SBC prices are automation-managed. Objectives, Rivals, and FUT Champions prices are set and edited manually through the admin dashboard.

### Decision 26: Customer dashboard

The accepted MVP customer account sections are Overview, Orders, Order Tracking, Wallet History, Profile/Security, and Support.

### Decision 27: Lean digital checkout

Country, street address, and city are removed from the normal digital-service checkout. Only a field explicitly required by the future payment provider may be added back during payment integration.

### Decision 28: Admin overview

The MVP admin overview shows revenue, orders by status, customer count, recent orders, and orders requiring attention.

### Technical recommendation 1: Lean loyalty

Mohamed delegated and approved the loyalty design choice. Preserve the current Silver/Gold/Platinum lifetime-spend structure and progress display, make thresholds and tier-targeted coupon benefits configurable in admin, and defer a separate points or recurring cashback engine. The current SAR 2,000 Gold and SAR 5,000 Platinum thresholds are starting defaults but remain editable.

### Technical recommendation 2: Hostinger-compatible application stack

Use one Laravel 13 application with MariaDB and a React 19/TypeScript frontend through Inertia 3 and Tailwind CSS 4. This provides one deployable codebase for the storefront, customer area, admin dashboard, authenticated n8n API, and business logic. The verified Hostinger runtime satisfies Laravel 13's PHP 8.3 and extension requirements. Frontend assets are built before deployment, so Node.js is not required on production hosting. Use scheduled PHP jobs for lightweight application scheduling while the existing n8n instance owns external workflows. Mohamed approved this stack on 2026-08-09.

### Decision 29: Brand and homepage priority

The primary public brand label is Arab UT. The homepage opens with the Coins configurator, followed by the broader service presentation.

### Decision 30: SBC discovery and review integrity

The SBC catalog adds text search alongside its existing filters. Reviews remain sourced through n8n, but all eligible ratings are presented honestly and the verified label is used only when backed by genuine verification data.

### Decision 31: MVP content management boundary

General visual content management for homepage banners, FAQ, legal pages, menus, and marketing copy is not required in the MVP admin dashboard. It can be added later. Product and category management remains fully included.

### Decision 32: Tax and customer documents

The MVP does not charge or identify VAT. Customers receive an email receipt and can download a PDF invoice/receipt that does not present itself as a VAT invoice.

### Decision 33: Policies and support

The current privacy, refund, warranty, and terms pages are source drafts for a bilingual review and rewrite. Customer support is offered through WhatsApp and email.

### Decision 34: Analytics

Google Analytics 4, Meta Pixel, and TikTok Pixel are included in the MVP. Their current official installation and consent requirements must be checked before implementation, and Mohamed will later provide the relevant account identifiers.

### Decision 35: URLs, repository, and hosting

Preserve existing routes where sensible and create permanent redirects for changed URLs. Use a private GitHub repository named `arab-ut-store`, owned by `momorgan1`. The application must run on Hostinger, subject to verifying the available plan and runtime before selecting the stack.

### Decision 36: n8n and release environment

The existing n8n instance remains in use and is outside the website-deployment scope. Use a lightweight development/staging environment and promote the accepted build to `shop.arab-ut.com`; no elaborate multi-environment platform is required for the MVP.

### Decision 37: Existing authentication providers and support address

Reuse the existing Whapi account for WhatsApp OTP and the existing Google OAuth project/account. Secrets are runtime environment values and must never enter Git history or project documentation. The public support address is `info@arab-ut.com`.

### Decision 38: Analytics account readiness

Mohamed already has a Google Analytics account. Meta Pixel and TikTok Pixel accounts are not ready yet; their identifiers and setup can be connected later without blocking the core build.

### Decision 39: Xbox supplier routing

For Coins, SBCs, and Objectives, PlayStation and Xbox remain separate customer-facing platform choices but share the same internal console market, pricing group, and automated supplier path. The saved order item still retains the customer's exact PlayStation or Xbox selection for display, support, and audit history.

### Decision 40: Notification language

Customer WhatsApp and email order/status messages follow the language selected on the customer's account. Arabic is the fallback when no supported preference is available.

### Decision 41: Operational source of truth

Laravel and MariaDB are the authoritative system for catalog, pricing configuration, customers, orders, wallets, fulfillment state, and audit history. Google Sheets is optional export/reporting only and must not be required for commerce or fulfillment to continue.

### Newly supplied workflow inputs

The following active n8n exports were supplied and reviewed read-only on 2026-08-08:

- `Salla Price Auto-Updater (3).json`
- `Customer Notifier.json`
- `Fulfillment v14 (2).json`

Credential values and private customer/contact details from workflow exports are intentionally excluded from all documentation.

#### Price updater findings

- Runs hourly, probes FFT and UTT market/supplier data, calculates PS Normal, PS Fast, and PC quantity prices, writes operational rows to Google Sheets, updates Salla option values, and sends WhatsApp summaries/alerts.
- It is tightly coupled to fixed Salla product/option IDs and parses quantities from Arabic display labels. The replacement must use stable variant IDs and an explicit numeric quantity field.
- The replacement API needs canonical variant queries, versioned pricing rules, dry-run proposals, optimistic concurrency, bulk updates, per-item results, price history, source freshness, idempotency, and an audit trail.
- Current execution has no distributed lock or durable idempotency and can partially update the catalog. Several failure modes can silently use degraded data or report an unsuccessful update as successful.

#### Customer notifier findings

- Receives `order.status.updated`, converts selected Arabic status text into a customer WhatsApp message, delays briefly, sends through Whapi, and logs the message externally.
- The webhook is not strongly authenticated, deduplication is volatile and marked before sending, message delivery has no durable recovery, and status classification depends on broad localized text matching.
- The replacement should emit signed canonical status events, persist an outbox/idempotency record before acknowledging, use stable status codes, produce signed customer links, and retain provider delivery state. n8n remains responsible for WhatsApp delivery while Laravel also owns email notifications.

#### Fulfillment findings

- Handles Salla order creation/status events, payment gating, duplicate checks, Coins and SBC automation through FFT/UTT, manual-service alerts, supplier polling, Salla status updates, Google Sheets cost logging, Supabase order/message persistence, and WhatsApp alerts.
- It routes by SKU and translated option labels, is limited to PS/PC in the current implementation, and uses hard-coded Salla statuses and identifiers.
- It is not safe for the approved multi-item cart: some paths keep only the first item, SBC lines are not all persisted, mixed order-level fields come from the first item, and supplier references are not unique per fulfillment job.
- The replacement must persist every order line, create a distinct fulfillment job and idempotency key per line or intentional aggregate, and explicitly retain the existing special rule where extra Coins in a mixed Coins+SBC order may be merged into the SBC job.
- Laravel must own durable webhook events, orders/items, encrypted temporary account secrets, fulfillment jobs/attempts, status history, costs, and outbox records. n8n should call authenticated Arab UT APIs rather than writing primary commerce records directly to Supabase or Salla.
- Current workflows contain embedded provider secrets. Their values are omitted here; affected credentials must be rotated and moved to n8n credentials/environment configuration before reuse.

### Workflow decisions now closed

- Automated Xbox Coins and SBC work uses the same internal console supplier path as PlayStation while retaining Xbox as the customer's selected platform.
- Customer WhatsApp and email messages use the saved Arabic/English preference, with Arabic fallback.
- Laravel/MariaDB is authoritative; Google Sheets is export-only.
