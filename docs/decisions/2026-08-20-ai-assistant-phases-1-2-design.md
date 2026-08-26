# AI Assistant Phase 1 Completion and Phase 2 Agent Runtime Design

**Date:** 2026-08-20

**Status:** Approved direction by Mohamed; written specification pending owner review

**Complexity:** Ambitious

## Purpose

Complete the deterministic chat foundation before adding a real AI runtime.
This work fixes the authenticated-account launcher regression, gives every
conversation an explicit lifecycle and retention boundary, closes the
highest-value Phase 1 reliability gaps, then adds an OpenAI GPT-5.6 Luna
runtime with rapid-message coalescing and real streamed responses.

The work is delivered in two separately verified releases. Phase 1 Completion
must pass production and Mohamed's manual acceptance before the Phase 2 runtime
is enabled for any production tester.

## Binding product decisions

- Do not rebuild the existing Phase 1 chat; extend and harden it.
- The launcher remains physically bottom-right regardless of locale.
- On mobile account pages, the closed launcher sits above the account bottom
  navigation; the open full-screen sheet sits above all account navigation.
- One owner may have only one open conversation.
- An inactive conversation closes after 24 hours.
- A conversation automatically closed for inactivity may reopen when its owner
  returns within seven days of last activity.
- After seven days, the owner receives a new conversation.
- The customer can choose **New conversation** at any time. That explicit close
  is never automatically reopened.
- Closed history is support-only in this version; customers do not receive a
  WhatsApp-style conversation list yet.
- Unclaimed guest conversations are hard-deleted after 30 days of last
  activity. Authenticated conversations are hard-deleted after 180 days.
- Laravel is the durable memory and authority. A model provider is never the
  system of record.
- Phase 2 uses OpenAI directly with model `gpt-5.6-luna` through the Responses
  API and `store: false`.
- Phase 2 has no commerce tools, order tools, RAG, admin inbox, human realtime,
  or autonomous actions.
- Phase 2 uses Laravel direct HTTP streaming on Hostinger. No permanent queue
  worker or new hosted worker service is introduced in this phase.
- AI remains disabled by default and is released to an authenticated tester
  allowlist before any public rollout.

## Verified current state

### Account launcher root cause

`ChatRootLayout` already wraps `account/*` pages and shared Inertia props expose
the enabled chat flag. The launcher is rendered, but on mobile:

- the chat root uses `z-index: 50` and `bottom: 16px`;
- the account bottom navigation uses `z-index: 60`, starts 10px above the safe
  area, and is at least 62px high.

The launcher therefore occupies the same physical band underneath the account
navigation. This is a stacking and placement defect, not a missing layout or
feature-flag problem.

### Conversation lifetime root cause

`CreateOrGetActiveConversation` always returns the session-pointed open
conversation or the owner's latest open conversation. There is currently:

- no inactivity close;
- no user-driven new-conversation action;
- no reopen policy;
- no retention or purge command;
- no code that transitions conversations to `closed` or `archived`;
- no database invariant preventing concurrent duplicate open conversations.

Consequently, authenticated history is selected indefinitely and orphaned guest
records remain in storage indefinitely.

### Hosting constraint

Production runs on Hostinger PHP/MariaDB. Laravel Scheduler runs once per minute,
but no permanent queue worker is provisioned. A one-to-two-second AI turn
debounce cannot depend on the existing database queue plus minute cron.

## Delivery boundary

### Phase 1 Completion

1. Fix account-page launcher placement and full-sheet stacking.
2. Add the approved lifecycle, retention, and New conversation behavior.
3. Enforce one open conversation per owner under concurrency.
4. Make conversation creation plus onboarding message atomic.
5. Make duplicate message recovery return the canonical stored result.
6. Normalize safe chat error responses needed by the new lifecycle.
7. Close the composer accessible-name, secondary touch-target, and mobile
   safe-area gaps in the audited surface.
8. Add authenticated real-browser coverage for `/my-account`.
9. Add scheduled close and purge operations plus runbook documentation.
10. Inspect the production session driver/encryption boundary through the
    approved secure path and present any change separately. Enabling session
    encryption may invalidate existing sessions and is not silently bundled
    into this release.

