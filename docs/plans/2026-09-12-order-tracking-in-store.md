# Order tracking in the store — implementation plan

**Status:** Draft for owner review (2026-09-12). Goes to `sol` for review before any brief is
dispatched and before implementation starts (owner instruction, 2026-09-12).
**Spec:** `docs/decisions/2026-09-12-order-tracking-in-store-design.md`
**ADR:** `docs/decisions/2026-09-12-ea-credentials-in-placement-payload.md`
**Glossary:** `CONTEXT.md`
**Complexity:** Ambitious — five slices, each independently shippable.

## Owner decisions in force

- Tracking is a **port** of `track.arab-ut.com`, not a redesign. Fulfillment is an **adaptation**
  of `Fulfillment v14`, not a rebuild.
- n8n places and picks the supplier; Laravel reads status and performs customer actions.
- The Google Sheet leaves the fulfillment path.
- Seven `OrderStatus` values stay; supplier detail, required action and progress sit under them.
- Session 30 days; signed per-order link never expires; its actions stop at a terminal state.
- EA credentials ride in the placement payload (see the ADR).
- Phone is the primary viewport; verify 390px first. No input under `1rem`.

## Blocked on Mohamed

- Export of `Fulfillment v14` and of the order-status workflow. Slice B4 and D3 wire to them;
  everything else proceeds without them.
- Rotated FFT and UTT keys (task A2).
- Review of the seventeen rewritten hold-reason texts (task C2).

---

## Slice A — the things that are true regardless (small)

**A1. Session and Admin MFA.** `SESSION_LIFETIME` 120 → 43200 in `.env.example` and on the
server. `EnsureAdminMfa` accepts a valid `TrustedDeviceRegistry` device in place of the
`auth.two_factor_confirmed_at` session flag; the flag remains the fast path. Feature test: a
fresh session on a trusted device reaches `/admin` without a TOTP challenge; an untrusted device
still gets one. Note the `sessions` table grows — confirm Laravel's session GC lottery is on.

**A2. Close the live exposure.** In the tracker repo, not this one: disable `mode=update`,
`mode=resume`, `mode=sbc-retry`, `mode=sbc-edit` and `admin-links.php`. Mohamed rotates the FFT
and UTT keys; the new values go into the store's `shared/.env` only.

**A3. Version the workflows.** Commit both n8n exports under `automation/n8n/fulfillment-v1/`
and `automation/n8n/order-status-v1/` with a README each, matching the shape of
`automation/n8n/coins-pricing-v2/`. Neither workflow has ever been in git.

---

## Slice B — the supplier boundary (Claude)

**B1. Supplier clients.** `App\Services\Suppliers\FftClient` and `UttClient` behind one
interface. Config in `config/services.php` with `.env.example` parity and a `configuration()`
guard that throws rather than half-working, following
`app/Actions/Fulfillment/PublishOrderPaidEvent.php:82-102`. House timeouts: `connectTimeout(5)`,
`timeout(12)`. There is no outbound rate limiter anywhere in the codebase — add a per-supplier
one, because the cron sweep and page reads both hit the same API.

**B2. Translation layer.** `App\Fulfillment\SupplierObservation` → `{OrderStatus,
OrderHoldReason|null, actionable: bool}`. `actionable` lives here, not on the enum. Must cover
every FFT `status` / `accountCheck` / `economyState`, every UTT `statusOrder`, and the whole
`sbcStatus` set; the authoritative lists are in `C:\xampp\htdocs\track\assets\js\ui.js` and
`includes/functions.php:1330-1445`, and are transcribed into a fixture. Unit test: every known
supplier code maps, and an unknown code degrades to `in_progress` without throwing.

**B3. Placement report endpoint.** `POST /api/automation/v1/fulfillment/placements` — n8n reports
`{order_item_public_id, supplier, supplier_order_id}` after a successful placement. Same HMAC
scheme as the existing automation routes (`X-ArabUT-Key/-Timestamp/-Event/-Signature`, canonical
string `timestamp\neventId\nrawBody`), its own key and 32-char secret, its own named rate
limiter. Writes `fulfillment_jobs` with a unique idempotency key so a replay is a no-op.
Rejects a reference already bound to a different item.

