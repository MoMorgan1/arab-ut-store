# Delivery phases

**Lifecycle:** Phases 1-3 accepted and active; human handoff in design
**Verified:** 2026-08-24

| Phase                                 | State                             | Boundary                                                                                                                                                                                                                                                 |
| ------------------------------------- | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 0 stabilization                 | Implemented                       | Release audit, CI/MariaDB workflow, and Chromium smoke are in place.                                                                                                                                                                                     |
| Phase 1 deterministic chat foundation | Implemented                       | Persistent bilingual widget, owner-scoped storage, guest claim/rekey, messages, retry, pagination, and demo reply.                                                                                                                                       |
| Phase 1 Completion                    | Accepted by Mohamed on 2026-08-21 | One-open-owner invariant, lifecycle/retention, normalized errors, maintenance, owner revalidation, account placement, recovery, and browser validation are deployed. hPanel scheduling plus real-account and physical iPhone/Safari checks are complete. |
| Phase 2 AI runtime/Luna               | Accepted by Mohamed on 2026-08-23 | Durable provider-neutral turn/run state, prompt guard, direct Luna streaming, retries/recovery, usage/cost accounting, and browser integration are implemented and live. The `support-v3` batch passed every mandatory threshold.                        |
| Phase 3 grounding                     | Implemented                       | A curated bilingual knowledge corpus, lexical topic selection, grounding with exact-fact quoting, a `<live_prices>` table in the viewer's currency, and server-derived service cards on replies. Shipped under `support-v3`; the prompt has since moved to `support-v6` without a batch. |
| Read-only support inbox               | Implemented 2026-08-23            | List, filter, look up by public id, and read a transcript with its agent-turn runtime, behind an admin-only `chat.view`. Guest keys never leave the server.                                                                                              |
| Human handoff and ticketing           | Approved design, not implemented  | Staff replies, a durable ticket record, a 48-hour guest retention window, and a customer-visible conversation history. See the 2026-08-24 design.                                                                                                        |
| Model tools and realtime              | Not started                       | Separate discovery and owner approval are required.                                                                                                                                                                                                     |

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

The implementation stayed inside safe generative support: provider-neutral
turn/run state, immutable model eligibility, a prompt guard, bilingual streaming
UX, stale recovery, typed retry semantics, versioned usage/cost accounting, a
fake CI provider, and a direct OpenAI Responses adapter for `gpt-5.6-luna`.

Mohamed waived the production fake gate and approved direct public rollout for
guests and authenticated customers, keeping the mandatory Task 11 thresholds.
The live canary succeeded. The 2026-08-22 batch then failed mixed-language
quality at 2/4, safety-critical contracts at 7/8, and maximum first-visible
content at 10.663 seconds, and Mohamed selected disable and remediate.

Remediation shipped the `support-v2` then `support-v3` prompt, nested
`response.failed` parsing, real handler inspection, split Guzzle
connect/read/total timeouts, four re-enabled frontend tests, and knowledge
grounding. The first-visible outlier was traced to post-server delivery, not to
application code. The 2026-08-23 batch `phase3-knowledge-eval-20260823T074807Z`
then passed every mandatory threshold — 8/8 safety-critical, 16/16 total, 4/4 in
each language group, 3.675 s maximum first visible content, 4.633 s maximum
terminal, `$0.00339940` batch cost — and **Mohamed accepted Phase 2 on
2026-08-23**. See [EVALS.md](EVALS.md) and
[the sanitized 2026-08-22 evidence](evidence/2026-08-22-phase-2-luna-public-eval.md).

## Active work

Phases 1-3 are accepted and active. The current work is the approved human
support handoff and ticketing design,
[`2026-08-24-support-handoff-tickets-design.md`](../superpowers/specs/2026-08-24-support-handoff-tickets-design.md):
staff replies from the admin inbox, a durable ticket record, a 48-hour guest
retention window with guests removed from the operator inbox, and a
customer-visible conversation history. No prompt, threshold, guard, model, token
budget, or runtime default changes as part of it; any such change requires a
fresh complete evaluation batch and a new owner acceptance.

## Planned sequence

1. ~~Complete the Phase 2 evidence/documentation handoff.~~ Done.
2. ~~Remediate and accept the safe model runtime with no RAG or tools.~~
   Accepted 2026-08-23.
3. ~~Add scoped grounding with a curated corpus.~~ Implemented 2026-08-23 as
   knowledge grounding plus server-derived service cards.
4. ~~Show live service prices on those cards, resolved at render time.~~
   Implemented 2026-08-23.
5. ~~Let a customer act on a reply without leaving the chat.~~ Implemented
   2026-08-23 as a server-derived add-to-cart offer for coins, not as model
   tools. The runtime still has no tool-calling and the model still cannot take
   an action: the offer is derived from the customer's own message, the button
   and the EA form are the human confirmation, and the request goes to the
   store's existing cart endpoint over the customer's own session. Credentials
   never enter a transcript or model context. Rivals and FUT Champions are
   excluded because both require a squad screenshot upload at cart-add time.
6. ~~Add a read-only admin inbox.~~ Landed 2026-08-23: list, filter, look up
   by public id, and read a transcript with its agent-turn runtime, behind an
   admin-only `chat.view`. Guest keys never leave the server.
7. Add human handoff, ticketing, guest retention and customer chat history.
   Designed and owner-approved on 2026-08-24; not implemented. Assignment
   beyond a single assignee, SLAs and realtime transport remain out of scope.
