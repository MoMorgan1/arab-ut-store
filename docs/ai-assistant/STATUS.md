# Live status

**Lifecycle:** Phase 1 accepted; Phase 2 implemented, deployed, and re-enabled
in production by owner decision on 2026-08-22 while remediation continues; not
accepted (the mandatory public Luna evaluation failed and has not been re-run)
**Verified:** 2026-08-22

## Release snapshot

| Item                                                       | Evidence                                                                                                                                                                                                                                                                                                                                                  |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Verified final Phase 1 application SHA deployed 2026-08-21 | `d77385a44e7ac1413aab419f79d38fc2040be650`                                                                                                                                                                                                                                                                                                                |
| Backend/MariaDB release checkpoint                         | [tests 32398600493](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32398600493) and [deploy 32399022501](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32399022501) passed for `1fd83d37b990833cd451d7c3a7b48314976a9f6f`.                                                                                                             |
| UI/account release checkpoint                              | [tests 32410960971](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32410960971) and [deploy 32411415481](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32411415481) passed for `e7f230d2ea01dc456aef1a51035f4d88f39542e2`.                                                                                                             |
| Final-review release checkpoint                            | [tests 32427300165](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32427300165) and [deploy 32427591352](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32427591352) passed for `1dfebf625bf68cea5069037a5115278e19c3cc09`.                                                                                                             |
| Owner-revalidation release checkpoint                      | [tests 32429880313](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32429880313) and [deploy 32430144972](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32430144972) passed for the final SHA. The tests workflow passed CI, MariaDB lifecycle/concurrency, seven Chromium checks, and packaging.                                       |
| Production read-only evidence                              | On 2026-08-21, the active release resolved to the final SHA; all four chat routes were registered; Laravel listed the minute order publisher and hourly maintenance event; the six health/public routes returned 200 in the preceding final-review deployment check.                                                                                      |
| Production session decision evidence                       | `SESSION_DRIVER=database`; `SESSION_ENCRYPT=true`.                                                                                                                                                                                                                                                                                                        |
| Final-review correction wave                               | Integrated and deployed in the final Phase 1 SHA above.                                                                                                                                                                                                                                                                                                   |
| Recurring scheduler execution                              | Verified from owner-provided hPanel evidence: exact custom command, manual `* * * * *` schedule, and successful `orders:publish-paid-events` output at `2026-08-21 10:14:01`. A read-only `schedule:list` on the active final release confirmed the minute publisher and hourly chat maintenance event.                                                   |
| Phase 1 owner acceptance                                   | Mohamed accepted the deployed Phase 1 release on 2026-08-21 after completing the real-account and physical iPhone/Safari checks. This closes the remaining device/owner gate without changing the scope of the automated Chromium evidence.                                                                                                               |
| Phase 2 runtime release                                    | [Tests 32578736891](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32578736891) and [deploy 32578995534](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32578995534) passed for `e13ee8bde25263a262788177d0ce78fb4f46f37f`. The active release, seven chat routes, and minute stale-turn recovery schedule were verified in production. |
| Public Luna evaluation                                     | The exact 16-case batch failed mandatory mixed-language, safety-critical-case, and first-visible-content thresholds. See [the sanitized evidence](evidence/2026-08-22-phase-2-luna-public-eval.md).                                                                                                                                                       |
| Current production mode                                    | **Luna re-enabled by Mohamed's decision on 2026-08-22** after the first remediation wave deployed at `b08b4475` (nested `response.failed` parsing, real handler inspection, re-enabled tests, docs). Production env was switched by the owner and activated by that deploy. A public storefront probe at `2026-08-22T19:47:57Z` produced `POST /chat/conversations/{id}/agent-turns → 200`, a streamed Luna reply, the agent-mode disclaimer, and zero browser console errors. The 16-case batch has **not** been re-run: the mixed-language prompt fix (`support-v2`) and the first-visible-content investigation are still open, so the mandatory gate remains unmet and this is an owner-accepted exception, not an acceptance. |

The deployed application contains the Phase 2 runtime, direct Luna adapter,
streaming routes, durable turn/run schema, recovery, and cost accounting. Those
capabilities are inactive in production after the failed gate. RAG, tools, live
commerce/account access, and the operator inbox remain unimplemented.

