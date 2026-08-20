# Security

**Lifecycle:** Implemented controls; one session decision remains open
**Verified:** 2026-08-20

## Ownership boundary

Authenticated chat ownership is the user ID. For guests,
`ResolveChatOwner` creates a random 32-byte token and places its hex encoding
in the Laravel session under `arabut_chat_guest_token`; `chat_conversations`
stores only `hash_hmac('sha256', token, APP_KEY)`. Current and
`APP_PREVIOUS_KEYS` HMACs permit transactional guest-key rekeying. On login,
`ClaimGuestChatConversations` moves matching guest conversations to the user
inside a transaction and clears the raw session token only after a successful
claim.

Production evidence shows `SESSION_DRIVER=database` and `SESSION_ENCRYPT=true`.
Thus the production database session payload is encrypted. This does not change
the code fact that the raw guest token lives in the Laravel session, and does
not prove repository or nonproduction configuration. `AI-B09` remains open for
an explicit session-boundary decision; do not change session configuration
without approval because active sessions can be invalidated.

## Database and traffic controls

- Database checks and model validation require exactly one of `user_id` and
  `guest_key`; owner-scoped queries hide cross-owner public IDs as 404.
- The migration's generated/indexed `active_owner_key` permits one open owner
  row. Production verified `ACTIVE_OWNER_DUPLICATE_GROUPS=0` and `LOCK_TABLES_OK`.
- Rate limits per minute are owner/IP: conversations and restart 10/30,
  messages 30/60, reads 60/120.
- `NoStore`, disabled responses, controller errors, and `ChatErrorResponse`
  set no-store cache control on chat responses.

## Error contract

`ChatErrorResponse` normalizes framework/exception responses only. Its 409
`conversation_closed`, 422 `validation_error`, 429 `rate_limited`, and 500
`chat_unavailable` JSON responses have `error.code`, localized
`error.message`, and an empty `error.details` object. The 429 response also
copies safe `Retry-After` and `X-RateLimit-*` headers. Each is
`Cache-Control: no-store, private`.

The following responses are built directly by middleware/controllers and do
**not** include `error.details`; their fixed message is part of the current
contract:

| HTTP status | Error code               | Message/source                                                                                                        |
| ----------- | ------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| 404         | `chat_disabled`          | `Chat is currently disabled.` from `EnsureChatEnabled`.                                                               |
| 404         | `conversation_not_found` | `The requested conversation was not found.` from either chat controller.                                              |
| 409         | `conversation_closed`    | Localized `chat.conversation_closed` from `ChatMessageController` when its pre-write status check finds a closed row. |
| 422         | `invalid_cursor`         | `The provided pagination cursor is invalid for this conversation.` from `ChatConversationController`.                 |

These direct responses also set `Cache-Control: no-store, private`. Input
limits are a locale string of 10 characters, history limits 1–100, content up
to 4000 characters after a nonblank check, and `client_message_id` up to 64
characters.

Audit resolution evidence is in [AUDIT.md](AUDIT.md). Future model/tool
boundaries remain planned and require separate owner approval.
