# Decision record

**Lifecycle:** Implemented record
**Verified:** 2026-08-21

## 2026-08-21 — Mohamed accepted Phase 1

Mohamed accepted the deployed Phase 1 release after the recurring hPanel
scheduler evidence and his real-account/physical iPhone/Safari review passed.
The accepted release remains
`d77385a44e7ac1413aab419f79d38fc2040be650`; acceptance does not erase the
limited `AI-B04`, `AI-B09`, or `AI-F04` findings recorded in [AUDIT.md](AUDIT.md).

## 2026-08-20 — Close/reopen/restart lifecycle

An owner may have one open conversation. Inactive threads close after 24 hours
and may reopen only within seven days of last activity. Guest/authenticated
retention is 30/180 days of last activity. All three clocks use
`last_message_at`, falling back to `closed_at`, then `updated_at` only for
legacy nulls. Reopen/reclose alone does not extend the activity anchor. New
conversation closes the old open
thread and creates a new one, so an explicitly closed thread does not reopen.
The lifecycle migration, actions, and hourly
`chat:maintain-conversations` command implement this decision.

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

## 2026-08-21 — Phase 2 plan is proposed, not implemented

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

None of these proposed runtime/schema/config/interface names or behaviors
exists in the application yet. Mohamed must also approve the proposed eval
thresholds, and he must set a secure OpenAI project spend ceiling before real
Luna testing.

Historical plans do not override [STATUS.md](STATUS.md), this record, current
source, or a newer explicit owner decision.
