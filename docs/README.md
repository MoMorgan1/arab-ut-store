# Arab UT project documentation

Status: Phase 3 implementation in progress

Approved by Mohamed on 2026-08-09.

Milestone 1 (repository and bilingual application foundation) is implemented, independently reviewed, and verified by GitHub CI on [draft PR #1](https://github.com/MoMorgan1/arab-ut-store/pull/1). Main-branch protection remains pending a signed-in GitHub admin session. Payment integration remains deferred.

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