### Phase 2 Agent Runtime

1. Add a provider-neutral model boundary.
2. Add GPT-5.6 Luna through OpenAI Responses API.
3. Add prompt/version management.
4. Add durable agent turns and provider runs.
5. Coalesce rapid messages after a 1.5-second quiet period.
6. Stream assistant text through Laravel to the existing React chat.
7. Persist the final assistant message and usage/cost evidence.
8. Recover cleanly from disconnects, timeouts, provider errors, and retries.
9. Release to authenticated testers only.

### Out of scope

- RAG, embeddings, ingestion, or knowledge citations.
- Live price, catalog, cart, order, wallet, payment, or account tools.
- Tool calling, pending confirmations, or commerce writes.
- Reverb, human takeover, admin inbox, assignment, or internal notes.
- A customer-visible list of old conversations.
- Attachments, audio, images, or arbitrary model-generated HTML.
- n8n in the synchronous chat request path.
- External queue/worker infrastructure.

## Phase 1 architecture

### Conversation lifecycle states

Keep the existing public status vocabulary:

- `open`: current customer conversation;
- `closed`: resolved, explicitly replaced, or inactive conversation;
- `archived`: reserved for later support operations and not set automatically
  in this release.

Add a typed close-reason vocabulary:

- `customer_started_new`;
- `inactive`;
- `superseded_by_login_claim`;
- `invariant_upgrade_duplicate`.

Add nullable lifecycle fields to `chat_conversations` through a new forward
migration:

- `active_owner_key`;
- `closed_at`;
- `close_reason`.

`active_owner_key` is non-null only while the conversation is open:

- authenticated: `user:<numeric-id>`;
- guest: `guest:<existing-hmac>`.

A unique index on `active_owner_key` enforces one open conversation per owner.
Closed rows use `NULL`, so historical rows do not conflict.

### Migration of existing data

The deployed Phase 1 migration remains immutable.

The new migration groups existing open rows by owner. For a duplicate group it
keeps the row with the newest `last_message_at`, then highest numeric ID, open.
Older rows are closed with reason `invariant_upgrade_duplicate`; nothing is
deleted. It then backfills `active_owner_key` and creates the unique index.

The migration must prove fresh, rollback, upgrade, and remigration behavior on
SQLite and MariaDB. A direct-query regression proves the unique invariant
without relying only on Eloquent callbacks.

### Lifecycle acquisition

Replace scattered acquisition behavior with one action responsible for:

1. owner-scoped session pointer lookup;
2. current open conversation lookup;
3. recently inactive-closed conversation lookup;
4. safe reopen;
5. atomic creation with its onboarding message;
6. session pointer update.

Rules:

- an open conversation is returned;
- a conversation closed for `inactive` may reopen only when its
  `last_message_at` is within seven days;
- an explicitly replaced or login-superseded conversation never auto-reopens;
- otherwise a new conversation and onboarding message are created in one
  transaction;
- unique-key contention recovers the canonical winner instead of returning a
  server error.

### Guest-to-login claim

The active guest conversation wins because continuity of its public ID is a
binding product contract.

Inside one transaction:

1. lock the active guest conversation and any existing authenticated open
   conversation;
2. close the existing authenticated conversation as
   `superseded_by_login_claim`;
3. claim the guest conversation to the user;
4. change its `active_owner_key` from guest to user;
5. preserve its public ID, messages, locale, and last activity;
6. clear guest session ownership only after commit.

### New conversation action

Add an owner-scoped endpoint:

```text
POST /chat/conversations/restart
```

It atomically closes the current open conversation with
`customer_started_new`, creates a new conversation plus onboarding message,
updates the session pointer, and returns the normal bounded conversation
contract.

The header exposes a bilingual **New conversation / محادثة جديدة** action.
When messages are still sending or an AI turn is running, the action is
temporarily disabled rather than discarding work.

### Closing and retention maintenance

Add an idempotent command:

