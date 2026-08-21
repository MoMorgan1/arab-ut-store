# Audit logging

Use the existing `StaffAuditLog` model/table. Do not install another audit
package or auto-log every model event.

## Event shape

- Actor user ID, stable action name, auditable subject when applicable, IP, and
  timestamp.
- Allowlisted metadata may include public request/idempotency IDs, previous/new
  enum states, controlled reason codes, nonnegative amount in minor units,
  direction, case reference, and related public identifiers.
- Free-text provider/domain reasons are not copied into audit metadata. Forms
  that must accept such text warn operators never to paste credentials or
  secrets.
- Never store credentials, passwords, TOTP secrets/recovery codes, tokens,
  encrypted payloads, raw provider metadata, application secrets, or request
  dumps.

## Required events

- credential reveal requested/succeeded/denied;
- order status transition and cancellation;
- refund reserved/completed/failed;
- wallet adjustment;
- customer activation change;
- staff role/activation/MFA reset decision;
- catalog/pricing mutation;
- settings mutation.

## Transaction rule

Write domain state and its success audit row in the same transaction whenever
no external provider call separates them. For provider calls, record truthful
reservation/result events; never log completion before verification.

Audit logs are append-only through application code and have no Admin edit or
delete control.
