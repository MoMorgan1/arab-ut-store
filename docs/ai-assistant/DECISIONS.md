# Decision record

**Lifecycle:** Implemented record
**Verified:** 2026-08-22

## 2026-08-22 — Failed public gate requires disable and remediate

The first clean direct-public Luna batch completed all 16 cases durably but
failed mandatory acceptance: mixed-language quality was 2/4, safety-critical
contracts were 7/8, and maximum first-visible content was 10.663 seconds. The
batch passed Arabic, English, boundary, terminal-latency, usage/cost, and the
recorded content-safety checks. This outcome does not change or waive a
threshold.

Mohamed selected **Disable and remediate**. Production was recached with AI
disabled, rollout `disabled`, an empty provider selector, and the accepted
Phase 1 demo enabled. A public proof received the demo and produced no agent
turn. Phase 2 remains implemented/deployed but inactive and unaccepted.

Direct-public rollout remains the approved topology for the next attempt; it is
not permission to reactivate before review. Re-entry requires owner approval of
the exact remediation, correction and execution of an actual deployed-handler
inspection, a live canary, resilience probes, and one new complete 16-case batch
meeting every mandatory threshold. No prompt/default/threshold change is
authorized silently.

## 2026-08-22 — Direct Luna rollout replaces the staged fake gate

