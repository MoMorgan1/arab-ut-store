# Delivery phases

**Lifecycle:** Implemented
**Verified:** 2026-08-20

| Phase                                 | Lifecycle   | State and boundary                                                                                                                                                                                              |
| ------------------------------------- | ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 0 stabilization                 | Implemented | Canonical audit, blank-screen regression coverage, Chromium release gate, incident record, and this handbook are implemented. Automated release evidence is green; Mohamed's manual acceptance remains pending. |
| Phase 1 deterministic chat foundation | Implemented | Persistent bilingual shell, owner-safe conversation/history storage, guest claim and key rotation, optimistic FIFO sends, retry, pagination, and optional demo reply are implemented.                           |
| Phase 2 AI runtime/Luna               | Planned     | Exact next engineering task is discovery/design, not implementation. No provider, model, runtime, prompt, schema, API, or budget is selected.                                                                   |
| Retrieval                             | Planned     | Approved sources, freshness, citation UX, deletion, storage, and evaluation require design approval.                                                                                                            |
| Tools                                 | Planned     | Owner authorization, least privilege, confirmation, idempotency, audit, and failure contracts require design approval.                                                                                          |
| Admin inbox and human handoff         | Planned     | Roles, queue behavior, privacy, operator workflow, notifications, and service expectations require discovery and approval.                                                                                      |
| Realtime                              | Planned     | No transport or presence stack is selected; it follows approved support workflow requirements rather than preceding them.                                                                                       |

Phase 2 and later phases must pass the project Discovery and plan approval gates
before any implementation begins.
