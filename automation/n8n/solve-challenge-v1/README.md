# solve-challenge v1 — submit the solve for a funded challenge

The solve half of `Fulfillment v14`'s SBC branch, cut loose from Salla, the
Google Sheets and the poll loops. The store posts a challenge whose coins have
landed here (`challenge.ready`, schema 1 — `docs/api/n8n-fulfillment-v1.md`,
*The solve request*); this workflow checks the set is still offered and
priced, submits it to FFT once the way v14 did, reports the solve ids back to
the store as the challenge placement, and answers. The store polls FFT from
there.

The funding half - buying the coins a challenge costs - is `ship-coins`
(`automation/n8n/ship-coins-v1/`). Plan and owner decisions:
`docs/plans/2026-09-15-solve-challenge-workflow.md`.

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
| `SBC: Get Available SBCs` | v14, same parameters, plus a timeout and retries. |
| `Validate Set` | the "set exists and is priced" half of v14's `SBC: Match & Validate`, for one set, with v14's messages. The coin arithmetic is not here: the coins are bought. |
| `SBC: Submit Solve` | v14, byte for byte the same `newSBCAPI` body, reading `$('Validate Set')` where v14 read `$('SBC: Split SBC IDs')`. |
| `Extract Solve Ids` | v14's `SBC: Solve Accepted?` and the id-collecting half of `SBC Solve: Prepare Poll`, as one Code node that throws on a refusal. |
| `Config`, `Verify Request`, `Sign Placement Report`, `Report Placement`, `Confirm Report`, `Respond` | new: the store's contract in, the store's contract out (the ship-coins nodes, adapted). |

Gone, and where the job went: the Salla pushes and the sheets (the store is
the record), the coins purchase (`ship-coins`), the solve poll loop and its
`retrySBCAPI` auto-retry (the store's sweep, D3a, and F2d), the ops WhatsApp
alerts (the Telegram error workflow).

**Only FFT solves.** `newSBCAPI`, `sbcStatusBulkAPI` and `retrySBCAPI` are
FFT; UTT has no solver. The coins may have come from UTT (`funding` in the
request says which); the challenge placement is always reported as `fft`.

**One item per run.** The store queues one `challenge.ready` per challenge
item and sends them one at a time; nothing is grouped here.

## Failure model

**Every failure throws.** A bad signature, a set FFT no longer lists or does
not price, a solve FFT refused, a report the store refused, a timeout - all
reach the Error Workflow, and every thrown message starts with the order
number, so the alert names the order without execution data.

Set **Workflow Settings → Error Workflow** to **solve-challenge - Failure
Alert** (`error-workflow.json`). **Without that setting, nothing alerts.** It
holds an instance-specific workflow id, so it cannot be committed here.

A run that throws answers HTTP 500 to the store, which is "retry later" (1, 2,
4 … 60 minutes, ten attempts, then the admin queue-health panel). Two of the
failures will not fix themselves - a set FFT no longer prices, and a set it
no longer lists - and the alert says so: the owner solves that challenge by
hand and pastes the reference through the admin, after which the store
finishes the queued event without a request.

## Keys and secrets: the `Config` node

As in `ship-coins`: no environment variables, no `$vars`; every key is a
field of the `Config` node and the export ships `CONFIGURE_<NAME>`
placeholders only.

| Field | Purpose |
| --- | --- |
| `N8N_SOLVE_CHALLENGE_KEY` | the store's solve publisher identity, compared with `X-ArabUT-Key` |
| `N8N_SOLVE_CHALLENGE_SECRET` | HMAC secret for the incoming request (32+ characters) - the same value as the store's `N8N_SOLVE_CHALLENGE_SECRET` |
| `N8N_FULFILLMENT_KEY`, `N8N_FULFILLMENT_SECRET` | the placement endpoint's credential - the same values `ship-coins` carries |
| `FFT_API_USER`, `FFT_API_KEY` | FuTTransfer, as in every other workflow |
| `ARABUT_STORE_URL` | ships filled in as `https://store.arab-ut.com`; change only for a staging store. Anything not starting with `https://` is ignored and the real store is used |

`Verify Request` fails the run at the first node while any of the first six
is empty or still reads `CONFIGURE_…`. **Include Other Input Fields** and
**Include Binary** must stay on, as in `ship-coins`.

## Import

1. Import `workflow.json` and `error-workflow.json` inactive. Attach the
   Telegram credential on the error workflow and type the ops chat into its
   Telegram node's Chat ID.
2. Open the `Config` node and replace every `CONFIGURE_…` value (table above).
   `N8N_FULFILLMENT_KEY` / `_SECRET` and the FFT pair are the same values as in
   `ship-coins`; generate a fresh pair for `N8N_SOLVE_CHALLENGE_KEY` / `_SECRET`.
3. Set **Workflow Settings → Error Workflow** to
   **solve-challenge - Failure Alert**.
4. Confirm **no execution data is saved** in the same settings screen.
5. Activate. Copy the production webhook URL
   (`…/webhook/arabut-solve-challenge-v1`).
6. On the store, set `N8N_SOLVE_CHALLENGE_URL` to that URL and
   `N8N_SOLVE_CHALLENGE_KEY` / `_SECRET` to the same values as in `Config`,
   then `php artisan config:cache`. Until then the store's publisher fails
   closed and every queued `challenge.ready` waits.

## Acceptance (F2c)

Before any customer challenge: one real challenge on the owner's own EA
account, funded by `ship-coins`, then this workflow submits it and reports
it, the store's sweep reads `sbcStatusBulkAPI`, the challenge card shows the
solve. **One thing the tests cannot prove:** the store accepts only
UUID-shaped `sbcSolveID`s in `challenge_ids` (`ChallengeIds::normalize()`);
v14 wrote them to a sheet cell and never checked. If the first report is
refused with `invalid_challenge_ids`, widen the store's normaliser to FFT's
real shape before retrying - the run's alert will carry the code.
