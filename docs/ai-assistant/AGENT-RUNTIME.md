# Agent runtime

**Lifecycle:** Planned
**Verified:** 2026-08-21

The Phase 2 design selects OpenAI Responses API with model `gpt-5.6-luna`
behind a provider-neutral boundary. No model adapter, prompt contract, agent
loop, streaming protocol, runtime API, or production credential is implemented
yet.

## Present decisions and constraints

- Keep the turn boundary provider-neutral: validated customer input enters one
  bounded turn, and only an approved customer-safe response may leave it.
- Phase 1 numerical limits are approved and implemented: 4000 message
  characters, a 50-message default/100-message maximum page, a 24-hour close,
  7-day reopen, 30-day guest retention, 180-day authenticated retention, and
  the documented owner/IP rate limits.
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
- The approved Phase 2 design baseline is 24 recent messages, 500 output
  tokens, low reasoning effort, one provider call, a five-second connect
  timeout, and a 45-second total provider timeout. It is not implemented;
  changes require evaluation evidence and the Phase 2 plan/approval.

## Open questions

- Does Hostinger deliver incremental deltas and continue terminal persistence
  after a browser disconnect under the approved PHP path?
- What are the production OpenAI project's effective retention controls and
  model access?
- Does changing the production session boundary justify a separate
  customer-session invalidation window? Read-only evidence on 2026-08-20
  observed `SESSION_DRIVER=database` and `SESSION_ENCRYPT=true`; no change is
  authorized or implied.

## Entry criteria

The approved direction is specified in
[`2026-08-20-ai-assistant-phases-1-2-design.md`](../superpowers/specs/2026-08-20-ai-assistant-phases-1-2-design.md).
The Phase 1 Completion plan is completed. Phase 2 has not started and requires
the remaining scheduler evidence, Mohamed's Phase 1 acceptance, then its own
implementation plan and explicit approval.
