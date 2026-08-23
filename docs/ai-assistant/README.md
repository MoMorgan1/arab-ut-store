# AI assistant handbook

**Lifecycle:** Phase 1 accepted; Phase 2 accepted 2026-08-23; Phase 3 grounding
and the read-only support inbox implemented
**Verified:** 2026-08-24

Start with [STATUS.md](STATUS.md). It identifies the verified application
release, operational flags, open findings, completed Phase 1 owner acceptance,
and the current open items. Then use the relevant canonical document below.

| Domain                    | Canonical document                   | State                                                    |
| ------------------------- | ------------------------------------ | -------------------------------------------------------- |
| Product boundary          | [PRODUCT.md](PRODUCT.md)             | Phases 1-3 live and accepted                             |
| Application flow and data | [ARCHITECTURE.md](ARCHITECTURE.md)   | Phase 1-3 runtime implemented                            |
| Ownership and safety      | [SECURITY.md](SECURITY.md)           | Controls implemented                                     |
| Customer experience       | [UX.md](UX.md)                       | Phases 1-3 implemented and accepted                      |
| Operations and release    | [OPERATIONS.md](OPERATIONS.md)       | Runtime deployed and active                              |
| Audit evidence            | [AUDIT.md](AUDIT.md)                 | Phase 1 and Phase 2 accepted                             |
| Incident history          | [INCIDENTS.md](INCIDENTS.md)         | Implemented record                                       |
| Phases                    | [PHASES.md](PHASES.md)               | Mixed; state shown per phase                             |
| Decisions                 | [DECISIONS.md](DECISIONS.md)         | Implemented record                                       |
| AI turn runtime           | [AGENT-RUNTIME.md](AGENT-RUNTIME.md) | Implemented, deployed, and active                        |
| Assistant tools           | [TOOLS.md](TOOLS.md)                 | No model tool calling; server-derived surfaces only      |
| Retrieval                 | [RAG.md](RAG.md)                     | Implemented as lexical selection over a curated corpus   |
| Support inbox             | [ADMIN-INBOX.md](ADMIN-INBOX.md)     | Read-only inbox implemented; handoff planned             |
| Evaluation                | [EVALS.md](EVALS.md)                 | 2026-08-23 batch passed every mandatory threshold        |

Historical plans and specs explain how the current state was reached. They do
not override this handbook, the newest explicit owner decision, or the
implementation itself.
