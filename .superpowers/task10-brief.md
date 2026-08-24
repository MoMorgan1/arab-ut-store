# Objective

Implement Phase 2 Task 10 exactly as specified in the canonical plan:
`docs/plans/2026-08-21-ai-assistant-phase-2-runtime.md`,
section "Task 10: Add the direct OpenAI Responses adapter and
usage-cost accounting" (lines ~2434-2726). That section is the authoritative
contract; follow it verbatim wherever this brief does not restate it.
Read that plan section FIRST, plus `docs/ai-assistant/AGENT-RUNTIME.md`.

Deliverable: a direct OpenAI Responses streaming adapter for
`gpt-5.6-luna` behind the existing provider-neutral `AgentModel` contract,
with strict SSE decoding, an explicit Guzzle `StreamHandler` transport, one
shared monotonic deadline (default 30s), typed retryable/non-retryable error
mapping, versioned usage-cost estimation persisted at finalization, a
loopback-only real-stream integration test, an
`agent:inspect-streaming-http` capability command, and hard privacy
guarantees. No OpenAI SDK and no Composer/dependency additions.

# Allowed paths

Create:
- app/Services/AI/OpenAiSseDecoder.php
- app/Services/AI/OpenAiResponsesAgentModel.php
- app/Services/AI/OpenAiStreamHandlerStack.php
- app/Services/AI/DeadlineAwareStreamReader.php
- app/Services/AI/EstimateAgentRunCost.php
- app/Console/Commands/InspectAgentStreamingHttp.php
- tests/Unit/AI/OpenAiSseDecoderTest.php
- tests/Feature/AI/OpenAiResponsesAgentModelTest.php
- tests/Integration/AI/OpenAiStreamHandlerTransportTest.php
- tests/Feature/Console/InspectAgentStreamingHttpTest.php
- tests/Fixtures/AI/streaming-provider.php
- tests/Support/AI/FakeMonotonicClock.php
- tests/Unit/AI/EstimateAgentRunCostTest.php
- tests/Feature/AI/AgentRunPrivacyTest.php

Modify (only these):
- app/Services/AI/ConfiguredAgentModelResolver.php
- app/Providers/AppServiceProvider.php (bindings only if needed)
- app/Actions/AI/FinalizeAgentTurn.php
- config/services.php
- .github/workflows/tests.yml
- tests/Unit/AI/ConfiguredAgentModelResolverTest.php

# Non-goals

- No frontend/resources/js changes; no migrations or schema changes;
  no composer.json/package.json edits; no new dependencies.
- No real network call ever: unit/feature tests use Http::fake() and
  Http::preventStrayRequests(); the only real transport is loopback 127.0.0.1.
- Never read .env, never print secret values; OPENAI_API_KEY stays empty.
- Do not touch prompts, rollout logic, demo behavior, docs, or anything
  outside Allowed paths.
- Production defaults stay disabled: config/services.php adds keys via env()
  with empty fallbacks only.

# Acceptance criteria

1. Preserve existing contracts exactly:
   `App\Contracts\AI\AgentModel::stream(AgentModelRequest $request,
   AgentDeadline $deadline): Generator<int, AgentModelEvent>`;
   `AgentModelEvent::delta/completed(usage, responseId)/failed(code, retryAfterMs)`;
   `   AgentErrorCode` cases and `isTransient()`; all `AgentRuntimeConfig`
   accessors. The adapter YIELDS `AgentModelEvent::failed(...)` for every
   configuration, request, transport, HTTP, parser, and provider failure -
   it rethrows ONLY `AgentDeadlineExceeded` unchanged. Do NOT modify
   StreamAgentTurn.php; the runner already consumes failed provider events. (model fixed to gpt-5.6-luna, request_timeout_seconds default 30,
   connect 5, read 2, pricing decimal-string rates,
   pricingVersion openai-gpt-5.6-luna-2026-08-21).
2. `ConfiguredAgentModelResolver` gains a lazy `AgentProvider::OpenAi` case
   returning `OpenAiResponsesAgentModel`. Resolution never validates the key;
   a missing key fails only when stream() runs. Update
   ConfiguredAgentModelResolverTest: OpenAi resolves to the adapter class
   instead of throwing; Fake case unchanged and still lazy.
3. Request body POSTed to https://api.openai.com/v1/responses is EXACTLY:
   model, instructions, input (the messages list), store=false, stream=true,
   reasoning={effort}, max_output_tokens, safety_identifier - no extra fields.
   Validate against AgentRuntimeConfig before sending: nonempty key;
   $request->model === config->model(); $request->reasoningEffort ===
   config->reasoningEffort(); 1 <= maxOutputTokens <= config->maxOutputTokens();
   safety_identifier must be 64 lowercase hex chars; messages a nonempty
   bounded list of role/content pairs. Tests assert the complete payload with
   exact key-set equality and reject extra fields; fixtures may use any value
   within the configured cap (e.g., 500 <= 1000).
4. Transport uses Laravel's `PendingRequest::setHandler()` with an explicit
   `GuzzleHttp\Handler\StreamHandler` stack - NEVER handler inside
   withOptions(). Guzzle timeout option = min(connectTimeoutSeconds, remaining
   seconds); read_timeout = min(streamReadTimeoutSeconds, remaining seconds).
   One shared monotonic deadline covers send/connect/headers/body/parser/
   Retry-After wait/retry budget; `$deadline->throwIfExpired()` before and
   after every body read and every provider event; AgentDeadlineExceeded
   bubbles up unchanged.
