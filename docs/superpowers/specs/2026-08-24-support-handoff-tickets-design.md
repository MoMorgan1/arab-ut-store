# Support handoff, tickets, and customer chat history — design

**Status:** Approved by Mohamed on 2026-08-24; revised after the Fable architecture debate
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
3. **Customers cannot reach their older chats.** `CreateOrGetActiveConversation`
   already reopens an inactivity-closed thread within `reopen_within_days` (7),
   so a customer returning the next day usually does see their last thread. What
   does not exist is any way to reach a thread that was explicitly restarted,
   that fell outside the seven-day window, or that is simply not the most recent
   one — the widget has no history picker at all, and `UX.md` records that
   omission. The admin ID shown in the inbox is also a raw 26-character ULID.

## Decisions taken (2026-08-24, Mohamed)

| Question | Decision |
| --- | --- |
| Guest data | Guests keep Luna. Guest conversations are excluded from the admin inbox entirely and hard-deleted after 48 hours. |
| Ticket shape | A real `support_tickets` record with its own number, status and assignee. |
| Who escalates | Both: an always-available customer control, and a server-side auto-offer when Luna genuinely cannot help. Luna must try first. |
| Admin chat ID | A short `CHT-XXXXXX` number replacing the ULID in the inbox. |
| Luna during handoff | Silent for that conversation until the ticket is resolved. |
| Delivery | Widget polls while open; admin dashboard badge + sound; email the customer when they are away. |
| Customer ticket surface | Inside the chat widget only. |
| Who may reply | New `chat.reply` permission, Admin-only for now. |

## Non-goals

- No websocket/Reverb transport, **no queue worker**, no realtime presence.
- No SLA timers, routing rules, canned-response library, or CSAT survey.
- No customer-facing ticket pages outside the widget.
- No change to Luna's prompt, knowledge file, model, token budget, or eval
  thresholds. `support-v3` and its accepted configuration are untouched.
- No change to session configuration; `AI-B09` stays open.

### Cut from v1 after the debate

Each of these was in the first draft and is removed because a one-operator queue
cannot use it. The reasoning is recorded so a future team can restore them
deliberately rather than rediscover them.

- **Priority UI.** Unread-first ordering already decides what Mohamed reads next.
  The `priority` column is kept (cheap, non-null default) but has no surface.
- **Assign action, `assignee=me` filter, assignee column.** `chat.reply` is
  Admin-only and Admin is one person. `support_tickets.assigned_admin_id` is
  kept and set by take-over; nothing else exposes it.
- **`order_id` on tickets.** No described flow ever sets it — the only customer
  surface is the widget, which has no order context. Dead column, dropped.
- **`pending_customer` status.** The unread signal is already
  `last_message_at > last_staff_message_at`. A status that flip-flops on every
  message duplicates it and doubles the transition matrix. Status is
  `open | resolved | closed`.
- **Staff subject editing.** The derived subject is enough.
- **`opened_by` column.** Recoverable from the `chat.ticket.opened` audit event.

---

## 1. Data model

### 1.1 `chat_conversations` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `short_id` | `string(10)` unique, nullable until backfilled | `CHT-XXXXXX`. Backfilled for every existing row inside the migration, then made non-nullable in the same migration. |
| `handoff_state` | enum string, default `none` | `none`, `offered`, `requested`, `active`, `resolved`. |
| `last_staff_message_at` | `timestamp` nullable | Drives the customer-away email decision and the inbox unread dot. |

`handoff_state` lives on the conversation rather than only on the ticket because
it governs Luna's behaviour, which is checked on every turn claim and must not
require a ticket join on the hot path. It is a **cache of ticket state, not a
second authority**: `support_tickets.status` is authoritative, and the two only
ever change together in one transaction under the conversation lock.

There is deliberately **no** `assigned_admin_id` on the conversation. The first
draft denormalised it "so the inbox can filter without a join", but the inbox
already joins the ticket for the badge and the unread condition, so the
denormalisation bought nothing and created a drift pair with two independent
`nullOnDelete` triggers.

