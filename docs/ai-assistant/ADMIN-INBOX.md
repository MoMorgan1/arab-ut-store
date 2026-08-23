# Admin and support inbox

**Lifecycle:** Read-only inbox implemented; handoff planned
**Verified:** 2026-08-23

A read-only inbox is implemented: `ConversationsController` lists conversations
with status, locale, owner and public-id filters, and
`ConversationDetailController` shows one transcript alongside its agent turns.
Both sit behind `chat.view`, which is admin-only — support transcripts are not
general staff reading — inside the existing MFA group, registered under both the
bare and `/en` admin prefixes. `guest_key` is never sent to the client: it
identifies an anonymous person and has no place in a UI payload.

No assignment system, realtime transport, operator reply path, or
assistant-to-human handoff workflow is implemented. Those remain gated on the
decisions below.

## Goals

- Let a customer request human help without losing conversation context.
- Let an authorized operator understand ownership, locale, history, assistant
  state, and the reason for handoff before responding.
- Make assignment, response ownership, resolution, and customer-visible status
  explicit.
- Preserve the existing customer authorization boundary and keep order
  credentials and production secrets out of chat transcripts.

## Operator needs

- A prioritized queue with clear waiting and ownership states.
- Search and filters that do not expose conversations beyond operator scope.
- Transcript context, internal notes separated from customer messages, and an
  auditable record of operator actions.
- Failure and escalation paths for abandoned, duplicated, or unavailable
  handoffs.

## Open questions and entry criteria

Discovery must decide roles and permissions, service expectations, working
hours, routing, notification channels, retention, privacy, and customer status
copy. Implementation starts only after Mohamed approves those decisions and the
authorization, audit, and operational plan. No realtime stack is selected.