```text
chat:maintain-conversations
```

The command runs hourly under `withoutOverlapping()` and:

1. closes open conversations whose `last_message_at` is at least 24 hours old;
2. skips conversations with a waiting/running agent turn once Phase 2 exists;
3. deletes guest-owned closed conversations at least 30 days after last
   activity;
4. deletes authenticated closed conversations at least 180 days after last
   activity;
5. deletes messages through the existing cascade;
6. clears no live browser session directly; stale pointers recover on the next
   acquisition request;
7. reports counts without logging message content or owner secrets.

The four durations are configuration values with the approved defaults. A
future legal/operational decision may change them without changing code.

### Canonical duplicate-message recovery

When the same `client_message_id` is replayed:

- recover the stored customer message;
- recover any deterministic demo reply associated with that original request;
- never create a second reply;
- under database contention, catch the unique winner and return the same
  canonical result.

Phase 2 replaces the demo-reply association with agent-turn recovery for AI
eligible conversations.

### Error contract

Lifecycle and message endpoints use a consistent safe JSON envelope with at
least:

- `validation_error` — 422;
- `conversation_not_found` — 404;
- `conversation_closed` — 409;
- `chat_disabled` — 404;
- `rate_limited` — 429;
- `chat_unavailable` — sanitized 500.

Every response remains `no-store` and never exposes owner keys, raw session
tokens, stack traces, or provider data.

## Phase 1 UI design

### Account launcher

`ChatRootLayout` reads the real Inertia component name and passes an account
surface flag to `ChatWidget`.

- On mobile account pages, the closed launcher uses an account-specific bottom
  offset above the 62px navigation plus safe area and spacing.
- The chat root and full-screen sheet use a layer above the account bottom nav.
- From the tablet/desktop breakpoint, the normal bottom-right position remains.
- Opening the full-screen sheet fully covers the account navigation.
- Closing restores focus to the relocated launcher.

No locale may mirror the launcher to the left.

### Composer and sheet hardening

- Give the textarea an explicit localized accessible name while retaining
  `dir="auto"`.
- Keep input text at least 16px on mobile.
- Apply bottom safe-area padding to the full-screen composer.
- Guarantee 44px targets for close, retry, load older, scroll-to-bottom, and
  New conversation controls.
- Preserve reduced motion and physical customer-right/assistant-left geometry.

### Authenticated browser regression

Add one dedicated Chromium test using the real local registration flow and
disposable CI database. It:

1. registers a synthetic user;
2. reaches `/my-account` authenticated;
3. verifies the chat launcher is visible and above—not overlapped by—the mobile
   account navigation;
4. opens chat and verifies the full sheet is above the navigation;
5. closes and verifies focus restoration;
6. uses the same authenticated session to open `/en/my-account` and verifies
   English locale/direction plus the same launcher geometry.

No test-only authentication route or production credential is introduced.

## Phase 2 architecture

### Direct streaming flow

```text
Customer messages
      │ persisted immediately by existing FIFO API
      ▼
1.5 s client quiet timer
      │
      ▼
POST /chat/conversations/{publicId}/agent-turns
      │ owner scope + DB transaction + turn idempotency
      ▼
AgentTurn claims every unprocessed customer message
      │
      ▼
OpenAiResponsesAgentModel (gpt-5.6-luna, store=false, stream=true)
      │ Responses API SSE events
      ▼
Laravel streamed response
      │
      ▼
React incremental assistant bubble
      │
      ▼
final ChatMessage + AgentRun usage persisted by Laravel
```

The browser uses `fetch()` with a readable response stream, not `EventSource`,
because turn creation is an authenticated POST.

### Hostinger feasibility gate

Before the real provider is enabled in production:

1. a fake deterministic provider streams multiple delayed deltas through the
   production Laravel/PHP path;
2. the tester browser must observe deltas before the response completes;
3. disconnect/reload must recover the durable turn result;
4. proxy buffering, PHP timeout, and early disconnect behavior are recorded.

