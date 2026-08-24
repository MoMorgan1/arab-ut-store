# Security

**Lifecycle:** Phase 1-3 controls and Support Handoff security controls implemented and active
**Verified:** 2026-08-24

## Ownership boundary

Authenticated chat ownership is the user ID. For guests,
`ResolveChatOwner` creates a random 32-byte token and places its hex encoding
in the Laravel session under `arabut_chat_guest_token`; `chat_conversations`
stores only `hash_hmac('sha256', token, APP_KEY)`. Current and
`APP_PREVIOUS_KEYS` HMACs permit transactional guest-key rekeying.

On login, `ClaimGuestChatConversations` moves matching guest conversations to the user
inside a transaction and clears the raw session token only after a successful claim.

## Support Handoff & Ticketing Security Controls

- **Internal Note Confidentiality:** Messages with `message_type = 'internal_note'` are filtered out of all customer-facing presenters (`ChatPresenter::loadBoundedMessages`). They are visible only in the authenticated admin inbox.
- **Data Leak Prevention:** Presenters never expose `guest_key`, `user_id`, or `assigned_admin_id`.
- **Admin Permission Boundary:** All admin conversation routes, conversation details, and `GET /admin/support/unread-count` are strictly protected by `can:chat.view` permission inside the admin MFA group.
- **Deadlock-Free Locking Order:** To ensure transactional safety across concurrent customer turns and staff actions, database locks strictly follow: `conversation -> ticket -> turn -> run`.
- **Customer Email Notifications:** `SupportReplyNotification` is sent synchronously via email to away customers without embedding conversation transcripts, order secrets, or sensitive customer notes.
- **Copy Invariants:** All customer-facing system and banner strings forbid time promises ("soon", "shortly", "within", "قريبًا", "خلال") to eliminate misleading customer commitments.

## Database and traffic controls

- Database checks and model validation require exactly one of `user_id` and
  `guest_key`; owner-scoped queries hide cross-owner public IDs as 404.
- Rate limits per minute are owner/IP: conversations and restart 10/30,
  messages 30/60, reads 60/120, and agent start/retry 6/20.
- `NoStore`, disabled responses, controller errors, and `ChatErrorResponse`
  set no-store cache control on chat responses.

## Agent and provider controls

- The server selects assistant mode. A customer message receives immutable
  agent eligibility only on its original insertion.
- Agent routes scope conversation and turn by the resolved owner. MariaDB and
  SQLite enforce one nonterminal turn per conversation.
- Current claimed content is checked before lazy provider resolution and run
  creation. A detected range is blocked; matched text is not copied into run
  records or logs.
- The provider safety identifier is an in-memory HMAC-SHA256 of owner scope
  under `APP_KEY`, never a raw user, guest token, email, or public ID.
- `agent_runs` stores operational metadata and usage, not prompts, response
  text, provider payloads, owner identifiers, safety identifiers, or API keys.
- The OpenAI request uses `store: false`.

## Error contract

`ChatErrorResponse::render()` normalizes framework/exception responses. Its 409
`conversation_closed`, 422 `validation_error`, 429 `rate_limited`, and 500
`chat_unavailable` JSON responses have `error.code`, localized `error.message`,
and an empty `error.details` object.
