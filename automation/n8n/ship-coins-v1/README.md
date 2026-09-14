# ship-coins v1 — place one shipment for a paid order

The placement half of `Fulfillment v14`, cut loose from Salla, the Google
Sheets and the poll loops. The store posts a paid order here
(`order.paid`, schema 2 — `docs/api/n8n-fulfillment-v1.md`, *The placement
request*); this workflow picks a supplier the way v14 did, places the shipment
at FFT or UTT, reports the reference back to the store, and answers. The store
polls the suppliers from there.

Plan and owner decisions: `docs/plans/2026-09-14-ship-coins-workflow.md`.

`workflow.json` and `error-workflow.json` are generated from `nodes/*.js` and
`scripts/build-workflow.mjs`. Edit the sources, never the exports.

```bash
npm run build
npm test
```

`npm test` runs `build:check` first and fails when either export is stale.

## What is v14 and what is new

| Node | Origin |
| --- | --- |
| `Supplier Decision Engine` | v14, verbatim. Reads `$('Shipment')` where v14 read `$('Coins Item')`. |
| `UTT: Get Stocks`, `UTT: Check Slow Cooldown`, `FFT: Check Cooldown`, `Cooldown OK?`, `Slow Shipping?`, `Supplier Switch`, `UTT: Add Order Public`, `External: Buy Coins`, `SBC: Get Available SBCs` | v14, same parameters, plus a timeout. |
| `SBC: Match & Validate` | v14's matching and coin arithmetic; the challenge list comes from the shipment instead of Salla option strings. |
| `Extract Reference` | the first half of v14's `Coins: Prepare Poll`. |
| `Config`, `Verify Request`, `Plan Shipment`, `Shipment`, `Sign Placement Reports`, `Report Placement`, `Confirm Reports`, `Respond` | new: the store's contract in, the store's contract out. |

