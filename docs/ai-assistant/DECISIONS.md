# Decision record

**Lifecycle:** Implemented record
**Verified:** 2026-08-20

## 2026-08-20 — Close/reopen/restart lifecycle

An owner may have one open conversation. Inactive threads close after 24 hours
and may reopen only within seven days. New conversation closes the old open
thread and creates a new one, so an explicitly closed thread does not reopen.
Retention is 30 days for closed guest history and 180 days for closed
authenticated history. The lifecycle migration, actions, and hourly
`chat:maintain-conversations` command implement this decision.

## 2026-08-20 — Database is the active-owner authority

The generated `active_owner_key` plus unique index is the MariaDB one-open-owner
invariant; SQLite has equivalent trigger/index behavior. The migration backfills
rows, closes historical duplicates, and takes a write table lock while
installing the MariaDB generated column/index. Production found no active owner
duplicates and confirmed `LOCK TABLES` permission.

## 2026-08-20 — Account launcher and browser evidence

The account chat root is above the mobile navigation with safe-area spacing.
Chromium regression coverage uses a synthetic local account and emulated safe
area; it does not create production users. Mohamed retains final physical
iPhone/Safari and real-account acceptance.

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
