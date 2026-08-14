# Arab UT project documentation

Status: Incremental storefront implementation in progress; not the full MVP and not launch-ready

Approved by Mohamed on 2026-08-09.

The repository currently contains the bilingual Laravel/React foundation, authoritative commerce schema, WordPress-parity storefront shell, Coins configurator with server-authoritative quotes, secure cart storage, order placement, fail-closed Paylink hosted checkout, verified callback/webhook reconciliation, original-method refund boundaries, signed paid-order n8n outbox delivery, and localized email/phone/Google authentication. Those are verified incremental slices, not evidence that the approved MVP or production launch is complete. Live Paylink checkout still requires direct Hostinger configuration and a controlled test-to-production pilot.

Major planned areas still include the remaining service workflows, the broader order lifecycle and wallet/account experiences, complete admin and support operations, fulfillment-secret retrieval and supplier automation, customer notifications, production infrastructure, and final launch-policy and operational-readiness checks.

## Current documents

| Document | Purpose | Status |
|---|---|---|
| [Discovery record](product/discovery-record.md) | Binding product decisions and the verified WordPress/Hostinger baseline | Complete |
| [v1 product blueprint](product/v1-blueprint.md) | Approved MVP boundary, users, pages, service rules, admin scope, stack, integrations, and milestones | Approved |
| [Workflow integration audit](architecture/workflow-integration-audit.md) | Findings and replacement contracts for catalog sync, pricing, fulfillment, and customer notifications | Complete |
| [MVP implementation plan](superpowers/plans/2026-08-08-arab-ut-mvp.md) | Test-first execution sequence with planned files, contracts, checks, and commits | In progress |
| [Paylink checkout API](api/paylink-checkout-v1.md) | Checkout, callback, webhook, refund, and signed paid-order event contracts | Implemented; credentials/pilot pending |
| [Paylink operations](operations/paylink-checkout.md) | Hostinger/Paylink setup, pilot, scheduler, and failure procedures | Ready for operator configuration |

## Decision precedence

1. Mohamed's newest explicit decision.
2. The approved v1 product blueprint.
3. The discovery record and workflow integration audit.
4. The task-level MVP implementation plan.

If implementation exposes a material conflict, new external requirement, or provider limitation, work stops at that decision point and Mohamed receives clear options. Small implementation details that preserve the approved behavior do not reopen Discovery.

## Authorization boundary

Approval authorizes repository creation and the documented implementation milestones. Live payment acceptance still requires Mohamed to install Paylink credentials directly in Hostinger and approve the controlled pilot; production supplier charges and final launch remain separate gates.

API, operations, and launch documents are added with the implementation they describe so their commands, routes, and behavior can be verified against working code.
