# SBC Catalog v4 — FFT authority, signed Laravel snapshot

Builds the SBC storefront catalog from two providers, prices it in SAR, signs it,
and POSTs it to the Laravel automation endpoint.

- **FuTTransfer (FFT)** is authoritative for availability and the current
  console/PC coin requirement.
- **EasySBC** supplies metadata — name, category, images, repeatability — and a
  cross-source identity check.

`workflow.json` and `error-workflow.json` are generated from `nodes/*.js`. Edit
the node sources, never the exports.

```bash
npm run build
npm test
```

`npm test` runs `build:check` first and fails when either export is stale.

## Failure model

**Every failure throws.** There is no in-flow failure rail: v3 routed a
`failureReason` through ten IF gates, and the two largest Code nodes had no
`onError` and bypassed that rail entirely — which is why a production outage was
found in the execution log rather than on anyone's phone.

Instead, set **Workflow Settings → Error Workflow** to
**SBC Catalog - Failure Alert** (`error-workflow.json`). That catches every
throw, including the ones the old rail could never see: timeouts, out-of-memory,
and crashes inside the Code nodes themselves.

**Without that setting, nothing alerts.** It holds an instance-specific
workflow id, so it cannot be committed here.

## Environment

| Variable | Purpose |
| --- | --- |
| `FFT_API_USER` | FuTTransfer API user |
| `FFT_API_KEY` | FuTTransfer API key |
| `N8N_SBC_PRICING_READ_SECRET` | HMAC for the Laravel pricing read |
| `N8N_SBC_CATALOG_SECRET` | HMAC for the catalog publish |
| `OPS_TELEGRAM_CHAT_ID` | Telegram chat for failure alerts |

`Config` fails the run at the first node if any of the first four is unset,
rather than letting an HTTP node send empty credentials and surfacing it as a
provider fault six nodes later.

The v3 export carried the FuTTransfer key inline in the request body, so it
landed in every commit, export, and backup. **That key is burned and must be
rotated.** `npm test` asserts it never reappears.

On Hostinger, set `N8N_CATALOG_MEDIA_HOSTS=assets.easysbc.io` before any apply.

## Tolerance policy

Both providers are third parties that routinely serve a few partial rows. Every
per-record problem is skipped and counted; the run fails only when a **ratio**
shows the feed itself is broken. v3 threw on the first invalid EasySBC record —
three cosmetic rows out of fifty-six stopped every price in the store.

Two separate health signals, because one number cannot answer both questions:

- **Join integrity** (`minJoinIntegrity`, 85%) — of the SBCs *both* providers
  list, how many agree on name and squad count. This is the safety property: it
  is what verifies FFT's `setID` 412 and EasySBC's `id` 412 are the same
  challenge. Expect ~100%.
- **FFT coverage** (`minFftCoverage`, 50%) — what share of EasySBC's catalog FFT
  sells at all. Structurally well under 100%, because FFT does not sell daily
  freebie upgrades or OVR Token Swaps; those are not bought with coins. Loose on
  purpose — it catches FFT's feed collapsing, not the normal overlap gap.

EasySBC prices are **not** required for an SBC that FFT lists, because FFT is the
price authority. Requiring them discarded sellable ~1M-coin player SBCs whose
EasySBC `pcPrice` happened to be blank.

### Why FFT lists far more SBCs than EasySBC

**FFT never deletes an SBC after it ends; EasySBC does.** So FFT's feed is a
running history — 943 records against EasySBC's 58 in a typical run, with ~835
FFT-only ids. That gap is the normal state, not a fault, and `fftOnlyIds` in the
audit is there for inspection rather than as a warning.

It is also why **the join, not FFT alone, decides what is sellable.** FFT
answers "can this be delivered", but its list on its own cannot answer "is this
challenge still live in the game" — an ended SBC stays in it forever. An SBC
reaches the storefront only when both providers list it, so anything EasySBC has
dropped is excluded before pricing ever runs. Where the two disagree on the end
date the merge keeps the **earlier** one (`Math.min`), so a stale FFT expiry can
never extend the life of a challenge that has already closed.

