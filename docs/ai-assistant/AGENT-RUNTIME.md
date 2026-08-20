# Agent runtime

**Lifecycle:** Planned
**Verified:** 2026-08-20

The Phase 2 design selects OpenAI Responses API with model `gpt-5.6-luna`
behind a provider-neutral boundary. No model adapter, prompt contract, agent
loop, streaming protocol, runtime API, or production credential is implemented
yet.

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
- Laravel remains durable memory and sends `store: false`; OpenAI project-level
  retention is verified separately and is not inferred from that request flag.
- Hostinger direct POST streaming is the selected v1 approach, subject to a
  real buffering/disconnect feasibility gate before a production tester is
  enabled.
- Initial limits are 24 recent messages, 500 output tokens, low reasoning
  effort, one provider call, a five-second connect timeout, and a 45-second
  total provider timeout. Changes require evaluation evidence.

## Open questions

- Does Hostinger deliver incremental deltas and continue terminal persistence
  after a browser disconnect under the approved PHP path?
- What are the production OpenAI project's effective retention controls and
  model access?
- What is the production Laravel session driver/encryption boundary, and does
  changing it justify a separate customer-session invalidation window?

## Entry criteria

The approved direction is specified in
[`2026-08-20-ai-assistant-phases-1-2-design.md`](../superpowers/specs/2026-08-20-ai-assistant-phases-1-2-design.md).
Write and approve the Phase 1 Completion implementation plan first. Phase 2
implementation begins only after Phase 1 is deployed and manually accepted.
