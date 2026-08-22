# AI assistant audit

**Lifecycle:** Phase 1 accepted; Phase 2 public evaluation failed and contained
**Verified:** 2026-08-22

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
Mohamed accepted the deployed Phase 1 release on 2026-08-21 after completing
the real-account and physical iPhone/Safari checks.

## Resolved findings

| ID     | Resolution evidence                                                                                                                                                                    |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AI-B03 | The active-owner generated key/index and acquisition retry recover concurrent first acquisition; MariaDB concurrent user/guest tests prove one open row.                               |
| AI-B05 | Conversation and onboarding creation are one transaction; forced onboarding failure leaves no conversation.                                                                            |
| AI-B06 | SQLite/MariaDB migration tests cover upgrade/down paths; CI runs MariaDB fresh/rollback/migrate, and production confirms schema, zero active duplicates, and `LOCK TABLES` permission. |
| AI-B08 | `ChatErrorResponse` normalizes 409/422/429/500 responses with no-store cache control; focused tests cover validation, throttling, server error, and conflict.                          |
| AI-F07 | Composer labeling and 44px interactive controls are implemented and covered by component/browser checks.                                                                               |
| AI-F06 | Chromium's emulated safe-area evidence was supplemented by Mohamed's accepted physical iPhone/Safari keyboard, safe-area, home-indicator, touch, and sheet review on 2026-08-21.       |

## Remaining findings and gates

| ID     | Severity | State                                                                                                                                                                                                                                                                                                                                                   |
| ------ | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AI-B04 | P2       | Narrowed. Canonical replay includes explicit `reply_to_message_id` rows created after the lifecycle migration. Pre-migration unlinked assistant rows are excluded because concurrent historical inserts make timestamp/order association unprovable; regression coverage returns the legacy customer with `demoReply: null` and creates no association. |
| AI-B09 | P2       | Open decision. Production database sessions are encrypted, but raw guest-token storage remains a Laravel-session boundary and no configuration change is approved.                                                                                                                                                                                      |
| AI-B10 | P2       | Open implementation drift. Reopen and retention use the approved legacy activity fallback, but auto-close queries `last_message_at` directly; a legacy open row with a null value does not auto-close.                                                                                                                                                  |
| AI-F04 | P3       | Open test-precision item: scroll geometry and unread-state assertions remain incomplete.                                                                                                                                                                                                                                                                |

No P0 or P1 Phase 1 code finding is open. Phase 1 is accepted; `AI-B04`,
`AI-B09`, `AI-B10`, and `AI-F04` retain the exact limited states above and are
not erased by owner acceptance.

## Phase 2 runtime and public evaluation

The provider-neutral turn runtime, fake provider, direct OpenAI Responses
adapter, durable turns/runs, usage and cost accounting, stale recovery,
bilingual prompt, browser streaming/recovery, and migration/route-cache fixes
are deployed in release `e13ee8bde25263a262788177d0ce78fb4f46f37f`.

Verification for that release:

- `composer test`: 1,483 tests and 35,476 assertions passed;
- [tests 32578736891](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32578736891)
  passed;
- [deploy 32578995534](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32578995534)
  passed;
- production `/up`, all seven chat routes, cached MFA route middleware, and the
  minute stale-turn schedule were verified read-only;
- a live guest Luna canary completed incrementally with no browser-console error.

The mandatory public batch ran on 2026-08-22 under label
`phase2-luna-eval-20260822T153457Z`. Its exact half-open UTC interval contained
16 distinct customer boundaries, turns, and Luna runs, with one attempt and a
durable completion for every case. Usage/cost evidence was complete. It failed:

- mixed-language quality: 2/4 rather than the required 3/4;
- safety-critical contracts: 7/8 rather than 8/8 (a contract failure without an
  unsafe live-data/action claim);
- maximum first visible content: 10.663 seconds rather than no more than 8.

Arabic, English, and boundary groups scored 4/4. Maximum terminal time was
11.496 seconds. No secret echo, HTML, fabricated live fact, implied action,
incomplete run, browser error, or missing usage/cost field was observed.

Mohamed selected disable and remediate. Production configuration was recached
with AI disabled, rollout `disabled`, an empty provider selector, and the demo
enabled. A public probe at `2026-08-22T16:10:39.260Z` received the deterministic
demo, opened no agent stream, produced no console error, and created no new
agent turn. See [the sanitized evidence](evidence/2026-08-22-phase-2-luna-public-eval.md).

The versioned eval fixture contains exactly 16 unique cases, four per group and
eight safety-critical cases. Its contract test passed with 182 assertions.

Post-batch source review also found five re-entry items. Three were fixed on
2026-08-22 after Mohamed approved the remediation order, with AI still disabled:

- fixed: the server nests `response.failed` code/message under `error`; the
  browser parser now reads that shape (the flat form stays accepted);
- fixed: `agent:inspect-streaming-http` now resolves the adapter's
  `OpenAiStreamHandlerStack` and labels the handler it really builds, passing
  only for Guzzle's `StreamHandler`;
- fixed: the four skipped frontend tests run and pass; every root cause was in
  the tests (Testing Library `waitFor` under Vitest fake timers, quiet-timer
  arithmetic, a stream fixture that errored inside `start()` and dropped
  `turn.created`, and microtask warm-up order dependence).

- fixed: `connect_timeout_seconds` is now Guzzle `connect_timeout`,
  `stream_read_timeout_seconds` stays `read_timeout`, and
  `request_timeout_seconds` is the total `timeout`; each is capped by the
  remaining turn deadline. Previously the connect value was the total timeout
  and Guzzle's default 10s connect timeout applied.

One remains open:

- the accepted nearby label/value guard boundary is not implemented as a
  proximity rule; current source pairs any qualifying label and value in the
  same message, creating a broader false-positive boundary.

These findings do not change the measured batch. The open items still require
owner review before Luna re-entry.

Future audit rounds should append new evidence rather than rewriting either the
Phase 1 acceptance or this failed Phase 2 evaluation record.
