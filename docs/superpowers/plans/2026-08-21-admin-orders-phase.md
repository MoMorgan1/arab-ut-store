# Admin Orders Phase — shadcn re-skin, orders, credential reveal, refunds

**Status:** Draft for owner review (2026-08-21)
**Spec:** `docs/superpowers/specs/2026-08-21-admin-dashboard-design.md`
**References:** `.agents/skills/arab-ut-admin/references/ui-reference.md`
**Complexity:** Ambitious (split into five independently shippable tasks)

## Owner decisions in force

- English-only Admin; Thmanyah fonts; Arab UT logo retained.
- Faithful shadcn-admin look, single dark theme.
- Laravel React Starter Kit patterns; TanStack Table for complex tables;
  server-side pagination/filtering/sorting only.

## Task 6 — shadcn-admin visual parity (re-skin first)

Goal: the existing shell + overview look like shadcn-admin (dark) before any
new screens are built, so Orders is styled natively once.

0. Blade: render the `dark` class unconditionally on admin routes
   (`'dark' => $isAdminRoute || appearance == dark`) so Tailwind `dark:`
   variants resolve regardless of OS preference; admin stays dark-only.
1. Introduce a dark-only shadcn-style token layer scoped to
   `html.admin-document` (`--background`, `--foreground`, `--card`,
   `--popover`, `--primary`, `--secondary`, `--muted`, `--accent`,
   `--destructive`, `--border`, `--input`, `--ring`) using zinc-dark values,
   mapped through Tailwind 4 `@theme inline` so `bg-background`,
   `text-muted-foreground`, etc. work inside the Admin without touching the
   storefront.
2. Rebuild Admin components on those tokens with Tailwind utilities following
   shadcn-admin structure:
   - sidebar: icon + label nav, active state via `bg-accent`, grouped ready,
     collapsible-ready markup (behavior may stay single-group in v1);
   - header bar: page title/breadcrumb region and actor session info ONLY —
     the command palette is explicitly deferred (no dead search UI);
   - overview KPIs become shadcn-style cards (`rounded-xl border bg-card`);
   - statuses use semantic Badge variants (`success`/`warning`/`info`/
     `danger`/`neutral`) defined once with WCAG 2.1 AA contrast verified
     against the zinc-dark tokens;
   - buttons/links adopt shadcn sizes while keeping ≥44px interactive targets
     on touch;
   - mobile keeps the existing Radix sheet pattern restyled as a shadcn
     `Sheet`.
3. Prune every replaced `.admin-*` block from `app.css` in the same task — no
   split-brain stylesheet.
4. Keep: Thmanyah font stack, logo asset, English copy, skip link, focus
   management, reduced-motion coverage, safe-area handling.
5. Tests: existing unit/browser assertions updated only where they reference
   removed classes or Arabic copy; all a11y/security assertions preserved.
   New: computed-style checks that admin surfaces resolve the new tokens.

Gates: focused vitest, types, lint, format, build, full smoke suite.

## Task 7 — Orders list (first TanStack Table)

1. `GET /admin/orders` (+ alias), permission `orders.view`, NoStore +
   encrypted history.
2. `ListAdminOrders` Request validator + Query object: allowlisted search
   (public order number, normalized customer contact; max 100 chars), filters
   (status enum, service enum, platform enum, payment state, `date_from`/
   `date_to` as `Y-m-d` with `date_to >= date_from`), sort keys/directions
   allowlist, `per_page` allowlist (15/25/50/100); unknown keys fail
   validation.
3. Presenter projects explicit safe rows (public IDs, money minor units as
   strings, status enums); never credentials or provider payloads.
4. React page with TanStack Table pinned to the validated latest stable v8.x
   release after checking React 19 peer support (`npm run types:check` +
   build gate): manual server mode; state initialized strictly from Inertia
   props and synced via `router.get(..., { preserveState: true, replace:
   true, preserveScroll: true })`; URL query state durable across back
   navigation; column visibility local; row selection current-page only; no
   bulk actions in v1. Mobile: compact record summaries per tables.md.