5. DeadlineAwareStreamReader detaches the PSR-7 body resource; before EACH
   fread(8192) it recomputes min(read timeout, remaining ms), reapplies
   stream_set_timeout, checks timed_out, and always closes the resource in a
   finally block.
6. Exhaustive mapping: connection exception -> ProviderConnectionFailed;
   deadline/read timeout -> ProviderTimeout; HTTP 400/404/409/422 ->
   ProviderRequestRejected; 401 -> ProviderAuthenticationFailed; 403 ->
   ProviderPermissionDenied; 429 -> RateLimited with parsed Retry-After
   (nonnegative delta-seconds converted to milliseconds, or an HTTP date
   evaluated with an INJECTED WALL-CLOCK seam - MonotonicClock is hrtime-based
   and used ONLY for deadline math; absent/invalid/past -> 0); other 5xx ->
   ProviderServerError; ANY other unlisted status -> ProviderRequestRejected.
   Test valid, invalid, absent, and past Retry-After values.
   SSE events: response.output_text.delta -> delta;
   response.completed -> completed(usage from response.usage incl.
   input_tokens_details.cached_tokens / cache_write_tokens /
   output_tokens_details.reasoning_tokens, response.id);
   response.failed -> non-retryable ProviderTerminalFailure;
   response.incomplete -> retryable ProviderIncomplete; top-level error event
   maps rate_limit/server_error/authentication/permission/invalid_request to
   their enums, anything else -> ProviderTerminalFailure; unknown well-formed
   nonterminal events are ignored after deadline validation; malformed JSON or
   malformed usage/terminal shape -> ProviderMalformed; EOF without terminal
   -> ProviderIncomplete.
7. Usage categories persisted exactly as columns on agent_runs: input_tokens,
   cached_input_tokens, cache_write_tokens, output_tokens, reasoning_tokens,
   total_tokens. Reasoning tokens are stored but never added to cost.
8. EstimateAgentRunCost::for(AgentUsage): string returns
   number_format(usd, 8, '.', '') where uncachedInput =
   max(0, inputTokens - cachedInputTokens - cacheWriteTokens) and usd =
   (uncached*inputRate + cached*cachedRate + cacheWrite*writeRate +
   output*outputRate) / 1_000_000, rates read from config each call.
   Fixture input=1000 cached=200 cacheWrite=100 output=300 reasoning=80 must
   equal "0.00052900". A second test mutates each rate independently and
   asserts the result moves in that category.
9. FinalizeAgentTurn injects the estimator and persists estimated_cost_usd on
   the run inside its completion transaction (pricing_version is already set
   at run start; keep it).
10. `php artisan agent:inspect-streaming-http` prints ONLY: resolved handler
    name ("stream"), cURL version, whether http/https stream wrappers exist,
    allow_url_fopen boolean, validated connect/read/total seconds, pass/fail
    verdict; exit 0
    on pass, 1 on fail. Never prints env/key/URL/header values. The feature
    test injects ready/not-ready capability results and asserts both exit
    codes plus the output whitelist.
11. Privacy test proves database rows, log output, exception messages, and
    serialized responses contain none of: the fake API key string, the HMAC
    safety identifier, customer prompt text, raw SSE payloads, provider error
    bodies/messages.
12. tests/Fixtures/AI/streaming-provider.php serves SSE on loopback via
    Symfony Process; tests/Integration/AI/OpenAiStreamHandlerTransportTest.php
    uses the REAL StreamHandler (no Http::fake), observes more than one body
    read, verifies per-read remaining-timeout application and resource close.
    It skips when allow_url_fopen/wrappers are missing ONLY outside CI; under
    CI=true missing capability fails with exact message "Configured CI PHP
    lacks stream-handler support."
13. .github/workflows/tests.yml ci job gains job-scope env AI_ASSISTANT_ENABLED=true,
    AI_ASSISTANT_ROLLOUT=public, AI_MODEL_PROVIDER=fake, OPENAI_API_KEY='',
    plus a separate step running
    `php artisan test tests/Integration/AI/OpenAiStreamHandlerTransportTest.php`.
    All existing MariaDB/Chromium steps stay untouched.
14. Pest style matches this repo: `uses(TestCase::class);`,
    test('...', closure), strict types, PSR-12; code must pass Pint and
    Larastan level used by composer scripts.
15. Precedence note: where docs/ai-assistant/AGENT-RUNTIME.md prose says
    45 seconds / 500 tokens, the plan section and current config prevail
    (request_timeout_seconds default 30, max_output_tokens default 1000).
16. config/services.php adds exactly 'openai' => ['base_url' =>
    'https://api.openai.com/v1', 'key' => env('OPENAI_API_KEY', '')].
    Only tests/Integration/AI/OpenAiStreamHandlerTransportTest.php may point
    a client at a loopback base URL instead.

# Required checks

Run and report outcomes for each:
1. php artisan test tests/Unit/AI tests/Feature/AI tests/Feature/Console/InspectAgentStreamingHttpTest.php tests/Integration/AI/OpenAiStreamHandlerTransportTest.php
2. composer ci:check
3. vendor/bin/pint --test app/Services/AI app/Console/Commands/InspectAgentStreamingHttp.php app/Actions/AI/FinalizeAgentTurn.php tests/Support/AI/FakeMonotonicClock.php tests/Fixtures/AI/streaming-provider.php tests/Feature/AI/AgentRunPrivacyTest.php
4. git diff --check
5. git diff --stat -- composer.json composer.lock package.json package-lock.json (must be EMPTY)

Report changed files, check results, any deviation from the plan section, and
remaining risks. You will NOT commit; the orchestrator commits.
