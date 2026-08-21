---
name: arab-ut-admin
description: Build or review Arab UT Admin Dashboard features in this Laravel/Inertia repository, including MFA, permissions, orders, credential access, customers, wallet, catalog, staff access, audit logs, and owner settings.
---

# Arab UT Admin

Use this skill for any route, backend action, query, presenter, React page,
component, test, or documentation change under the Arab UT Admin Dashboard.
Do not use it for the separately planned AI support operator inbox unless its
canonical design explicitly adopts these foundations.

## Binding sources

1. Read `docs/superpowers/specs/2026-08-21-admin-dashboard-design.md`.
2. Read the reference matching the touched domain.
3. For UI work, also follow the repository `AGENTS.md`, `.impeccable.md`,
   `frontend-design`, `ui-ux-pro-max`, the relevant Impeccable skills, and a
   final `polish` pass.
4. New behavior requires TDD and the project verification gates.

## Non-negotiable boundaries

- Before a leaf feature starts, verify the Admin route, MFA, permission, recent
  reauthentication, and audit foundations it depends on are implemented and
  covered. If not, implement the planned foundation phase first.
- Laravel is the authorization and business-logic boundary. React never
  decides permission or financial/domain validity.
- Admin/Staff access requires an active account and confirmed TOTP MFA.
- Sensitive mutations and credential reveals require recent password
  confirmation.
- Credentials never enter Inertia props, HTML, URLs, browser storage, audit
  metadata, logs, analytics, or toast text.
- Use public identifiers at HTTP boundaries and permission-scoped queries.
- Reuse existing Actions and domain rules. Do not create an Admin-only duplicate
  of checkout, refund, pricing, wallet, or fulfillment logic.
- Mutations that depend on current state lock their records and fail on stale
  state. Repeatable financial actions require explicit idempotency.
- Wallet adjustments are the approved exception to generic stale-state wording:
  they apply to the latest locked balance with underflow, overflow, and
  idempotency checks unless a later spec adds an expected-state token.
- Audit metadata is allowlisted and secret-free.
- Tables use server-side filtering, sorting, and pagination with validated
  allowlists. Never fetch an unbounded dataset for browser-side processing.
- Automation-authoritative catalog fields remain read-only until an approved
  authority-transition design exists.
- The Admin dashboard is English-only (owner decision, 2026-08-21). Arabic
  remains the storefront and customer-account default. Thmanyah typography is
  retained; verify English LTR at 320, 390, 768, and 1440 CSS pixels.

## Reference routing

- Architecture and placement: [references/architecture.md](references/architecture.md)
- Domain relationships/invariants: [references/domain-model.md](references/domain-model.md)
- Permission matrix and enforcement: [references/permissions.md](references/permissions.md)
- Brand/UI system: [references/design-system.md](references/design-system.md)
- Server-driven tables: [references/tables.md](references/tables.md)
- Forms and sensitive confirmations: [references/forms.md](references/forms.md)
- Audit events and safe metadata: [references/audit-logging.md](references/audit-logging.md)
- Required tests/gates: [references/testing.md](references/testing.md)
- Threat model and secret handling: [references/security.md](references/security.md)

Before a new slice, run [checklists/new-feature.md](checklists/new-feature.md).
Before review/merge, run [checklists/pre-merge.md](checklists/pre-merge.md).
