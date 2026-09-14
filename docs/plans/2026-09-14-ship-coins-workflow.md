# F1 — `ship-coins`: the placement workflow, adapted from Fulfillment v14

**Status:** approved 2026-09-14; the four questions at the end are answered inline. In build.
**Reference:** `automation/n8n/fulfillment-v14/workflow-v14-salla.json` (117 nodes), read node by
node and edge by edge on 2026-09-14. Owner rule: adaptation of v14, not a rebuild.
**Input contract:** `docs/api/n8n-fulfillment-v1.md`, *The placement request* (B4, shipped).
**Output contract:** the same file, the placement report (B3, shipped).

## What v14 does today, read from the export

One webhook, one workflow, three jobs braided together. The coins path, in order:

| # | v14 node(s) | What it does | Fate in `ship-coins` |
| --- | --- | --- | --- |
| 1 | `Webhook` → `Auth: Salla Token?` → `Switch` | Salla `order.created` / `order.status.updated`, bearer token check | **Replaced.** The store's `order.paid` v2, HMAC-SHA256 over `timestamp\neventId\nbody`, ±300 s window. |
| 2 | `Paid?` → `Dedup` (Supabase) → `Already Processed?` | skip unpaid, dedupe on Salla id | **Dropped.** The store only sends paid orders, and only the items nobody has placed yet. |
| 3 | `Read Price Settings` → `Router Logic1` → `Price Fallback?` | parse Salla option strings (email, password, codes, platform, amount, balance, times), read the price sheet, compute `calculatedMaxPrice` | **Dropped.** Every field arrives typed in the request; `budget.max_eur_per_100k` is the sheet figure, computed by the store. |
| 4 | `Order: First Item Only` → `Create Order` / `Create Coins Item` / `Create Service Item` (Supabase), `Append row in sheet1`, `WA: Build Confirmation` → `Send WA (Confirmed)` | records, sheet row, customer WhatsApp confirmation | **Dropped.** The store is the record; the confirmation message is D3c's. |
| 5 | `Switch1` → `WA: Manual Order Alert` | booster services alert the ops group | **Dropped.** Those orders no longer reach n8n (owner decision, 2026-09-14). *Gap noted: the store sends no alert for a new paid order.* |
| 6 | `Coins Item` → `Slow Shipping?` → `UTT: Get Stocks` → **`Supplier Decision Engine`** | UTT stock combination via `getMaxOrderPrediction` (≤12 calls, ≤10 stocks), FFT `maxOrderPreviewAPI`, cooldown force, partial-coverage rule, 10 % headroom | **Kept as is.** Reads `budget.max_eur_per_100k`, `coins.quantity`, `supplier_platform`, `account.current_balance ?? 200000`. |
| 7 | `Supplier Switch` → `FFT: Check Cooldown` → `Cooldown OK?` → `UTT: Add Order Public` / `External: Buy Coins`; slow: `UTT: Check Slow Cooldown` → FFT with `transferMethod: cycle` when `orderCardsRemaining === 0` | the placement itself, with the `topUpEnabled` ladder, `riskLevel 10`, `autoFinishCycle 1` | **Kept as is.** |
| 8 | `Coins: Prepare Poll` | pull `order.idOrder` (UTT) or `data.orderID` (FFT) out of the placement response; none = in-band rejection | **Kept**, and its output becomes the placement report: `POST /api/automation/v1/fulfillment/placements` with `delivery_phase: coins`. |
| 9 | `Update Coins Item`, `Update row in sheet` (+ retries) | record the supplier id | **Replaced** by the report in 8. |
| 10 | `Coins: Poll Wait` → `Coins: Poll Route` → `Coins: UTT Status` / `Coins: FFT Status` → `Coins: Evaluate Status` → stall alerts, Salla status pushes, `resumeOrderAPI` auto-resume, `Sheet: Coins Actual Cost` | the three-day poll loop | **Dropped.** D3a is the loop. *Two things it did that the store does not yet: auto-resume an FFT `interrupted` order every third poll, and record the actual cost (`moneySpent` / `toPay`) on completion. Both belong to D3, noted below.* |
| 11 | `WA: Coins Order Failed`, `WA: SBC Charge Failed` | ops-group WhatsApp on a placement failure, with the 429 hint | **Kept** (question 2). |