### 1.2 `support_tickets` (new)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint pk | |
| `public_id` | ulid unique | Used in URLs, never as authorization. |
| `ticket_number` | `string(10)` unique | `TKT-XXXXXX`. |
| `conversation_id` | FK → `chat_conversations`, cascade delete | |
| `user_id` | FK → `users`, cascade delete, **not nullable** | This column is the enforcement of "no ticket without login". |
| `subject` | `string(160)` | Derived from the first customer message, truncated on a word boundary. |
| `status` | enum string | `open`, `resolved`, `closed`. |
| `priority` | enum string, default `normal` | `low`, `normal`, `high`. No UI in v1. |
| `assigned_admin_id` | FK → `users` nullable, `nullOnDelete` | Set by take-over. No assign UI in v1. |
| `last_notified_at` | timestamp nullable | Throttles the customer email (§6.2). |
| `resolved_at`, `closed_at` | timestamp nullable | |
| `created_at`, `updated_at` | | |

**One live ticket per conversation.** A generated column
`active_conversation_key` equals `conversation_id` **only when `status = 'open'`**
and is `NULL` otherwise, with a unique index on it. This is the proven
`active_owner_key` shape from
`2026_08_20_000002_add_chat_conversation_lifecycle.php:156-167`; SQLite gets the
equivalent partial unique index.

The `'open'`-only condition is load-bearing. The first draft keyed on
"status is not closed", which meant a **resolved** ticket kept occupying the
unique slot — so the approved "Still need help?" reopen in §5.1 would have hit a
duplicate-key 500, permanently, on every conversation that ever had a ticket
resolved. "Live ticket" means `status = 'open'` everywhere in this document: the
inbox badge, the unread dot, the unread count, the auto-close exemption, and
this index.

Any path that opens a ticket takes `lockForUpdate` on the conversation row
first, so the unique index is a backstop, not the primary guard.

Indexes: `(status, created_at)`, `(user_id)`.

### 1.3 `chat_messages` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `staff_user_id` | FK → `users` nullable, `nullOnDelete` | Present exactly when `sender_type = staff`; a model invariant enforces both directions. |

`ChatSenderType` gains `Staff = 'staff'`. `ChatMessageType` gains
`InternalNote = 'internal_note'`.

Staff replies are ordinary chat messages, so message grouping, RTL handling,
`dir="auto"`, cursor pagination, the `(conversation_id, client_message_id)`
retry-idempotency key, cascade deletes and the 180-day retention sweep all keep
working with no second code path.

**A staff message must leave `reply_to_message_id` NULL.** That column is UNIQUE
(`2026_08_20_000002...:24`) and `FinalizeAgentTurn` writes
`reply_to_message_id = last_customer_message_id` when a turn completes. If a
staff reply claimed the same customer message, the in-flight Luna turn that
§3.3 deliberately lets finish would die on the unique index at the finish line —
producing exactly the partial bubble §3.3 exists to avoid. Separately,
`PendingAgentMessages` excludes messages `whereDoesntHave('reply')`
(`app/Queries/AI/PendingAgentMessages.php:21`), so a staff `reply_to` would
silently remove customer messages from future claims. A model invariant test
pins this.

**Internal notes never reach the customer.** `ChatPresenter::loadBoundedMessages`
and every customer-facing query filter
`where('message_type', '!=', 'internal_note')` at the query level — not in the
browser, not in the presenter's map step. A feature test asserts the customer
conversation JSON for a conversation containing a note does not contain the
note's text.

Notes and staff messages can never reach Luna's prompt either, and that holds
without new code: both context queries are allowlists —
`PendingAgentMessages` requires `sender_type = customer`, and
`CompletedAgentContextMessages` requires turn-completed customer/assistant rows.

### 1.4 Short numbers

