# Security

**Lifecycle:** Local implementation verified; production configuration pending

**Verified:** 2026-08-20

## Ownership boundary

- Authenticated conversations are scoped to one `user_id`; guests are scoped to an HMAC-SHA-256 `guest_key`. Both chat controllers apply `forOwner()` before looking up a public ID, so a public ID is not an authorization credential.
- `ResolveChatOwner` generates a random 32-byte guest token and keeps the raw token in Laravel session storage. Chat tables store only the HMAC derived from `APP_KEY`; `APP_PREVIOUS_KEYS` candidates allow transactional rekeying.
- At login, guest conversations are claimed in a transaction. The selected active guest conversation keeps its public ID; any conflicting open user or guest conversations close with `superseded_by_login_claim` before ownership changes.
- The schema and model guard require exactly one owner, and the lifecycle migration adds a unique open-owner key. Direct database writes are covered by the local invariant regression in `tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php`.

## Request and persistence controls

- Owner/IP limiters are 10/30 per minute for acquisition or restart, 30/60 for messages, and 60/120 for reads.
- Private chat responses use no-store cache control. Validation, throttling, and unexpected errors use a localized JSON `error` envelope without stack traces or owner/session data.
- `(conversation_id, client_message_id)` prevents duplicate customer messages; `reply_to_message_id` is unique, preventing more than one linked reply to a customer message. The duplicate-key recovery path returns the canonical stored message/reply pair.
- `chat:maintain-conversations` deletes only closed rows beyond the configured retention threshold. Existing cascading foreign keys delete their messages.

## Security evidence state

Local source and focused tests close the prior code-level findings `AI-B03`, `AI-B04`, `AI-B05`, `AI-B06`, and `AI-B08`. The MariaDB migration and concurrency checks are defined in the repository CI workflow but have not been observed in CI for this handoff.

`AI-B09` remains open. Repository defaults are `SESSION_DRIVER=database` and `SESSION_ENCRYPT=false`, while the raw guest token remains in the Laravel session. Production session-driver and encryption values were not inspected. Inspecting or changing them is an external, security-sensitive approval checkpoint; session encryption must not be enabled silently because it may invalidate active sessions.

## Future model and tool boundary

**Section lifecycle:** Planned

No provider credential, order data, payment secret, session token, or customer credential may enter a future model context. Any later data access requires an approved owner-authorized, least-privilege tool boundary with audit logging and confirmation rules in [TOOLS.md](TOOLS.md).
