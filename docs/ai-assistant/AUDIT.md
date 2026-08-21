# Phase 1 Completion audit

**Lifecycle:** Repository fixes and scheduler evidence deployed; owner
acceptance pending
**Verified:** 2026-08-21

## Release evidence

The verified final Phase 1 application SHA deployed on 2026-08-21 is
`d77385a44e7ac1413aab419f79d38fc2040be650`.
[tests 32429880313](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32429880313)
passed CI, MariaDB lifecycle/concurrency, seven Chromium checks, and packaging;
[deploy 32430144972](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32430144972)
activated that release. The earlier backend checkpoint was [tests
32398600493](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32398600493)
and [deploy 32399022501](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32399022501)
for `1fd83d37b990833cd451d7c3a7b48314976a9f6f`.

Read-only production evidence on 2026-08-21: the active release matched the
final SHA; four chat routes, the minute publisher, and hourly chat maintenance
event were registered. Owner-provided hPanel evidence shows the exact
`schedule:run` command at `* * * * *` and successful minute-job output.

## Resolved findings

| ID     | Resolution evidence                                                                                                                                                                    |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AI-B03 | The active-owner generated key/index and acquisition retry recover concurrent first acquisition; MariaDB concurrent user/guest tests prove one open row.                               |
| AI-B05 | Conversation and onboarding creation are one transaction; forced onboarding failure leaves no conversation.                                                                            |
| AI-B06 | SQLite/MariaDB migration tests cover upgrade/down paths; CI runs MariaDB fresh/rollback/migrate, and production confirms schema, zero active duplicates, and `LOCK TABLES` permission. |
| AI-B08 | `ChatErrorResponse` normalizes 409/422/429/500 responses with no-store cache control; focused tests cover validation, throttling, server error, and conflict.                          |
| AI-F07 | Composer labeling and 44px interactive controls are implemented and covered by component/browser checks.                                                                               |

## Remaining findings and gates

| ID     | Severity | State                                                                                                                                                                                                                                                                                                                                                   |
| ------ | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AI-B04 | P2       | Narrowed. Canonical replay includes explicit `reply_to_message_id` rows created after the lifecycle migration. Pre-migration unlinked assistant rows are excluded because concurrent historical inserts make timestamp/order association unprovable; regression coverage returns the legacy customer with `demoReply: null` and creates no association. |
| AI-B09 | P2       | Open decision. Production database sessions are encrypted, but raw guest-token storage remains a Laravel-session boundary and no configuration change is approved.                                                                                                                                                                                      |
| AI-F06 | P2       | Narrowed. Chromium checks account safe-area geometry; real iPhone/Safari keyboard and safe-area acceptance is Mohamed's pending gate.                                                                                                                                                                                                                   |
| AI-F04 | P3       | Open test-precision item: scroll geometry and unread-state assertions remain incomplete.                                                                                                                                                                                                                                                                |

No P0 or P1 code finding is open. That is repository/release evidence, not a
claim that Phase 1 is accepted. Mohamed's manual checklist in
[STATUS.md](STATUS.md) is still required; no Phase 2 work starts before owner
acceptance is recorded.