## Pricing

One formula, in `build-and-price.js`. v3 computed a full legacy price in
`prepare-snapshot.js` and then overwrote every total in a second node.

1. The FFT coin requirement already contains its provider-side cushion.
2. Add Arab UT's own coin buffer (approved default 5%).
3. Convert buffered coins at the signed retail 1M quote.
4. Add automation cost per submitted squad.
5. Add service margin and one fixed order fee.
6. Round up to the next whole SAR. Minimum 6 SAR.

Commercial and platform adjustments scale **service margin only**; they can
never reduce retail coin value, automation cost, or the fixed order fee.

### `multiplierBps` is a policy constant, not a computed discount

The store validates it with `!==` against a fixed table in
`app/ValueObjects/Pricing/SbcCompletionPricing.php::expectedTiers`, so any other
value is rejected outright. `validate-snapshot.js` mirrors that table and
`tests/pricing.test.mjs` transcribes the whole PHP rule, so a drift fails here
with a readable message instead of as a 422 from the store.

Be aware that this percentage does **not** describe the price beside it. The
admin price dialog renders it verbatim while the price comes from the
`fft-plus-owner-buffer-v2` formula. No customer sees it. Reconciling the two is
a store-side decision — the workflow cannot fix it by sending different numbers,
because those get rejected.

## Workflow static data

The translation cache and the safety baseline live in workflow static data.

**A manual or test execution does not persist workflow static data** — n8n only
writes it for production-triggered runs. Bootstrap the first production baseline
from the webhook or the schedule, not from **Run SBC Catalog Now**, or the run
stays in bootstrap mode and re-pays for every translation. A failed run also discards the translations it just paid for,
because static data is only written when an execution completes.

There is no workflow-level concurrency lock in n8n, and a static-data lock
cannot work — static data is persisted only at the end of an execution, so a
second run would not see it. If the schedule and a webhook overlap, the later
run wins the baseline. Publishing itself is safe: Laravel deduplicates on
`eventId`. Keep the instance concurrency limit at 1 for production if this
matters.

## Safe rollout

1. Deploy and verify the signed Laravel pricing-read and catalog endpoints.
2. Import `workflow.json` and `error-workflow.json` inactive. Attach every
   credential (the exports carry `CONFIGURE_*` placeholders) and set the host
   environment values.
3. **Set Workflow Settings → Error Workflow to SBC Catalog - Failure Alert.**
   Nothing alerts until you do.
4. Rotate the FuTTransfer API key if it has not been rotated since the v3 export.
5. Publish and activate, so the production webhook is registered.
6. Trigger the production webhook once. Confirm HTTP 201 with a matching `runId`
   and `status: completed`, then check that static data holds
   `lastSuccessfulItems` and the accepted counts. The first run reports
   `bootstrapMode: true`; the next should report `false` — that is the signal
   the baseline actually persisted.
7. Verify the storefront and that mirrored media counts match products with
   EasySBC images. Leave the workflow active; two-hourly runs then apply under
   the identity, expiry, and safety-floor guards.

A replay 409 does not mutate state. HTTP 422 or 5xx fails closed and retains the
previous catalog.

## Official n8n references checked

- [Schedule Trigger](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.scheduletrigger/)
- [Webhook](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook/)
- [HTTP Request](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest/)
- [Error Trigger](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.errortrigger/)
- [Error handling](https://docs.n8n.io/flow-logic/error-handling/)
- [Code node cookbook](https://docs.n8n.io/code/cookbook/code-node/)
- [Workflow static data](https://docs.n8n.io/code/cookbook/builtin/get-workflow-static-data/)
- [OpenAI Chat Model](https://docs.n8n.io/integrations/builtin/cluster-nodes/sub-nodes/n8n-nodes-langchain.lmchatopenai/)
- [Telegram](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.telegram/)
- [HTTP Request credentials](https://docs.n8n.io/integrations/builtin/credentials/httprequest/)
- [Security audit](https://docs.n8n.io/hosting/securing/security-audit/)
