# Product contract

**Lifecycle:** Phase 1 live and accepted; Phase 2 runtime deployed but inactive
and not accepted
**Verified:** 2026-08-22

## Purpose and users

The assistant is the website assistant and support entry point for Arab UT
customers. It currently serves guests and authenticated storefront customers.
Support agents and administrators are later users, after their workflows are
designed and approved.

## Implemented v1

- A persistent chat shell is mounted across Inertia storefront navigation.
- A conversation and its bounded history remain tied to the current guest or
  authenticated owner.
- Guests keep continuity through a session token and their conversations are
  claimed after successful login.
- Customer messages are stored and can receive an immediate bilingual canned
  demo reply when the demo flag is enabled.
- The launcher, full-screen mobile sheet, anchored desktop panel, retry state,
  older-history loading, and Arabic/English presentation are present.

The current production reply is deterministic demo behavior. It is not a model
response and it does not make a claim about answer quality or availability.

## Implemented but inactive

Phase 2 added owner-scoped durable agent turns/runs, a prompt guard, bilingual
streaming/recovery UX, usage/cost accounting, a fake CI provider, and a direct
OpenAI Responses adapter for `gpt-5.6-luna`. Mohamed approved direct public
rollout, but the mandatory public evaluation failed mixed-language and
first-visible-content gates. AI is disabled while remediation is reviewed, so
these capabilities are not part of the currently accepted customer experience.

## Excluded from the current product

- Autonomous order changes, cancellations, refunds, or fulfillment actions.
- Payment initiation, capture, credential access, or other financial actions.
- Currently live model-generated answers or AI accuracy guarantees.
- Tool calling, retrieval, realtime support, or an admin inbox.

## Success criteria

The foundation succeeds when conversation continuity is owner-safe, Arabic and
English directionality is clear, the browser-verified release path is reliable,
and Mohamed completes manual owner acceptance on the deployed experience.

## Later product work

**Section lifecycle:** Planned

The immediate next product gate is approval, remediation, and complete
re-evaluation of the existing AI turn runtime. Human support/administration,
retrieval, and approved tools require separate discovery, design, security
review, and owner approval.

See [STATUS.md](STATUS.md), [AGENT-RUNTIME.md](AGENT-RUNTIME.md), and
[EVALS.md](EVALS.md).