## Phase status

| Phase                                 | State                                                                                                                                                                                                                                                                                |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Phase 0 stabilization                 | Implemented.                                                                                                                                                                                                                                                                         |
| Phase 1 deterministic chat foundation | Implemented.                                                                                                                                                                                                                                                                         |
| Phase 1 Completion                    | **Accepted by Mohamed on 2026-08-21.** Implementation, deployment, scheduler evidence, real-account testing, and physical iPhone/Safari testing are complete.                                                                                                                        |
| Phase 2 AI runtime/Luna               | Implemented and deployed at `e13ee8bde25263a262788177d0ce78fb4f46f37f`, but **not accepted**. Mohamed approved direct public rollout and waived the fake-production gate; the mandatory public evaluation then failed and AI was disabled while the Phase 1 demo remained available. |

## Scheduler evidence

Owner-provided hPanel screenshots on 2026-08-21 show this exact custom command
running every minute:

```text
/usr/bin/php /home/u372356793/domains/store.arab-ut.com/current/artisan schedule:run
```

The hPanel output recorded `orders:publish-paid-events` as `DONE` at
`2026-08-21 10:14:01`. A subsequent read-only `php artisan schedule:list` from
the active `d77385a44e7ac1413aab419f79d38fc2040be650` release listed the minute
publisher and hourly `chat:maintain-conversations` event. This closes the
external scheduler gate. A 2026-08-22 read-only check on the current release
also verified `agent:recover-stale-turns` every minute.

## Phase 1 acceptance record

On 2026-08-21, Mohamed completed the deployed real-account and physical
iPhone/Safari review, including:

- the account launcher is above the mobile navigation;
- the full sheet covers navigation and closes back to the focused launcher;
- Arabic, English, and mixed-language messages remain readable;
- New conversation returns a distinct public ID; refresh, navigation, and
  login preserve the appropriate conversation; and an explicit old thread does
  not reopen;
- iPhone zoom, keyboard/safe-area behavior, and touch targets are acceptable.

The final Chromium fixture keeps exactly seven Phase 1 tests. Its synthetic
authenticated account scenario covers both locales at 320/390px modal and
768/1440px nonmodal sizes, safe-area geometry/reset, focus, hit testing,
restart-control availability, outside-panel actionability, and
request/console/overflow checks. It does not exercise replacement behavior and
was never treated as Safari proof; Mohamed's completed physical-device review
supplies that separate acceptance evidence. See [UX.md](UX.md) and
[AUDIT.md](AUDIT.md).

## Exact next gate

Phase 2 re-entry is blocked on an owner-approved remediation for the two
mixed-language failures and the first-visible-content maximum. Of the five
source-review items, three were fixed on 2026-08-22 with Mohamed's approval and
AI still disabled: the browser now parses the server's nested `response.failed`
payload, `agent:inspect-streaming-http` inspects the adapter's real handler
stack, and the four previously skipped frontend tests (quiet timing/rescheduling,
disconnect polling, retry UI) run and pass. The connect timeout is now passed
as Guzzle `connect_timeout`, with the request timeout as the total budget (also
2026-08-22). Still open: the accepted nearby-value guard decision is broader in
current source (any qualifying label and value in one message can pair). No prompt, threshold, guard, or runtime default may be
changed silently. After a reviewed fix is deployed with AI still disabled, verify the
actual deployed handler, enable the approved direct-public Luna configuration
through secure access, require a live canary, and execute a new complete 16-case
batch. Any mandatory miss disables Luna again. See
[AGENT-RUNTIME.md](AGENT-RUNTIME.md), [EVALS.md](EVALS.md), and
[OPERATIONS.md](OPERATIONS.md).

## Open decision

`AI-B09` remains open as a documented session-boundary decision. Chat tables
contain only an HMAC guest key. The raw 32-byte-token representation remains in
the Laravel session; production database session payloads are encrypted, as
shown above. Repository and nonproduction defaults must not be inferred from
that production observation, and changing session configuration may invalidate
active sessions. No configuration change is authorized by this release.

Read this file first, then select the canonical subject from [README.md](README.md).
