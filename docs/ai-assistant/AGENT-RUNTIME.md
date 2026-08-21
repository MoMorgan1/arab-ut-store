# Agent runtime

**Lifecycle:** Implementation plan proposed; implementation not started
**Verified:** 2026-08-21

Mohamed accepted Phase 1 on 2026-08-21 after the scheduler, real-account, and
physical iPhone/Safari gates passed. Phase 2 now has an executable proposed
plan, but no runtime code, schema, provider adapter, prompt, agent route,
streaming behavior, production credential, or tester rollout is implemented.
The plan requires Mohamed's explicit approval before implementation.

## Proposed Phase 2 boundary

- Laravel remains the durable authority for conversations, messages, turns,
  runs, final assistant messages, and recovery.
- Use one provider-neutral `AgentModel` contract and a direct OpenAI Responses
  adapter for `gpt-5.6-luna`; introduce no community OpenAI SDK.
- Persist `support-v1` on every turn and keep its versioned prompt at
  `resources/ai-assistant/prompts/support-v1.md`.
- Use direct authenticated POST streaming. The existing browser FIFO persists
  each message, and a 1.5-second quiet window begins only after persistence and
  an empty send queue. No queue worker is used for coalescing.
- Claim at most 24 customer messages, include every claimed message, and fill
  only the remaining context slots with prior conversation messages.
- Keep one nonterminal turn per conversation and lock conversation -> turn ->
  run. No database lock spans provider I/O or streamed waiting.
- Use 500 total provider output tokens, including reasoning, `low` reasoning,
  a five-second connect timeout, a 45-second total provider timeout, and at
  most 4000 Unicode characters of final customer-visible text.
- Allow six turn starts per owner/minute and 20/IP/minute. Permit three
  attempts: initial, at most one automatic bounded 429 retry, and an explicit
  retry while budget remains; cap automatic `Retry-After` waiting at two
  seconds.
- Recover `waiting`/`running` turns older than 120 seconds from the verified
  minute scheduler as retryable failures.
- Preserve the Phase 1 demo for ineligible owners; an eligible owner receives
  agent mode and never both demo and AI replies.
- Release only to an authenticated tester. Public behavior exists as a
  fail-closed configuration option but is neither enabled nor approved.

RAG, embeddings, ingestion, tools, live commerce/account data, human/admin
inbox, Reverb, a permanent worker, and public rollout remain outside Phase 2.

The accepted Phase 1 limits remain unchanged: 4000 message characters, a
50-message default/100-message maximum page, 24-hour inactivity close, seven-
day reopen, 30-day guest retention, 180-day authenticated retention, and the
documented Phase 1 owner/IP rate limits.

## Proposed persistence and stream contracts

`agent_turns` will hold a public ULID, conversation cascade, status, numeric
first/last customer-message bounds without range foreign keys, nullable unique
final assistant message, quiet deadline, `support-v1`, attempt count,
start/completion times, safe terminal code, and a driver-derived unique active
conversation key. It will also enforce unique
`(conversation_id, last_customer_message_id)`.

`agent_runs` will cascade from its turn and enforce unique
`(agent_turn_id, attempt_number)`. It will contain provider/model,
nullable-unique provider response ID, status, latency, input/cached-input/cache-
write/output/reasoning/total token counts, versioned estimated USD cost,
pricing version, trace ID, safe error code, and timestamps. It will contain no
message/prompt content, provider payload, safety identifier, owner secret, API
key, or chain-of-thought. Turn/run rows inherit the conversation's existing
30-day guest or 180-day authenticated retention; Phase 2 adds no longer-lived
raw cost ledger.

The application stream exposes only `turn.created`, `response.delta`,
`response.completed`, `response.failed`, and heartbeat comments. Conversation
JSON exposes only safe resolved assistant mode and the latest bounded turn
state. Provider events, rollout/allowlist/config, numeric IDs, runs, model/key,
tokens, latency, cost, and traces remain server-side.

## Verified OpenAI and Laravel facts

As verified from official sources on 2026-08-21:

- `gpt-5.6-luna` supports Responses and streaming, `low` reasoning, a
  1,050,000-token context window, and 128,000 maximum output tokens.
