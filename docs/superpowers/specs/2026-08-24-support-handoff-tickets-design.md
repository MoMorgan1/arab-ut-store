# Support handoff, tickets, and customer chat history — design

**Status:** Approved by Mohamed on 2026-08-24
**Base:** `origin/main` at `4c0d417`
**Branch:** `claude/support-handoff`

## Problem

Three gaps in the shipped assistant:

1. **No human path.** Luna answers from `support-v3` plus the curated knowledge
   file. When it cannot help, the prompt tells it to offer that "the team will
   follow up here" — but no such follow-up exists. There is no way for Mohamed
   to reply to a customer, and no record of a request that needs a person.
2. **Guest chat is over-retained and clutters the operator view.** Guest
   conversations persist for 30 days and appear in the admin inbox next to real
   customers. Mohamed wants the inbox to be customers only, guest data to be
   short-lived, and no ticket to exist without a logged-in owner.
3. **Customers lose their history.** The widget loads only the active
   conversation, and a thread auto-closes after 24 hours of silence. A customer
   who returns the next day sees an empty chat with no way back to what they
   asked. The admin ID shown in the inbox is also a raw 26-character ULID.

## Decisions taken (2026-08-24, Mohamed)

| Question | Decision |
| --- | --- |
| Guest data | Guests keep Luna. Guest conversations are excluded from the admin inbox entirely and hard-deleted after 48 hours. |
| Ticket shape | A real `support_tickets` record with its own number, status, priority and assignee. |
| Who escalates | Both: an always-available customer control, and a server-side auto-offer when Luna genuinely cannot help. Luna must try first. |
| Admin chat ID | A short `CHT-XXXXXX` number replacing the ULID in the inbox. |
| Luna during handoff | Silent for that conversation until the ticket is resolved. |
| Delivery | Widget polls while open; admin dashboard badge + sound; email the customer when they are away. |
| Customer ticket surface | Inside the chat widget only. |
| Who may reply | New `chat.reply` permission, Admin-only for now. |

## Non-goals

- No websocket/Reverb transport, no queue worker, no realtime presence.
- No SLA timers, routing rules, canned-response library, or CSAT survey.
- No customer-facing ticket pages outside the widget.
- No change to Luna's prompt, knowledge file, model, token budget, or eval
  thresholds. `support-v3` and its accepted configuration are untouched.
- No change to session configuration; `AI-B09` stays open.

---

## 1. Data model

### 1.1 `chat_conversations` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `short_id` | `string(10)` unique, nullable until backfilled | `CHT-XXXXXX`. Backfilled for every existing row inside the migration, then made non-nullable in the same migration. |
| `handoff_state` | enum string, default `none` | `none`, `offered`, `requested`, `active`, `resolved`. |
| `assigned_admin_id` | `foreignId` nullable, `nullOnDelete` → `users` | Denormalised from the live ticket so the inbox list can filter "assigned to me" without a join to tickets. |
| `last_staff_message_at` | `timestamp` nullable | Drives the customer-away email decision and the inbox unread dot. |

`handoff_state` is a conversation-level concern rather than a ticket column
because it governs Luna's behaviour, which is evaluated on every turn claim and
must not require a ticket join on the hot path.

### 1.2 `support_tickets` (new)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint pk | |
| `public_id` | ulid unique | Used in URLs, never as authorization. |
| `ticket_number` | `string(10)` unique | `TKT-XXXXXX`. |
| `conversation_id` | FK → `chat_conversations`, cascade delete | |
| `user_id` | FK → `users`, cascade delete, **not nullable** | This column is the enforcement of "no ticket without login". |
| `order_id` | FK → `orders` nullable, `nullOnDelete` | Set when the ticket is opened from an order context. |
| `subject` | `string(160)` | Derived from the first customer message, truncated on a word boundary. Editable by staff. |
| `status` | enum string | `open`, `pending_customer`, `resolved`, `closed`. Set to `pending_customer` automatically when staff replies, and back to `open` when the customer replies — that transition is what drives the inbox unread ordering. |
| `priority` | enum string, default `normal` | `low`, `normal`, `high`. |
| `assigned_admin_id` | FK → `users` nullable, `nullOnDelete` | |
| `opened_by` | enum string | `customer`, `staff`, `system`. |
| `last_notified_at` | timestamp nullable | Throttles the customer email (§6.2). |
| `resolved_at`, `closed_at` | timestamp nullable | |
| `created_at`, `updated_at` | | |

