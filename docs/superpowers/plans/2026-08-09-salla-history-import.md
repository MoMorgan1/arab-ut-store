# Salla History Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan one task at a time with review after each task.

**Goal:** Preserve the supplied Salla customers, orders, items, and wallet balances in a private, idempotent historical import and expose calculated homepage proof without forcing incomplete old records into live fulfillment.

**Architecture:** A Laravel console command streams two private UTF-8 CSV inputs into encrypted legacy archive models. Customer identities link to normal users where safe; historical orders/items remain queryable archive records. The operational order schema and WordPress database are not mutated.

**Tech Stack:** Laravel 13, PHP 8.3, MariaDB/SQLite, Pest 4, native CSV streaming, existing Laravel encryption and console facilities.

## Global constraints

- Default to dry-run; writes require `--commit`.
- Make the command isolatable so overlapping committed imports cannot run.
- Never commit, log, print, or serialize source PII.
- Preserve all source records even when a canonical user cannot be linked.
- Never invent email addresses, verification, passwords, FX rates, platforms, coin quantities, or payment timestamps.
- No new Composer dependency. The one customer workbook is converted privately to UTF-8 CSV before import.
- Status and SKU mappings are explicit configuration; unknown values fail closed in committed mode.
- Identical file reruns are no-ops.
- Real import work targets a disposable local database only in this milestone; production and WordPress remain untouched.

---

## Task 1: Add the historical archive schema and protected models

**Files:**
- Create: `database/migrations/2026_08_09_000006_create_legacy_import_tables.php`
- Create: `app/Models/LegacyImportRun.php`
- Create: `app/Models/LegacyCustomer.php`
- Create: `app/Models/LegacyOrder.php`
- Create: `app/Models/LegacyOrderItem.php`
- Modify: `app/Models/User.php`
- Modify: `tests/Feature/Database/DomainSchemaTest.php`
- Create: `tests/Feature/Legacy/LegacyArchiveSchemaTest.php`

- [ ] Write failing tests for nullable imported-user email, unique source identities, required archive relationships, encrypted/hidden payloads, decimal-string casts, and cascade/restrict behavior.
- [ ] Run focused schema tests and capture RED.
- [ ] Implement the four archive tables and model relationships. Alter `users.email` to nullable and unique in the new migration; new registration validation remains unchanged.
- [ ] Add source-system plus source-ID unique constraints and a unique committed file-hash pair.
- [ ] Verify migrations on SQLite and the MariaDB CI profile, then run focused tests GREEN.
- [ ] Review schema/model serialization for PII leakage.
- [ ] Commit: `feat: add protected historical import archive`.

---

## Task 2: Build normalization, mapping, and dry-run inspection

**Files:**
- Create: `config/legacy-salla.php`
- Create: `app/Console/Commands/ImportSallaHistory.php`
- Create: `app/Services/LegacyImport/CsvSource.php`
- Create: `app/Services/LegacyImport/ImportReport.php`
- Create: `app/Services/LegacyImport/LegacyNormalizer.php`
- Create: `app/Services/LegacyImport/SallaHistoryInspector.php`
- Create: `tests/Fixtures/Legacy/*.csv`
- Create: `tests/Unit/LegacyImport/LegacyNormalizerTest.php`
- Create: `tests/Feature/Legacy/SallaHistoryDryRunTest.php`

- [ ] Write fictional fixture tests for exact header validation, Unicode/Saudi phone normalization, email validation, one-token/Arabic names, decimal strings, explicit status mapping, SKU classification, mixed currencies, and redacted output.
- [ ] Capture RED before production implementation.
- [ ] Implement streaming native CSV readers with UTF-8 BOM handling and constant-memory row iteration; use an empty CSV escape value rather than PHP's deprecated default.
- [ ] Implement the audited status map and conservative SKU classifier in configuration.
- [ ] Implement an isolatable dry-run command using Laravel's current attribute signature/description API. Its report contains only counts, hashes, reason codes, status/currency aggregates, and reconciliation results.
- [ ] Reject unknown headers/statuses and invalid order/item identities; retain identity conflicts as aggregate warnings.
- [ ] Run focused tests GREEN, then clean-code and test-quality review.
- [ ] Commit: `feat: inspect Salla history imports safely`.

