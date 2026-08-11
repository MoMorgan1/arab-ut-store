# Salla Customer, Order, and Wallet History Import

**Status:** Approved by Mohamed's 2026-08-09 request to migrate the supplied exports

**Scope:** Historical customers, order headers, order items, wallet opening balances, reconciliation, and homepage proof metrics

**Complexity:** Ambitious

## Outcome

Import the supplied Salla exports into Arab UT without changing the current WordPress site, losing historical rows, inventing missing customer details, or forcing incomplete historical records into the live fulfillment schema.

The new application will preserve every source customer, order, and item in a queryable historical archive. Valid identities will also create or link customer accounts, and confirmed wallet balances will become immutable opening ledger entries. Imported accounts receive no password and no verification timestamp.

## Audited source facts

- Customer export: 13,081 source records and 13,064 unique normalized mobile numbers.
- Customer email quality: 1,126 blank values, 10 invalid values, and four duplicated valid email values.
- Every customer row has a usable normalized mobile number; 17 mobile values appear on more than one source customer record.
- Orders export: 36,210 unique item rows representing 34,211 unique orders.
- Multi-item structure is sound: 1,470 orders have multiple items, with a maximum of 15 items; repeated order fields are internally consistent.
- Completed history: 29,161 unique orders across five explicitly audited source statuses and 8,877 distinct normalized customer mobiles.
- Order identities are complete: no blank order IDs, blank item references, duplicate item references, or duplicate complete rows.
- Thirty-five valid order mobiles are absent from the customer export. Ten non-completed orders have no usable mobile; those orders remain preserved but unclaimed.
- Historical orders use 14 currencies. Values must remain in their original currency; no silent FX conversion is allowed.
- Wallet export: 1,724 nonzero positive balances totaling 10,191.35. Salla's wallet API represents wallet amounts without a per-entry currency, while this store's authoritative base/checkout currency is SAR, so these balances are imported as SAR opening credit and the assumption is recorded in the import run.
- The export does not include the selected coin quantity for most dynamic Coins orders. Total Coins delivered cannot be recalculated reliably.

## Selected architecture

Use a dedicated historical archive beside the operational order tables.

This is intentionally not a forced conversion of every old line into a live `OrderItem`. Historical rows often lack the exact platform, variation, coin quantity, or service configuration required by the new fulfillment domain. Guessing those values could make an old order look fulfillable again. The archive remains queryable by the future customer and admin order-history screens while live orders continue using the stricter operational schema.

### Tables

`legacy_import_runs`

- Identifies the source system, schema version, customer-file hash, order-file hash, mode, status, and timestamps.
- Stores PII-free counts, warnings, and reconciliation totals.
- The file-hash pair is unique so an identical committed import cannot be duplicated.

`legacy_customers`

- Unique source customer ID and optional linked `user_id`.
- Source creation/last-purchase dates and wallet amount for reconciliation.
- Payload hash plus encrypted raw source payload.
- Raw payload is hidden from serialization.

`legacy_orders`

- Unique source order ID and optional linked `user_id`.
- Explicit mapped status plus the original Arabic status and payment state.
- Original currency and exact decimal order totals as exported, with no FX conversion.
- Source timestamps, payload hash, and encrypted raw order payload.
- The ten orders without a usable identity remain present with `user_id = null` until manually claimed.

`legacy_order_items`

- Unique source item reference and parent historical order.
- Original SKU, product name, quantity, and exact decimal item amounts.
- Best-effort archival service/platform classification only when deterministic; otherwise `legacy` / `unknown`.
- Payload hash and encrypted raw item payload.

The real export files and converted private CSV are never copied into Git. Test fixtures use fictional data only.

## Customer identity and activation

