# Decision record

**Lifecycle:** Implemented record
**Verified:** 2026-08-21

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
safe area; it does not create production users. Mohamed retains final physical
iPhone/Safari and real-account acceptance.

## 2026-08-21 — Hostinger scheduling is an external acceptance gate

The Laravel hourly schedule is implemented, but recurring `schedule:run`
execution is not verified. The SSH account has no `crontab` command and this
task has no hPanel/API credentials, so no local scheduler or inferred evidence
is substituted. An authorized operator must configure and verify the exact
hPanel Cron Jobs entry documented in [OPERATIONS.md](OPERATIONS.md).

## 2026-08-20 — Session boundary remains unchanged

Production read-only evidence: database sessions with encryption enabled. Chat
tables retain only an HMAC guest key; the raw token remains in the Laravel
session. No session configuration change is made: it can invalidate active
sessions, and nonproduction defaults are not inferred from production. `AI-B09`
remains open pending an owner security decision.

## 2026-08-20 — Phase 2 stays behind acceptance

No provider runtime, model, prompt, RAG, tool, streaming transport, operator
inbox, or Phase 2 plan starts with this release. Phase 1 owner acceptance is a
prerequisite.

Historical plans do not override [STATUS.md](STATUS.md), this record, current
source, or a newer explicit owner decision.