**One live ticket per conversation.** MariaDB has no partial indexes, so this
follows the pattern the codebase already proved for `active_owner_key`: a
generated column `active_conversation_key` that equals `conversation_id` when
`status` is not `closed` and is `NULL` otherwise, with a unique index on it.
SQLite gets the equivalent partial unique index. Any code path that opens a
ticket takes a `lockForUpdate` on the conversation row first, so the unique
index is a backstop, not the primary guard.

Indexes: `(status, created_at)`, `(assigned_admin_id, status)`, `(user_id)`.

### 1.3 `chat_messages` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `staff_user_id` | FK → `users` nullable, `nullOnDelete` | Present exactly when `sender_type = staff`; a check constraint / model invariant enforces both directions. |

`ChatSenderType` gains `Staff = 'staff'`. `ChatMessageType` gains
`InternalNote = 'internal_note'`.

Staff replies are ordinary chat messages. That is deliberate: message grouping,
RTL handling, `dir="auto"`, cursor pagination, the
`(conversation_id, client_message_id)` retry-idempotency key, cascade deletes
and the 180-day retention sweep all keep working with no second code path.

**Internal notes never reach the customer.** `ChatPresenter::loadBoundedMessages`
and every customer-facing query filter
`where('message_type', '!=', 'internal_note')` at the query level. They are not
filtered in the browser and not filtered in the presenter's map step — a note
must never be serialised into a customer payload in the first place. A feature
test asserts the customer conversation JSON for a conversation containing a note
does not contain the note's text.

### 1.4 Short numbers

