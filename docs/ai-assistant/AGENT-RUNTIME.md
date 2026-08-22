# Agent runtime

**Lifecycle:** Implemented and deployed; inactive and not accepted after the
mandatory public evaluation failed
**Verified:** 2026-08-22

Phase 2 adds a durable provider-neutral turn runtime and a direct OpenAI
Responses adapter for `gpt-5.6-luna`. Mohamed waived the planned production fake
gate and approved direct public rollout. The live canary and runtime operated,
but the formal 16-case gate failed. Production now uses the Phase 1 demo while
the Phase 2 code remains deployed.

## Implemented boundary

- Laravel owns customer-message eligibility, turn/run state, retries, final
  assistant messages, recovery, usage, and cost records.
- `AgentModel` and `AgentModelResolver` isolate provider behavior. The configured
  resolver supports only `fake` and `openai`; no community OpenAI SDK is used.
- Each turn persists the `support-v1` version identifier. Instructions are
  loaded from `resources/ai-assistant/prompts/support-v1.md` when the model
  request is built; prompt content itself is not persisted on the turn.
- RAG, tools, live prices/availability/orders/wallet/payments/accounts, human
  handoff, the admin inbox, cancellation, and realtime infrastructure are not
  implemented.
- Agent mode and the Phase 1 demo are mutually exclusive for an original
  customer-message insert.

## Message and turn lifecycle

`chat_messages.agent_eligible_at` is set only for a newly inserted customer
message when the server selects agent mode. Duplicate-message recovery and
rollout changes never rewrite that decision. Legacy, demo, and old unreplied
rows remain ineligible.

Claims include only eligible, unblocked, unreplied customer messages after the
previous turn boundary. The default claim/context limit is 24 messages. Current
claimed messages consume the limit first; remaining slots use only messages and
final assistant replies associated with completed prior turns.

The browser waits for its FIFO send queue to persist, then starts a 1.5-second
quiet window. The server recalculates the quiet deadline and can return
`waiting_for_quiet` with a bounded retry delay. One `waiting` or `running` turn
is allowed per conversation. MariaDB/MySQL enforce that invariant with a
generated unique key; SQLite uses triggers and a unique index. No database lock
spans provider I/O.

At the default limit, 25 pending rows drain as 24 then 1. Terminal turn state
includes a server-derived `hasPendingMessages`; when the browser FIFO is empty,
one successor starts. Reload/poll recovery uses the same terminal state.

## Prompt guard

`GuardAgentPromptContent` runs before provider resolution and before a run is
created. It detects credential labels combined with secret-like values,
bearer/OpenAI-shaped tokens, and Luhn-valid card numbers when card terminology
is present. A current-range match marks the whole claimed numeric range blocked
and fails the turn without calling a provider. A sensitive prior-context message
is omitted without blocking the current range. Matched content is not copied to
turn/run records or logs, but remains in the existing customer message row under
normal chat retention.

The implementation evaluates qualifying labels and values within the same
message; it does not enforce a smaller proximity window.

## Streaming contract

The application exposes these server-sent events:

- `turn.created`;
- `response.delta`;
- `response.completed`;
- `response.failed`.

The controller sends an initial heartbeat comment, flushes each event, sets
`X-Accel-Buffering: no`, and continues finalization after client disconnect.
There is no recurring heartbeat loop. Polling supplies reload/disconnect and
terminal-state recovery.

The server emits `response.failed` as `{turn, error: {code, message}}`.
`resources/js/lib/agent-stream.ts` reads that nested shape and still accepts the
older flat `{turn, code, message}` form; either parses to the same browser
event. A frame missing `code` or `message` is rejected as `invalid_stream`.
(Fixed 2026-08-22; previously the browser expected only the flat form, so a
real failure frame became `invalid_stream`.)

## OpenAI adapter

`OpenAiResponsesAgentModel` sends a direct authenticated `POST /responses` with:

- `store: false` and `stream: true`;
- `gpt-5.6-luna`;
- `low` reasoning;
- 1,000 maximum output tokens;
- the versioned instructions and message input;
- a 64-character HMAC safety identifier derived from owner scope and `APP_KEY`.

