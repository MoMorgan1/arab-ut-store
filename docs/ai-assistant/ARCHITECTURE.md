# Architecture

**Lifecycle:** Local implementation verified; deployment evidence pending

**Verified:** 2026-08-21

## Lifecycle routes

`routes/chat.php` registers these non-localized routes behind `EnsureChatEnabled`, `NoStore`, and the named limiter shown below.

| Method | Path                                          | Name                         | Limiter              | Behavior                                                                                                                        |
| ------ | --------------------------------------------- | ---------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `POST` | `/chat/conversations`                         | `chat.conversations.store`   | `chat-conversations` | Returns the owner's open conversation, reopens a recent inactivity closure, or creates a conversation and onboarding message.   |
| `POST` | `/chat/conversations/restart`                 | `chat.conversations.restart` | `chat-conversations` | Closes the owner's current open conversation as `customer_started_new`, creates a replacement, and updates the session pointer. |
| `GET`  | `/chat/conversations/{conversation}`          | `chat.conversations.show`    | `chat-read`          | Returns owner-scoped bounded history.                                                                                           |
| `POST` | `/chat/conversations/{conversation}/messages` | `chat.messages.store`        | `chat-messages`      | Stores one customer message and, when enabled, its deterministic demo reply.                                                    |

The conversation controller accepts `locale` (up to 10 characters) and a `limit` from `1` through `100`; an omitted limit uses `chat.default_page_size` (`50`). Reads also accept a 26-character `before_id` cursor. The message controller accepts non-blank `content` up to `chat.max_message_length` (`4000`) and `client_message_id` up to 64 characters.

## Conversation lifecycle

`chat_conversations.status` uses `open`, `closed`, and the reserved `archived` vocabulary. The lifecycle migration adds `closed_at`, `close_reason`, and `active_owner_key`.

- A user may have at most one open conversation. `active_owner_key` is unique while a row is open and is `NULL` once the row is closed. The migration derives it from `user:<id>` or `guest:<hmac>` and reconciles historical duplicates by retaining the newest `last_message_at` (then highest numeric ID) as open; older rows close with `invariant_upgrade_duplicate`.
- A `POST /chat/conversations` acquisition prefers the valid session pointer, then the canonical open row. An `inactive` closure may reopen when `last_message_at` is within `chat.reopen_within_days`; other close reasons never auto-reopen. Unique-key contention re-reads and returns the canonical open winner.
- `POST /chat/conversations/restart` is transactional. It closes the current open row with `customer_started_new`, creates the replacement and onboarding message, then writes the new public ID to the session pointer. A failed replacement rolls the close and pointer update back.
- `CreateChatConversation` creates the conversation and onboarding message in one transaction. A duplicate `client_message_id` returns the stored customer message and its linked demo reply rather than creating another reply.
- `CreateChatMessage` locks and reloads the selected conversation inside the same transaction as duplicate lookup, customer-message insertion, `last_message_at`, and the optional demo reply. It revalidates both the resolved owner and `open` status before any write. A row still owned by the requester but closed by restart or maintenance returns `conversation_closed`; a missing row or a guest row claimed by another owner returns `conversation_not_found`.

The first migration retains the exactly-one-owner database guard and cascade relationships. The lifecycle migration adds a nullable self-reference `chat_messages.reply_to_message_id`, with a unique index so one customer message has at most one reply.

## Maintenance and retention

`php artisan chat:maintain-conversations` closes rows whose `last_message_at` is at least `chat.auto_close_hours` old, using the `inactive` reason. It purges only closed guest rows at `chat.guest_retention_days` and only closed authenticated rows at `chat.user_retention_days`, in 200-row chunks. Conversation deletion cascades to messages through the existing foreign key.

`routes/console.php` schedules this command hourly with `withoutOverlapping()`. It reports only closed/purged counts. The repository does not prove that Hostinger's scheduler is currently invoking it; that is a deployment checkpoint.

## Configuration defaults

| Environment flag            | Config key                  | Repository default | Local effect                                                                              |
| --------------------------- | --------------------------- | ------------------ | ----------------------------------------------------------------------------------------- |
| `CHAT_ENABLED`              | `chat.enabled`              | `false`            | Renders no widget and returns a no-store `chat_disabled` 404 from chat routes when false. |
| `CHAT_DEMO_ASSISTANT`       | `chat.demo_assistant`       | `false`            | Adds the deterministic demo reply after a newly stored customer message.                  |
| `CHAT_AUTO_CLOSE_HOURS`     | `chat.auto_close_hours`     | `24`               | Inactivity closure threshold.                                                             |
| `CHAT_REOPEN_WITHIN_DAYS`   | `chat.reopen_within_days`   | `7`                | Window for reopening only an inactivity closure.                                          |
| `CHAT_GUEST_RETENTION_DAYS` | `chat.guest_retention_days` | `30`               | Closed guest-history purge threshold.                                                     |
| `CHAT_USER_RETENTION_DAYS`  | `chat.user_retention_days`  | `180`              | Closed authenticated-history purge threshold.                                             |

Production values for these flags have not been inspected in this handoff.

## Safe error and cache contract

Chat exceptions are normalized by `ChatErrorResponse`; normal responses and explicit controller errors use the same private, non-cacheable boundary.

| Code                     | Status | Meaning                                                      |
| ------------------------ | ------ | ------------------------------------------------------------ |
| `validation_error`       | `422`  | Request validation failed.                                   |
| `invalid_cursor`         | `422`  | The history cursor is absent from the selected conversation. |
| `conversation_not_found` | `404`  | The public ID is not available to the current owner.         |
| `chat_disabled`          | `404`  | Chat is disabled.                                            |
| `conversation_closed`    | `409`  | A message was sent to a non-open conversation.               |
| `rate_limited`           | `429`  | The chat limiter rejected the request.                       |
| `chat_unavailable`       | `500`  | An unexpected server failure was sanitized.                  |

The feature, controller, and exception paths set `Cache-Control: no-store, private`; route middleware also enforces `no-store` downstream.

## Boundary

This is still deterministic chat. No model provider, streaming transport, agent turns/runs, RAG, tools, realtime operator channel, or administrator API is implemented. The approved Phase 2 design remains planned in [the phase design](../superpowers/specs/2026-08-20-ai-assistant-phases-1-2-design.md).

Local source and tests establish the implementation described above. They do not establish a deployed migration, production route health, production flag values, or production scheduler operation.
