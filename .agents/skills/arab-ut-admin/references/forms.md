# Forms

## Validation and authorization

- Form Requests validate explicit fields and reject unknown mutation fields.
- Authorization uses the planned permission Gate/policy; Actions recheck
  high-risk permission and current state.
- Use public IDs at the boundary and locked internal IDs in transactions.

## Sensitive actions

- Require recent password confirmation for credential reveal, refund, wallet
  adjustment, customer activation, staff/role changes, catalog/pricing changes,
  and settings changes.
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
