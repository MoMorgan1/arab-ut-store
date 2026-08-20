# Phase 1 chat audit

**Lifecycle:** Local verification record; production gate pending

**Verified:** 2026-08-20

## Scope and verdict

This record verifies the current local implementation against the Phase 1 Completion design. It is not a CI, deployment, or production audit. The local code closes the former P2 implementation gaps below; production-only evidence remains open.

| ID       | Local disposition      | Verified implementation evidence                                                                                                                                                                    | Remaining boundary                                                                                          |
| -------- | ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `AI-B03` | Locally closed         | `active_owner_key` is unique; acquisition re-reads the canonical winner after an active-owner unique violation. `ChatConversationConcurrencyTest` covers authenticated and guest first acquisition. | MariaDB CI is pending.                                                                                      |
| `AI-B04` | Locally closed         | Duplicate client IDs re-read the stored message and linked reply; `reply_to_message_id` is unique. The concurrent-message regression expects one canonical customer/reply pair.                     | MariaDB CI is pending.                                                                                      |
| `AI-B05` | Locally closed         | Conversation and onboarding creation run in a transaction; restart also rolls back close/pointer changes on replacement failure.                                                                    | Deployment evidence pending.                                                                                |
| `AI-B06` | Locally closed         | The lifecycle upgrade regression uses direct writes for one-open-owner and one-reply invariants, and includes a MariaDB down/up/remigration test.                                                   | GitHub MariaDB result pending.                                                                              |
| `AI-B08` | Locally closed         | `ChatErrorResponse` maps validation, throttling, and unexpected failures to private no-store envelopes; `ChatCacheHeaderTest` covers the status/cache contract.                                     | Deployed route behavior pending.                                                                            |
| `AI-B09` | Open                   | Chat-table ownership remains HMAC-only.                                                                                                                                                             | Production session driver/encryption is unverified; approved inspection and an owner decision are required. |
| `AI-F04` | Open                   | History and scroll behavior have component coverage.                                                                                                                                                | Precise scroll geometry/unread automated assertions remain incomplete.                                      |
| `AI-F06` | Locally closed         | Account launcher/sheet layering and composer safe-area code are exercised by the local authenticated Chromium fixture.                                                                              | Real iPhone/Safari acceptance remains Mohamed's deployed manual gate.                                       |
| `AI-F07` | Locally closed         | Composer has a localized accessible name; relevant controls use 44px minimum targets; modal focus behavior is covered in component/browser tests.                                                   | Deployed assistive-technology acceptance remains manual.                                                    |
| `AI-F08` | Limited local evidence | Chromium browser smoke checks the local application fixture.                                                                                                                                        | It does not prove cross-browser or production behavior.                                                     |

## Required external evidence before release completion

1. Observe the GitHub `ci` and `mariadb-schema` results for the release SHA.
2. Through the approved secure path, inspect only the production session driver and encryption setting; do not expose session records or the environment file.
3. After an authorized deployment, perform read-only route health checks for `/`, `/en`, `/login`, `/en/login`, `/cart`, and an authenticated account route without creating a production synthetic account.
4. Obtain Mohamed's explicit manual acceptance of the deployed Phase 1 build.

Until those steps complete, the phase is not deployed or production-implemented.
