# Security

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Ownership and authorization

- An authenticated conversation is owned by the authenticated user ID.
- A guest receives a random 32-byte token stored only in the Laravel session.
  The database stores its HMAC-SHA-256 derived with `APP_KEY`, not the bearer
  token.
- Keys listed through `APP_PREVIOUS_KEYS` are accepted for rotation. Matching
  guest conversations are transactionally rekeyed to the current application
  key.
- After successful login, conversations matching the current or previous guest
  HMACs are transactionally claimed by the user. The raw guest token is cleared
  only after the claim succeeds; claim failure logs the user back out.
- A conversation public ID is not authorization. Both read and write
  controllers apply the current owner scope before matching `public_id`, and a
  cross-owner request receives the same not-found boundary.

## Input, traffic, and storage controls

- Conversation creation accepts an optional locale string of at most 10
  characters and a history limit from 1 through 100. Only `ar` and `en` affect
  the seeded conversation locale; other values fall back to the application
  locale.
- Message creation requires non-blank string content of at most 4000 characters
  and a string `client_message_id` of at most 64 characters.
- Rate limits are owner- and IP-scoped per minute: conversation creation allows
  10 per owner and 30 per IP, message creation allows 30 per owner and 60 per
  IP, and reads allow 60 per owner and 120 per IP.
- Chat routes use `NoStore` response middleware, which enforces a
  `Cache-Control: no-store` directive on downstream responses. Disabled and
  chat exception responses independently set a no-store directive.
- Database constraints enforce unique public IDs, exactly one conversation
  owner, cascading message ownership, and unique per-conversation client
  message IDs.

## Audit state

The canonical [Phase 1 audit](AUDIT.md) found no unresolved P0 production
blocker or P1 security/data-integrity blocker and set the release gate to
Proceed.

| Audit ID | Severity | Remaining risk                                                                                                                       |
| -------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `AI-B03` | P2       | Concurrent first requests can create multiple owner-valid open conversations.                                                        |
| `AI-B04` | P2       | Concurrent duplicate client IDs can produce a transient failure, and lost-response retry does not return the stored demo reply.      |
| `AI-B05` | P2       | Conversation creation and onboarding-message creation are not one transaction.                                                       |
| `AI-B06` | P2       | MariaDB migration lifecycle is CI-covered, but direct-query owner-invariant coverage remains absent.                                 |
| `AI-B08` | P2       | Framework validation, throttle, and server errors do not share one chat error envelope; focused 429/500 no-store coverage is absent. |
| `AI-F04` | P3       | Scroll geometry and unread behavior lack precise automated assertions.                                                               |
| `AI-F06` | P2       | iOS keyboard and safe-area behavior remain a manual-browser risk.                                                                    |
| `AI-F07` | P2       | The composer lacks an explicit accessible name, and some secondary controls do not guarantee a 44px target.                          |

`AI-F08` is mitigated by the Chromium application smoke in release
`fdba471af2fef38905581a309bf8b0e9119ab41b`; Safari and full visual/device
acceptance remain outside that automated smoke.

## Future model and tool boundary

**Section lifecycle:** Planned

Order credentials, payment secrets, production keys, session tokens, and other
sensitive data must never be exposed to a future model. Any future access to
order or account data requires an approved, owner-authorized tool boundary with
least-privilege output, audit logging, and confirmation rules documented in
[TOOLS.md](TOOLS.md).
