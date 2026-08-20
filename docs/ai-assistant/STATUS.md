# Live status

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Release snapshot

| Item                                                       | Status                                                                            |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------- |
| `main` SHA inspected before this documentation-only change | `fdba471af2fef38905581a309bf8b0e9119ab41b`                                        |
| Last browser-verified application release SHA              | `fdba471af2fef38905581a309bf8b0e9119ab41b`                                        |
| Application tests workflow                                 | [Successful](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32366435437) |
| Application production deployment workflow                 | [Successful](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32366826404) |
| Read-only production HTML                                  | HTTP 200; `chat.enabled=true`; `chat.demoAssistant=true`                          |
| Operational flags                                          | `CHAT_ENABLED=true`; `CHAT_DEMO_ASSISTANT=true`                                   |
| P0/P1 release gate                                         | Proceed; no unresolved P0 or P1 audit finding                                     |
| Owner acceptance                                           | `Pending Mohamed manual test`                                                     |

The documentation commit containing this file is later than the application
release above and is documentation-only. Its commit SHA identifies the handbook
revision; it does not claim that runtime behavior was browser-revalidated. Use
`git log -1 --format=%H -- docs/ai-assistant/STATUS.md` to identify that
documentation revision after it is committed.

## Open audit findings

| ID       | Severity | Current state                                                                                   |
| -------- | -------- | ----------------------------------------------------------------------------------------------- |
| `AI-B03` | P2       | Open-conversation creation concurrency remains unguarded.                                       |
| `AI-B04` | P2       | Duplicate contention and lost-response demo-reply recovery remain open.                         |
| `AI-B05` | P2       | Conversation plus onboarding-message creation remains non-atomic.                               |
| `AI-B06` | P2       | MariaDB migration lifecycle passed CI; a direct-query owner-constraint regression remains open. |
| `AI-B08` | P2       | Chat 429/500 error-envelope and no-store coverage remains open.                                 |
| `AI-F04` | P3       | Scroll geometry and unread-state assertions remain open.                                        |
| `AI-F06` | P2       | iOS keyboard and safe-area acceptance remains open.                                             |
| `AI-F07` | P2       | Composer accessible naming and some 44px secondary targets remain open.                         |

`AI-F08` is mitigated by the release-blocking Chromium application smoke. Its
remaining cross-browser and visual limits are handled by the manual owner gate,
not treated as proof of Safari behavior.

Full evidence and severity reasoning remain in [AUDIT.md](AUDIT.md); this status
does not redefine those findings.

## Next task and acceptance gate

The exact next engineering task is **Phase 2 discovery/design, not
implementation**. Do not start Luna/model runtime work until Mohamed completes
the deployed Arabic/English mobile/desktop checklist in [UX.md](UX.md), accepts
the release, and approves a Phase 2 design.
