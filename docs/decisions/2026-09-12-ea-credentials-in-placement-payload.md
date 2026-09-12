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

## Consequence that must be handled

n8n saves full node input and output in its execution history by default. Without changing that,
every customer's EA password and backup codes persist in n8n's run history indefinitely, which
is a far longer exposure than the payload itself. **Execution-data saving is disabled on the node
that receives this payload** — a workflow setting, not a redesign. This is a condition of the
decision, not an optional hardening.

The unbuilt credential boundary stays unbuilt; `docs/api/paylink-checkout-v1.md` should no longer
be read as describing a planned endpoint.