The challenge funding path (`SBC: First Item Only` → `SBC: Get Available SBCs` →
`SBC: Match & Validate` → `SBC: Get UTT Stocks` → `SBC: Supplier Decision` → cooldown →
`SBC: Charge Coins` / `SBC: UTT Charge Coins` → `SBC: Prepare Poll`) is the same shape with two
differences: the amount is `ceil(FFT consolePrice|pcPrice / 1000) × timesToSolve` read live from
`availableSBCsAPI`, and an SBC FFT does not price throws "needs manual solve". That path is
**in scope for `ship-coins`**: the owner's restructuring is by phase, and a challenge's first phase
is a coins shipment. The solve itself (`SBC: Split SBC IDs` → `SBC: Submit Solve` and its poll)
is F2, `solve-challenge`, triggered by the store when the funding lands.

## What `ship-coins` is

```
Webhook (order.paid v2)
  → Verify Signature          Code: key, timestamp window, HMAC; throw on mismatch
  → Plan Items                Code: one item per run of the loop; a challenge item first asks
                              availableSBCsAPI for its coin cost (Match & Validate, kept)
  → for each item:
      Get UTT Stocks          HTTP (kept)
      Supplier Decision       Code (kept verbatim, inputs renamed)
      Cooldown / Slow gates   HTTP + IF (kept)
      Place at FFT or UTT     HTTP (kept)
      Extract Reference       Code (Prepare Poll's first half)
      Report Placement        HTTP → store, signed with N8N_FULFILLMENT_KEY/SECRET
  → Respond                   { data: { acknowledged: true } } once every item is reported
Error Workflow                one throw anywhere → ops WhatsApp with the order number and the
                              node that failed, and the response is not an acknowledgement
```

Rules that fall out of the two contracts, and are what make this simpler than v14:

- **No dedup, no retry logic in n8n.** A non-acknowledged response makes the store retry in
  1, 2, 4 … 60 minutes, up to ten times, and each retry is composed afresh: an item whose
  placement was already reported is left out (`AwaitingPlacement`), so a run that placed one item
  and failed on the second is retried with the second only. The report endpoint is idempotent on
  its side, so a placement that succeeded after the store gave up waiting is still safe to report.
- **The response deadline is the store's timeout.** `PublishOrderPaidEvent` waits 12 s today,
  which a decision engine with up to twelve UTT calls will exceed. Raise it to 60 s (one line;
  the command already runs `withoutOverlapping`). A timeout is not harmful, by the rule above.
- **Every failure throws** and reaches the Error Workflow, the pattern `sbc-catalog-v1` settled
  on: no IF rail for failures, no HTTP node without `neverError` + a Code node that turns a bad
  status into a throw. What v14 wrote as six `WA: … Failed` nodes is one.
- **Concurrency 1** on the workflow, as the SBC catalogue README already requires: two runs on
  the same EA account would be two bots on one account.
- **Save no execution data** in any mode (success, error, manual, progress) — the ADR's condition
  for the account travelling in the request. `N8N_ORDER_PAID_*` stay unset on the store until
  this is verified on the instance.

## What changes in the store (small)

1. `PublishOrderPaidEvent` timeout 12 s → 60 s.
2. `docs/api/n8n-fulfillment-v1.md`: drop the "deduplicate on eventId" sentence — the retry
   design above replaces it.
