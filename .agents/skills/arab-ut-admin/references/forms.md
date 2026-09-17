# Forms

## Validation and authorization

- Form Requests validate explicit fields and reject unknown mutation fields.
- Authorization uses the permission Gate/policy; Actions recheck
  high-risk permission and current state.
- Use public IDs at the boundary and locked internal IDs in transactions.

## Sensitive actions

- **No recent-password confirmation on an admin action.** Owner decision,
  2026-09-18: it is not wanted for credential reveal, refund, wallet
  adjustment, customer activation, staff or role changes, catalog and pricing
  changes, settings, or a supplier re-send. What gates those is the permission
  the actor holds, two-factor at sign in, a confirm step that names the record
  and the consequence, and the audit row. Do not ask for a password, and do not
  write copy promising one.
- The one exception is not ours: Fortify guards its own two-factor endpoints
  with `password.confirm` and answers 423 when the confirmation has lapsed.
  Changing two-factor therefore still asks for a password, and
  `/admin/security/confirm-password` is the link that lets an operator give it.
- Credential reveal requires a purpose and confirmation but never echoes secret
  input in errors.
- Destructive confirmations name the record and consequence. Buttons say the
  actual action, not “Yes” or “Submit”.

## Money and idempotency

- Browser sends integer minor units as strings/integers only according to the
  typed request contract; server normalizes and validates.
- Financial input amount is positive; direction/method is a separate enum.
- Wallet adjustment amount and checked addition must stay within the signed
  64-bit nonnegative range used by the schema. Debit underflow fails before any
  write.
- Repeatable mutations carry a client-generated idempotency key. Exact replay
  returns the canonical result; changed payload conflicts.
- Wallet adjustment keys are UUIDs stored as globally unique
  `admin-wallet-adjustment:{uuid}` references. Canonical comparison includes
  wallet, amount, direction, reason code, and case reference; duplicate-key races
  must resolve through replay/conflict logic.
- The browser creates one key per form attempt, retains it through validation
  and transport retries, and replaces it only after canonical success or an
  explicit reset. Never persist it in browser storage.
- Wallet adjustments use an allowlisted reason code and optional bounded case
  reference, not a free-text note. Do not duplicate free-text provider/domain
  reasons into audit metadata.
- Credential reveal likewise uses an allowlisted purpose code and optional
  bounded case reference instead of a free-text reason.

## Stable v1 reason codes

Wallet adjustments:

| Code                      | Arabic            | English                   | Direction    |
| ------------------------- | ----------------- | ------------------------- | ------------ |
| `customer_service_credit` | `رصيد خدمة عملاء` | `Customer service credit` | credit       |
| `refund_correction`       | `تصحيح استرجاع`   | `Refund correction`       | credit       |
| `payment_correction`      | `تصحيح دفعة`      | `Payment correction`      | credit/debit |
| `promotional_credit`      | `رصيد ترويجي`     | `Promotional credit`      | credit       |
| `balance_correction`      | `تصحيح رصيد`      | `Balance correction`      | credit/debit |

Credential reveal:

| Code                     | Arabic          | English                  |
| ------------------------ | --------------- | ------------------------ |
| `fulfillment`            | `تنفيذ الطلب`   | `Order fulfillment`      |
| `customer_support`       | `دعم العميل`    | `Customer support`       |
| `order_review`           | `مراجعة الطلب`  | `Order review`           |
| `incident_investigation` | `تحقيق في حادث` | `Incident investigation` |

## UX

- Persistent visible labels, helper text before the field, inline errors, and an
  announced form summary when useful.
- Disable mutation buttons while processing and preserve safe user input on
  validation failure.
- Never preserve decrypted credentials after the reveal panel closes or route
  changes.
