# Arab UT project documentation

Status: Live incremental storefront; the complete approved MVP and launch-readiness program remain in progress

Approved by Mohamed on 2026-08-09.

The repository currently powers `store.arab-ut.com` with the bilingual Laravel/React storefront, authoritative commerce schema, Coins and SBC buying flows, secure cart storage, localized email/phone/Google authentication, live signed Coins/SBC automation boundaries, and the historical Salla review archive. Order placement and the fail-closed Paylink hosted-checkout, callback/webhook, refund, and signed paid-order n8n boundaries are implemented. Real Paylink acceptance remains an operational gate until Hostinger is configured and the controlled test-to-production pilot succeeds.

Major planned areas still include the remaining service workflows, the broader order lifecycle and wallet/account experiences, complete admin and support operations, fulfillment-secret retrieval and supplier automation, customer notifications, production infrastructure, and final launch-policy and operational-readiness checks.

## Layout

| Folder | Holds | Changes when |
| --- | --- | --- |
| `product/` | What the store is and what v1 must contain | Mohamed makes a product decision |
| `decisions/` | Why each feature was built the way it was | A feature is designed |
| `plans/` | The execution sequence for work still in flight | A feature is planned; deleted once shipped and superseded by the code |
| `api/` | Signed contracts with n8n and Paylink | A contract version changes |
| `operations/` | Deploy, rollback, and runbooks | Infrastructure changes |
| `architecture/` | Cross-cutting system findings | A system-wide audit lands |
| `ai-assistant/` | The assistant handbook — start at `STATUS.md` | Assistant behaviour changes |

## Current documents

| Document                                                                 | Purpose                                                                                                  | Status                                              |
| ------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| [Discovery record](product/discovery-record.md)                          | Binding product decisions and the verified WordPress/Hostinger baseline                                  | Complete                                            |
| [v1 product blueprint](product/v1-blueprint.md)                          | Approved MVP boundary, users, pages, service rules, admin scope, stack, integrations, and milestones     | Approved                                            |
| [Workflow integration audit](architecture/workflow-integration-audit.md) | Findings and replacement contracts for catalog sync, pricing, fulfillment, and customer notifications    | Complete                                            |
| [Coins pricing API and workflow](api/n8n-pricing-v1.md)                  | Signed Laravel contract plus the adaptive FFT/UTT v2 tier, fallback, quantity-curve, and stability rules | Implemented; n8n import/operator activation pending |
| [MVP implementation plan](plans/2026-08-08-arab-ut-mvp.md)   | Test-first execution sequence with planned files, contracts, checks, and commits                         | In progress                                         |
| [Paylink checkout API](api/paylink-checkout-v1.md)                       | Checkout, callback, webhook, refund, and signed paid-order event contracts                               | Implemented; credentials/pilot pending              |
| [Paylink operations](operations/paylink-checkout.md)                     | Hostinger/Paylink setup, pilot, scheduler, and failure procedures                                        | Ready for operator configuration                    |
| [Hostinger deployment](operations/hostinger-deployment.md)               | Release, scheduler, maintenance, and post-deploy procedures                                              | Active                                              |
| [Hostinger rollback](operations/hostinger-rollback.md)                   | Automatic and manual Laravel release rollback                                                            | Active                                              |
| [AI Assistant](ai-assistant/README.md)                                   | Canonical assistant handbook; read the [live status](ai-assistant/STATUS.md) first                       | Implemented foundation; future phases planned       |

## Decision precedence

1. Mohamed's newest explicit decision.
2. The approved v1 product blueprint.
3. The discovery record and workflow integration audit.
4. The task-level MVP implementation plan.

If implementation exposes a material conflict, new external requirement, or provider limitation, work stops at that decision point and Mohamed receives clear options. Small implementation details that preserve the approved behavior do not reopen Discovery.

## Authorization boundary

Approval authorizes repository creation and the documented implementation milestones. Live payment acceptance still requires Mohamed to install Paylink credentials directly in Hostinger and approve the controlled pilot; production supplier charges and final launch remain separate gates.

API, operations, and launch documents are added with the implementation they describe so their commands, routes, and behavior can be verified against working code.

Files under `docs/plans/`, `docs/decisions/`, `.superpowers/sdd/`, and task reports are immutable implementation history. They can describe the state or plan at the time they were written. For current operational truth, use `docs/api/`, `docs/operations/`, the active configuration, and the application routes.