`App\Support\ChatNumber` and `App\Support\TicketNumber` mirror
`App\Checkout\OrderNumber` exactly: prefix + six characters from
`23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (no `0/O`, no `1/I`), `random_int`,
uniqueness verified against the table, ten attempts, `RuntimeException` on
exhaustion, and a `PATTERN` constant for validation. Six characters over a
32-symbol alphabet is ~1.07 billion combinations.

`CHT-` identifies a conversation the operator is looking at; `TKT-` identifies a
support record the customer is told about. They are separate because most
conversations never become tickets, and a customer should never be given a
number that does not correspond to a promise of a human reply.

---

## 2. Guest policy

1. `ConversationsController` adds an unconditional `whereNotNull('user_id')`.
   The `guest` option is removed from `ListAdminConversations`' owner filter and
   from `filterOptions.owners`; a request carrying `owner=guest` normalises to
   `null` rather than erroring, so a stale bookmark degrades to "all customers".
2. `ConversationDetailController` 404s for a conversation with a null `user_id`.
   Guest transcripts are not operator-readable at all.
3. New config key `chat.guest_retention_hours`, default `48`, env
   `CHAT_GUEST_RETENTION_HOURS`. `chat.guest_retention_days` is **removed**, not
   left dangling; `OPERATIONS.md` and `.env.example` are updated in the same
   change.
4. `MaintainChatConversations::purgeExpiredConversationsForOwner` takes the
   guest cutoff in hours. Its existing guarantees are preserved unchanged: it
   locks each row, re-checks the cutoff under the lock, and refuses to delete a
   conversation with a `waiting`/`running` agent turn. Cascades remove messages,
   turns and runs.
   The purge must also delete guest conversations that are still `open` — a
   guest thread that is 48 hours old is deleted whether or not the hourly
   auto-close has run yet. Authenticated purge behaviour is unchanged (closed
   only, 180 days).
5. Guests keep Luna, the knowledge grounding, service cards and cart offers
   exactly as today.
6. The escalation control renders for a guest as "Log in to reach the team",
   linking to the existing login route with a return URL. `POST /chat/tickets`
   returns `403 handoff_requires_login` for a guest owner regardless of what the
   client sent — the UI variant is a convenience, the server check is the rule.
7. Claim-on-login is unchanged, so a guest who logs in within 48 hours keeps the
   thread and can then open a ticket on it.

**Accepted consequence:** a guest who does not log in loses their transcript
after 48 hours, and Mohamed cannot review guest conversations for quality or
abuse. This is the explicit request; it is recorded in `DECISIONS.md`.

---

## 3. Escalation

Luna answers first. The **server**, never the model, decides when a human is
offered — the same rule that already governs service cards.

### 3.1 Offer triggers

`App\Actions\Support\EvaluateHandoffOffer` runs after a turn terminalises and
returns whether the conversation should move to `handoff_state = offered`. It
offers when any of:

1. **The customer asked for a person.** Bilingual lexical match against a
   maintained list (`موظف`, `خدمة العملاء`, `بشري`, `شخص حقيقي`, `الدعم`,
   `human`, `agent`, `real person`, `support team`, `representative`,
   `talk to someone`). Word-boundary aware; Arabic matching is normalised for
   tatweel and the alef/hamza family so `الدعم` and `الدّعم` both match.
2. **Luna had nothing to ground on, twice.** `SelectSupportKnowledge` returned
   zero topics for this turn *and* for the immediately preceding completed
   agent turn in the same conversation. One empty selection is not enough —
   Mohamed's instruction is that Luna tries first.
3. **The turn failed terminally** (timeout, provider error, non-retryable
   failure). The customer gets a human offer instead of a dead end.
4. **The sensitive-content guard blocked the claimed range.** The customer is
   trying to share something the assistant must not process.

### 3.2 The offer is not a ticket

Reaching `offered` renders a chip in the thread ("Talk to the team" / "تحدث مع
الفريق"). A ticket is created only when the customer taps it, or when Mohamed
opens one from the inbox. This mirrors the confirmation principle in `TOOLS.md`
and prevents an accidental flood of tickets from one bad Luna day.

The always-available control in the widget header does the same thing without
waiting for a trigger.

### 3.3 Luna goes silent

`CreateChatMessage` sets `agent_eligible_at` only when
`handoff_state ∉ {requested, active}`. Messages written while a human owns the
thread are permanently ineligible — the column is immutable once written, which
is the existing Phase 2 rule, so resolving a ticket does not retroactively hand
Luna a backlog to answer.

When a ticket is resolved, `handoff_state` returns to `resolved`, and the *next*
customer message is eligible again. The customer sees a system line: "Luna is
back to help — reply here any time."

A `waiting`/`running` turn at the moment of takeover is allowed to finish. It is
already streaming to the customer, and cancelling it mid-flight would leave a
partial bubble. The takeover applies from the next message.

---

## 4. Admin inbox

### 4.1 List (`/admin/conversations`)

- The ULID column becomes `short_id` (`CHT-4F9A2C`), monospaced, copyable.
- New columns: ticket badge (`TKT-…` + status colour) when a live ticket
  exists, assignee initials, and an unread dot when
  `last_message_at > last_staff_message_at` for a conversation with a live
  ticket.
- Filters gain `ticketStatus` and `assignee=me`; the `owner` filter is removed.
- Search (`q`) matches `short_id`, `ticket_number`, or the full `public_id`,
  case-insensitively. It does **not** search message content — that would need a
  full-text index and is out of scope.
- Default sort becomes: live tickets with unread customer messages first, then
  last activity descending.

### 4.2 Detail (`/admin/conversations/{publicId}`)

Existing transcript and agent-turn diagnostics stay. Added:

- **Reply composer** — 4000-character limit matching `chat.max_message_length`,
  sends a `staff` message. Requires `chat.reply`.
- **Take over** — creates a ticket if none exists (or claims an unassigned one),
  sets `assigned_admin_id`, `handoff_state = active`. Idempotent: taking over an
  already-owned ticket returns the same ticket.
- **Internal note** — visually separated, never sent to the customer.
- **Resolve** — `status = resolved`, `resolved_at`, `handoff_state = resolved`,
  posts the "Luna is back" system line.
- **Assign** — Admin only.
- **Priority** and **subject** edit.

### 4.3 Routes

All inside the existing admin MFA group, registered under both the bare and
`/en` prefixes exactly like the current conversation routes.

| Method | Path | Permission |
| --- | --- | --- |
| POST | `/admin/conversations/{publicId}/reply` | `chat.reply` |
| POST | `/admin/conversations/{publicId}/note` | `chat.reply` |
| POST | `/admin/conversations/{publicId}/ticket` | `chat.reply` |
| PATCH | `/admin/tickets/{publicId}` | `chat.reply` (assign requires Admin) |
| GET | `/admin/support/unread-count` | `chat.view` |

### 4.4 Audit

Every staff write emits a `StaffAuditEvent`: `chat.reply.sent`,
`chat.note.added`, `chat.ticket.opened`, `chat.ticket.assigned`,
`chat.ticket.resolved`, `chat.ticket.priority_changed`. Metadata carries the
ticket number, conversation short id, target user id and character count —
**never message bodies**. A transcript is not audit data, and `StaffAuditEvent`
already rejects secret-shaped keys.

---

## 5. Customer experience

### 5.1 Ticket banner

A pinned banner in the thread while a ticket is live:

- `requested`: "طلبك وصل للفريق — TKT-4F9A2C" / "Your request reached the team".
- `active`: shows the responder's display name.
- `resolved`: "تم حل التذكرة" plus a "Still need help?" control that reopens by
  creating a new ticket on the same conversation.

### 5.2 Staff bubbles

Physically on the assistant side, visually distinct from Luna (different accent,
staff avatar, name label). The customer must never mistake a person for the bot
or vice versa. `dir="auto"` as with every other bubble.

### 5.3 Chat history — the fix for disappearing chats

`chat-home.tsx` gains a **"Previous conversations"** section for authenticated
customers: up to 10 recent threads showing `subject` (or a first-message
preview), relative date, and a ticket badge if one existed. Tapping opens the
thread **read-only** with a "Start a new conversation" control — reopening a
closed thread is not introduced, because the accepted lifecycle says an
explicitly closed thread never reopens.

New endpoint `GET /chat/conversations` — owner-scoped, cursor-paginated,
`chat-read` throttle, `NoStore`, `SetChatLocale`, `EnsureChatEnabled`. Returns
only conversations belonging to the resolved owner. **Guests receive an empty
list**, consistent with the 48-hour purge; the history section does not render
for them.

### 5.4 Polling

While the widget is open and `handoff_state ∈ {requested, active}`, poll
`chat.conversations.show` every 5s, backing off to 15s after two minutes with no
new message, pausing entirely on `document.hidden`, and stopping when the
handoff resolves. Polling reuses the existing conversation read path and its
rate limit (60/owner/min) — 5s polling is 12/min, comfortably inside it.

---

## 6. Notifications

### 6.1 Admin

`GET /admin/support/unread-count` returns the number of live tickets with a
customer message newer than `last_staff_message_at`. The admin shell polls it
every 30s and renders a badge on the Conversations nav entry, plus an optional
chime reusing the existing `chat-sound` Web Audio helper and honouring the same
mute preference. No sound on first load — only on an increase.

### 6.2 Customer email

When a staff reply is written and the customer has not been active in the thread
for five minutes, queue `SupportReplyNotification` (bilingual, keyed on the
conversation locale). The email says a reply is waiting and links to the store —
**it contains no transcript content**, because chat content in email is
un-revocable and outside the retention model.

Rate limit: at most one email per conversation per hour, enforced by a
`last_notified_at` column on `support_tickets` checked under the same lock as the
reply write. Mail uses the existing configured mailer and the existing
notification pattern; delivery rides the 1-minute cron.

---

## 7. Authorization and safety

- `AdminPermission::ChatReply = 'chat.reply'`. `AdminAccess::STAFF` is **not**
  extended, so only `UserRole::Admin` gets it today. Adding a support role later
  is one line.
- `chat.view` continues to gate reading.
- Ticket and reply endpoints resolve the conversation by owner scope and
  re-check permission server-side on every call. A public ID is never
  authorization — the Phase 1 rule is unchanged.
- `guest_key` is still never serialised to any client payload.
- Staff messages skip the agent sensitive-content guard (a human wrote them) but
  are still length-validated and stored as plain text; the existing XSS-safe
  rendering path is reused with no `dangerouslySetInnerHTML`.
- Rate limits: staff reply 60/min/actor; ticket creation 5/min/owner and
  20/min/IP.

---

## 8. Testing

**Feature (Pest):** escalation trigger matrix including the "two consecutive
empty selections" rule and the Arabic normalisation cases; guest 403 on ticket
create; guest purge at 48h including an open guest thread and including refusal
while an agent turn is nonterminal; one-live-ticket-per-conversation under
concurrent creation; `agent_eligible_at` null while handoff is active and
non-null after resolve; internal note absent from the customer payload; `Staff`
role denied `chat.reply`; audit events emitted with no message body; email
rate limit.

**Vitest:** reply composer, ticket banner states, staff bubble rendering,
history list (including empty and guest cases), polling lifecycle —
start/backoff/pause on hidden/stop on resolve.

**Playwright:** admin opens a ticket, replies, customer widget receives it via
polling; the 44px touch-target floor on every new control at all widths. That
suite measures **every** admin control, so no `md:min-h-9` / `md:size-9` shrink
override may be introduced, and nav-link counts must be updated if a nav entry
is added.

**Gate:** `npm run ci:check` plus the Playwright suite, run in full. Partial runs
have historically hidden real failures in this repo.

---

## 9. Documentation

Fix the drift found on 2026-08-24 before layering new docs on top:

1. `README.md` — header still says Phase 2 "inactive and not accepted"
   (stamped 08-22) while `STATUS.md` records acceptance on 08-23. Its table
   lists Support inbox and Retrieval as "Planned" although `ADMIN-INBOX.md` and
   `RAG.md` both describe shipped implementations.
2. `STATUS.md` — the "Exact next gate" section says Phase 2 re-entry is blocked,
   directly below the acceptance record that says it passed. The "Current
   production mode" row still describes the 08-22 owner exception.
3. `PRODUCT`, `ARCHITECTURE`, `SECURITY`, `UX`, `OPERATIONS`, `AUDIT`, `PHASES`,
   `AGENT-RUNTIME`, `EVALS` — all stamped 08-22 with "inactive/not accepted"
   lifecycles that predate both acceptance and the Phase 3 work.
4. `ARCHITECTURE.md` omits the admin inbox routes, `ServicePriceController`, and
   the cards/cart surface.
5. `TOOLS.md` says no assistant tool is live; server-derived service cards, the
   price lookup and the in-chat cart offer exist. It must state precisely what
   they are — server-derived from the customer's message, never model-authored,
   carrying no model-chosen price — instead of being false.

Then record this work: `ADMIN-INBOX.md` becomes the canonical operator
document; `PRODUCT.md` removes human handoff from its exclusion list;
`ARCHITECTURE.md`, `SECURITY.md`, `UX.md`, `OPERATIONS.md` and `PHASES.md` gain
the new routes, tables, permission, retention window and polling behaviour; and
`DECISIONS.md` records the eight decisions in the table above, including the
accepted consequence of the 48-hour guest purge.

---

## 10. Implementation lanes

Four lanes, sequenced so each starts from a merged base rather than a guess:

- **Lane A — schema and policy.** Migrations, `ChatNumber`/`TicketNumber`,
  `SupportTicket` model, enum additions, `chat.reply` permission, guest purge
  window, admin list/detail guest exclusion, short-id column. Merges first;
  every other lane depends on it.
- **Lane B — escalation and Luna silence.** `EvaluateHandoffOffer`, the
  bilingual matcher, `handoff_state` transitions, `agent_eligible_at` gating,
  ticket create/resolve actions, `POST /chat/tickets`.
- **Lane C — admin inbox UI.** Reply composer, take over, notes, resolve,
  assign, priority, list columns and filters, audit events.
- **Lane D — customer UI, history, notifications.** Banner, staff bubbles,
  history list and `GET /chat/conversations`, polling, unread badge and chime,
  `SupportReplyNotification`, plus the whole of section 9.

Lanes B, C and D are independent of each other once A lands. Lane D owns every
documentation file to avoid conflicts.
