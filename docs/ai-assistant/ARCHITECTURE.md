# Architecture

**Lifecycle:** Phase 1 and Phase 2 runtime implemented; Phase 2 inactive
**Verified:** 2026-08-22

The persistent Inertia `ChatRootLayout` renders `ChatWidget` on storefront,
authentication, and account surfaces. `HandleInertiaRequests` shares chat
feature state. When chat is disabled, the widget does not render and
`EnsureChatEnabled` returns the no-store `chat_disabled` 404.

## HTTP boundary

All routes in `routes/chat.php` use `EnsureChatEnabled`, `NoStore`, and
`SetChatLocale`.

| Method | Route name                   | Path                                                          | Throttle             |
| ------ | ---------------------------- | ------------------------------------------------------------- | -------------------- |
| POST   | `chat.conversations.store`   | `/chat/conversations`                                         | `chat-conversations` |
| POST   | `chat.conversations.restart` | `/chat/conversations/restart`                                 | `chat-conversations` |
| GET    | `chat.conversations.show`    | `/chat/conversations/{conversation}`                          | `chat-read`          |
| POST   | `chat.messages.store`        | `/chat/conversations/{conversation}/messages`                 | `chat-messages`      |
| POST   | `chat.agent-turns.store`     | `/chat/conversations/{conversation}/agent-turns`              | `agent-turns`        |
| GET    | `chat.agent-turns.show`      | `/chat/conversations/{conversation}/agent-turns/{turn}`       | `chat-read`          |
| POST   | `chat.agent-turns.retry`     | `/chat/conversations/{conversation}/agent-turns/{turn}/retry` | `agent-turns`        |

Creation returns the owner's existing open conversation or reopens their most
recent inactivity-closed conversation inside the configured last-activity
window. Restart closes the owner's open conversation with
`customer_started_new` and creates a replacement in one transaction. Reads and
writes scope by the resolved owner before public ID; public IDs are not
authorization.

Agent start returns an event stream for a runnable turn, `202` for quiet-wait or
already-running state, or `204` when no eligible message remains. Poll returns a
safe turn projection. Explicit retry succeeds only for a retryable terminal turn
inside the attempt budget.

## Data model and lifecycle

`2026_08_20_000001_create_chat_tables.php` creates `chat_conversations` and
`chat_messages`. `2026_08_20_000002_add_chat_conversation_lifecycle.php` adds
`closed_at`, `close_reason`, `active_owner_key`, and the one-to-one assistant
`reply_to_message_id` relationship.

- A conversation has exactly one owner: `user_id` or HMAC `guest_key`.
- The generated `active_owner_key` permits one open conversation per owner in
  MariaDB; SQLite uses equivalent triggers/indexes.
- `(conversation_id, client_message_id)` makes message persistence idempotent.
- Post-migration demo replies link through `reply_to_message_id`; unlinked
  legacy rows are never guessed by timestamp/order.
- Conversation creation and its onboarding message use one transaction.

Phase 2 migrations add:

- nullable server-owned `agent_eligible_at` and `agent_prompt_blocked_at`
  message fields plus `idx_chat_messages_agent_claim`; eligibility is selected
  on insertion, while the blocked marker can later move from null to a timestamp;
- `agent_turns`, including message boundaries, prompt version, final assistant
  linkage, attempt/status timestamps, terminal code, and one-active-turn
  invariant;
- `agent_runs`, including provider/model, attempt, terminal latency, usage,
  versioned cost, safe error code, and internal trace fields;
- millisecond precision for `agent_turns.debounce_until`.

Conversation deletion cascades messages, turns, and runs. The established
30-day guest and 180-day authenticated closed-conversation retention therefore
applies to Phase 2 records.

## Runtime flow

1. `ResolveAssistantMode` selects `agent`, `demo`, or `none` from server
   configuration and owner scope.
2. `CreateChatMessage` persists one immutable eligibility decision. Agent mode
   never also creates a demo reply.
3. The browser FIFO finishes persistence and waits 1.5 seconds. The server can
   return a corrected quiet delay.
4. `CreateOrRecoverAgentTurn` locks conversation then active turn, claims up to
   24 pending messages, and creates one durable turn.
5. `GuardAgentPromptContent` blocks sensitive current ranges before lazy
   provider resolution.
6. `StreamAgentTurn` creates a run, releases database locks, streams provider
   events, and atomically finalizes one assistant message or a safe terminal
   failure.
7. Browser polling recovers disconnect/reload/terminal state. A terminal
   `hasPendingMessages` signal starts one successor after the FIFO empties.
8. The minute scheduler terminalizes stale nonterminal turns after 60 seconds.

Prior prompt context comes only from completed agent turns. Demo replies,
failed turns, arbitrary assistant rows, blocked rows, and legacy ineligible
messages are excluded.

## Configuration

`config/chat.php` owns the chat feature flags, 4,000-character message limit,
50-message default page, 24-hour close, seven-day reopen, and 30/180-day
retention windows.

`config/ai-assistant.php` owns fail-closed enablement/rollout/provider settings,
the fixed Luna model and prompt version, quiet/context/output limits, timeout and
attempt policy, rate limits, stale recovery, fake delay, and versioned pricing.
Repository defaults are AI disabled, rollout `disabled`, and an empty provider.

Production currently keeps chat/demo enabled while the AI enable flag, rollout,
and provider selector are inactive after the failed public evaluation.

## Error and stream boundary

`ChatErrorResponse` normalizes Phase 1 validation, conflict, rate-limit, and
server failures. Agent controllers add owner-scoped not-found, unavailable,
nonretryable, and safe terminal error behavior. The application stream permits
only `turn.created`, `response.delta`, `response.completed`, and
`response.failed`.

The server/browser `response.failed` payload mismatch recorded in
[AGENT-RUNTIME.md](AGENT-RUNTIME.md) is an open remediation item and must not be
documented as a stable client contract.

See [SECURITY.md](SECURITY.md), [UX.md](UX.md), [EVALS.md](EVALS.md), and
[OPERATIONS.md](OPERATIONS.md).