---

## Task 3: Import customers and wallet opening balances idempotently

**Files:**
- Create: `app/Services/LegacyImport/SallaCustomerImporter.php`
- Create: `app/Services/LegacyImport/CustomerIdentityResolver.php`
- Create: `app/Services/LegacyImport/WalletOpeningImporter.php`
- Modify: `app/Console/Commands/ImportSallaHistory.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/Legacy/SallaCustomerImportTest.php`
- Create: `tests/Feature/Legacy/SallaWalletImportTest.php`
- Modify: `tests/Feature/Auth/AuthenticationTest.php`

- [ ] Write RED tests for source-ID reruns, phone/email matching, duplicate source identities, split identity conflicts, phone-only accounts, null passwords, no verification, no notifications, and exact wallet opening references.
- [ ] Import customer source records in chunks inside transactions and link/create users conservatively.
- [ ] Fill only blank live profile fields; never overwrite an existing customer's established identity silently.
- [ ] Append one SAR opening wallet entry per nonzero source customer balance and an adjustment delta only for a changed source balance.
- [ ] Prove a second identical committed run changes no user, archive, or wallet counts.
- [ ] Run focused authentication/customer/wallet tests plus schema tests.
- [ ] Commit: `feat: import Salla customers and wallet credit`.

---

## Task 4: Import all historical order headers and items

**Files:**
- Create: `app/Services/LegacyImport/SallaOrderImporter.php`
- Modify: `app/Console/Commands/ImportSallaHistory.php`
- Create: `tests/Feature/Legacy/SallaOrderImportTest.php`
- Create: `tests/Feature/Legacy/SallaImportIdempotencyTest.php`

- [ ] Write RED tests for multi-row grouping, exact item cardinality, source item uniqueness, all audited statuses, mixed currencies without conversion, unknown-status failure, unclaimed orders, missing SKUs, and deterministic reruns.
- [ ] Stream/group rows by source order ID, validate repeated header invariants, and upsert each order plus every item by source identity.
- [ ] Link orders by the resolved normalized mobile when unambiguous; create a phone-only imported user for valid order mobiles absent from the customer file; keep unusable identities unclaimed.
- [ ] Store all source money as exact decimal strings with original currency and keep operational order/payment tables untouched.
- [ ] Encrypt raw header/item payloads and prove model arrays, reports, logs, and exceptions remain redacted.
- [ ] Verify the second identical import is a no-op.
- [ ] Commit: `feat: import complete Salla order history`.

---

## Task 5: Use calculated proof and rehearse the real files locally

**Files:**
- Create: `app/Services/Store/StoreProofReader.php`
- Modify: `config/store.php`
- Modify: `app/Http/Controllers/Store/HomeController.php`
- Modify: `tests/Feature/Store/HomeCoinsConfiguratorTest.php`
- Create: `tests/Feature/Store/StoreProofReaderTest.php`
- Modify: `docs/superpowers/specs/2026-08-09-wordpress-hero-coins-parity-design.md`

- [ ] Write RED tests proving imported completed counts override the audited fallback while empty/unavailable storage uses `8,877` and `29,161`.
- [ ] Implement a cached aggregate reader for distinct linked customers on completed historical orders and completed historical order count.
- [ ] Keep `30B+` Coins and `99.9%` security fixed and clearly separated in configuration from calculated proof.
- [ ] Convert the supplied customer workbook to a private UTF-8 CSV outside Git and record source hashes in the import run.
- [ ] Create a disposable local database, migrate it, run dry-run, run committed import, and run the same committed import again.
- [ ] Reconcile exactly: 13,081 customer source records; 34,211 orders; 36,210 items; 29,161 completed orders; 8,877 distinct completed customer identities; 1,724 nonzero wallet sources; wallet total 10,191.35 SAR under the documented store-base-currency assumption.
- [ ] Run the full PHP/frontend CI gate, secret scan, docs guard, and `git diff --check`.
- [ ] Commit: `feat: calculate storefront proof from imported history`.

---

## Final handoff

Update the existing draft PR with task commits and exact verification evidence. Report the local rehearsal separately from production: no source WordPress write, customer notification, n8n call, or production database import is authorized by this plan.
