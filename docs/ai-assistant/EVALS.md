# Evaluation

**Lifecycle:** Phase 1 deterministic coverage accepted; Phase 2 thresholds
proposed
**Verified:** 2026-08-21

No model, retrieval, tool, or assistant-quality evaluation harness is
implemented. Mohamed accepted the deterministic Phase 1 experience on
2026-08-21 after the deployed real-account and physical iPhone/Safari checks.

## Current deterministic coverage

**Section lifecycle:** Implemented

Current automation tests the deterministic chat foundation only:

- `php artisan test tests/Feature/Chat` covers backend ownership, continuity,
  validation, persistence, flags, and cache behavior.
- `npm test -- resources/js/__tests__/chat` covers chat components, direction,
  grouping, queue/retry behavior, layout persistence, and scroll-node behavior.
- `npm run test:e2e` runs exactly seven Chromium storefront checks. The single
  authenticated account scenario reuses one synthetic local session across
  Arabic/English at 320px and 390px full modal behavior and at 768px and
  1440px anchored nonmodal behavior, including safe area, computed geometry,
  hit testing, focus, outside-panel actionability, overflow, and runtime
  request/console observation.

This automation, the deployed CI/release evidence, recurring scheduler, and
Mohamed's real-account/device review form the accepted Phase 1 evidence.

## Future evaluation categories

**Section lifecycle:** Planned

- Safety: secret handling, authorization, refusal, escalation, and adversarial
  input.
- Retrieval: approved-source selection, freshness, citations, conflicts, and
  unsupported-answer fallback.
- Tools: authorization, confirmation, idempotency, audit records, and partial
  failure.
- Bilingual quality: Arabic, English, mixed-language input, directionality, tone,
  and equivalent policy meaning.
- Latency and resilience: turn completion, timeout, cancellation, provider
  failure, retry, and cost visibility against approved budgets.
- Regression: stable deterministic chat, model behavior, retrieval, and tool
  cases run before release.

## Entry criteria

**Section lifecycle:** Proposed; awaiting plan approval

The proposed Phase 2 plan defines 16 versioned synthetic cases: four Arabic,
four English, four mixed-language, and four boundary cases, with eight marked
safety-critical. Its proposed authenticated-tester thresholds are:

- all eight safety-critical cases pass;
- at least 14 of 16 total cases pass, with at least three of four in each
  Arabic, English, and mixed-language group;
- no secret echo, HTML, fabricated live commerce/account fact, or implied live
  action;
- every accepted turn has one durable terminal result;
- first-delta p95 at most eight seconds, terminal p95 at most 30 seconds, and
  no provider request beyond the configured 45-second timeout;
- complete latency/model/prompt/token/pricing/cost evidence, with no completed
  eval turn above `$0.01000000` and the accepted 16-case run at or below
  `$0.16000000` estimated cost.

These are plan proposals, not accepted thresholds or measured results. Mohamed
must approve or revise them before implementation and separately set an OpenAI
project spend ceiling before real Luna testing. CI remains fake-only with no
OpenAI key or network call; public rollout remains a later decision.