3. Question 1, answered (A): a supplier reference may be recorded on several items of the
   same order in the coins phase. The unique index on `fulfillment_placements(supplier,
   supplier_order_id)` goes; `RecordSupplierPlacement` refuses a reference seen on another
   order or on a challenge phase, under the order lock it already holds. Each funded item
   keeps its own job, and both jobs read the same shipment.

## How it is built and proven

Same shape as `automation/n8n/sbc-catalog-v1/`: `nodes/*.js` are the source, a build script
emits `workflow.json` and `error-workflow.json`, `node --test` runs the Code nodes against
recorded supplier responses (the shapes are in v14's own expressions: UTT `order.idOrder`,
`stocks[].price`, `maxTransferable`, `orderCardsRemaining`; FFT `data.orderID`, `maxPossibleK`,
`isReady`). The decision engine is tested for the four branches it has — forced UTT, no full
coverage, FFT price-win, UTT — with the same inputs v14 fed it.

Acceptance, before any customer order: one real order through the whole chain — paid (a manual
gift order is enough), placed by `ship-coins` at a supplier, reported, observed by the sweep,
shown on the tracking page. Question 4 asks what account that runs on.

## Sequence

1. Store: timeout, contract sentence, and the answer to question 1 (one PR).
2. `ship-coins` sources, build, tests, README with the import steps and the environment table
   (`N8N_ORDER_PAID_KEY/SECRET`, `N8N_FULFILLMENT_KEY/SECRET`, `FFT_API_USER/KEY`,
   `UTT_API_KEY`, `ARABUT_STORE_URL`, `OPS_WHATSAPP_TARGET`).
3. Mohamed imports both workflows inactive, sets the environment, turns off execution-data
   saving, sets concurrency 1, attaches the Whapi credential, activates.
4. Mohamed sets `N8N_ORDER_PAID_URL/KEY/SECRET` and `N8N_FULFILLMENT_KEY/SECRET` on the store.
5. Acceptance run.

## Found while reading, outside F1

- **Actual cost is not recorded.** v14 wrote `moneySpent`/`toPay` to the sheet on completion;
  `fulfillment_jobs.actual_cost_halalah` exists and nothing writes it. D3/B5.
- **FFT auto-resume is not ported.** v14 called `resumeOrderAPI` on `interrupted` at polls 1, 4,
  7 and every twelfth. The sweep does not. D3.
- **No new-order alert.** v14's `WA: Manual Order Alert`; the store has no equivalent for any
  paid order. Separate work, if wanted.

## Questions for the owner — answered 2026-09-14

1. **A challenge and coins on one order.** v14 merged them into one supplier shipment on purpose
   ("منع التعارض على الحساب": two shipments to one EA account at once would be two bots on one
   account). The store binds one supplier reference to one item, so a merged shipment cannot be
   reported for both. Options: **(A)** allow a reference to bind several items of the same order
   — one shipment, both items track it, the faithful port; **(B)** ship the coins item first and
   fund the challenge when that job completes — needs the store to fire a second event, the same
   trigger F2 needs anyway; **(C)** refuse a challenge and coins in one cart.
   **Answer: (A).**
2. **Ops WhatsApp alerts.** The plan's "remove every WhatsApp node" was written about the
   customer messages. Keep the ops-group alert on a failed placement in `ship-coins`?
   **Answer: keep the alert, but by email or Telegram, not WhatsApp.** The catalogue's error
   workflow already alerts on Telegram (`OPS_TELEGRAM_CHAT_ID`); `ship-coins` reuses that shape.
3. **Partial coverage.** v14 sends a UTT order it knows will only partly cover the amount when
   UTT moves more than FFT right now, and lets the poll loop's stall alert handle the rest. Port
   as is, or refuse to place what cannot be covered?
   **Answer: as is.**
4. **The acceptance run.** Which EA account, and does a small real coins order count as the
   test, or is there a supplier sandbox?
   **Answer: Mohamed's own EA account, a small real coins order, placed as a manual gift order
   from the admin.**