- Per one million tokens, input is `$0.20`, cached input `$0.02`, and output
  `$1.20`; cache writes are 1.25 times uncached input, or `$0.25`.
- The proposed request uses `store: false`, `stream: true`,
  `reasoning: { effort: low }`, `max_output_tokens: 500`, and a maximum
  64-character safety identifier. The output limit includes visible and
  reasoning tokens.
- The required provider events are `response.output_text.delta`,
  `response.completed`, `response.failed`, `response.incomplete`, and top-level
  `error`; the adapter maps them to provider-neutral events rather than passing
  them through.
- `store: false` disables 30-day Response-object state. It does not establish
  Zero Data Retention: default abuse monitoring may retain content for up to 30
  days unless the OpenAI project has approved controls.
- The repository currently has Laravel 13.24 and Guzzle 7.15.3. Laravel 13
  supports `response()->stream()`, explicit flushing,
  `X-Accel-Buffering: no`, and HTTP-client Guzzle options; the installed PSR
  stream supports `read()` and `eof()`.

Production CLI observation on 2026-08-21 found PHP 8.3.30, memory 2048M,
`output_buffering=0`, `implicit_flush=1`, `max_execution_time=0`, and curl
enabled. These CLI values do not prove web/FPM/proxy streaming or disconnect
finalization.

## Non-negotiable feasibility and secret gates

Code first deploys with `AI_ASSISTANT_ENABLED=false`,
`AI_ASSISTANT_ROLLOUT=disabled`, and an empty provider. A production fake uses
the identical Laravel route/browser parser, one authenticated tester, and
exactly three localized plain-text deltas separated by 350ms. The browser must
receive delta one before completion, and disconnect/reload must recover one
durable terminal result.

If Hostinger buffers the response or loses terminal finalization after
disconnect, Phase 2 stops. OpenAI is not enabled and cosmetic streaming is not
substituted; Mohamed must select a newly designed non-streaming/polling product
or managed streaming service.

Only after that gate may an authorized operator inspect OpenAI billing, Luna
access, retention controls, and an owner-approved spend ceiling. The project
key is entered only in Hostinger's shared `.env`; it never enters chat, GitHub,
CI, frontend props, logs, screenshots, or evidence. The 64-character safety
identifier is an in-memory HMAC-SHA256 of `ChatOwner::idempotencyScope()` with
`APP_KEY`, never a raw user/email/guest/token/public identifier.

No structured credential/account source is connected. The plan also fails a
turn before provider resolution when its deterministic English/Arabic
credential-label, token, backup-code, or payment-card guard detects sensitive
content; matched text is neither logged nor copied into run records.

The Phase 1 session decision also remains unchanged. Production read-only
evidence observed database sessions with encryption enabled; chat tables hold
only the HMAC guest key while the raw guest token remains in the Laravel
session. `AI-B09` remains open, and Phase 2 does not authorize a session-driver
or session-encryption change that could invalidate active sessions.

## Approval state and next step

The scheduler and Phase 1 owner/device prerequisites are complete. The proposed
implementation is
[`2026-08-21-ai-assistant-phase-2-runtime.md`](../superpowers/plans/2026-08-21-ai-assistant-phase-2-runtime.md).
Mohamed must approve its exact v1, operational defaults, and proposed eval
thresholds before Task 1. He must separately set the OpenAI project spend
ceiling before real Luna testing. Public rollout remains a later explicit
decision.

Official sources:

- [GPT-5.6 Luna](https://developers.openai.com/api/docs/models/gpt-5.6-luna)
- [Create a Response](https://developers.openai.com/api/reference/resources/responses/methods/create)
- [Responses streaming events](https://developers.openai.com/api/reference/resources/responses/streaming-events)
- [Streaming responses](https://developers.openai.com/api/docs/guides/streaming-responses)
- [Conversation state](https://developers.openai.com/api/docs/guides/conversation-state)
- [Default endpoint usage policies](https://platform.openai.com/docs/models/default-usage-policies-by-endpoint)
- [Laravel streamed responses](https://laravel.com/docs/13.x/responses#streamed-responses)
- [Laravel event streams](https://laravel.com/docs/13.x/responses#event-streams)
- [Laravel HTTP client Guzzle options](https://laravel.com/docs/13.x/http-client#guzzle-options)