If Hostinger buffers the complete response, public Phase 2 rollout stops. The
team then chooses between a non-streaming/polling product change and a managed
worker/streaming service. Fake or cosmetic streaming must not be shipped.

### Model abstraction

Use a provider abstraction rather than a model-named business service:

```text
AgentModel
  └── OpenAiResponsesAgentModel
          configured model: gpt-5.6-luna
```

The interface receives a provider-neutral prompt/turn value object and emits
provider-neutral stream events plus a final result. Prompt construction,
authorization, persistence, retries, cost policy, and customer error copy stay
outside the adapter.

Laravel's HTTP client calls the official `/v1/responses` boundary directly.
No unverified community PHP SDK is introduced. The adapter sends:

- `model: gpt-5.6-luna`;
- `store: false`;
- `stream: true`;
- bounded input;
- bounded output tokens;
- a pseudonymous safety identifier derived from the owner scope, never an
  email, user ID, public conversation ID, or guest token.

Official model pricing and availability are operational configuration, not a
permanent code guarantee. The approved baseline at design time is documented
from OpenAI's current model catalog.

### Durable turn model

Add `agent_turns`:

- public ID and conversation foreign key;
- status: `waiting`, `running`, `completed`, `failed`, `cancelled`;
- first and last customer message numeric IDs;
- `debounce_until`;
- prompt version;
- attempt count;
- started/completed timestamps;
- sanitized terminal error code;
- unique `(conversation_id, last_customer_message_id)` idempotency boundary.

Add `agent_runs`:

- public ID and agent-turn foreign key;
- provider and model;
- provider response ID;
- status;
- latency;
- input, cached-input, output, reasoning, and total token counts when returned;
- estimated USD cost using a versioned operational rate;
- started/completed timestamps;
- sanitized provider error code;
- trace ID.

No prompt body, API key, raw provider payload, chain-of-thought, or message
content is written to run logs.

### Turn coalescing and concurrency

- Customer messages remain separate `chat_messages`.
- The timer begins only after a customer message is successfully persisted.
- Every new persisted customer message resets the 1.5-second client timer.
- On turn creation, Laravel locks the conversation and selects all customer
  messages after the last completed/claimed turn cursor.
- If the latest message has not been quiet for 1.5 seconds, the endpoint returns
  `202` with a bounded `retry_after_ms`; it does not call the model.
- A unique last-message boundary and running-turn lock prevent two tabs from
  creating duplicate provider calls.
- Messages arriving during a running turn remain unprocessed and form the next
  turn.
- A failed turn can retry the same message range without creating a second
  assistant message.

### Prompt and context

Store the system prompt as a versioned repository resource. Persist the exact
prompt version on every turn.

Initial context includes only:

- the current conversation's bounded recent messages;
- locale and authenticated/guest boolean;
- an explicit statement that no live commerce tools are available;
- the approved Arab UT tone and live-fact refusal rules.

The model never receives:

- raw guest tokens or owner keys;
- passwords, EA credentials, backup codes, payment secrets, or internal logs;
- full browser history;
- other conversations;
- live prices, orders, wallet, cart, or availability data.

Initial guardrails:

- maximum 24 recent messages;
- maximum 500 output tokens;
- low reasoning effort for the initial support-only runtime;
- one provider call per run because no tools exist;
- 5-second connect timeout and 45-second total provider timeout;
- localized failure rather than a fabricated answer;
- explicit refusal to invent live price/order/wallet/payment/availability
  values.

These values are versioned configuration and may change only with evaluation
evidence.

### Stream contract

Laravel emits trusted SSE-shaped events over the POST response:

- `turn.created` — public turn ID;
- `response.delta` — plain text delta;
- `response.completed` — final message contract and usage-safe summary;
- `response.failed` — localized safe error code/message;
- comment heartbeat when required to keep the connection open.

The frontend treats every delta as text. It never renders provider HTML.

The final assistant message is persisted once. A reconnect fetches the durable
turn/message state rather than asking the provider again. A partial bubble is
visually marked streaming and is not treated as a stored final answer.

### Provider outage and disconnect behavior

