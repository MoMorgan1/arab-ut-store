# Arab UT Admin Dashboard Design

**Status:** Approved by Mohamed on 2026-08-21

**Security decision:** Option 1 — TOTP MFA is mandatory for every Admin and
Staff account

**Complexity:** Ambitious

## Outcome

Arab UT gains an Admin-first, near-MVP operations dashboard inside the existing
Laravel/Inertia application, with Arabic-first and English-capable interfaces.
Admin remains the owner and full operator across the planned dashboard scope.
Staff is limited to the implemented overview, order visibility, legal
non-financial order-status operations including cancellation, and explicit
fulfillment-credential reveal.

The dashboard is not the AI support operator inbox described in
`docs/ai-assistant/ADMIN-INBOX.md`. It does not add an AI runtime, handoff
workflow, ticketing model, or realtime transport.

## Owner decisions

- On 2026-08-21, Mohamed set the Admin UI/UX engineering references: the
  Laravel React Starter Kit as architectural reference, shadcn-admin
  (satnaing/shadcn-admin) as the UI/UX reference for sidebar, navigation,
  layouts, tables, filters, forms, command/search, settings, responsive
  behavior, and information density, and TanStack Table patterns for complex
  tables. The Admin follows the faithful shadcn-admin look in a single dark
  theme; Thmanyah typography and the Arab UT logo are retained. This supersedes
  the earlier "not a generic SaaS dashboard / no copied shadcn-admin" visual
  direction for the Admin surface; architecture remains Laravel-owned (see the
  skill's `references/ui-reference.md`).
- On 2026-08-21, Mohamed superseded the earlier bilingual Admin contract: the
  Admin dashboard surface is English-only. Arabic remains the storefront and
  customer-account default. Local Thmanyah typography is retained for the
  Admin. Privileged TOTP challenges render in English; customer flows are
  unchanged.
- On 2026-08-21, Mohamed superseded the earlier broad Staff matrix: this is an
  Admin-first near-MVP, not a feature-complete Staff dashboard.
- Admin retains all 19 planned permissions and remains the owner/full operator.
- Staff receives exactly five permissions: overview, order view, legal order
  status updates, order cancellation, and explicit credential reveal.
- Refund/payment operations, customer administration, wallet, catalog/pricing,
  audit history, staff management, and settings are Admin-only.
- The chatbox/operator inbox remains deferred until it is separately completed
  and working; it has no Admin permission or placeholder in this scope.
- TOTP MFA is mandatory for all Admin and Staff accounts.
- Sensitive actions require recent password confirmation in addition to the
  authenticated MFA session.
- Customer-facing behavior and existing domain actions remain authoritative;
  the Admin does not duplicate business logic in React or a parallel API.

## Existing architecture to preserve

- Laravel 13 and PHP 8.3 own authentication, authorization, validation,
  transactions, locking, encryption, idempotency, and audit records.
- Inertia 3 and React 19 render server-projected data and submit same-origin
  mutations.
- Arabic is the default locale; English uses the existing `/en` route pattern.
- Existing Actions, Queries, Presenters, Form Requests, Wayfinder routes,
  `NoStore`, `inertia.encrypt`, and typed React props are reused.
- `UserRole`, `StaffAuditLog`, `SecretAccessLog`, `OrderItemSecret`, immutable
  wallet entries, refund idempotency, and order status history remain the
  current domain foundations.
- Catalog snapshots and Coins pricing runs remain automation-authoritative.

## v1 scope

### Admin foundation

- `/admin` and `/en/admin` route groups in a dedicated `routes/admin.php`.
- Shared middleware: session authentication, active-user enforcement,
  Admin/Staff role admission, confirmed TOTP enrollment, `NoStore`, and
  encrypted Inertia history.
- A planned `AdminPermission` enum and one central role-to-permission matrix.
- Laravel Gates and policies consume the permission matrix; UI permission props
  are projections only and never the authorization boundary.
- A planned audit action writes allowlisted contextual metadata to the existing
  `staff_audit_logs` table.
- Every Admin response containing private operational data uses private,
  no-store caching.

### MFA and reauthentication

- Enable Fortify two-factor authentication with confirmation and password
  confirmation required.
- Add Fortify's `TwoFactorAuthenticatable` trait and the required encrypted
  two-factor columns through a forward migration.
- Add an Arabic/English TOTP challenge view and an Admin MFA enrollment page.
- An Admin/Staff user without a password is directed through the existing
  verified account password-setup flow before MFA enrollment.
- Admin/Staff authentication uses email/password followed by Fortify's TOTP
  challenge. Existing Google and WhatsApp login flows must reject privileged
  roles with the normal generic authentication error because those controllers
  do not participate in Fortify's MFA pipeline. Customer Google/WhatsApp login
  remains unchanged.
- An Admin/Staff user without confirmed TOTP cannot enter ordinary Admin pages.
- Password authentication for an Admin/Staff user whose TOTP is missing or
  unconfirmed creates only the constrained authenticated session needed for the
  existing password-setup and MFA-enrollment flow. It does not grant ordinary
  Admin access; Task 3's `EnsureAdminMfa` middleware is the route boundary that
  denies those Admin pages until TOTP is confirmed.
- Credential reveal, refund, wallet adjustment, account activation changes,
  staff access changes, catalog/pricing mutations, and settings mutations
  require a recently confirmed password.
- Recovery codes are displayed only on explicit request, never in Inertia
  shared props, logs, analytics, or URLs.

### Admin shell

- Arabic-first responsive shell with equivalent English LTR behavior.
- Desktop/tablet: persistent collapsible sidebar, compact page header, main
  content region, and explicit current-location state.
- Mobile: accessible sheet navigation; critical actions remain available and
  are not hidden behind hover behavior.
- Navigation is permission-filtered but backend authorization remains
  mandatory.
- Arab UT logo, local Thmanyah type, near-black/deep-brown surfaces, warm gold
  decision accents, and restrained status colors reuse the storefront design
  context.
- No liquid glass, decorative gradients, generic SaaS cards, or copied
  shadcn-admin architecture.

### Overview

The overview answers operational questions with bounded aggregate queries:

- orders received and needing action;
- orders in progress;
- orders waiting for customers;
- pending/failed payments and failed refunds;
- captured revenue for an explicit time window;
- oldest unresolved order;
- recent operational audit events visible to the current role.

Staff can use the operational overview, but global audit events remain visible
only to Admin.

No metric is included only because it is easy to count. Charts are omitted
until a time-series question and sufficient data justify them.

### Orders

- Server-side paginated list with allowlisted search, status, service, platform,
  payment state, date filters, and sorting.
- Search covers exact public/order identifiers and normalized customer contact
  fields without exposing credential contents.
- Detail page shows customer, money breakdown, items, safe configuration,
  payment/refund history, fulfillment state, status history, and audit context.
- Legal transitions only:
    - `pending_payment` may be cancelled;
    - `received` may move to `in_progress`, `waiting_for_customer`, `completed`,
      or `cancelled`;
    - `in_progress` and `waiting_for_customer` may move between each other or to
      `completed`/`cancelled`;
    - terminal `completed`, `cancelled`, and `refunded` orders do not accept an
      arbitrary status mutation;
    - `refunded` is set only by the verified refund action.
- Each transition locks the order and affected items, validates current state,
  writes order status history and staff audit records, and returns a conflict
  for stale submissions.
- Staff may perform the legal non-financial transitions above, including
  cancellation. Refund/payment actions remain Admin-only.
- Bulk destructive or financial order actions are not in v1.

### Credential reveal

- Credentials are never eager-loaded into list/detail queries or serialized in
  Inertia props.
- The operator explicitly selects an order item, chooses an allowlisted
  operational-purpose code, may add a bounded case reference, and confirms the
  reveal. v1 accepts no free-text reveal reason.
- Credential-purpose codes and labels are fixed in v1:
    - `fulfillment`: `تنفيذ الطلب` / `Order fulfillment`;
    - `customer_support`: `دعم العميل` / `Customer support`;
    - `order_review`: `مراجعة الطلب` / `Order review`;
    - `incident_investigation`: `تحقيق في حادث` / `Incident investigation`.
- A same-origin JSON endpoint reauthorizes the actor and item, checks recent
  password confirmation, decrypts only that secret, records a
  `SecretAccessLog` and `StaffAuditLog`, and returns a private no-store response.
- The UI starts masked, labels the sensitive state, supports explicit
  show/hide/copy actions, and forgets the payload when the panel closes or the
  page changes.
- Credentials never enter query strings, browser storage, error telemetry,
  toast text, audit metadata, or validation payload echoes.

### Refunds and payments

- Refund and payment views/actions are Admin-only in the near-MVP.
- Reuse `RefundPaylinkOrder`; do not reimplement gateway logic.
- Only supported full Paylink refunds are exposed in v1.
- The form requires the exact amount, a staff reason, recent password
  confirmation, and the existing payment throttle.
- Exact retries replay the canonical refund. Provider mismatch/failure states
  remain visible without leaking provider credentials or raw metadata.
- Successful and failed attempts emit allowlisted staff audit records.

### Customers

- Server-side paginated list and detail pages for identity, account activity,
  order summary, wallet summary, and recent history.
- Admin may suspend/reactivate a customer with a reason. The mutation locks the
  user row and is audited.
- v1 does not directly overwrite email, phone, password, verification state, or
  social identity. Those require the existing verification workflows or a
  separately approved recovery design.

### Wallet

- Wallet views and adjustments are Admin-only in the near-MVP.
- Customer and global wallet-ledger views use the immutable existing entries.
- Adjustment input is a positive SAR minor-unit amount, `credit` or `debit`
  direction, allowlisted reason code, optional case reference matching
  `[A-Za-z0-9._:-]{1,64}`, and client-generated UUID idempotency key. v1 accepts
  no free-text adjustment note.
- Wallet reason codes, labels, and allowed directions are fixed in v1:
    - `customer_service_credit`: `رصيد خدمة عملاء` / `Customer service credit`,
      credit only;
    - `refund_correction`: `تصحيح استرجاع` / `Refund correction`, credit only;
    - `payment_correction`: `تصحيح دفعة` / `Payment correction`, credit or debit;
    - `promotional_credit`: `رصيد ترويجي` / `Promotional credit`, credit only;
    - `balance_correction`: `تصحيح رصيد` / `Balance correction`, credit or debit.
- A customer without an existing wallet account cannot receive an adjustment in
  v1. The action fails safely; it never silently creates an account. Wallet
  provisioning requires a separately approved domain rule.
- The action locks the wallet account, rejects debit underflow, allocates the
  next sequence, checks signed-64-bit addition before writing, inserts one
  immutable `adjustment` entry with allowlisted metadata and actor, updates the
  balance, and writes the staff audit record in one transaction. It uses the
  latest balance under the lock; v1 has no expected-balance token.
- The global reference is `admin-wallet-adjustment:{uuid}`. Exact retry compares
  wallet, amount, direction, reason code, and case reference and returns the
  canonical entry. Reuse against another wallet or changed input conflicts.
  Unique-reference contention is recovered through the same canonical
  replay/conflict comparison instead of exposing a database exception.
- The browser creates one UUID when the adjustment form starts, retains it
  through validation and transport retries, replaces it only after canonical
  success or explicit form reset, and never persists it in browser storage.
- Wallet presenters derive adjustment credit/debit display from validated
  direction metadata. Legacy adjustments without valid direction remain
  neutral; new debit adjustments must never display as unsigned credits.

### Catalog and pricing

- Catalog, variant, sync-run, and pricing visibility are Admin-only in the
  near-MVP.
- Admin may use only explicitly designed pricing/schedule actions that preserve
  price versions and current checkout validation.
- Automation-authoritative product/category fields are read-only in v1; no
  manual edit is added that a later snapshot can silently overwrite.

### Governance and settings

- Admin-only staff account list, role assignment between `Admin` and `Staff`,
  activation control, and MFA-enrollment status. Passwords and recovery codes
  are never visible.
- Admin-only audit list with actor, action, subject, timestamp, IP, and safe
  metadata filters.
- Settings are explicit typed resources, not a generic key/value table.
- v1 settings cover only capabilities implemented by an approved Action. No
  environment secret, provider credential, application key, or raw config file
  is editable from the dashboard.

## Permissions

| Permission                | Admin | Staff |
| ------------------------- | ----- | ----- |
| `dashboard.view`          | yes   | yes   |
| `orders.view`             | yes   | yes   |
| `orders.update`           | yes   | yes   |
| `orders.cancel`           | yes   | yes   |
| `orders.refund`           | yes   | no    |
| `order_credentials.view`  | yes   | yes   |
| `customers.view`          | yes   | no    |
| `customers.update_status` | yes   | no    |
| `payments.view`           | yes   | no    |
| `payments.refund`         | yes   | no    |
| `wallet.view`             | yes   | no    |
| `wallet.adjust`           | yes   | no    |
| `catalog.view`            | yes   | no    |
| `catalog.manage`          | yes   | no    |
| `audit.view`              | yes   | no    |
| `staff.view`              | yes   | no    |
| `staff.manage`            | yes   | no    |
| `settings.view`           | yes   | no    |
| `settings.manage`         | yes   | no    |

`ServiceAccount` never receives interactive Admin permissions. Customer never
receives any Admin permission. The Admin role is the owner-level role in v1.

## Query and table contracts

- Each table has an explicit Request validator and Query object.
- Search/filter/sort fields use allowlists; unknown fields return validation
  errors rather than being ignored.
- The database performs pagination, filtering, and sorting over the whole
  result set.
- URL query state is the durable source for shareable list state.
- TanStack Table may own column rendering, visibility, selection, and controlled
  state, but never re-sorts or re-filters only the loaded page.
- Default page size is conservative; larger sizes have a bounded allowlist.
- Only current-page row identifiers may be selected. Bulk actions are added
  only when their transactional semantics are separately specified.
- Mobile layouts show a compact record summary with a detail action; secondary
  columns may use an explicitly labeled horizontal scroll region.

## Audit contracts

- Required fields: actor, stable action name, subject when applicable, IP, and
  timestamp.
- Metadata is allowlisted per action and may include request/idempotency IDs,
  previous/new enum states, controlled reason codes, nonnegative amount in minor
  units, and related public identifiers. Free-text provider/domain reasons are
  not duplicated into audit metadata.
- Metadata never includes credentials, passwords, recovery codes, tokens,
  encrypted payloads, raw provider metadata, or secrets.
- An audited mutation writes its domain state and audit record in the same
  database transaction whenever the external provider boundary permits it.
- Provider calls use reservation/result audit events without claiming success
  before provider verification.
- Staff audit logs are append-only through application code; UI offers no edit
  or delete action.

## Error and feedback contracts

- Authorization failures are fail-closed and reveal no cross-owner resource
  existence.
- Validation errors render beside their fields and are announced.
- Stale/concurrent mutations return conflict feedback and refresh the canonical
  record.
- Provider/network failures preserve truthful pending/failed state and provide
  a retry or escalation path when safe.
- Mutation buttons disable while submitting and do not optimistically claim a
  financial or destructive success.
- Empty states explain the filter result and offer a safe next step.

## UI system

- Reuse local Thmanyah fonts and the existing Arab UT tokens.
- Dense app typography uses a fixed rem scale; monetary and sequence values use
  tabular numerals.
- Gold indicates active decisions and primary actions, not decoration.
- Statuses pair text/icon with restrained semantic colors; color alone never
  communicates state.
- Avoid nested cards. Use page regions, dividers, table rows, and spacing for
  hierarchy.
- Motion is limited to navigation/state feedback, uses existing easing/duration
  tokens, and respects reduced motion.
- Verify Arabic RTL and English LTR at 320px, 390px, 768px, and 1440px; keyboard
  navigation, visible focus, 44px targets, 200% zoom, reduced motion, no document
  overflow, and clean browser console are release gates.

## Package decisions

- Keep native Laravel Gates and the existing fixed `UserRole` enum for v1; do
  not add Spatie Permission until custom roles are approved.
- Keep the existing `staff_audit_logs` model/schema; do not add Activitylog.
- Keep explicit native Eloquent Query objects; do not add Spatie Query Builder
  initially.
- Add TanStack Table only when the first complex server-driven table is built,
  after pinning and validating the installed API.
- Do not add Horizon while the application uses database queues.
- Do not add Pulse in the Admin foundation; evaluate it as a later secured
  operations slice.
- Use shadcn-admin only as a responsive/RTL interaction reference.

## Testing strategy

- Feature tests cover every Admin route for guest, Customer, inactive Staff,
  Staff without MFA, permitted Staff, and Admin.
- Permission-matrix tests prove every role/permission mapping and backend Gate.
- MFA tests cover enrollment, confirmation, challenge, recovery code, missing
  password, Admin-route enforcement, and generic fail-closed Google/WhatsApp
  attempts for privileged roles without exposing role membership or secrets.
- Security tests prove no credential appears in Inertia HTML/props, logs,
  validation errors, URLs, or cross-owner responses.
- Action tests cover legal/illegal state transitions, stale conflicts, audit
  records, wallet missing-account rejection, underflow/overflow, latest-locked
  balance behavior, idempotent replay/conflict across wallets, unique-key race
  recovery, and refund replay.
- MariaDB process tests cover row locks and concurrent wallet/order mutations
  whose contracts depend on database locking.
- React tests cover table URL state, permission-hidden navigation, loading,
  empty/error states, destructive confirmation copy, credential clearing, and
  wallet-adjustment UUID creation, retention across validation/transport
  failure, and regeneration only after canonical success or explicit reset.
- Browser acceptance covers Arabic/English, the four required widths, keyboard,
  visible focus, mobile navigation, 44px targets, reduced motion, overflow, and
  console/request errors.
- Full Composer CI, JavaScript CI, migration lifecycle, MariaDB focused suite,
  and Chromium suite must pass before release.

## Implementation phases

1. Acceptance record, this design, repository Admin skill, and implementation
   plan.
2. MFA, permission, middleware, audit, route, and test foundations.
3. Admin shell and operational overview.
4. Orders list/detail, Staff-safe legal transitions, credential reveal, and
   Admin-only refunds.
5. Admin-only customers and activation controls.
6. Admin-only wallet views and idempotent adjustments.
7. Admin-only catalog/pricing visibility and approved owner mutations.
8. Admin-only team access, global audit list, and typed owner settings.
9. Final responsive/accessibility/security/browser review and release handoff.

## Explicitly deferred

- AI runtime, retrieval, tools, realtime transport, and support operator inbox.
- Admin/staff chatbox integration until the separate chatbox is complete and
  receives a new owner decision.
- Custom/dynamic role creation and direct per-user permission editing.
- Partial or arbitrary provider refunds.
- Bulk destructive or financial actions.
- Direct identity/password/verification overrides.
- Generic settings storage or editing environment/provider secrets.
- Manual edits to automation-authoritative catalog fields.
- Horizon/Pulse deployment and unrelated infrastructure changes.

## Success criteria

- Only authenticated, active, confirmed-MFA Admin/Staff users can enter Admin.
- Backend permissions match the approved matrix for every route and Action.
- Full credential access is explicit, recently reauthenticated, private,
  no-store, nonpersistent in the browser, and doubly audited.
- Refunds, wallet adjustments, status transitions, customer activation, staff
  changes, pricing changes, and settings changes are validated, transactional
  where applicable, concurrency-safe, idempotent where repeatable, and audited.
- Server tables remain correct across search/filter/sort/pagination combinations
  without loading unbounded datasets.
- The UI preserves the verified Arab UT identity and passes the required
  RTL/LTR, viewport, accessibility, reduced-motion, overflow, and console gates.
- No deferred feature is represented by a fake route, empty shell, or mock data.

## Official references

- Laravel Fortify 13.x: https://laravel.com/docs/13.x/fortify
- Laravel authorization 13.x: https://laravel.com/docs/13.x/authorization
- TanStack Table: https://tanstack.com/table/latest/docs/overview
- shadcn-admin reference: https://github.com/satnaing/shadcn-admin
- WooCommerce order operations reference:
  https://woocommerce.com/document/managing-orders/
