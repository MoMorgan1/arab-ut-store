# Evaluation

**Lifecycle:** Planned
**Verified:** 2026-08-20

No model, retrieval, tool, or assistant-quality evaluation harness is
implemented. Current automation tests the deterministic chat foundation only:

- `php artisan test tests/Feature/Chat` covers backend ownership, continuity,
  validation, persistence, flags, and cache behavior.
- `npm test -- resources/js/__tests__/chat` covers chat components, direction,
  grouping, queue/retry behavior, layout persistence, and scroll-node behavior.
- `npm run test:e2e` runs the six-test Chromium storefront smoke, including one
  390px chat open/close and overflow check.

## Future evaluation categories

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

Define representative, versioned scenarios and owners before selecting pass
thresholds. Phase 2 implementation must not ship until Mohamed approves the
quality, safety, latency, and cost gates relevant to its scope.
