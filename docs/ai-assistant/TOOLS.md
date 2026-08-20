# Assistant tools

**Lifecycle:** Planned
**Verified:** 2026-08-20

No assistant tool, tool schema, model-to-tool API, or autonomous action is live.

## Required principles

- Read before write: the assistant must retrieve the current owner-authorized
  state before proposing any mutation.
- Owner authorization: public IDs, customer text, or model intent never replace
  authenticated ownership and server-side policy checks.
- Least privilege: each tool exposes only the minimum fields and action needed;
  credentials and secrets never enter model context.
- Idempotency: every retryable write needs a server-enforced idempotency key and
  a canonical recovery result.
- Auditability: record the authenticated actor, bounded action, target,
  confirmation, outcome, and correlation identity without logging secrets.
- Confirmation: customer-impacting writes require a clear preview and explicit
  confirmation at the moment of action. Payment, refund, credential, and other
  high-risk actions need separately approved policies and may remain human-only.

## Open questions

- Which read-only customer support action is safe and useful enough to be the
  first tool?
- Which operations must always hand off to a human?
- What retention and operator-review rules apply to tool audit records?

## Entry criteria

Implement a tool only after its owner authorization, input/output boundary,
idempotency behavior, confirmation copy, audit record, failure handling, and
evaluation cases are approved.