`App\Support\ChatNumber` and `App\Support\TicketNumber` mirror
`App\Checkout\OrderNumber` exactly: prefix + six characters from
`23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (no `0/O`, no `1/I`), `random_int`,
uniqueness verified against the table, ten attempts, `RuntimeException` on
exhaustion, and a `PATTERN` constant for validation.

`CHT-` identifies a conversation the operator is looking at; `TKT-` identifies a
support record the customer is told about. They stay separate because most
conversations never become tickets, and a customer should never be handed a
number that does not correspond to a promise of a human reply.

---

## 2. Guest policy

1. `ConversationsController` adds an unconditional `whereNotNull('user_id')`.
   The `guest` option is removed from `ListAdminConversations`' owner filter and
   from `filterOptions.owners`; a request carrying `owner=guest` normalises to
   `null` rather than erroring, so a stale bookmark degrades to "all customers".
2. `ConversationDetailController` 404s for a conversation with a null `user_id`.
3. New config key `chat.guest_retention_hours`, default `48`, env
   `CHAT_GUEST_RETENTION_HOURS`. `chat.guest_retention_days` is **removed**, not
   left dangling; `OPERATIONS.md` and `.env.example` are updated with it.
4. `MaintainChatConversations::purgeExpiredConversationsForOwner` takes the guest
   cutoff in hours. Its existing guarantees are preserved unchanged: it locks
   each row, re-checks the cutoff under the lock, and refuses to delete a
   conversation with a `waiting`/`running` agent turn. Cascades remove messages,
   turns and runs.
   The guest branch drops the `status = closed` filter (currently
   `MaintainChatConversations.php:107,117`) so an *open* guest thread is also
   deleted at 48 hours. The cutoff stays activity-based
   (`whereLastActivityAtOrBefore`), so an actively-messaging guest is never
   deleted mid-conversation. Authenticated purge behaviour is unchanged.
5. **A guest whose conversation is purged under an open widget must not see a raw
   error.** An idle-47h guest who is reading, then sends, currently gets a 404
   from `lockOwnedOpenConversation`'s `firstOrFail`. The widget treats
   404-on-send as "this conversation expired" and transparently starts a new
   one, the same way it already reacquires after a cross-tab close.
6. Guests keep Luna, the knowledge grounding, service cards and cart offers
   exactly as today.
7. The escalation control renders for a guest as "Log in to reach the team",
   linking to the existing login route with a return URL. `POST /chat/tickets`
   returns `403 handoff_requires_login` for a guest owner regardless of what the
   client sent — the UI variant is a convenience, the server check is the rule.
8. **Login claim must not supersede a ticketed thread.**
   `ClaimGuestChatConversations` currently closes the user's existing open
   conversation with `SupersededByLoginClaim` when a guest thread wins
   (`app/Actions/Chat/ClaimGuestChatConversations.php:43-49`). If that user
   conversation carries a live ticket, the result is a live ticket on a closed
   thread. The claim must keep the user's conversation as the winner whenever its
   `handoff_state` is `requested` or `active`.

**Accepted consequence:** a guest who does not log in loses their transcript
after 48 hours, and Mohamed cannot review guest conversations for quality or
abuse. This is the explicit request; it is recorded in `DECISIONS.md`.

---

## 3. Escalation

Luna answers first. The **server**, never the model, decides when a human is
offered — the same rule that already governs service cards.

### 3.1 Offer triggers

`App\Actions\Support\EvaluateHandoffOffer` decides whether the conversation
moves to `handoff_state = offered`. It offers when any of:

1. **The customer asked for a person.** Bilingual lexical match against a
   maintained list (`موظف`, `خدمة العملاء`, `بشري`, `شخص حقيقي`, `الدعم`,
   `human`, `agent`, `real person`, `support team`, `representative`,
   `talk to someone`). Word-boundary aware; Arabic matching is normalised for
   tatweel and the alef/hamza family so `الدعم` and `الدّعم` both match.
2. **Luna had nothing to ground on, twice.** Zero knowledge topics selected for
   this turn *and* for the immediately preceding completed agent turn.
3. **The turn failed terminally** during a live request — timeout, provider
   error, or a non-retryable failure the customer was waiting on.
4. **The sensitive-content guard blocked the claimed range.**

**Trigger 2 is recomputed, not read back.** The selection happens inside
`BuildAgentModelRequest::knowledgeBlock` and the result is discarded, and
`agent_runs` is content-free by design — so there is nothing persisted to query.
`SelectSupportKnowledge` is deterministic and the turn's customer-message bounds
*are* persisted, so `EvaluateHandoffOffer` re-runs the selection over the
current and previous completed turns' customer text. That is one lexical pass
over a bounded string and touches no provider.

Trigger 2 is **skipped entirely when `ai-assistant.knowledge_max_topics` is 0**.
`BuildAgentModelRequest` returns an empty block without selecting at that
setting, so treating it as "nothing to ground on" would offer a handoff on every
single turn the moment grounding is switched off.

**Trigger 3 excludes cron-recovered turns.** `RecoverStaleAgentTurns`
terminalises abandoned turns every minute with `StaleTurnRecovered`. A customer
who simply closed the tab mid-stream would otherwise return to a handoff chip
they never asked for. Only a failure observed on a live request offers a human.

`EvaluateHandoffOffer` is called from exactly two places: after
`FinalizeAgentTurn` terminalises a turn on a live request, and from
`CreateChatMessage` for the lexical trigger. The cron recovery path does not
call it.

### 3.2 The offer is not a ticket

Reaching `offered` renders a chip in the thread ("Talk to the team" / "تحدث مع
الفريق"). A ticket is created only when the customer taps it, or when Mohamed
replies or takes over from the inbox. This mirrors the confirmation principle in
`TOOLS.md` and prevents an accidental flood of tickets from one bad Luna day.

The always-available control in the widget header does the same thing without
waiting for a trigger.

### 3.3 Luna goes silent — enforced at claim time

`CreateChatMessage` sets `agent_eligible_at` only when `handoff_state` is not
`requested` or `active`. **That write-time gate is a fast path, not the guard.**

The guard is in the claim: `CreateOrRecoverAgentTurn::claimPendingRange`
re-reads `handoff_state` under the conversation lock it already holds and
returns `AgentTurnClaim::idle()` when the state is `requested` or `active`.

Without this the design fails its own goal. `agent_eligible_at` is stamped at
insert and is immutable, `PendingAgentMessages` never looks at handoff state, and
the widget starts turns on backlog resume when a conversation loads
(`handleTerminalTurnBacklog` in `resources/js/hooks/use-chat.ts`). So: a customer
sends a message and closes the widget before the quiet window elapses; Mohamed
takes over and replies; the customer reopens the widget; the backlog resume
claims that pre-takeover message and **Luna answers on top of the human,
mid-ticket**. The claim-time re-check closes that window at the cost of one
query on an already-locked row.

When a ticket is resolved, `handoff_state` becomes `resolved` and the next
customer message is eligible again. The customer sees a system line: "Luna is
back to help — reply here any time."

A `waiting`/`running` turn at the moment of takeover is allowed to finish. It is
already streaming to the customer, `StreamAgentTurn` has no clean cancellation
point, and killing it would leave a partial bubble. Takeover applies from the
next claim.

### 3.4 Lock order

Staff reply, customer message, turn finalization, ticket creation, the hourly
maintenance sweep and the minute stale-turn recovery all touch the same
conversation. The accepted order is extended by exactly one step:

> **conversation → ticket → turn → run**

Every ticket mutation — including `PATCH /admin/tickets/{publicId}`, which is
addressed by ticket and would naturally invite locking the ticket first —
resolves the ticket by `public_id` **without** a lock, then opens its transaction
by locking the *conversation* row, then re-reads and locks the ticket. Without
this rule, two admins acting at once (one replying, one resolving) deadlock, and
MariaDB picks a victim during exactly the "two admins at once" moment.

No lock spans provider I/O, unchanged from Phase 2.

---

## 4. Admin inbox

### 4.1 List (`/admin/conversations`)

- The ULID column becomes `short_id` (`CHT-4F9A2C`), monospaced, copyable.
- New: ticket badge (`TKT-…`) when a live ticket exists, and an unread dot when
  `last_message_at > last_staff_message_at` on a conversation with a live ticket.
- Filters gain `ticketStatus`; the `owner` filter is removed.
- Search (`q`) matches `short_id`, `ticket_number`, or the full `public_id`,
  case-insensitively. It does not search message content — that needs a
  full-text index and is out of scope.
- Default sort: live tickets with unread customer messages first, then last
  activity descending.

### 4.2 Detail (`/admin/conversations/{publicId}`)

Existing transcript and agent-turn diagnostics stay. Added:

- **Reply composer** — 4000-character limit matching `chat.max_message_length`.
  **Replying performs take-over implicitly**, in the same transaction: it
  creates or claims the ticket, sets `assigned_admin_id`, and sets
  `handoff_state = active` before writing the staff message.

  This is not a convenience. If replying left `handoff_state = none` — and just
  typing an answer is the obvious thing to do — the customer's next message
  would be stamped eligible and Luna would reply on top of the human, with no
  ticket, no banner, and no polling, so the reply would not even be delivered
  until the customer reloaded. It also makes §6.2's "checked under the same lock
  as the reply write" coherent, because the ticket row is guaranteed to exist.
- **Take over** — the same operation without a message, for claiming a thread
  before writing. Idempotent for the same admin. Taking over a ticket already
  `active` under a *different* admin returns **409**, not a silent reassignment.
- **Internal note** — visually separated, never sent to the customer.
- **Resolve** — `status = resolved`, `resolved_at`, `handoff_state = resolved`,
  posts the "Luna is back" system line, and frees the `active_conversation_key`
  slot so the customer can open a new ticket later.

### 4.3 Routes

All inside the existing admin MFA group, registered under both the bare and
`/en` prefixes exactly like the current conversation routes.

| Method | Path | Permission |
| --- | --- | --- |
| POST | `/admin/conversations/{publicId}/reply` | `chat.reply` |
| POST | `/admin/conversations/{publicId}/note` | `chat.reply` |
| POST | `/admin/conversations/{publicId}/take-over` | `chat.reply` |
| PATCH | `/admin/tickets/{publicId}` | `chat.reply` |
| GET | `/admin/support/unread-count` | `chat.view` |

### 4.4 Auto-close and purge exemption

`MaintainChatConversations::closeInactiveConversations` closes any open
conversation idle for 24 hours. A ticket opened at 22:00 while Mohamed is asleep
would be closed by the sweep at 22:01 the next day, leaving a banner that
promises "your request reached the team" over a thread neither party can post to
— `CreateChatMessage` throws 409 on a closed conversation — and the 180-day
purge would eventually cascade-delete the ticket itself.

Conversations with a live ticket, or with `handoff_state` in
`{requested, active}`, are therefore exempt from both the auto-close sweep and
the purge. This mirrors the existing `whereDoesntHave` refusal for nonterminal
agent turns at `MaintainChatConversations.php:39-40,109-110`.

### 4.5 Audit

Every staff write emits a `StaffAuditEvent`: `chat.reply.sent`,
`chat.note.added`, `chat.ticket.opened`, `chat.ticket.assigned`,
`chat.ticket.resolved`. Metadata carries the ticket number, conversation short
id, target user id and character count — **never message bodies**. A transcript
is not audit data, and `StaffAuditEvent` already rejects secret-shaped keys.

---

## 5. Customer experience

### 5.1 Ticket banner

A pinned banner in the thread while a ticket is live:

- `requested`: "طلبك وصل للفريق — TKT-4F9A2C" / "Your request reached the team".
- `active`: shows the responder's display name.
- `resolved`: "تم حل التذكرة" plus a "Still need help?" control that opens a new
  ticket on the same conversation — which works because a resolved ticket no
  longer occupies the unique slot (§1.2).

**The copy must not imply a response time.** There is no SLA, no working-hours
promise and no queue position. "The team will reply here" is the ceiling; "we
will reply shortly" is not acceptable copy.

### 5.2 Staff bubbles

Physically on the assistant side, visually distinct from Luna (different accent,
staff avatar, name label). The customer must never mistake a person for the bot
or vice versa. `dir="auto"` as with every other bubble.

### 5.3 Chat history

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

### 5.4 Polling and state sync

While the widget is open and `handoff_state ∈ {requested, active}`, poll
`chat.conversations.show` every 5s, backing off to 15s after two minutes with no
new message, pausing on `document.hidden`, and stopping when the handoff
resolves. 5s polling is 12 requests/min, inside the existing 60/owner/min read
limit.

**`handoff_state` is returned in the message-send response and in terminal
stream payloads, not only on conversation load.** Otherwise a takeover initiated
from the inbox while the widget is already open is invisible: the client never
learns the state changed, never starts polling, and the staff reply does not
render until the customer reloads.

---

## 6. Notifications

### 6.1 Admin

`GET /admin/support/unread-count` returns the number of live tickets with a
customer message newer than `last_staff_message_at`. The admin shell polls it
every 30s and renders a badge on the Conversations nav entry, plus an optional
chime reusing the existing `chat-sound` Web Audio helper and honouring the same
mute preference. No sound on first load — only on an increase.

### 6.2 Customer email — synchronous, because there is no queue worker

When a staff reply is written and the customer has not been active in the thread
for five minutes, send `SupportReplyNotification` (bilingual, keyed on the
conversation locale). The email says a reply is waiting and links to the store —
**it contains no transcript content**, because chat content in email is
un-revocable and outside the retention model.

**It must not be queued.** `routes/console.php` schedules five commands and none
of them is `queue:work` or `queue:run`, and there is no permanent worker on
Hostinger. A `ShouldQueue` notification on the database queue driver would sit
in `jobs` forever and the only offline delivery channel in this design would
silently never fire. The existing notifications it mirrors
(`PendingEmailChangeNotification`) are plain synchronous `Notification`s.

Therefore: set `last_notified_at` under the same lock as the reply write, commit
the transaction, then send **after commit**, wrapped in try/catch with logging.
A mail outage must not 500 the staff reply, and writing the throttle stamp first
means a send failure costs one missed email rather than a duplicate storm. At
most one email per conversation per hour.

---

## 7. Authorization and safety

- `AdminPermission::ChatReply = 'chat.reply'`. `AdminAccess::STAFF` is **not**
  extended, so only `UserRole::Admin` gets it today; the enum already returns
  `true` for Admin, so this is enforced by existing code and a support role
  later is a one-line change.
- `chat.view` continues to gate reading.
- Ticket and reply endpoints resolve the conversation by owner scope and
  re-check permission server-side on every call. A public ID is never
  authorization.
- `guest_key` is still never serialised to any client payload.
- Staff messages skip the agent sensitive-content guard (a human wrote them) but
  are still length-validated and stored as plain text; the existing XSS-safe
  rendering path is reused with no `dangerouslySetInnerHTML`.
- Rate limits: staff reply 60/min/actor; ticket creation 5/min/owner and
  20/min/IP.
- **Account deletion cascades away a live ticket.** `users` → conversation →
  ticket all cascade, so deleting an account mid-handoff removes the thread
  Mohamed may be drafting a reply to; his next action 404s. This is consistent
  with conversation-cascaded retention and is accepted, but it is recorded in
  `DECISIONS.md` rather than left as a surprise.

---

## 8. Testing

**Feature (Pest):** escalation trigger matrix including the recomputed
two-empty-selections rule, the `knowledge_max_topics = 0` skip, the cron-recovery
exclusion, and the Arabic normalisation cases; **Luna does not answer after
takeover, exercised through the backlog-resume path, not just the write path**;
guest 403 on ticket create; guest purge at 48h for an open thread, and refusal
while an agent turn is nonterminal; auto-close and purge exemption for a live
ticket; one-live-ticket-per-conversation under concurrent creation; **resolve
then reopen succeeds**; staff message leaves `reply_to_message_id` NULL and an
in-flight turn still finalizes; internal note absent from the customer payload;
`Staff` role denied `chat.reply`; second admin take-over returns 409; login
claim does not supersede a ticketed conversation; audit events carry no message
body; email throttle and mailer-failure containment.

**Vitest:** reply composer, ticket banner states, staff bubble rendering,
history list (empty and guest cases), polling lifecycle
(start/backoff/pause-on-hidden/stop-on-resolve), state re-sync from a send
response, and 404-on-send starting a fresh conversation.

**Playwright:** admin replies, customer widget receives it via polling; the 44px
touch-target floor on every new control at all widths. That suite measures
**every** admin control, so no `md:min-h-9` / `md:size-9` shrink override may be
introduced, and nav-link counts must be updated if a nav entry is added.

**Gate:** `npm run ci:check` plus the Playwright suite, run in full. Partial runs
have historically hidden real failures in this repo.

---

## 9. Documentation

Fix the drift found on 2026-08-24 before layering new docs on top:

1. `README.md` — header said Phase 2 "inactive and not accepted" while
   `STATUS.md` recorded acceptance on 08-23; its table listed Support inbox and
   Retrieval as "Planned" although both are shipped.
2. `STATUS.md` — the "Exact next gate" section said Phase 2 re-entry was
   blocked, directly below the acceptance record saying it passed. The "Current
   production mode" row still described the 08-22 owner exception.
3. `PRODUCT`, `ARCHITECTURE`, `SECURITY`, `UX`, `OPERATIONS`, `AUDIT`, `PHASES`,
   `AGENT-RUNTIME`, `EVALS` — all stamped 08-22 with "inactive/not accepted"
   lifecycles predating both acceptance and Phase 3.
4. `ARCHITECTURE.md` omitted the admin inbox routes, `ServicePriceController`,
   and the cards/cart surface.
5. `TOOLS.md` said no assistant tool is live; service cards, the price lookup and
   the in-chat cart offer exist. It must state precisely what they are —
   server-derived from the customer's message, never model-authored, carrying no
   model-chosen price — instead of being false.

Then record this work: `ADMIN-INBOX.md` becomes the canonical operator document;
`PRODUCT.md` removes human handoff from its exclusion list; `ARCHITECTURE.md`,
`SECURITY.md`, `UX.md`, `OPERATIONS.md` and `PHASES.md` gain the new routes,
tables, permission, retention window, lock order and polling behaviour; and
`DECISIONS.md` records the eight decisions above, the six v1 cuts, the accepted
48-hour guest purge consequence, and the account-deletion consequence.

---

## 10. Implementation lanes

Four lanes, sequenced so each starts from a merged base rather than a guess:

- **Lane A — schema and policy.** Migrations, `ChatNumber`/`TicketNumber`,
  `SupportTicket` model, enum additions, `chat.reply` permission, guest purge
  window and open-thread purge, auto-close/purge exemption, admin list/detail
  guest exclusion, short-id column and backfill. Merges first; every other lane
  depends on it.
- **Lane B — escalation and Luna silence.** `EvaluateHandoffOffer` and its two
  call sites, the bilingual matcher, `handoff_state` transitions, the
  **claim-time re-check in `CreateOrRecoverAgentTurn`**, ticket create/resolve
  actions, the lock order, `POST /chat/tickets`, and the login-claim fix.
- **Lane C — admin inbox UI.** Reply composer with implicit take-over, take
  over, notes, resolve, list columns and filters, audit events, 409 on
  cross-admin takeover.
- **Lane D — customer UI, history, notifications.** Banner, staff bubbles,
  history list and `GET /chat/conversations`, polling and state re-sync,
  404-on-send recovery, unread badge and chime, synchronous
  `SupportReplyNotification`, plus the whole of section 9.

Lanes B, C and D are independent of each other once A lands. Lane D owns every
documentation file to avoid conflicts.
