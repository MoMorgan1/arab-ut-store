# Live status

**Lifecycle:** Phase 1 accepted; Phase 2 accepted by Mohamed on 2026-08-23;
Phase 3 knowledge grounding, service cards, and human support handoff & ticketing are implemented and active; running `support-v8`
**Verified:** 2026-09-01

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
| Public Luna evaluation                                     | The 2026-08-22 batch failed mandatory mixed-language, safety-critical-case, and first-visible-content thresholds; see [the sanitized evidence](evidence/2026-08-22-phase-2-luna-public-eval.md). The 2026-08-23 batch `phase3-knowledge-eval-20260823T074807Z` passed every mandatory threshold and is the accepted evidence. See [EVALS.md](EVALS.md).                                                                                       |
| Current production mode                                    | **Nawaf is active in production.** Phase 2 was accepted by Mohamed on 2026-08-23 after the `support-v3` batch passed every mandatory threshold. The active configuration is `support-v8` with knowledge grounding at `knowledge_max_topics: 3` and server-derived service cards. The kill switch and rate limits are unchanged: clearing the AI enable flag returns new messages to the accepted Phase 1 demo behavior. |

The application contains the Phase 2 runtime, direct Nawaf adapter,
streaming routes, durable turn/run schema, recovery, and cost accounting, plus
the Phase 3 knowledge grounding, server-derived service cards, in-chat cart
offer, and the integrated Human Support Handoff & Ticketing workflow.

## Phase status

| Phase                                 | State                                                                                                                                                                                                                                                                                |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Phase 0 stabilization                 | Implemented.                                                                                                                                                                                                                                                                         |
| Phase 1 deterministic chat foundation | Implemented.                                                                                                                                                                                                                                                                         |
| Phase 1 Completion                    | **Accepted by Mohamed on 2026-08-21.** Implementation, deployment, scheduler evidence, real-account testing, and physical iPhone/Safari testing are complete.                                                                                                                        |
| Phase 2 AI runtime/Nawaf              | **Accepted by Mohamed on 2026-08-23** after the `support-v3` batch passed every mandatory threshold. The 2026-08-22 failure and the remediation that resolved it are recorded below and in [EVALS.md](EVALS.md).                                                                     |
| Phase 3 knowledge grounding           | Implemented 2026-08-23: a curated bilingual corpus, lexical topic selection, `support-v3` grounding, server-derived service cards, live card prices resolved at render time, and a server-derived add-to-cart offer.                                                                |
| Human support handoff & ticketing     | Implemented 2026-08-24: staff replies, durable ticketing, pinned banners, staff initial bubbles, conversation history on home view, 48-hour guest retention, unread badge & chime, and away-customer email notification.                                                             |

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

## Phase 2 acceptance record

Mohamed accepted Phase 2 on 2026-08-23 after the batch
`phase3-knowledge-eval-20260823T074807Z` passed every mandatory threshold
(8/8 safety-critical, 16/16 total, 4/4 in each language group, 3.675 s maximum
first visible content, 4.633 s maximum terminal, `$0.00027180` maximum run cost,
`$0.00339940` batch cost). The three 2026-08-22 failures — safety 7/8, mixed
language 2/4, and a 10.663 s first-visible outlier — are all resolved. See
[EVALS.md](EVALS.md) for the full table and for the selection defect the batch
surfaced and that was fixed before acceptance.

The accepted configuration is `support-v3` with knowledge grounding
(`knowledge_max_topics: 3`) and server-derived service cards. Setting
`knowledge_max_topics` to 0 disables grounding without touching the prompt.

## Operational and maintenance status

Phases 1-3 and human support handoff & ticketing are implemented, tested, and active in production:
staff replies from the admin inbox, durable `support_tickets` records, a 48-hour guest retention window,
and customer-visible conversation history are live.

Production currently runs `support-v8` with `knowledge_max_topics: 3`. Setting `AI_ASSISTANT_KNOWLEDGE_MAX_TOPICS=0`
disables grounding dynamically. Any prompt, threshold, guard, model, token budget, or runtime default
change requires checking and updating the canonical docs to match `config/ai-assistant.php`.
See [AGENT-RUNTIME.md](AGENT-RUNTIME.md), [EVALS.md](EVALS.md), and [OPERATIONS.md](OPERATIONS.md).

## Open decision

`AI-B09` remains open as a documented session-boundary decision. Chat tables
contain only an HMAC guest key. The raw 32-byte-token representation remains in
the Laravel session; production database session payloads are encrypted, as
shown above. Repository and nonproduction defaults must not be inferred from
that production observation, and changing session configuration may invalidate
active sessions. No configuration change is authorized by this release.

Read this file first, then select the canonical subject from [README.md](README.md).
