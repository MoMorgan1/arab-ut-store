# Delivery phases

**Lifecycle:** Current state recorded
**Verified:** 2026-08-21

| Phase                                   | State                                     | Boundary                                                                                                                                                                                                                                                                                                                                          |
| --------------------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 0 stabilization                   | Implemented                               | Release audit, CI/MariaDB workflow, and Chromium smoke are in place.                                                                                                                                                                                                                                                                              |
| Phase 1 deterministic chat foundation   | Implemented                               | Persistent bilingual widget, owner-scoped storage, guest claim/rekey, messages, retry, pagination, and demo reply.                                                                                                                                                                                                                                |
| Phase 1 Completion                      | Accepted by Mohamed on 2026-08-21         | One-open-owner invariant, last-activity close/reopen/retention, normalized errors, maintenance, owner revalidation, account placement, recovery, and the seven-test authenticated Chromium matrix are deployed at `d77385a44e7ac1413aab419f79d38fc2040be650`. hPanel scheduling plus real-account and physical iPhone/Safari checks are complete. |
| Phase 2 AI runtime/Luna                 | Plan proposed; implementation not started | The executable plan is awaiting Mohamed's explicit approval. Its boundary ends at authenticated-tester acceptance; public rollout remains a later decision.                                                                                                                                                                                       |
| Retrieval, tools, admin inbox, realtime | Not started                               | Separate discovery and owner approval are required.                                                                                                                                                                                                                                                                                               |

## Phase 1 acceptance record

Phase 1 acceptance combines the verified operational evidence with Mohamed's
completed deployed real-account/device test:

1. **Verified:** hPanel shows recurring one-minute UTC execution of the exact
   `schedule:run` command recorded in [OPERATIONS.md](OPERATIONS.md), including
   successful minute-job output on 2026-08-21.
2. **Accepted:** Account launcher is visible above the mobile navigation.
3. **Accepted:** Open sheet covers navigation and closes to the focused launcher.
4. **Accepted:** Arabic, English, and mixed-language messages work as intended.
5. **Accepted:** New conversation yields a distinct public ID; refresh, navigation, and login
   continuity work; an old explicit thread stays closed.
6. **Accepted:** iPhone zoom, safe area, keyboard, and touch targets are acceptable.

Automation remains green evidence rather than a substitute for the now-complete
visual/device gate. See [STATUS.md](STATUS.md), [UX.md](UX.md), and
[AUDIT.md](AUDIT.md).

## Phase 2 approval gate

The proposed runtime plan is
[`2026-08-21-ai-assistant-phase-2-runtime.md`](../superpowers/plans/2026-08-21-ai-assistant-phase-2-runtime.md).
Its presence on disk does not authorize implementation: work remains blocked
until Mohamed approves its exact scope, architecture, operational defaults,
and tester-evaluation thresholds.
