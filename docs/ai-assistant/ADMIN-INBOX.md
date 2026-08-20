# Admin and support inbox

**Lifecycle:** Planned
**Verified:** 2026-08-20

No support inbox, operator route, assignment system, realtime transport, or
assistant-to-human handoff workflow is implemented.

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