- No API key or disabled AI: eligible users see a localized unavailable state;
  no provider call occurs.
- Connect timeout or provider 5xx: mark run/turn failed with a sanitized code,
  keep customer messages, and expose retry.
- Rate limit: honor bounded backoff once, then fail safely.
- Browser disconnect: Laravel continues finalization where the PHP runtime
  permits it. The stream handler uses `ignore_user_abort(true)` and a terminal
  `finally` path to persist completed/failed state. The Hostinger feasibility
  gate must prove this behavior; on reload the client polls the turn and
  recovers completed or failed state.
- Incomplete provider response: never persist it as a completed assistant
  message.
- Storefront and deterministic chat endpoints remain functional when OpenAI is
  unavailable.

## Rollout and configuration

Add separate flags:

```text
AI_ASSISTANT_ENABLED=false
AI_ASSISTANT_ROLLOUT=disabled
AI_ASSISTANT_TEST_USER_IDS=
AI_MODEL_PROVIDER=openai
AI_MODEL=gpt-5.6-luna
OPENAI_API_KEY=<secure Hostinger value>
AI_TURN_DEBOUNCE_MS=1500
AI_MAX_CONTEXT_MESSAGES=24
AI_MAX_OUTPUT_TOKENS=500
AI_REASONING_EFFORT=low
AI_CONNECT_TIMEOUT_SECONDS=5
AI_REQUEST_TIMEOUT_SECONDS=45
```

Allowed rollout values:

- `disabled`;
- `authenticated_testers`;
- `public`.

Code and migrations deploy with `disabled`. Mohamed supplies the API key only
through the approved secure Hostinger path; it is never pasted into chat,
commits, GitHub logs, or frontend props.

For an AI-eligible owner, AI reply mode suppresses the deterministic demo reply.
For an ineligible owner, current demo behavior remains controlled by
`CHAT_DEMO_ASSISTANT`. Both modes never reply to the same customer message.

Promotion order:

1. disabled after deployment;
2. fake-provider streaming feasibility;
3. authenticated Mohamed tester with real Luna;
4. manual Arabic/English acceptance and cost review;
5. separate approval before `public`.

## Accounts and operational requirements

Required before real Luna testing:

- an OpenAI API project with billing enabled;
- access to `gpt-5.6-luna` for that project;
- a project API key stored server-side in Hostinger shared environment;
- an approved tester user ID;
- inspection of the OpenAI project's configured data-retention control;
- confirmation of Hostinger streamed-response behavior and PHP execution
  limits.

No secret is requested in chat. CI uses the fake provider and requires no
external network or OpenAI credential.

## Testing strategy

### Phase 1 focused contracts

- one open conversation per guest/user under true concurrent acquisition;
- forward invariant migration on SQLite and MariaDB;
- atomic conversation plus onboarding creation;
- login claim preserves guest public ID and closes conflicting user thread;
- inactivity close, seven-day reopen, explicit restart, and non-reopen reasons;
- 30/180-day purge boundaries and idempotent maintenance command;
- canonical duplicate message plus reply recovery;
- consistent 404/409/422/429/500 no-store envelopes;
- composer name, safe area, 44px targets, reduced motion, and account offset;
- real registration-to-account Chromium regression.

### Phase 2 focused contracts

- provider adapter maps Responses API request/events without leaking secrets;
- `store: false`, exact model/config bounds, and pseudonymous safety identifier;
- rapid-message debounce/coalescing and two-tab concurrency;
- one message range creates one turn and at most one completed assistant
  message;
- messages arriving during a run form the next turn;
- streaming delta order and final persistence;
- disconnect/reload recovery;
- timeout, rate limit, provider 5xx, malformed event, incomplete response, and
  retry behavior;
- no live-value fabrication when no tools exist;
- Arabic, English, and mixed-language behavior;
- token/latency/cost metadata without content or credential logs;
- disabled/tester/public rollout enforcement.

### Complete gates

Each release runs the existing Pest, MariaDB, Vitest, static, Vite, and Chromium
gates. CI never calls the real model provider.

