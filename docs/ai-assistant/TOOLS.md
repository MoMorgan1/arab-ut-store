# Assistant tools

**Lifecycle:** No model tool calling; three server-derived surfaces are live
**Verified:** 2026-08-24

## What is and is not live

The runtime has **no tool calling**. The model is never given a tool schema,
never emits a tool call, and can never take an action. `support-v3` forbids
emitting JSON, tool calls, or code fences, and the adapter maps only text
deltas, completion and failure events.

Three customer-visible surfaces exist and are frequently mistaken for tools.
Each is derived **server-side from the customer's own message**:

| Surface | What it is | What the model does |
| --- | --- | --- |
| Service cards | `cards.v1` metadata attached to an assistant message, linking to a service | Nothing. The server selects cards from the customer's message. |
| Card prices | Resolved at render time through `chat.service-prices` | Nothing. The model may not state a price at all. |
| Add-to-cart offer | A button that posts to the store's existing cart endpoint over the customer's own session | Nothing. The customer confirms, and the EA details go in the secure form, never the transcript. |

Prices resolve at render time rather than being frozen into the message because
messages persist: a price written into history would go stale and become a false
statement to the customer. Rivals and FUT Champions are excluded from the
add-to-cart offer because both require a squad screenshot at cart-add time.

## Required principles for any future tool

- Read before write: retrieve the current owner-authorized state before
  proposing any mutation.
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
  first real tool?
- Which operations must always hand off to a human?
- What retention and operator-review rules apply to tool audit records?

## Entry criteria

Implement a tool only after its owner authorization, input/output boundary,
idempotency behavior, confirmation copy, audit record, failure handling, and
evaluation cases are approved.
