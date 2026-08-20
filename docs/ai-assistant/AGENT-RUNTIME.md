# Agent runtime

**Lifecycle:** Planned
**Verified:** 2026-08-20

No model provider, model, prompt contract, agent loop, streaming protocol, or
runtime API has been selected or implemented.

## Present decisions and constraints

- Keep the turn boundary provider-neutral: validated customer input enters one
  bounded turn, and only an approved customer-safe response may leave it.
- Define explicit budgets before implementation for context, output, tool use,
  elapsed time, and cost. No numerical budget is approved yet.
- A timeout, provider failure, malformed output, or policy failure must fail
  safely without losing the stored customer message or pretending success.
- The runtime must preserve the existing owner boundary and must not receive
  order credentials, payment secrets, production keys, or raw guest tokens.
- Deterministic storage and customer-visible failure behavior remain outside
  provider-specific adapters.

## Open questions

- Which provider and model meet the approved Arabic/English quality, safety,
  latency, cost, and data-handling requirements?
- What turn state is stored, for how long, and what may be replayed on retry?
- What response and failure states belong in the existing message model?

## Entry criteria

Begin implementation only after Mohamed approves Phase 2 discovery/design,
including the provider decision, data boundary, budgets, failure behavior,
evaluation gate, operating cost, and rollback plan.
