# Arab UT project documentation

Status: Incremental storefront implementation in progress; not the full MVP and not launch-ready

Approved by Mohamed on 2026-08-09.

The repository currently contains the bilingual Laravel/React foundation, authoritative commerce schema, WordPress-parity storefront shell, Coins configurator with server-authoritative quotes, secure authenticated Coins cart storage, a real read-only cart page, and localized email/password login, registration, and password-reset handoff. Those are verified incremental slices, not evidence that the approved MVP or production launch is complete. Main-branch protection remains pending a signed-in GitHub admin session, and payment integration remains deferred.

Major planned areas still include the remaining service catalog and service-specific flows, checkout and the payment-provider adapter, order lifecycle and wallet/account experiences, admin and support operations, fulfillment and n8n integrations, customer notifications, catalog/data import, production infrastructure and deployment, and final launch-policy and operational readiness checks. Google and WhatsApp authentication are not part of the active auth slice.

## Current documents

| Document | Purpose | Status |
|---|---|---|
| [Discovery record](product/discovery-record.md) | Binding product decisions and the verified WordPress/Hostinger baseline | Complete |
| [v1 product blueprint](product/v1-blueprint.md) | Approved MVP boundary, users, pages, service rules, admin scope, stack, integrations, and milestones | Approved |
| [Workflow integration audit](architecture/workflow-integration-audit.md) | Findings and replacement contracts for catalog sync, pricing, fulfillment, and customer notifications | Complete |
| [MVP implementation plan](superpowers/plans/2026-08-08-arab-ut-mvp.md) | Test-first execution sequence with planned files, contracts, checks, and commits | In progress |

## Decision precedence

1. Mohamed's newest explicit decision.
2. The approved v1 product blueprint.
3. The discovery record and workflow integration audit.
4. The task-level MVP implementation plan.

If implementation exposes a material conflict, new external requirement, or provider limitation, work stops at that decision point and Mohamed receives clear options. Small implementation details that preserve the approved behavior do not reopen Discovery.

## Authorization boundary

Approval authorizes repository creation and the documented implementation milestones. It does not authorize a live payment integration, real supplier charges, customer WhatsApp messages from staging, production deployment, or final domain cutover.

API, operations, and launch documents are added with the implementation they describe so their commands, routes, and behavior can be verified against working code.
