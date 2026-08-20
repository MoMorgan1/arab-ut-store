# Phase 1 Completion audit

**Lifecycle:** Repository fixes implemented; deployment, scheduler evidence,
and owner acceptance pending
**Verified:** 2026-08-21

## Release evidence

The verified Phase 1 application SHA observed/deployed on 2026-08-20 was
`e7f230d2ea01dc456aef1a51035f4d88f39542e2`.
[tests 32410960971](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32410960971)
passed CI, MariaDB lifecycle, seven Chromium checks, and packaging; [deploy
32411415481](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32411415481)
activated that Phase 1 release. The earlier backend checkpoint was [tests
32398600493](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32398600493)
and [deploy 32399022501](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32399022501)
for `1fd83d37b990833cd451d7c3a7b48314976a9f6f`.

Read-only production evidence on 2026-08-20: `/up` returned 200, the
release/current path matched that Phase 1 SHA, four chat routes were registered,
and checks returned
`CHAT_SCHEMA_OK`, `ACTIVE_OWNER_DUPLICATE_GROUPS=0`, and `LOCK_TABLES_OK`.

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

No P0 or P1 finding is open. That is repository/release evidence, not a claim
that this final-review wave is deployed or Phase 1 is accepted. Integration and
deployment, recurring Hostinger scheduler evidence, and Mohamed's manual
checklist in [STATUS.md](STATUS.md) are still required; no Phase 2 work starts
before those gates are satisfied and acceptance is recorded.
