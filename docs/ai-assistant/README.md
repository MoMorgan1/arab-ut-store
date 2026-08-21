# AI assistant handbook

**Lifecycle:** Phase 1 accepted; Phase 2 plan proposed
**Verified:** 2026-08-21

Start with [STATUS.md](STATUS.md). It identifies the verified application
release, operational flags, open findings, completed Phase 1 owner acceptance,
and the exact Phase 2 approval gate. Then use the relevant canonical document
below.

| Domain                    | Canonical document                   | State                                                    |
| ------------------------- | ------------------------------------ | -------------------------------------------------------- |
| Product boundary          | [PRODUCT.md](PRODUCT.md)             | Implemented foundation; later support/admin work planned |
| Application flow and data | [ARCHITECTURE.md](ARCHITECTURE.md)   | Implemented                                              |
| Ownership and safety      | [SECURITY.md](SECURITY.md)           | Implemented controls with recorded P2/P3 risks           |
| Customer experience       | [UX.md](UX.md)                       | Implemented and accepted                                 |
| Operations and release    | [OPERATIONS.md](OPERATIONS.md)       | Implemented; external scheduler evidence verified        |
| Audit evidence            | [AUDIT.md](AUDIT.md)                 | Phase 1 accepted record                                  |
| Incident history          | [INCIDENTS.md](INCIDENTS.md)         | Implemented record                                       |
| Phases                    | [PHASES.md](PHASES.md)               | Mixed; state shown per phase                             |
| Decisions                 | [DECISIONS.md](DECISIONS.md)         | Implemented record                                       |
| AI turn runtime           | [AGENT-RUNTIME.md](AGENT-RUNTIME.md) | Implementation plan proposed; awaiting approval          |
| Assistant tools           | [TOOLS.md](TOOLS.md)                 | Planned                                                  |
| Retrieval                 | [RAG.md](RAG.md)                     | Planned                                                  |
| Support inbox             | [ADMIN-INBOX.md](ADMIN-INBOX.md)     | Planned                                                  |
| Evaluation                | [EVALS.md](EVALS.md)                 | Phase 1 accepted; Phase 2 thresholds proposed            |

Historical plans and specs explain how the current state was reached. They do
not override this handbook, the newest explicit owner decision, or the
implementation itself.
