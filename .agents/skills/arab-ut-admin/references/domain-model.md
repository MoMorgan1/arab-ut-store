# Domain model

## Identity

- `User.role`: `customer`, `admin`, `staff`, or `service_account`.
- `User.is_active` blocks interactive access when false.
- Admin is the owner-level role in v1; Staff is the operational role.
- Admin/Staff must have a password and confirmed Fortify TOTP before ordinary
  Admin access.

## Orders and fulfillment

- `Order` belongs to `User` and owns items, payments, refunds, wallet entries,
  status history, receipt, and notifications.
- `OrderItem` owns at most one encrypted `OrderItemSecret`, optional squad image,
  fulfillment job, attempts, status history, and reviews.
- `OrderItemSecret.encrypted_payload` uses Laravel's encrypted array cast and is
  hidden. Treat `masked_summary` as display-only, never proof of payload validity.
- `SecretAccessLog` records each explicit reveal; `StaffAuditLog` records the
  operator decision/context.
- Terminal order states are `completed`, `cancelled`, and `refunded`.
  `refunded` is produced only by verified refund behavior.

## Payments and refunds

- Payments have unique provider/idempotency identities and captured/refunded
  minor-unit amounts.
- `RefundPaylinkOrder` is authoritative for supported full Paylink refunds.
- Never expose raw provider metadata or credentials to the Admin UI.

## Wallet

- One `WalletAccount` per user.
- `WalletEntry` rows are immutable at database level and ordered by a unique
  per-wallet sequence.
- Amount columns are nonnegative; adjustment direction belongs in validated
  action input and allowlisted metadata.
- Customers without a wallet account are a valid existing state. v1 Admin
  adjustments reject that state and never provision an account silently.
- New adjustment presenters derive credit/debit display from validated direction
  metadata; legacy rows without valid direction remain neutral.
- Update balance and insert the canonical ledger/audit rows in one locked
  transaction.

## Catalog and pricing

- Categories/products/variants can be automation-authoritative.
- Snapshot sync archives missing records and can overwrite managed attributes.
- Coins pricing runs and manual-service price schedules own versioned checkout
  rules. Admin mutations must preserve version increments and current checkout
  validation.

## Chat/support

- Current chat is a deterministic customer foundation with no operator queue,
  assignment, notes, or handoff model.
- Do not represent the planned AI support inbox as an implemented Admin module.
