# Fulfillment v14 (n8n, Salla-era)

The owner's live fulfillment workflow, exported from his instance while the store
still ran on Salla. It is the reference the store's own fulfillment is being ported
from — the supplier call set, the poll cadence, the hold reasons and the recovery
paths all come from here. Read it alongside `docs/api/supplier-endpoints.md` and
`docs/plans/2026-09-12-order-tracking-in-store.md`.

It is **not** a ready-to-import artifact. It still writes to the Google Sheet that
is being deleted, and it still reads Salla order shapes.

## Secrets

This export originally carried the FFT and UTT credentials inline, in twenty-two
places across body parameters, `jsonBody` expressions and Code nodes, plus the
shared bearer the intake webhook compared against. They now read from n8n
environment variables, the same names the pricing workflow already uses:

| Variable | Used for |
| --- | --- |
| `FFT_API_USER` | `apiUser` on every futtransfer.top call |
| `FFT_API_KEY` | `apiKey` on every futtransfer.top call |
| `UTT_API_KEY` | `apiKey` on every utautotransfer.com call |
| `ARABUT_N8N_WEBHOOK_TOKEN` | the `authorization` header the intake webhook requires |

`$env` resolves in HTTP-node expressions and in Code nodes alike, so importing this
export after setting those four variables behaves as the live workflow did.

**The old values still need rotating.** They were committed in plaintext and pushed
to this repository, which is public, so they are in its history whatever this file
now says. Redacting the tip stops the next reader finding them; only rotating at
FFT, at UTT and on the webhook actually revokes them.