**B4. Contract doc.** `docs/api/n8n-fulfillment-v1.md`: B3's inbound shape, and the outbound
placement payload including the credential block the ADR authorises. Written from the v14 export
once it arrives; the store side does not wait for it.

---

## Slice C — the customer read path

**C1. Tracking payload** (Claude). Extend `App\Account\Queries\ReadLiveOrder` with a per-item
`tracking` object: canonical status, hold reason, `actionable`, progress (`delivered`/`ordered`
for coins, `solved`/`requested` for challenges), the coins→challenge phase for SBC items, and
`finishedAt`. A live supplier read on page open, cached ~60s per job, degrading to the last
stored values when the supplier is unreachable — never an error page. Manual-service items carry
status only.

**C2. Copy** (Claude, Mohamed approves). Stop folding `Refunded` in `OrderStatus::forCustomer()`;
update `tests/Unit/Lang/OrderStatusLabelParityTest.php` and any customer-status snapshot.
Rewrite the seventeen `lang/{ar,en}/orders.php` hold-reason texts to end in the button the
customer presses instead of "ثم أخبرنا". Gulf-leaning simple Arabic, no Egyptian slang.

**C3. Canvas, then the UI port** (canvas: Claude; port: DeepSeek). A `/design` canvas leading
with 390px showing the tracker's screens redrawn in the store's tokens — the ring, the progress
bar, the three stat boxes, the action box, the challenge cards. Mohamed approves or edits on the
canvas; his edits are the design. Then the port: the page in Inertia/React with the store's
tokens and Thmanyah, the canvas ring engine re-expressed, `prefers-reduced-motion` respected.
Not a copy of the tracker's 146KB stylesheet.

> Brief for DeepSeek — **Objective:** port the approved tracking screens into the store's React
> stack. **Allowed paths:** `resources/js/pages/account/`, `resources/js/components/account/`,
> `resources/css/`. **Non-goals:** any PHP, any route, any payload shape, any design decision not
> on the canvas. **Acceptance:** matches the canvas at 320/390/768/1440 in Arabic RTL and English
> LTR; keyboard focus visible; 44px touch targets; no horizontal overflow; no console errors;
> every input ≥ 1rem. **Required checks:** `npm run ci:check`.

---

## Slice D — actions and notifications (Claude)

**D1. Self-service actions.** Correct credentials, resume a stopped delivery, retry a challenge —
each a Laravel action that writes `order_item_secrets` where relevant, logs to
`secret_access_logs`, then calls the supplier. Rate limited per order. Only offered when the
translation layer says `actionable`.

**D2. Signed per-order link.** A random token bound to one order, stored hashed, never expiring.
Opens the tracking page without the account shell. Read works for the life of the order; actions
refuse once the order is terminal. Grants that order and nothing else.

**D3. Sweep and notify.** A minute-scheduled command with `withoutOverlapping()`, batch-capped so
a run fits inside the 55-second budget, walking non-terminal jobs by `next_poll_at`. Writes
`notification_deliveries` and sends over Whapi, de-duplicated per order and state so a stuck
order messages once. Message catalogue ported from Mohamed's order-status workflow.

---

## Slice E — operations (later)

**E1. Admin fulfillment screen.** Every item currently at a supplier: supplier, age, stall,
failure, actual cost, retry. Inside the existing Admin with its permissions, server-side filter
and sort allowlists, and staff audit. Follows the Admin conventions in
`.agents/skills/arab-ut-admin/`. Deliberately last: a queue screen over an empty table is worth
nothing.

---

## Gates

`npm run ci:check` and `composer test` both pass, run by the lead rather than reported by a
worker. Playwright covers the tracking page at 390px in Arabic. No secret enters a brief, a log,
an Inertia prop, analytics, or audit metadata.
