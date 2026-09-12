# Order tracking in the store — implementation plan

**Status:** Revised 2026-09-12 after an adversarial review (Codex/sol, read-only) that found
twenty-five issues in the first draft. Awaiting owner approval before any brief is dispatched.
**Spec:** `docs/decisions/2026-09-12-order-tracking-in-store-design.md`
**ADR:** `docs/decisions/2026-09-12-ea-credentials-in-placement-payload.md`
**Glossary:** `CONTEXT.md`
**Complexity:** Ambitious.

## Owner decisions in force

- Tracking is a **port** of `track.arab-ut.com`, not a redesign. Fulfillment is an **adaptation**
  of `Fulfillment v14`, not a rebuild.
- n8n places, picks the supplier, retries a failed placement and alerts Mohamed — all of that
  already works and is not rebuilt here.
- The Google Sheet leaves the fulfillment path.
- Seven `OrderStatus` values stay; supplier detail, allowed actions and progress sit under them.
- Session 30 days; signed per-order link never expires; its actions stop at a terminal state.
- EA credentials ride in the placement payload; the durable outbox row stays secret-free.
- Phone is the primary viewport; verify 390px first. No input under `1rem`.

## Blocked on Mohamed

- Export of `Fulfillment v14` and of the order-status workflow.
- Rotated FFT and UTT keys, distributed to the store **and** n8n (task A2).
- Review of the changed hold-reason texts (task C2).
- Confirmation from the supplier dashboards that no unresolved job is outstanding, before the
  Sheet writer is switched off.

## Ordering

Not "each slice ships independently" — that claim was wrong. The real dependencies:

- A is independent of everything and ships first.
- B0 (persistence) precedes everything that stores an observation.
- B5 (reconciliation) precedes C, because C displays what B5 decides.
- C's action boxes are inert until D1 exists; ship them disabled or behind a flag.
- Real end-to-end placement needs B4 **and** the adapted workflow; nothing reaches customers
  before that path passes an end-to-end acceptance run.

---

## Slice A — independent of the rest (small, ships first)

**A1. Session, phone verification, and the Admin MFA gate.** In this order, because the middle
one is a security regression the first would otherwise introduce.

1. **First**, give pending phone verification its own short TTL.
   `app/Actions/Auth/PendingVerifiedRegistrationPhone.php:13` stores a number in the session with
   no timestamp, and `app/Actions/Fortify/CreateNewUser.php:54` later turns it into
   `phone_verified_at`. Raising the session lifetime would silently make a verification valid for
   thirty days. Store a verified-at alongside it and reject it when stale.
2. Then `SESSION_LIFETIME` 120 → 43200 in `.env.example` and on the server. Document the knock-on
   effects rather than discovering them: it is an **inactivity** window renewed on each response,
   not a fixed login date; it lengthens the XSRF cookie; and it raises guest-cart claim retention
   through `config/coins.php:5`. The `[2,100]` GC lottery already exists
   (`config/session.php:117`). Password confirmation stays at three hours (`config/auth.php:115`)
   and sensitive identity confirmation at ten minutes — leave both.
3. Then `EnsureAdminMfa` accepts a valid `TrustedDeviceRegistry` device. The session marker stays
   as a fast path **only with an age and provenance of its own**: today the middleware checks the
   marker's mere presence (`EnsureAdminMfa.php:25`) while revoking a trusted device deletes the
   device row without clearing it (`TrustedDeviceController.php:29`), so a thirty-day session
   would turn a revoked device into a standing bypass. Keep the enrollment, active-user and
   permission gates as they are.
4. Note the gap in enrollment: `ConfirmTwoFactorController.php:104` stamps the session but never
   calls `TrustedDeviceRegistry::remember()` — only the Fortify login response issues a device
   (`TwoFactorLoginResponse.php:17`). A Google or WhatsApp user who confirms on the admin page
   therefore has no trusted device at all. Fix it in the same task or the gate change does
   nothing for them.

Tests: revoked device, expired device, wrong-user device, no device, Google login path, WhatsApp
login path, and a stale marker.

**A2. Close the live exposure.** In the tracker repo: disable `mode=update`, `mode=resume`,
`mode=sbc-retry`, `mode=sbc-edit` and `admin-links.php`. Mohamed rotates the FFT and UTT keys and
distributes them to **both** the store's `shared/.env` and n8n — n8n still places orders and runs
the pricing and catalog workflows against these suppliers.

**A3. Version the workflows.** Commit both n8n exports under `automation/n8n/fulfillment-v1/` and
`automation/n8n/order-status-v1/`, sanitised of credentials, with a README each matching
`automation/n8n/coins-pricing-v2/`. Blocked on the exports.