Mohamed directed Phase 2 to skip the production fake-provider gate entirely
("start directly with luna no fake") and chose **public rollout**: every
visitor, including guests, reaches Luna at go-live ("do not exclude to me
only no need for my id"). Accepted consequences: the first production
streaming proof happens with a live key and real spend, and disconnect/
reload recovery is proven under Luna instead of the fake provider.
Mitigations that remain mandatory: the owner sets the OpenAI project spend
ceiling before any key entry; existing turn/IP rate limits stay; the kill
switch stays; Task 11 evaluation thresholds still gate acceptance; the source
key enters through Hostinger's shared `.env` only after the deployed-SHA
`agent:inspect-streaming-http` outbound-handler check passes. Laravel
configuration caching may materialize the resolved value in retained release
caches, which stay inside the production secret boundary. The Task 9 fake-gate
stop is superseded by this decision.

## 2026-08-21 — Phase 2 started with lead-debate amendments

Mohamed approved starting Phase 2 implementation and accepted the amendments
from the orchestrator-versus-Sol architecture debate: output budget raised to
1000 tokens; total request deadline lowered to 30 seconds; stale sweep lowered
to 60 seconds and required to exceed the deadline by at least 15 seconds;
disconnect/reload `finally` finalization is the primary recovery with the
minute sweeper as process-death backstop; credential guarding requires
label-plus-nearby-value context, Luhn only near card terminology, and no
eight-digit-group heuristic; prior assistant context that trips the guard is
excluded from prompts instead of blocking the current claim; terminal fallback
fails a nonterminal run and its turn atomically; eval latency thresholds are
stated as maxima. Public rollout remains unapproved.

## 2026-08-21 — Mohamed accepted Phase 1

Mohamed accepted the deployed Phase 1 release after the recurring hPanel
scheduler evidence and his real-account/physical iPhone/Safari review passed.
The accepted release remains
`d77385a44e7ac1413aab419f79d38fc2040be650`; acceptance does not erase the
limited `AI-B04`, `AI-B09`, or `AI-F04` findings recorded in [AUDIT.md](AUDIT.md).

## 2026-08-20 — Close/reopen/restart lifecycle

An owner may have one open conversation. Inactive threads close after 24 hours
and may reopen only within seven days of last activity. Guest/authenticated
retention is 30/180 days of last activity. The approved decision required all
three clocks to use `last_message_at`, falling back to `closed_at`, then
`updated_at` only for legacy nulls. Reopen/reclose alone does not extend the
activity anchor. New conversation closes the old open thread and creates a new
one, so an explicitly closed thread does not reopen.

Current source applies the fallback to reopen and retention, but auto-close
queries `last_message_at` directly. A legacy open row with a null value therefore
does not auto-close. This implementation drift remains open; this decision
record does not silently redefine the approved lifecycle.

## 2026-08-21 — Legacy reply association is not guessed

Canonical replay is guaranteed where `reply_to_message_id` explicitly links a
post-migration assistant reply to its customer message. Pre-migration assistant
rows have no provable reply identity, and historical concurrent inserts can
interleave. A heuristic timestamp/order backfill was rejected: legacy replay
returns the stored customer and `demoReply: null` rather than creating or
misassociating a link.

## 2026-08-20 — Database is the active-owner authority

The generated `active_owner_key` plus unique index is the MariaDB one-open-owner
invariant; SQLite has equivalent trigger/index behavior. The migration backfills
rows, closes historical duplicates, and takes a write table lock while
installing the MariaDB generated column/index. Production found no active owner
duplicates and confirmed `LOCK TABLES` permission.

## 2026-08-20 — Account launcher and browser evidence

The account chat root is above the mobile navigation with safe-area spacing.
Chromium regression coverage uses one synthetic local account across the exact
seven-test suite. The account scenario covers 320/390 mobile modal and
768/1440 anchored nonmodal behavior in Arabic and English, including emulated
safe area; it does not create production users. Mohamed completed the separate
physical iPhone/Safari and real-account acceptance on 2026-08-21.

## 2026-08-21 — Hostinger recurring scheduling is verified

Owner-provided hPanel evidence shows the documented command at the manual
`* * * * *` schedule and successful `orders:publish-paid-events` output at
`2026-08-21 10:14:01`. A subsequent read-only `schedule:list` on the active
release confirmed the minute publisher and hourly chat maintenance event. The
external scheduler gate is complete; local cron inference was not substituted
for the owner evidence.

## 2026-08-20 — Session boundary remains unchanged

Production read-only evidence: database sessions with encryption enabled. Chat
tables retain only an HMAC guest key; the raw token remains in the Laravel
session. No session configuration change is made: it can invalidate active
sessions, and nonproduction defaults are not inferred from production. `AI-B09`
remains open pending an owner security decision.

## 2026-08-21 — Historical Phase 2 proposal

**Superseded:** Implementation approval and the 2026-08-22 rollout/outcome
decisions above replaced this proposal state and several listed defaults. It is
retained only as a decision-history snapshot; use [STATUS.md](STATUS.md),
[AGENT-RUNTIME.md](AGENT-RUNTIME.md), and current source for active behavior.

Phase 1 acceptance now permits planning, not implementation. The executable
Phase 2 runtime plan is proposed and awaits Mohamed's explicit approval. Its
proposed operational defaults are:

- direct authenticated POST streaming with no debounce queue worker;
- provider-neutral `AgentModel`, direct OpenAI Responses adapter, model
  `gpt-5.6-luna`, `store: false`, streamed required-event mapping, 500 total
  output tokens, `low` reasoning, and `support-v1`;
- immutable nullable message eligibility/block timestamps: legacy/demo/old
  unreplied rows remain ineligible; claims use only eligible, unblocked,
  unreplied customers; prior assistant context is completed-agent-only;
- at most 24 claimed messages, one nonterminal turn per conversation,
  conversation -> turn -> run locks, and no lock during provider I/O;
- six turn starts per owner/minute, 20/IP/minute, three attempts, one bounded
  automatic 429 retry, a two-second automatic wait cap, and 120-second stale
  recovery from the verified minute scheduler;
- automatic 429 fails the run but keeps the turn nonterminal waiting, sleeps
  outside locks, exposes no explicit retry, and terminalizes timeout if the
  shared deadline expires during the wait;
- fail-closed disabled/rollout/provider defaults; fake and OpenAI providers;
  disabled, authenticated-tester, and public configuration values, with public
  neither enabled nor approved;
- a production fake authenticated-tester gate with exactly three localized
  350ms deltas, observable incremental delivery, and disconnect/reload durable
  recovery; failure stops OpenAI work;
- a server-derived pending-after-terminal boolean: at default limit 24, 25 rows
  drain as 24 + 1/two starts; nondefault validated limits drain in configured
  chunks, including reload/poll recovery;
- validated config consumers, typed retry policy, lazy provider resolution
  after the sensitive guard, and a 45-second monotonic deadline covering
  connect/headers/body/parser/automatic wait/retry with bounded per-read time;
- explicit Guzzle `StreamHandler` tested on loopback and gated on production
  web-PHP `allow_url_fopen`/HTTP(S) wrappers before key entry; Luna's first
  delta is final proof;
- one final assistant message of at most 4000 Unicode characters, content-free
  run records, conversation-cascaded 30/180-day retention, and versioned Luna
  input/cached/cache-write/output cost categories without double-charging
  reasoning tokens;
- a 64-character in-memory HMAC safety identifier and a real project key only
  in Hostinger shared `.env` after the fake gate;
- authenticated-tester Luna acceptance only. Public rollout requires a new
  explicit owner decision.

At the time of this proposal none of these runtime/schema/config/interface
names or behaviors existed. The subsequent implementation used approved
amendments including a 1,000-token output limit, 30-second deadline, 60-second
stale threshold, and direct-public rollout. Its first mandatory evaluation then
failed and was contained as recorded above.

Historical plans do not override [STATUS.md](STATUS.md), this record, current
source, or a newer explicit owner decision.