The adapter installs Guzzle `StreamHandler` explicitly. `connect_timeout_seconds`
(five seconds) is Guzzle `connect_timeout`, `stream_read_timeout_seconds` (two
seconds) bounds each body read as `read_timeout`, and `request_timeout_seconds`
(thirty seconds) is the total `timeout`; each is capped by the remaining
monotonic deadline. (Until 2026-08-22 the connect value was passed as the total
`timeout` and Guzzle's default ten-second connect timeout applied.) The
required provider events are
`response.output_text.delta`, `response.completed`,
`response.failed`, `response.incomplete`, and top-level `error`. Unknown
nonterminal provider events are ignored. Visible output is capped at 4,000
Unicode characters while the adapter continues consuming the provider stream
to its terminal event.

The deployed `agent:inspect-streaming-http` command resolves the adapter's
`OpenAiStreamHandlerStack`, labels the handler it actually builds (`stream` for
Guzzle's `StreamHandler`, `curl` for either cURL handler, otherwise the class
name), and passes only when that label is `stream` and `allow_url_fopen`, the
HTTP/HTTPS wrappers, and runtime timeout values are valid. It does not make an
OpenAI request. (Before 2026-08-22 it printed a hardcoded `stream` label.) The
live canary remains the real outbound and incremental-delivery proof.

## Retry and recovery

- The fixed attempt budget is three.
- Attempt one may receive one automatic 429 retry under the same deadline.
- The first run is failed as `rate_limited`, the turn returns to `waiting`, the
  transaction commits, and any wait occurs outside locks.
- Automatic wait is bounded by `Retry-After`, the 2,000 ms configured cap, and
  remaining deadline.
- Attempt three is available only through explicit retry of an eligible
  transient failure.
- Retryable codes are rate limit, connection failure, timeout, provider 5xx,
  incomplete stream, stream termination, and stale recovery.
- A fresh 30-second monotonic deadline covers each start invocation, including
  prompt construction, provider resolution, request/stream parsing, and one
  automatic retry.
- `agent:recover-stale-turns` runs each minute and terminalizes nonterminal turns
  unchanged for 60 seconds. Configuration requires the stale threshold to
  exceed the request deadline by at least 15 seconds.

## Rate limits

Chat-enabled agent start and explicit retry requests admitted by both named
limits consume the six-per-owner and 20-per-IP minute counters, including
controller outcomes that are waiting, in progress, idle, or rejected. An
already-exhausted key is rejected before counters increment. When chat is
disabled, middleware returns before the route throttle and the limiter is
configured as unlimited. Polling uses the existing chat-read limits of 60/owner
and 120/IP. Conversation creation/restart and message persistence keep their
Phase 1 limits.

## Usage, cost, and retention

Completed runs store provider/model, a provider response ID when supplied,
terminal provider latency, input/cached-input/cache-write/output/reasoning/total
tokens, pricing version, estimated USD cost, and an internal trace ULID. They
contain no prompt, response text, provider payload, safety identifier, owner
identifier, or API-key column. Failed provider work has no usage/cost evidence
even when the provider may have incurred billable work before failure.

Uncached input is `max(0, input - cached input - cache write)`. Cost applies the
versioned rates to uncached input, cached input, cache writes, and output.
Reasoning tokens are stored but not charged again. `agent_runs.latency_ms`
measures terminal provider-attempt time, not customer-visible first-content
latency.

Turns/runs cascade from their conversation and inherit the existing 30-day
guest and 180-day authenticated closed-conversation retention. `store: false`
disables provider Response-object storage but does not establish Zero Data
Retention; no unsupported provider-retention claim is made.

The source credential is managed through Hostinger's shared `.env`. Running
`config:cache` also materializes resolved configuration in each retained release
cache, so operations must not claim the secret exists only in the shared file.

## Defaults and current production state

Repository defaults are fail closed:

| Setting                 | Default           |
| ----------------------- | ----------------- |
| AI enabled              | `false`           |
| Rollout                 | `disabled`        |
| Provider                | empty             |
| Model                   | `gpt-5.6-luna`    |
| Prompt                  | `support-v1`      |
| Quiet window            | 1,500 ms          |
| Context                 | 24 messages       |
| Output                  | 1,000 tokens      |
| Visible response        | 4,000 characters  |
| Open/read/deadline      | 5 s / 2 s / 30 s  |
| Owner/IP turn limit     | 6 / 20 per minute |
| Attempts/retry-wait cap | 3 / 2,000 ms      |
| Stale recovery          | 60 s              |

Production currently has AI disabled, rollout `disabled`, an empty provider
selector, and the Phase 1 demo enabled. The failed batch and containment proof
are in [the evaluation evidence](evidence/2026-08-22-phase-2-luna-public-eval.md).

## Re-entry gate

Re-entry requires Mohamed's approval of the remediation scope. It must address
mixed-language instruction adherence, customer-visible first-content latency,
and the failed-event payload mismatch without silently changing thresholds.
After reviewed code/prompt changes deploy with AI disabled, operations run the
corrected actual-handler inspection, direct-public Luna canary, resilience
checks, and one fresh complete 16-case batch. See [EVALS.md](EVALS.md),
[OPERATIONS.md](OPERATIONS.md), [SECURITY.md](SECURITY.md), and
[DECISIONS.md](DECISIONS.md).

Official references:

- [GPT-5.6 Luna](https://developers.openai.com/api/docs/models/gpt-5.6-luna)
- [Create a Response](https://developers.openai.com/api/reference/resources/responses/methods/create)
- [Responses streaming events](https://developers.openai.com/api/reference/resources/responses/streaming-events)
- [Streaming responses](https://developers.openai.com/api/docs/guides/streaming-responses)
- [Default endpoint usage policies](https://platform.openai.com/docs/models/default-usage-policies-by-endpoint)
- [Laravel streamed responses](https://laravel.com/docs/13.x/responses#streamed-responses)
