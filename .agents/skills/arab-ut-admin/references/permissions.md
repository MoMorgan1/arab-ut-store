# Permissions

## Approved matrix

| Permission                | Admin | Staff |
| ------------------------- | ----- | ----- |
| `dashboard.view`          | yes   | yes   |
| `orders.view`             | yes   | yes   |
| `orders.update`           | yes   | yes   |
| `orders.cancel`           | yes   | yes   |
| `orders.refund`           | yes   | yes   |
| `order_credentials.view`  | yes   | yes   |
| `customers.view`          | yes   | yes   |
| `customers.update_status` | yes   | yes   |
| `payments.view`           | yes   | yes   |
| `payments.refund`         | yes   | yes   |
| `wallet.view`             | yes   | yes   |
| `wallet.adjust`           | yes   | yes   |
| `catalog.view`            | yes   | yes   |
| `catalog.manage`          | yes   | no    |
| `audit.view`              | yes   | no    |
| `staff.view`              | yes   | no    |
| `staff.manage`            | yes   | no    |
| `settings.view`           | yes   | no    |
| `settings.manage`         | yes   | no    |

Customer and ServiceAccount receive none.

## Enforcement

- Define permissions once in a backed enum and roles once in a central matrix.
- Laravel Gates/policies consume the matrix. Use `$user->can(...)` or
  authorization middleware instead of role comparisons in feature code.
- Route admission, request/policy authorization, and high-risk Action checks are
  separate layers. Never rely on only one.
- UI props may hide unavailable navigation/actions but never grant access.
- Cross-resource lookups scope before resolving and fail without confirming
  another resource exists.

## Change control

- v1 roles are fixed and code-defined; do not install a dynamic permission
  package or add direct per-user permission editing.
- Role changes are Admin-only, require recent password confirmation, prevent the
  acting Admin from removing the last active Admin, and are audited.