---

## Slice B — the supplier boundary (Claude)

**B0. Persistence for observations.** `fulfillment_jobs` has placement columns and nowhere to put
what a supplier said. Migration adds: the raw observation and its timestamp, progress counters
(delivered/ordered, solved/requested), the delivery phase for challenge items, the derived hold
reason, and the allowed-action set. Keep four things distinct and do not overload one column:
placement state, supplier observation, canonical item status, customer presentation. Reconcile
with the existing `FulfillmentStatus` enum (`app/Enums/FulfillmentStatus.php:7`), which has
`failed` and no `refunded` and is not the same ladder as `OrderItemStatus`.

**B1. Supplier clients.** `FftClient` and `UttClient` behind one interface. Config in
`config/services.php` with `.env.example` parity and a `configuration()` guard that throws rather
than half-works, following `PublishOrderPaidEvent.php:82-102`. House timeouts `connectTimeout(5)`
/ `timeout(12)`. No shared outbound limiter or circuit breaker exists anywhere in this codebase —
add a per-supplier one plus outage backoff, because page refreshes and the sweep hit the same API.

**B2. Translation layer.** One supplier observation → `{OrderStatus, OrderHoldReason|null, allowed
actions}`. Rules that are not negotiable:

- An unknown or malformed code **keeps the last known canonical state**, marks the observation
  unsupported, disables unvouched actions, and alerts. It does not become `in_progress`.
- The output is a set of allowed actions, not one boolean: edit and resume are separately gated,
  and SBC edits are gated more narrowly still.
- Supplier codes are stored for diagnosis, never rendered.

Source lists: `C:\xampp\htdocs\track\assets\js\ui.js` and `includes/functions.php:1330-1445`,
transcribed into a fixture. Test every known code, plus an unknown one, plus a regression attempt.

**B3. Placement callback endpoint.** `POST /api/automation/v1/fulfillment/placements` — n8n
reports `{order_item_public_id, supplier, supplier_order_id}`. **Pick one existing HMAC
convention explicitly and say which**: the routes do not share one. Coins pricing and base catalog
sign `timestamp\neventId\nrawBody`; SBC catalog inserts `n8n-sbc\n` before the body
(`VerifyN8nSbcCatalogSignature.php:17`); SBC pricing read signs
`timestamp\nGET\n<path>\n` with no event header. Adopt the first. Specify SHA-256 hex, the
existing ±300-second window, its own key and 32-char secret, and its own named limiter. Idempotent
by key so n8n's retries are no-ops; a reference already bound to a different item is rejected;
a reused key with different data is a conflict, not an overwrite.

**B4. Outbound placement request.** The real gap in the first draft: today both payment paths
publish only identifiers, locale, currency, total and item count (`PlaceOrder.php:331`,
`ReconcilePaylinkPayment.php:77`). This task builds the payload — automated items selected, their
configuration, and the credential block the ADR authorises — **composed at send time** from
`order_item_secrets` with the access logged, leaving the persisted outbox row secret-free. A
retried send re-reads current credentials rather than replaying old ones. Contract written up as
`docs/api/n8n-fulfillment-v1.md`. Tests for a wallet-paid and a Paylink-paid order.

**B5. Reconciliation.** Applying an observation to canonical state is its own transactional
service, not a side effect of reading. It locks order and items the way
`TransitionAdminOrder.php:63` does, orders observations, lets a manual admin hold win, refuses to
move a terminal order, aggregates items to the order conservatively, and fires completion effects
(cashback, review invite) exactly once regardless of which path completed the order. A supplier
cancellation never produces `refunded`.

**B6. Silence alarm.** An automated paid item with no placement row after a bounded wait is
surfaced to Mohamed. Covers "n8n placed successfully and its callback was lost", which n8n cannot
see and which otherwise leaves a paid order invisible.

---

## Slice C — the customer read path

**C1. Tracking payload and refresh** (Claude). `ReadLiveOrder` gains a per-item `tracking` object
read **from storage**, with the observation's age. It does not call a supplier: the controller
evaluates it synchronously (`LiveOrderController.php:36`) and the page already reloads every
thirty seconds (`live-order.tsx:34`), so a supplier call there is a twelve-second hostage every
thirty seconds per viewer. Refresh goes through a separate bounded request, de-duplicated by a
per-job lock so concurrent viewers cause one supplier call. Manual-service items carry status
only.

