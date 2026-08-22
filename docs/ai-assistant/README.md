# AI assistant handbook

**Lifecycle:** Phase 1 accepted; Phase 2 implemented but inactive and not accepted
**Verified:** 2026-08-22

Start with [STATUS.md](STATUS.md). It identifies the verified application
release, operational flags, open findings, completed Phase 1 owner acceptance,
and the exact Phase 2 remediation/re-entry gate. Then use the relevant canonical document
below.

| Domain                    | Canonical document                   | State                                                    |
| ------------------------- | ------------------------------------ | -------------------------------------------------------- |
| Product boundary          | [PRODUCT.md](PRODUCT.md)             | Phase 1 live; Phase 2 inactive pending remediation       |
| Application flow and data | [ARCHITECTURE.md](ARCHITECTURE.md)   | Phase 1 and Phase 2 runtime implemented                  |
| Ownership and safety      | [SECURITY.md](SECURITY.md)           | Controls implemented; Phase 2 inactive after failed gate |
| Customer experience       | [UX.md](UX.md)                       | Phase 1 accepted; Phase 2 UX inactive and unaccepted     |
| Operations and release    | [OPERATIONS.md](OPERATIONS.md)       | Runtime deployed; safe demo fallback active              |
| Audit evidence            | [AUDIT.md](AUDIT.md)                 | Phase 1 accepted; Phase 2 evaluation failed              |
| Incident history          | [INCIDENTS.md](INCIDENTS.md)         | Implemented record                                       |
| Phases                    | [PHASES.md](PHASES.md)               | Mixed; state shown per phase                             |
| Decisions                 | [DECISIONS.md](DECISIONS.md)         | Implemented record                                       |
| AI turn runtime           | [AGENT-RUNTIME.md](AGENT-RUNTIME.md) | Implemented/deployed; disabled pending remediation       |
| Assistant tools           | [TOOLS.md](TOOLS.md)                 | Planned                                                  |
| Retrieval                 | [RAG.md](RAG.md)                     | Planned                                                  |
| Support inbox             | [ADMIN-INBOX.md](ADMIN-INBOX.md)     | Planned                                                  |
| Evaluation                | [EVALS.md](EVALS.md)                 | Mandatory Phase 2 public evaluation failed               |

Historical plans and specs explain how the current state was reached. They do
not override this handbook, the newest explicit owner decision, or the
implementation itself.
