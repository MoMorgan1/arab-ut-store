# EA credentials travel in the placement payload to n8n

Date: 2026-09-12
Status: owner decision by Mohamed on 2026-09-12, made against the recommendation below and
against what `docs/api/paylink-checkout-v1.md` says. Recorded so nobody reopens it.
Related: `docs/decisions/2026-09-12-order-tracking-in-store-design.md`.

## Decision

When the store hands a paid automated order to n8n for placement at FFT or UTT, the customer's
decrypted EA credentials travel **inside that same payload**. There is no separate credential
endpoint and no just-in-time pull.

## Why this is surprising

`docs/api/paylink-checkout-v1.md` states the opposite about the `order.paid` event: "The payload
deliberately excludes customer phone/email and all EA emails, passwords, and backup codes.
**Fulfillment-secret access must use a separate, authenticated, audited boundary.**" That
boundary was specified and never built. A future reader finding credentials in a placement body
will assume it is an oversight. It is not.

## The alternative that was rejected

n8n pulls one order item's credentials from a dedicated authenticated store endpoint at the
moment it needs them, each pull written to `secret_access_logs`. This was recommended because it
keeps credentials out of the broad placement event, produces a per-access audit trail, and can
be revoked without changing the placement contract.

Mohamed rejected it on three grounds, stated at the time:

1. It is complexity for a two-person operation.
2. Customers are asked to change their EA password after an order completes, so the exposure
   window is bounded by the customer's own action rather than by our storage.
3. Nobody but Mohamed has access to the n8n instance.

He was told the residual risk and reaffirmed the decision. That is the owner's call to make.

## Consequences that must be handled

These are conditions of the decision, not optional hardening. All three were found in review
after the decision was taken.

### The store must not create a second plaintext credential store

The existing outbound path persists its payload before sending it: `integration_events.payload`
is a plain JSON column, not encrypted (`app/Models/IntegrationEvent.php:13`,
`database/migrations/2026_08_08_000004_create_fulfillment_tables.php:86`), and
`PublishOrderPaidEvent` serialises that stored row straight onto the wire
(`app/Actions/Fulfillment/PublishOrderPaidEvent.php:39`). Putting credentials into that row would
write every customer's EA password into our own database in the clear, before n8n receives
anything — a worse exposure than the one this decision accepts.

**The durable outbox row stays secret-free.** Credentials are read from `order_item_secrets`,
decrypted, and composed into the request body at send time only, with the access written to
`secret_access_logs`. The payload n8n receives is exactly what this decision authorises; nothing
extra is persisted on our side. How a credential revision interacts with a retried send must be
specified in the implementation: a retry re-reads current credentials rather than replaying old
ones.

### n8n retention is a workflow setting, not a node setting

n8n's execution-data controls are **workflow-level** and separate for successful, failed, manual
and progress saves; default retention prunes after 336 hours rather than never. Disabling one
receiving node does not establish that credentials are absent. **Every save mode is disabled on
every credential-bearing workflow and sub-workflow**, and waiting executions, pinned data, error
workflows and instance backups are checked too. Verify with synthetic credentials and confirm
they appear nowhere in the instance. The deployed instance's current settings have not been
inspected.

### The supplier-side effect is account-wide, not order-wide

Correcting SBC credentials at FFT rewrites the supplier's *customer record keyed by account
email* (`createCustomerAPI.php` with `updateCustomer: '1'`), so the change reaches every order on
that EA account at that supplier, not only the order the customer opened. This matters because
the tracking link is per-order and never expires: its blast radius is that EA account at the
supplier, not that one order.

Mohamed's position, 2026-09-12: keep it, and say nothing to the customer about it — the effect
stays within one customer's own account, and a customer does not have two concurrent orders on
the same account because the suppliers cannot run them concurrently anyway. No customer-facing
notice is added. Recorded here because the constraint is invisible in our code and lives entirely
in the supplier's API. Note it is an operational expectation, not something this codebase
enforces.

---

The unbuilt credential boundary stays unbuilt; `docs/api/paylink-checkout-v1.md` should no longer
be read as describing a planned endpoint.