1. An existing legacy source identity wins on rerun.
2. Otherwise match a normalized phone and email when both identify the same user.
3. Otherwise match the single unambiguous phone or email identity.
4. If email and phone point to different users, preserve the legacy record and report an identity conflict; never merge silently.
5. Multiple source customer IDs may link to one user when their normalized identity agrees. Their source records remain separate.
6. Source names fill an imported account; they do not overwrite an existing customer's nonblank live profile.
7. `users.email` becomes nullable so phone-only imported customers do not receive a fake deliverable address.
8. Imported users have `password = null`, and email/phone verification timestamps remain null. The import sends no messages.
9. Email/password activation uses the existing one-time password-reset path. Phone-only customers can later claim the account by proving possession through the approved WhatsApp OTP flow.

## Wallet rules

- Each nonzero source balance creates one deterministic opening-credit reference per source customer record.
- Duplicate source customer records linked to one user retain separate opening references, so the source total reconciles exactly.
- Rerunning the same files creates no duplicate entries.
- A corrected export appends only the balance delta; it never edits or deletes an immutable ledger entry.
- A production rehearsal begins from a database backup. Because wallet entries are append-only, rollback means restoring the rehearsal backup, not pretending that committed ledger history can be deleted safely.

## Historical status mapping

| Source status | Historical mapped status |
| --- | --- |
| `تم التنفيذ` and the four audited `انتهت - ...` completion labels | `completed` |
| `بإنتظار المراجعة` | `received` |
| Both `قيد التنفيذ-...` labels | `in_progress` |
| `مسترجع` | `refunded` |
| `ملغي`, `محذوف`, and every audited `فاشلة...` label | `cancelled` |

The original label always remains available. An unknown future label fails the committed import and appears in the dry-run report; it is never mapped to a default silently. Payment status remains a separate source field because 3,119 completed-status orders are not marked paid.

## Historical product classification

- `COIN_PS` maps to Coins and the combined console market.
- `COIN_PC` maps to Coins and PC.
- `SBC_*` maps to SBC with an unknown historical platform unless the source proves one.
- `FUT` and known FUT SKUs map to FUT Champions with an unknown historical platform unless proven.
- `RIVALS` and known Rivals SKUs map to Rivals with an unknown historical platform unless proven.
- `BUY_COINS`, blank SKUs, and other unmatched products remain archival `legacy` service rows.

Original SKU and product name are always retained. Classification does not make a historical item eligible for fulfillment or sale.

## Homepage proof

The homepage reads calculated proof from committed historical records:

- `+8,877` customers served: distinct linked customer identities on completed historical orders.
- `+29,161` completed orders: unique historical orders mapped to `completed`.

Until the real local import exists, these audited values are the configured fallback. After import, database counts are authoritative.

The remaining values stay fixed as Mohamed requested:

- `+30 مليار` / `30B+` Coins delivered.
- `99.9%` security rate.

They must not be described internally as calculated from the exports.

## Import workflow

1. Convert the customer XLSX to a private UTF-8 CSV outside the repository using the bundled spreadsheet runtime.
2. Run the planned `legacy:import-salla` command without `--commit` for a read-only dry run.
3. Validate headers, file hashes, distinct statuses/currencies, identity conflicts, and all source/reconciliation counts.
4. Run the command against a disposable local database with `--commit`.
5. Run it a second time and prove it is a no-op.
6. Compare customer, order, item, completed-order, wallet, status, and currency totals with the audited source.
7. Production import remains a later deployment operation after backup and staging acceptance; this work does not write to WordPress.

## Safety and observability

- Command output, logs, exceptions, tests, and committed reports contain counts and reason codes only—never names, email addresses, phones, notes, or raw rows.
- Raw payloads use Laravel encryption and are hidden from model arrays/JSON.
- Committed mode uses transactions and deterministic source keys.
- Dry-run is the default; a write requires the explicit `--commit` switch.
- Missing headers, unknown statuses, invalid money, and contradictory identity matches fail closed.

## Verification contract

Automated tests cover header validation, exact decimal parsing, phone/email normalization, name splitting, identity deduplication/conflicts, null-password activation, encrypted/hidden payloads, multi-item grouping, status mapping, mixed currencies, idempotent reruns, wallet opening references, and redacted reports.

The real rehearsal must reconcile exactly to 13,081 customer source records, 34,211 orders, 36,210 items, 29,161 completed orders, and the source wallet count/total before the migration is considered ready for staging.
