# Delivery phases

**Lifecycle:** Phase 1 accepted; Phase 2 implemented but inactive and not
accepted pending remediation
**Verified:** 2026-08-22

| Phase                                 | State                             | Boundary                                                                                                                                                                                                                                                 |
| ------------------------------------- | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 0 stabilization                 | Implemented                       | Release audit, CI/MariaDB workflow, and Chromium smoke are in place.                                                                                                                                                                                     |
| Phase 1 deterministic chat foundation | Implemented                       | Persistent bilingual widget, owner-scoped storage, guest claim/rekey, messages, retry, pagination, and demo reply.                                                                                                                                       |
| Phase 1 Completion                    | Accepted by Mohamed on 2026-08-21 | One-open-owner invariant, lifecycle/retention, normalized errors, maintenance, owner revalidation, account placement, recovery, and browser validation are deployed. hPanel scheduling plus real-account and physical iPhone/Safari checks are complete. |
| Phase 2 AI runtime/Luna               | Accepted by Mohamed on 2026-08-23 | Durable provider-neutral turn/run state, prompt guard, direct Luna streaming, retries/recovery, usage/cost accounting, and browser integration are implemented and live. The `support-v3` batch passed every mandatory threshold.                        |
| Phase 3 grounding                     | Implemented                       | A curated bilingual knowledge corpus, lexical topic selection, `support-v3` grounding with exact-fact quoting, and server-derived service cards on replies.                                                                                              |
| Tools, admin inbox, realtime          | Not started                       | Separate discovery and owner approval are required. The agreed next step is live prices on service cards, resolved at render time rather than frozen into chat history.                                                                                  |

## Phase 1 acceptance record

Phase 1 acceptance combines the verified operational evidence with Mohamed's
completed deployed real-account/device test:

1. **Verified:** hPanel shows recurring one-minute UTC execution of the exact
   `schedule:run` command recorded in [OPERATIONS.md](OPERATIONS.md), including
   successful minute-job output on 2026-08-21.
2. **Accepted:** Account launcher is visible above the mobile navigation.
3. **Accepted:** Open sheet covers navigation and closes to the focused launcher.
4. **Accepted:** Arabic, English, and mixed-language messages work as intended.
5. **Accepted:** New conversation yields a distinct public ID; refresh,
   navigation, and login continuity work; an old explicit thread stays closed.
6. **Accepted:** iPhone zoom, safe area, keyboard, and touch targets are acceptable.

See [STATUS.md](STATUS.md), [UX.md](UX.md), and [AUDIT.md](AUDIT.md).

## Phase 2 outcome

The approved implementation stayed inside safe generative support:
provider-neutral turn/run state, immutable model eligibility, prompt guard,
bilingual streaming UX, stale recovery, typed retry semantics, versioned
usage/cost accounting, a fake CI provider, and a direct OpenAI Responses adapter
for `gpt-5.6-luna`. RAG, tools, live support integrations/data, and the admin
inbox remain later work.

Mohamed waived the production fake gate and approved direct public rollout for
guests and authenticated customers, with the mandatory Task 11 thresholds
retained. The live canary succeeded. The clean 16-case production batch then
failed mixed-language quality at 2/4, safety-critical contracts at 7/8, and
maximum first-visible content at 10.663 seconds. It passed durable completion,
Arabic, English, boundary, terminal latency, usage/cost completeness, and the
recorded content-safety observations.

Mohamed selected disable and remediate. Production now resolves new chat
messages to the accepted Phase 1 demo. Re-entry requires approved remediation,
a corrected actual-handler inspection, a live direct-public Luna canary,
resilience checks, and one fresh complete batch meeting every mandatory
threshold. See [AGENT-RUNTIME.md](AGENT-RUNTIME.md), [EVALS.md](EVALS.md), and
[the sanitized evidence](evidence/2026-08-22-phase-2-luna-public-eval.md).

## Active work

The current work is to preserve the failed Phase 2 evidence, correct canonical
documentation, and present one bounded remediation proposal. No
prompt/default/threshold change or production reactivation starts before
Mohamed approves the exact approach. Work remains reviewable and gated by
repository tests, CI, deployment, live canary, and the documented evaluation
checks.

## Planned sequence

1. ~~Complete the Phase 2 evidence/documentation handoff.~~ Done.
2. ~~Remediate and accept the safe model runtime with no RAG or tools.~~
   Accepted 2026-08-23.
3. ~~Add scoped grounding with a curated corpus.~~ Implemented 2026-08-23 as
   knowledge grounding plus server-derived service cards.
4. Show live service prices on those cards, resolved at render time.
5. Add least-privilege tools, with human confirmation for writes.
6. Add the admin inbox, live handoff, transcript controls, SLAs, and realtime.