Gone, and where the job went: Salla parsing and the price sheet (the request
carries typed fields and the budget), Supabase and the sheets (the store is the
record), the customer WhatsApp confirmation (D3c), the three poll loops (the
store's sweep, D3a), the ops WhatsApp alerts (the Telegram error workflow).

**One shipment per run.** v14 merged a challenge's coins with the coins bought
beside it into one supplier order, on purpose - two shipments to one EA
account at once are two bots on one account. `Plan Shipment` keeps that and
goes one step further only where the store's data forces it: items are grouped
by EA account and platform, the first group is placed, and the run answers
`acknowledged: false` while other groups remain. The store retries with only
what is still unplaced, so the next run places the next group. Nothing is
deduplicated here; the store's report endpoint is idempotent.

**`Cooldown OK?` is ported as it was.** When the engine picks UTT, v14 asks FFT
`getCooldownStatus` for the account and sends the UTT order only when
`isReady` is true, otherwise FFT. That reads inverted; it is what ran in
production and it stays until the owner says otherwise.

## Failure model

**Every failure throws.** A bad signature, an unpriced challenge, a supplier
that returned no order id, a report the store refused, a timeout - all reach
the Error Workflow, and every thrown message starts with the order number, so
the alert names the order without execution data.

Set **Workflow Settings → Error Workflow** to **ship-coins - Failure Alert**
(`error-workflow.json`). **Without that setting, nothing alerts.** It holds an
instance-specific workflow id, so it cannot be committed here.

A run that throws answers HTTP 500 to the store, which is "retry later" (1, 2,
4 … 60 minutes, ten attempts, then the admin queue-health panel). A retry
carries only the items still unplaced.

## Keys and secrets: the `Config` node

The n8n instance has no environment variables and no `$vars` (owner decision,
2026-09-14: the values live inside the workflow). Every key is a field of the
`Config` node - an Edit Fields node that sits between `Webhook` and
`Verify Request` and passes the request through untouched - and every other
node reads it as `$('Config').first().json.<NAME>`. The export ships
`CONFIGURE_<NAME>` placeholders only; the real values are pasted in the n8n UI
after import and never committed.

| Field | Purpose |
| --- | --- |
| `N8N_ORDER_PAID_KEY` | the store's publisher identity, compared with `X-ArabUT-Key` |
| `N8N_ORDER_PAID_SECRET` | HMAC secret for the incoming request (32+ characters) - the same value as the store's `N8N_ORDER_PAID_SECRET` |
| `N8N_FULFILLMENT_KEY` | the credential the store's placement endpoint expects in `X-ArabUT-Key` |
| `N8N_FULFILLMENT_SECRET` | HMAC secret for the placement report (32+ characters) - the same value as the store's `N8N_FULFILLMENT_SECRET` |
| `FFT_API_USER`, `FFT_API_KEY` | FuTTransfer, as in every other workflow |
| `UTT_API_KEY` | UT Auto Transfer |
| `ARABUT_STORE_URL` | optional; leave the placeholder to use `https://store.arab-ut.com` |

`Verify Request` fails the run at the first node while any of the first seven
is empty or still reads `CONFIGURE_…`. The Telegram chat for failure alerts is
typed into the Telegram node of the error workflow (Chat ID, placeholder
`CONFIGURE_OPS_TELEGRAM_CHAT_ID`).

Two options on the `Config` node must stay on: **Include Other Input Fields**
(the request headers) and **Include Binary** (the raw body). Turning either off
fails every run at `Verify Request`.

The values sit in the workflow definition, which n8n stores unencrypted, so
whoever can open the workflow can read them. That is the trade the owner
accepted in place of the paid Variables feature; it changes nothing about the
EA account, which never rests in n8n (execution data off, below).

## Import

1. Import `workflow.json` and `error-workflow.json` inactive. Attach the
   Telegram credential on the error workflow (`CONFIGURE_TELEGRAM_CREDENTIAL_ID`
   placeholder) and type the ops chat into its Telegram node's Chat ID. The
   main workflow needs no credential: authentication is the HMAC, and every
   supplier key comes from the `Config` node.
2. Open the `Config` node and replace every `CONFIGURE_…` value with the real
   one (table above). Save.
3. Set **Workflow Settings → Error Workflow** of ship-coins to
   **ship-coins - Failure Alert**.
4. Confirm, in the same settings screen, that **no execution data is saved**
   (success, error, manual, progress all off). The export says so; the instance
   can override it. The EA account travels in the request
   (`docs/decisions/2026-09-12-ea-credentials-in-placement-payload.md`) and
   this is the condition attached to that decision.
5. Keep the instance concurrency at 1.
6. Activate ship-coins. Copy the production webhook URL
   (`…/webhook/arabut-ship-coins-v1`).
7. On the store, set `N8N_ORDER_PAID_URL` to that URL, and `N8N_ORDER_PAID_KEY`
   / `N8N_ORDER_PAID_SECRET` / `N8N_FULFILLMENT_KEY` / `N8N_FULFILLMENT_SECRET`
   to the same values as in the `Config` node, then `php artisan config:cache`.
   Until then the store's publisher fails closed and nothing reaches this
   workflow.

**One thing to confirm at import that the tests cannot:** `Verify Request`
reads the raw request bytes from the Webhook node's binary output ("Raw Body"
is on in the export). In n8n's default in-memory binary mode that is
`binary.data.data`, base64; in filesystem mode the node falls back to
`this.helpers.getBinaryDataBuffer`, which reads the bytes the `Config` node
passed on. If the first run fails at `Verify Request` with "the raw request
body is unavailable", either **Include Binary** is off on `Config` or the
instance's binary mode is one this node does not know - report it rather than
turning "Raw Body" off, because without the raw bytes the signature cannot be
checked at all.

## Acceptance

Before any customer order (owner decision, 2026-09-14): one small real coins
order on the owner's own EA account, placed as a manual gift order from the
admin, then: this workflow places it at a supplier and reports it, the store's
sweep reads it, the tracking page shows it. Watch the Telegram chat and the
store's queue-health panel through the run.