5. Migration adds verified indexes including `order_items(service_type,
   order_id)` and `order_items(platform, order_id)` for child-table filters;
   lifecycle tested.
6. Feature tests cover allowlist rejection, filter/sort/pagination combos,
   permission denial for Customer/ServiceAccount, privacy serialization.

## Task 8 — Order detail + Staff-safe legal transitions

1. `GET /admin/orders/{publicId}` detail presenter: customer identity, money
   breakdown, items with safe configuration, payment/refund history, status
   history, audit context. Credentials never serialized.
2. Legal transitions exactly per spec matrix, implemented by an
   `app/Admin/Actions` action reusing domain rules: lock order + items,
   validate current state, write status history + staff audit in one
   transaction, return conflict on stale submissions.
3. Authorization is transition-specific: `orders.update` gates active-status
   moves; `orders.cancel` gates the transition to `cancelled`; manual
   transitions to `refunded` are always rejected (reserved for Task 10's
   verified refund action).
4. Stale submissions carry `expected_status` in the Form Request; under lock
   a mismatch returns 409 Conflict with the fresh canonical status.
5. Item-status propagation rules per transition are defined explicitly during
   implementation (order and item statuses are independent columns; no
   blanket overwrite without a specified mapping).
6. Form Requests reject unknown fields; destructive confirmations name the
   record and consequence; buttons disable while submitting; no optimistic
   success.
7. Feature tests: every legal/illegal transition per role, stale conflict,
   audit records, history integrity.

## Task 9 — Credential reveal

1. Same-origin JSON endpoint under `/admin`, gated on `orders.view` +
   `order_credentials.view` (reveal is a read-only audited operation; Staff
   keep reveal access), recent password confirmation required.
2. Explicit item selection, allowlisted purpose code
   (`fulfillment`/`customer_support`/`order_review`/`incident_investigation`),
   optional bounded case reference `[A-Za-z0-9._:-]{1,64}`; no free text.
3. Decrypts only the selected `OrderItemSecret`; writes `SecretAccessLog` +
   `StaffAuditLog`; private no-store response; nothing cached or logged.
   Audit metadata keys are exactly `purpose`, `case_reference`,
   `order_item_public_id` — never keys containing `secret`, `credential`,
   `password`, or `token`, which `StaffAuditEvent` hard-rejects.
4. Purged or retention-expired secrets return a safe `410 Gone`
   (`secret_purged`) instead of a decrypt failure.
5. UI starts masked; show/hide/copy; payload forgotten on close/navigation;
   never in URLs, storage, errors, toasts, or telemetry. A 423 password-
   confirmation response opens an inline Confirm Password modal that submits
   to Fortify and replays the reveal request without navigation.
6. Security tests prove absence from props/HTML/logs/URLs/cross-owner
   responses; feature tests cover purpose validation, password recency,
   double audit, purged-secret handling.

## Task 10 — Admin-only refunds

0. Already closed by hotfix `fix(admin): restrict paylink refunds to
   confirmed mfa admins`: the refund route now lives under `routes/admin.php`
   with the full stack plus `password.confirm` + `can:orders.refund`, and the
   Request/Action authorize Admin only.
1. Reuse `RefundPaylinkOrder`; no gateway logic reimplemented.
2. Full Paylink refunds only; form requires exact amount, staff reason,
   recent password confirmation, existing throttle.
3. Exact retries replay canonically; provider mismatch/failure states render
   truthfully without leaking provider secrets/metadata.
4. Success/failure emit allowlisted staff audit records via
   `RecordStaffAudit` integrated into the refund flow (keys such as
   `amount_halalah`, `currency`, `provider`, `refund_public_id`).
5. Feature tests: replay idempotency, throttle, failure rendering, audit.

## Sequencing and gates

Tasks are sequential and independently shippable: 6 → 7 → 8 → 9 → 10. Every
task ends with focused vitest, types, lint, format, build, targeted Pest, and
the browser smoke suite; MariaDB process tests join where locking contracts
apply (Task 8). Each task lands as its own commit reviewed by the lead.
