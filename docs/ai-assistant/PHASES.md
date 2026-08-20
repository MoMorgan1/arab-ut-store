# Delivery phases

**Lifecycle:** Current state recorded
**Verified:** 2026-08-21

| Phase                                   | State                                                                                   | Boundary                                                                                                                                                                                                                                                                                               |
| --------------------------------------- | --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Phase 0 stabilization                   | Implemented                                                                             | Release audit, CI/MariaDB workflow, and Chromium smoke are in place.                                                                                                                                                                                                                                   |
| Phase 1 deterministic chat foundation   | Implemented                                                                             | Persistent bilingual widget, owner-scoped storage, guest claim/rekey, messages, retry, pagination, and demo reply.                                                                                                                                                                                     |
| Phase 1 Completion                      | Implementation and deployment complete; scheduler evidence and owner acceptance pending | One-open-owner invariant, last-activity close/reopen/retention, normalized errors, maintenance, account placement, recovery, and the seven-test authenticated Chromium matrix are deployed at `1dfebf625bf68cea5069037a5115278e19c3cc09`. Recurring Hostinger scheduler execution is not yet verified. |
| Phase 2 AI runtime/Luna                 | Not started                                                                             | No Phase 2 plan or implementation is authorized until Mohamed accepts the deployed Phase 1 release.                                                                                                                                                                                                    |
| Retrieval, tools, admin inbox, realtime | Not started                                                                             | Separate discovery and owner approval are required.                                                                                                                                                                                                                                                    |

## Phase 1 acceptance handoff

Acceptance requires both operational evidence and Mohamed's deployed real-account
test:

1. hPanel shows recurring one-minute UTC execution of the exact
   `schedule:run` command recorded in [OPERATIONS.md](OPERATIONS.md).
2. Account launcher is visible above the mobile navigation.
3. Open sheet covers navigation and closes to the focused launcher.
4. Arabic, English, and mixed-language messages work as intended.
5. New conversation yields a distinct public ID; refresh, navigation, and login
   continuity work; an old explicit thread stays closed.
6. iPhone zoom, safe area, keyboard, and touch targets are acceptable.

Automation is green evidence, not a replacement for this visual/device gate.
See [STATUS.md](STATUS.md), [UX.md](UX.md), and [AUDIT.md](AUDIT.md).
