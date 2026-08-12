# Salla Review Archive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import every currently published Salla review once as a safe durable Laravel archive, display it, verify it, and retire recurring Salla review synchronization; local review submission remains a separately approved future release.

**Architecture:** n8n projects the source response into a PII-free bounded archive payload. Laravel supports deterministic batched/idempotent archive ingestion and never calls n8n during customer requests. Existing review readers/pages render the resulting database rows; future local review submission is a separate release.

**Tech Stack:** n8n, Laravel 13 console/action, MariaDB/SQLite, Pest, React/Inertia, Vitest.

## Global Constraints

- Import all published ratings, including low ratings.
- No phone, email, customer object, avatar, raw order ID, or raw payload persistence/output.
- Imported rows are not verified.
- Dry run before apply; apply is idempotent and preserves last-good state on failure.
- No production code before failing tests.

---

### Task 1: Safe one-time archive importer

**Files:**
- Modify: `app/Actions/Reviews/ImportStoreReviews.php`
- Modify: `app/Console/Commands/RefreshStoreReviews.php`
- Create: `app/Console/Commands/ImportSallaReviewArchive.php`
- Modify: `tests/Unit/Reviews/ImportStoreReviewsTest.php`
- Modify: `tests/Feature/Console/RefreshStoreReviewsTest.php`
- Create: `tests/Feature/Console/ImportSallaReviewArchiveTest.php`
- Modify: `docs/operations/storefront-runbook.md`

**Interfaces:**
- Consumes exact safe `{reviews:[...]}` batches with stable source IDs.
- Produces idempotent `salla-import` review rows and count/rating summaries without record content.

- [ ] Add failing tests for 731 deterministic rows, batching, idempotency, all ratings, source isolation, PII rejection, malformed rollback, and last-good retention.
- [ ] Run focused Pest and confirm failures for the current 500-row/recurring contract.
- [ ] Add the one-time dry-run/apply command and archive-mode importer without weakening the existing strict field allowlist.
- [ ] Ensure output contains counts/distribution only and never names/text/source IDs.
- [ ] Run focused Pest, PHPStan, Pint, and PII/log sink scans.
- [ ] Commit Laravel archive support.

### Task 2: Safe n8n archive projection and controlled import

**Files:**
- Create: `automation/n8n/salla-review-archive-v1/workflow.json`
- Create: `automation/n8n/salla-review-archive-v1/scripts/build-workflow.mjs`
- Create: `automation/n8n/salla-review-archive-v1/tests/workflow.test.mjs`
- Create: `automation/n8n/salla-review-archive-v1/package.json`
- Create: `automation/n8n/salla-review-archive-v1/README.md`

**Interfaces:**
- Consumes the existing Salla review credential/source inside n8n.
- Produces only exact public review objects accepted by the Laravel archive command.

- [ ] Add failing package tests for public projection, nested public text selection, PII stripping, published-only rows, stable dedupe, all-rating retention, deterministic newest-first batches, and dry-run/apply separation.
- [ ] Run package tests and confirm missing workflow behavior fails.
- [ ] Generate an inactive secret-free workflow with manual dry run/apply and terminal failure paths.
- [ ] Require exact expected counts and reject malformed/ambiguous source data before apply.
- [ ] Run package tests, export freshness, ESLint, Prettier, and secret scans.
- [ ] Commit workflow package.

### Task 3: Import, render, and retire recurring sync

**Files:**
- Modify only operational environment/workflow state after dry-run approval; no secrets enter Git.
- Update: `docs/operations/storefront-runbook.md`

**Interfaces:**
- Consumes safe dry-run/apply output.
- Produces the verified historical archive and an inactive legacy recurring Reviews workflow.

- [ ] Run production dry run and record only total, rating distribution, duplicate, invalid, and PII-drop counts.
- [ ] Apply the complete archive and verify persisted count/idempotent rerun.
- [ ] Verify homepage and `/reviews` in AR/EN with no private fields in HTML/Inertia/logs.
- [ ] Disable the recurring Salla Reviews workflow only after successful rendering and database verification.
- [ ] Run full backend/frontend gates and browser matrix.
- [ ] Commit runbook evidence without credentials or customer content.