**C2. Copy and the refund unfolding** (Claude; Mohamed approves the texts). Stop folding
`Refunded` in **both** `OrderStatus::forCustomer()` and `OrderItemStatus::forCustomer():16`. The
assertions that will fail are `tests/Feature/Account/AccountOrdersTest.php:309` and `:334` — the
parity tests check enum/label coverage, not folding. Revise the cancellation wording at
`lang/{ar,en}/orders.php:8`. Keep raw status driving financial logic. Then the hold-reason work:
separate reason text from contextual action copy, and change only the reasons that actually tell
the customer to message us — several already describe automatic recovery and must not be given a
button. Gulf-leaning simple Arabic, no Egyptian slang.

**C3. Canvas, then the port** (canvas: Claude; port: DeepSeek). A `/design` canvas leading with
390px: the ring, the progress bar, the three stat boxes, the action box, the challenge cards,
drawn in the store's tokens. Mohamed approves or edits on the canvas; his edits are the design.
Then the port into Inertia/React with the store's tokens and Thmanyah, the ring re-expressed,
`prefers-reduced-motion` respected. Not a copy of the tracker's 146KB stylesheet.

**C4. A separate presenter for the bearer link** (Claude). The account payload is not fit to serve
a capability URL: it carries payment breakdowns, review actions and purchase analytics
(`ReadLiveOrder.php:95`, `:119`, `:181`), and the frontend fires a `purchase` event whenever
`analytics` is non-null (`live-order.tsx:75`). A WhatsApp link opened in another browser would
re-count a purchase. The link gets its own presenter with an explicit field and action allowlist
and no analytics at all.

---

## Slice D — actions and notifications (Claude)

**D1. Self-service actions.** Each action derives from the allowed-action set, and is
re-authorised server-side when pressed — never trusted from the client. Credential correction
needs a real operation model, because the supplier can return HTTP 200 and still not have applied
the change: credential versions, `pending`/`applied`/`failed` state, concurrency control, and
semantic validation of the response, not just its status code. Otherwise stored credentials
silently disagree with the supplier while the page says success. Rate limited per order.

**D2. Signed per-order link.** A random token bound to one order, stored hashed, never expiring,
serving the C4 presenter. Read for the life of the order; actions refuse once terminal.

**D3. Sweep and notify.** Its own scheduled command, and its own budget — the 55-second figure in
the first draft was wrong: that limit belongs to `queue:work`, not to scheduled commands, which
run before it with no such cap (`routes/console.php:23`, `:42`). Therefore:

- An explicit wall-clock deadline inside the command, not an assumed one.
- A conservative start: three worst-case calls per tick, raised only after measuring real latency
  and confirming supplier quotas. At a twelve-second timeout, four calls consume 48 seconds.
- Oldest-due-first selection, `next_poll_at` advanced on **failure as well as success**, jittered
  backoff, `Retry-After` honoured.
- A real lease with crash recovery, not bare `withoutOverlapping()` — whose default lock lasts a
  day, exactly what the queue comment at `routes/console.php:47` warns against.
- Notification sending separated from polling.

Notification de-duplication needs a durable transition identifier and a unique delivery claim;
`notification_deliveries` has no unique constraint for order-plus-state today
(`2026_08_08_000004:99`), and keying on "order and state" alone would swallow a second item's
problem and a genuine recurrence after recovery. Whapi's OTP sender is a bare HTTP call
(`WhapiVerificationSender.php:29`), not delivery machinery — that part is new.

---

## Slice E — operations (later)

**E1. Admin fulfillment screen.** Every item currently at a supplier: supplier, age, stall,
failure, actual cost, retry. Inside the existing Admin with its permissions, server-side filter
and sort allowlists, and staff audit, per `.agents/skills/arab-ut-admin/`.

**E2. Alarms and recovery.** Poll-age and placement-age alerts, and a written manual recovery
procedure for a paid order with no reference. `docs/operations/hostinger-rollback.md:21` covers
release rollback only and says nothing about external state; rolling Laravel back after the Sheet
writer is gone needs its own note.

---

## Gates

`npm run ci:check` and `composer test` both pass, run by the lead rather than reported by a
worker. Playwright covers the tracking page at 390px in Arabic. An end-to-end acceptance run —
paid order through placement, callback, observation, display — passes before any customer sees
the page. No secret enters a brief, a log, an Inertia prop, analytics, or audit metadata.

## Brief for DeepSeek (C3 port only, after canvas approval)

**Objective:** port the approved tracking screens into the store's React stack.
**Allowed paths:** `resources/js/pages/account/`, `resources/js/components/account/`,
`resources/css/`.
**Non-goals:** any PHP, any route, any payload shape, any design decision not on the canvas.
**Acceptance:** matches the canvas at 320/390/768/1440 in Arabic RTL and English LTR; keyboard
focus visible; 44px touch targets; no horizontal overflow; no console errors; every input ≥ 1rem.
**Required checks:** `npm run ci:check`.
