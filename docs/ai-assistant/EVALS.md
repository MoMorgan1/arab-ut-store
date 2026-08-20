# Evaluation

**Lifecycle:** Planned
**Verified:** 2026-08-21

No model, retrieval, tool, or assistant-quality evaluation harness is
implemented.

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

**Section lifecycle:** Planned

Define representative, versioned scenarios and owners before selecting pass
thresholds. Phase 2 implementation must not ship until Mohamed approves the
quality, safety, latency, and cost gates relevant to its scope.
