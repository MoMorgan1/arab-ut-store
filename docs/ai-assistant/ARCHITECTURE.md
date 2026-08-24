# Architecture

**Lifecycle:** Phases 1-3 and Support Handoff & Ticketing implemented and active
**Verified:** 2026-08-24

The persistent Inertia `ChatRootLayout` renders `ChatWidget` on storefront,
authentication, and account surfaces. `HandleInertiaRequests` shares chat
feature state. When chat is disabled, the widget does not render and
`EnsureChatEnabled` returns the no-store `chat_disabled` 404.

## HTTP boundary

All routes in `routes/chat.php` use `EnsureChatEnabled`, `NoStore`, and `SetChatLocale`.

| Method | Route name                   | Path                                                          | Throttle             |
| ------ | ---------------------------- | ------------------------------------------------------------- | -------------------- |
| GET    | `chat.service-prices`        | `/chat/service-prices`                                        | `chat-read`          |
| POST   | `chat.conversations.store`   | `/chat/conversations`                                         | `chat-conversations` |
| POST   | `chat.conversations.restart` | `/chat/conversations/restart`                                 | `chat-conversations` |
| GET    | `chat.conversations.index`   | `/chat/conversations`                                         | `chat-read`          |
| GET    | `chat.conversations.show`    | `/chat/conversations/{conversation}`                          | `chat-read`          |
| POST   | `chat.messages.store`        | `/chat/conversations/{conversation}/messages`                 | `chat-messages`      |
| POST   | `chat.tickets.store`         | `/chat/conversations/{conversation}/ticket`                   | `chat-conversations` |
| POST   | `chat.agent-turns.store`     | `/chat/conversations/{conversation}/agent-turns`              | `agent-turns`        |
| GET    | `chat.agent-turns.show`      | `/chat/conversations/{conversation}/agent-turns/{turn}`       | `chat-read`          |
| POST   | `chat.agent-turns.retry`     | `/chat/conversations/{conversation}/agent-turns/{turn}/retry` | `agent-turns`        |

Admin routes in `routes/admin.php` sit behind `can:chat.view` and admin MFA:

| Method | Route name                  | Path                         | Throttle             |
| ------ | --------------------------- | ---------------------------- | -------------------- |
| GET    | `admin.conversations`       | `/admin/conversations`       | `admin`              |
| GET    | `admin.conversations.show`  | `/admin/conversations/{id}`  | `admin`              |
| GET    | `admin.support.unread-count`| `/admin/support/unread-count`| `admin`              |

Creation returns the owner's existing open conversation or reopens their most
recent inactivity-closed conversation inside the configured last-activity
window. Restart closes the owner's open conversation with
`customer_started_new` and creates a replacement in one transaction. Reads and
writes scope by the resolved owner before public ID; public IDs are not
authorization.

`GET /chat/conversations` lists up to 10 previous conversations for authenticated customers. Guest requests return an empty list immediately.

## Data model and lifecycle

- `chat_conversations`: Holds conversation state, `handoff_state` (`none`, `offered`, `requested`, `active`, `resolved`), `status`, and timestamps.
- `support_tickets`: Holds ticket number (`TKT-XXXXXX`), `status` (`open`, `resolved`, `closed`), `assigned_admin_id`, `priority`, and `last_notified_at`.
- `chat_messages`: Holds `sender_type` (`customer`, `assistant`, `staff`, `system`), `message_type` (`text`, `system`, `internal_note`), `staff_user_id`, `client_message_id`, and `reply_to_message_id`.
- `agent_turns` and `agent_runs`: Durable AI runtime tracking, latency, token usage, and costs.

### Invariant: Strict Lock Order

To prevent deadlocks between customer turns, staff replies, and ticket status changes, lock acquisition strictly follows:
`conversation -> ticket -> turn -> run`.
Never lock a ticket or turn before the parent conversation.

## Polling and State Resynchronization

1. **Customer Handoff Polling:**
   - Active when widget is open and `handoff_state` is `requested` or `active`.
   - Starts at 5s (`5000ms`) interval.
   - Backs off to 15s (`15000ms`) after 2 minutes of inactivity.
   - Pauses on background tab (`document.hidden`) and resumes immediately on visibility.
   - Stops when resolved or closed.
2. **Admin Unread Badge Polling:**
   - Polls `GET /admin/support/unread-count` every 30s.
   - Synthesizes audio chime only when count increases from previous value.
   - Pauses on hidden document.
3. **Transparent 404 Expiry Recovery:**
   - If a message send returns a 404 (`conversation_not_found`), the client re-acquires a fresh active conversation without surfacing raw errors.

## Grounding and derived surfaces

`SelectSupportKnowledge` picks topics lexically from `resources/ai-assistant/knowledge/arab-ut.json`, and `support-v6` injects them as a `<store_knowledge>` block, alongside a `<live_prices>` block built from the store catalogue in the viewer's own display currency.

Customer-visible service cards and add-to-cart offers are derived server-side from customer message intent, never authored by the model.

## Retention and Maintenance

- Guest conversations are purged after 48 hours of inactivity by the hourly scheduler.
- Authenticated closed conversations follow standard 180-day retention.
- Cascade deletion ensures messages, tickets, turns, and runs are cleanly removed.
