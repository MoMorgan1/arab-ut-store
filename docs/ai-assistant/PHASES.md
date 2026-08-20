# Delivery phases

**Lifecycle:** Implemented
**Verified:** 2026-08-20

| Phase                                 | Lifecycle   | State and boundary                                                                                                                                                                                                       |
| ------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Phase 0 stabilization                 | Implemented | Canonical audit, blank-screen regression coverage, Chromium release gate, incident record, and this handbook are implemented. Automated release evidence is green; Mohamed's manual acceptance remains pending.          |
| Phase 1 deterministic chat foundation | Implemented | Persistent bilingual shell, owner-safe conversation/history storage, guest claim and key rotation, optimistic FIFO sends, retry, pagination, and optional demo reply are implemented.                                    |
| Phase 1 Completion                    | Planned     | Fix account mobile launcher overlap; add one-open-conversation invariant, restart, 24-hour close, seven-day reopen, 30/180-day retention, concurrency/error/accessibility hardening, and authenticated browser coverage. |
| Phase 2 AI runtime/Luna               | Planned     | OpenAI Responses API with `gpt-5.6-luna`, `store: false`, durable turns/runs, 1.5-second coalescing, direct POST streaming, bounded context/cost, and tester-only rollout are designed but not implemented.              |
| Retrieval                             | Planned     | Approved sources, freshness, citation UX, deletion, storage, and evaluation require design approval.                                                                                                                     |
| Tools                                 | Planned     | Owner authorization, least privilege, confirmation, idempotency, audit, and failure contracts require design approval.                                                                                                   |
| Admin inbox and human handoff         | Planned     | Roles, queue behavior, privacy, operator workflow, notifications, and service expectations require discovery and approval.                                                                                               |
| Realtime                              | Planned     | No transport or presence stack is selected; it follows approved support workflow requirements rather than preceding them.                                                                                                |

The approved design is
[`2026-08-20-ai-assistant-phases-1-2-design.md`](../superpowers/specs/2026-08-20-ai-assistant-phases-1-2-design.md).
Phase 1 Completion requires its own implementation plan and production/manual
acceptance. Only then may Phase 2 receive and execute its implementation plan.
Later phases remain behind their own Discovery and approval gates.