## Acceptance gates

### Phase 1 Completion acceptance

- Account launcher is visible, reachable, and not overlapped on real mobile.
- Full sheet covers account navigation and restores focus on close.
- Guest, login claim, authenticated, refresh, and navigation continuity work.
- New conversation creates a new public ID and does not reopen the explicit old
  thread.
- Inactivity/reopen/retention rules have automated evidence.
- No duplicate open conversation or duplicate message/reply under concurrency.
- Arabic/English at 320, 390, 768, and 1440 pass the required UI checks.
- CI/deploy/production verification are green.
- Mohamed approves manual Phase 1 behavior.

### Phase 2 acceptance

- Hostinger proves actual incremental streaming before completion.
- Four rapid customer messages create four stored messages and one agent turn.
- Luna produces one natural bilingual response through the provider abstraction.
- Reload recovers the durable completed turn without another provider call.
- Provider outage and timeout preserve the customer message and never fabricate
  live data.
- No API key, sensitive customer data, raw provider payload, or chain-of-thought
  appears in browser props, logs, DB traces, or errors.
- Token, latency, model, prompt version, and estimated cost are recorded.
- Authenticated-tester rollout works while public users remain on the existing
  safe mode.
- Focused eval set passes and Mohamed approves the tester experience and cost.
- Public rollout remains a separate explicit decision.

## Delivery stages

1. **Design/spec and implementation plan** — documentation only.
2. **Phase 1 lifecycle backend** — migration, invariant, acquisition, restart,
   maintenance, error contracts.
3. **Phase 1 UI/account regression** — launcher layering, safe area,
   accessibility, authenticated browser test.
4. **Phase 1 production acceptance** — full CI, deploy, read-only verification,
   Mohamed manual test, status update.
5. **Phase 2 durable runtime foundation** — turns/runs, prompt version,
   provider-neutral fake adapter, rollout resolver.
6. **Hostinger streaming feasibility** — real PHP/browser path with fake
   provider; stop on buffering.
7. **Luna adapter and streaming UI** — OpenAI Responses API, tester-only real
   provider, resilience and cost evidence.
8. **Phase 2 production tester acceptance** — full gates, secure configuration,
   deploy disabled, enable tester, verify, status update.

Every completed stage is independently reviewed, committed, pushed to `main`,
and allowed through the existing CI/deployment path before the next production
stage.

## Risks and controls

- **Hostinger buffers streaming:** feasibility gate stops rollout; do not fake
  streaming or silently adopt minute polling.
- **Duplicate turns from tabs/retries:** database lock plus unique last-message
  boundary returns the canonical turn.
- **Provider application state:** use `store: false`; Laravel reconstructs
  bounded context and remains memory authority. This does not claim zero abuse
  monitoring or zero provider retention; the OpenAI project retention control
  is inspected and documented before real customer testing.
- **Runaway cost:** bounded context/output, one provider call, per-owner rate
  limit, tester rollout, usage/cost records, kill switch.
- **Live-value hallucination:** no live tools in Phase 2 and prompt/evals require
  explicit unavailability language.
- **Disconnect loses answer:** durable turn/run state and final-message
  idempotency support reload recovery.
- **Retention deletes active work:** maintenance skips active turns and uses
  last activity plus owner-specific windows.
- **Login creates duplicate open threads:** guest continuity wins inside one
  locked transaction and the unique active-owner invariant prevents duplicates.
- **Account launcher collides again:** account-aware offset plus authenticated
  mobile browser geometry regression.

## External technical references

- [OpenAI model catalog and GPT-5.6 Luna](https://developers.openai.com/api/docs/models)
- [OpenAI Responses API create/stream contract](https://developers.openai.com/api/reference/typescript/resources/beta/subresources/responses/methods/create)
- [Intercom close/reopen conversation behavior](https://www.intercom.com/help/en/articles/8363763-close-a-conversation)
- [European Commission storage-limitation principle](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/principles-gdpr_en)

These references inform the design. Repository code, approved product decisions,
and Laravel authorization remain authoritative for Arab UT.
