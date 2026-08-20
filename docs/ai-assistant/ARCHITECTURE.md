# Architecture

**Lifecycle:** Implemented
**Verified:** 2026-08-21

The persistent Inertia `ChatRootLayout` renders `ChatWidget` on storefront,
authentication, and account surfaces. `HandleInertiaRequests` shares
`chat.enabled` and `chat.demoAssistant`; when disabled, the widget does not
render and `EnsureChatEnabled` returns the no-store `chat_disabled` 404.

## HTTP boundary

All routes in `routes/chat.php` use `EnsureChatEnabled`, `NoStore`, and
`SetChatLocale`.

| Method | Route name                   | Path                                          | Throttle             |
| ------ | ---------------------------- | --------------------------------------------- | -------------------- |
| POST   | `chat.conversations.store`   | `/chat/conversations`                         | `chat-conversations` |
| POST   | `chat.conversations.restart` | `/chat/conversations/restart`                 | `chat-conversations` |
| GET    | `chat.conversations.show`    | `/chat/conversations/{conversation}`          | `chat-read`          |
| POST   | `chat.messages.store`        | `/chat/conversations/{conversation}/messages` | `chat-messages`      |

Creation returns the owner's existing open conversation or reopens their most
recent inactivity-closed conversation inside the configured last-activity
window.
Restart closes the owner's open conversation with `customer_started_new` and
creates a replacement in one transaction. Reads and writes first scope by the
resolved owner, then public ID; a public ID is never authorization.

## Data model and lifecycle

`2026_08_20_000001_create_chat_tables.php` creates `chat_conversations` and
`chat_messages`. `2026_08_20_000002_add_chat_conversation_lifecycle.php` adds
`closed_at`, `close_reason`, `active_owner_key`, and the one-to-one assistant
`reply_to_message_id` relationship.

- A conversation has exactly one owner: `user_id` or HMAC `guest_key`.
- The generated `active_owner_key` is unique only for `status=open`, enforcing
  one open conversation per owner in MariaDB; SQLite uses an equivalent index
  and triggers. The migration backfills it and closes older duplicates with
  `invariant_upgrade_duplicate`.
- The message unique key `(conversation_id, client_message_id)` gives retry
  idempotency. Messages created after the lifecycle migration have an explicit
  `reply_to_message_id`, so a recovered retry returns that canonical assistant
  reply. A pre-migration customer row without that link returns `demoReply:
null`; no timestamp/order heuristic guesses which historical assistant row
  belongs to it.
- `CreateChatConversation` creates the conversation and its system onboarding
  message in the same database transaction.

`CreateOrGetActiveConversation` stores its public-ID pointer in the Laravel
session. Reopen eligibility is anchored to `last_message_at`, with `closed_at`
then `updated_at` used only for legacy null activity. The chosen fallback is
copied into `last_message_at` on reopen so reopen/reclose alone cannot extend
the window. Explicit restarts are never candidates for reopening.
`CreateChatMessage` locks the
conversation, rejects a closed conversation, and optionally creates the
deterministic demo reply.

## Configuration

`config/chat.php` provides these keys: `chat.enabled`, `chat.demo_assistant`,
`chat.max_message_length` (4000), `chat.default_page_size` (50),
`chat.auto_close_hours` (24), `chat.reopen_within_days` (7),
`chat.guest_retention_days` (30), and `chat.user_retention_days` (180). The
four durations have corresponding `CHAT_*` environment variables; the two
feature flags default to `false`. The 7-, 30-, and 180-day clocks use the same
last-activity anchor and legacy fallback described above.

`ChatErrorResponse` converts chat validation, conflict, rate-limit, and server
failures into no-store JSON error envelopes. Error codes are documented in
[SECURITY.md](SECURITY.md). Operations and rollback are in [OPERATIONS.md](OPERATIONS.md).
