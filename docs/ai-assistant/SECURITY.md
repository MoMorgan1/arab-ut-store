# Security

**Lifecycle:** Phase 1 and Phase 2 controls implemented; Phase 2 inactive and
one session decision remains open
**Verified:** 2026-08-22

## Ownership boundary

Authenticated chat ownership is the user ID. For guests,
`ResolveChatOwner` creates a random 32-byte token and places its hex encoding
in the Laravel session under `arabut_chat_guest_token`; `chat_conversations`
stores only `hash_hmac('sha256', token, APP_KEY)`. Current and
`APP_PREVIOUS_KEYS` HMACs permit transactional guest-key rekeying. Rotation
locks every candidate conversation in ascending database-ID order, preserves a
candidate-owned pointed open row (otherwise the newest activity/ID winner),
closes other open candidates as `invariant_upgrade_duplicate`, and only then
rekeys all matching history to the current HMAC. On login,
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
  messages 30/60, reads 60/120, and agent start/retry 6/20.
- `NoStore`, disabled responses, controller errors, and `ChatErrorResponse`
  set no-store cache control on chat responses.

## Agent and provider controls

- The server selects assistant mode. A customer message receives immutable
  agent eligibility only on its original insertion; a public identifier or
  frontend flag cannot grant it.
- Agent routes scope conversation and turn by the resolved owner. MariaDB and
  SQLite enforce one nonterminal turn per conversation.
- Conversation/turn/run locks are ordered, and no database lock spans provider
  I/O.
- Current claimed content is checked before lazy provider resolution and run
  creation. A detected range is blocked; matched text is not copied into run
  records or logs.
- Prior context is completed-agent-only. Sensitive prior messages are omitted
  rather than included or used to block a harmless current range.
- The provider safety identifier is an in-memory HMAC-SHA256 of owner scope
  under `APP_KEY`, never a raw user, guest token, email, or public ID.
- `agent_runs` stores operational metadata and usage, not prompts, response
  text, provider payloads, owner identifiers, safety identifiers, or API keys.
- The OpenAI request uses `store: false`. This does not prove Zero Data
  Retention or remove default provider abuse-monitoring policy.
- The source key is managed through the shared production environment. Laravel
  `config:cache` also materializes resolved configuration in retained release
  caches; those files require the same secret-handling boundary.

## Error contract

`ChatErrorResponse::render()` normalizes framework/exception responses. Its 409
`conversation_closed`, 422 `validation_error`, 429 `rate_limited`, and 500
`chat_unavailable` JSON responses have `error.code`, localized `error.message`,
and an empty `error.details` object. Agent controllers also call its direct 404
`agent_unavailable` helper, which has the same shape. The 429 response copies
safe `Retry-After` and `X-RateLimit-*` headers. Each is `Cache-Control:
no-store, private`.

The following responses are built directly by middleware/controllers and do
**not** include `error.details`; their fixed message is part of the current
contract:

| HTTP status | Error code               | Message/source                                                                                                                    |
| ----------- | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| 404         | `chat_disabled`          | `Chat is currently disabled.` from `EnsureChatEnabled`.                                                                           |
| 404         | `conversation_not_found` | `The requested conversation was not found.` from `ChatConversationController`, `ChatMessageController`, or `AgentTurnController`. |
| 404         | `turn_not_found`         | `The requested agent turn was not found.` from `AgentTurnController`.                                                             |
| 409         | `conversation_closed`    | Localized `chat.conversation_closed` from `ChatMessageController` when its pre-write status check finds a closed row.             |
| 409         | `turn_not_retryable`     | `This agent turn cannot be retried.` from `AgentTurnController`.                                                                  |
| 422         | `invalid_cursor`         | `The provided pagination cursor is invalid for this conversation.` from `ChatConversationController`.                             |

These direct responses also set `Cache-Control: no-store, private`. Input
limits are a locale string of 10 characters, history limits 1–100, content up
to 4000 characters after a nonblank check, and `client_message_id` up to 64
characters.

Agent failures use safe enumerated codes, fixed/localized customer messages,
typed retry policy, and owner-scoped terminal state. The current server and
browser disagree on the nested shape of `response.failed`; this is an open
re-entry blocker documented in [AGENT-RUNTIME.md](AGENT-RUNTIME.md).

The 2026-08-22 public evaluation observed no secret echo, HTML, fabricated live
fact, implied live action, incomplete terminal result, or missing usage/cost
field. The gate still failed mixed-language and first-visible-content
requirements; that is not reclassified as a secret/privacy failure. AI was
disabled and the Phase 1 demo restored. See [the sanitized evidence](evidence/2026-08-22-phase-2-luna-public-eval.md).

Audit resolution evidence is in [AUDIT.md](AUDIT.md). RAG, tools, live
commerce/account data, and the admin inbox remain unimplemented and require
separate owner approval. Operational containment is in
[OPERATIONS.md](OPERATIONS.md).
