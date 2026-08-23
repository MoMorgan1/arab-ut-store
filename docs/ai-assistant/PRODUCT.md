# Product contract

**Lifecycle:** Phases 1-3 live and accepted; human handoff in design
**Verified:** 2026-08-24

## Purpose and users

The assistant is the website assistant and support entry point for Arab UT
customers. It serves guests and authenticated storefront customers. An
administrator can read transcripts today; replying to customers, assignment and
ticketing are designed but not implemented.

## Implemented and accepted

- A persistent chat shell is mounted across Inertia storefront navigation.
- A conversation and its bounded history remain tied to the current guest or
  authenticated owner.
- Guests keep continuity through a session token and their conversations are
  claimed after successful login.
- The launcher, home view, full-screen mobile sheet, anchored desktop panel,
  retry state, older-history loading, streamed reply reveal, notification chime,
  and Arabic/English presentation are present.
- **Phase 2, accepted 2026-08-23.** Owner-scoped durable agent turns and runs, a
  prompt guard, bilingual streaming and recovery UX, usage and cost accounting,
  a fake CI provider, and a direct OpenAI Responses adapter for `gpt-5.6-luna`.
  Luna answers customers in production.
- **Phase 3, implemented 2026-08-23.** A curated bilingual knowledge corpus,
  lexical topic selection, `support-v3` grounding with exact-fact quoting,
  server-derived service cards whose prices resolve at render time, and a
  server-derived add-to-cart offer for coins.
- **Read-only support inbox, implemented 2026-08-23.** An administrator can
  list, filter and read conversations with their agent-turn runtime behind an
  admin-only `chat.view`. Guest keys never leave the server.

The Phase 1 deterministic demo reply remains the fail-closed fallback. Clearing
the AI enable flag returns new messages to it without a deployment.

## Excluded from the current product

- Autonomous order changes, cancellations, refunds, or fulfillment actions.
- Payment initiation, capture, credential access, or other financial actions.
- Model tool calling. The service cards, the price lookup and the add-to-cart
  offer are derived server-side from the customer's own message; the model never
  authors them, never chooses a price, and never takes an action. See
  [TOOLS.md](TOOLS.md).
- Live order, wallet, payment or account data in model context.
- Staff replies, ticketing, assignment, SLAs, and realtime transport. These are
  designed in
  [`2026-08-24-support-handoff-tickets-design.md`](../superpowers/specs/2026-08-24-support-handoff-tickets-design.md)
  and are not implemented.

## Success criteria

Conversation continuity is owner-safe, Arabic and English directionality is
clear, the browser-verified release path is reliable, grounded answers quote
approved facts exactly, and every mandatory evaluation threshold passes before a
configuration is accepted.

## Later product work

**Section lifecycle:** Approved design, not implemented

The next product gate is human support handoff: a customer reaching a person
without losing context, a durable ticket record, a 48-hour guest retention
window with guests removed from the operator inbox, and a customer-visible
conversation history. Retrieval beyond the curated corpus, model tool calling,
and realtime transport require separate discovery, design, security review, and
owner approval.

See [STATUS.md](STATUS.md), [ADMIN-INBOX.md](ADMIN-INBOX.md),
[AGENT-RUNTIME.md](AGENT-RUNTIME.md), and [EVALS.md](EVALS.md).
