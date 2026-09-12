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
- **Laravel reads the suppliers; n8n only places.** Settled on cohesion, not capacity: the
  translation layer is PHP, so an n8n poller would be a relay with no interpretive value.
- `Fulfillment v14` is restructured **by phase** — `ship-coins` and `solve-challenge` — not by
  product, because a Challenge order ships coins too.
- The purchase budget comes from the store in the placement request; `ArabUT Price Settings`
  goes with the other Sheets.
- `Customer Notifier` is absorbed into the store; its message catalogue is kept.
- The Google Sheet leaves the fulfillment path.
- Seven `OrderStatus` values stay; supplier detail, allowed actions and progress sit under them.
- Session 30 days; signed per-order link never expires; its actions stop at a terminal state.
- EA credentials ride in the placement payload; the durable outbox row stays secret-free.
- Phone is the primary viewport; verify 390px first. No input under `1rem`.

Added 2026-09-12, after the first implementation round:

- **The customer controls everything.** Adopt the tracker's button sets as they are, for every
  hold reason. The "we will retry and update you" wording came from Salla's constraints; on the
  tracking link the customer has always had the controls, and that is what the owner wants kept.
  So the reason's class still decides the canonical status, but the actions come from the tracker's
  `showEditStates` / `showResumeStates` with no exceptions carved out.
- **Manual orders take everything**: pick an existing customer or add one, pick an existing
  product or add one, and so on through the form. Not a narrowed subset.
- **Objectives is a manual service.** `CONTEXT.md` was right and the enum was wrong.
- **The activation gate is documentation, not a blocker right now**: the store is taking no orders
  at all while FIFA 26 winds down, so there is nothing to strand. It becomes real again the day
  orders resume - see the gate section below.

Decided 2026-09-12, briefing the C3 canvas:

- **The tracking lives inside the existing order page.** No separate route, no order-number box, no
  order-history list: the customer is signed in, and a WhatsApp link reaches the same page through a
  signed link. Whether the tracking sits inside each item's details or in one block above the invoice
  is on the canvas as a pair for the owner to pick.
- **Tabs only when an order has both kinds** - coins and challenges. A single-kind order shows none.
- **Opening the page is the refresh.** One supplier read fires on page open; after that the page shows
  stored state. No manual refresh button - the owner's words: "`تلقائي بس لما يرفرش الموقع مثلا يسال
  المورد على طول`". The tracker's `متصل` connection dot therefore becomes the age of the last
  observation, since the browser holds no supplier connection.
- **A challenge is named and pictured from the product the customer bought.** The tracker kept its own
  name and image map keyed by FFT's `setId` because it could only see an order number; the store has
  the order item.
- **The credentials sheet is email, password and the three backup codes.** The email is **editable**,
  which reverses the tracker's locked field and its "message us on WhatsApp" note: in the store the
  customer is signed in, and a wrong email is a case the page should handle. The tracker's five
  operational fields - platform, Persona ID, price limit, sort mode and the after-update action
  (`index.php:576-635`) - are not shown to the customer. Price limit and sort mode govern what the
  bot pays for players, so they touch our cost; platform is already known from the order. Resuming a
  stopped order stays on the standalone button.
- **The optimistic window is not a ten-second timer.** The owner: "we are not tied to the ten
  seconds - you said you have something better, I am fine with it." So port the tracker's condition:
  hold until the grace deadline passes or a poll shows the state actually moved, whichever is first.
- **`جاري إنهاء طلبك` is a pass-through, not a resting state.** Waiting for the players to sell takes
  30 seconds to two minutes normally and 15 minutes at the very worst. Design it as brief.
- **The ETA baseline stays as the tracker has it.**
- **`الكوينز في الحساب` is read when the supplier opens the account** and can see the balance, which
  is why it is unknown before then rather than zero.
- **Manual services get a screen of their own.** The tracker has none, so this is designed rather than
  ported; the owner asked for whatever design suits. It needs a progress figure that nothing records
  today, so an admin surface to enter it is implied.
- **The WhatsApp link stays in n8n**, inside the fulfillment automation. The store does not send it.

- **The visual layer is part of the port, not decoration around it.** Seeing the first canvas the
  owner said his own site looks better, and he is right: `ring.js` is a canvas living-gold ring with
  drifting embers, a breathing glow and a completion burst of shockwave plus coin-sparks, pausing its
  own loop off-screen and drawing one static frame under `prefers-reduced-motion`; `styles.css`
  carries 29 keyframes including the sheen that travels along the progress fill; and `fx.js` adds a
  fine-pointer-only 3D tilt and magnetic buttons. His instruction: "`عايزه برضو نفس موشنز وايكونز وكل
  تفاصيل الاساسي متهملهمش مع النقل ... اعتمد نفس الموقع الحالي يعني على الأقل انقله تماما ونبقى نعدل
  عليه`". Port it, then adjust from there.
- **The icon glyphs are the tracker's; the icon library is the store's.** The tracker loads Font
  Awesome 6.4.0 for 41 glyphs. The store keeps `lucide-react` and draws the same glyphs in lucide's
  geometry, because a second icon font for forty glyphs costs a download and puts two visual
  vocabularies on one page. WhatsApp is a brand mark lucide does not carry; the store already draws it
  in `resources/js/components/account/app-icon.tsx`.
- **The customer never reads about our plumbing.** Recorded in `CONTEXT.md`; it killed four strings
  the first canvas invented and it applies to every customer surface, not only tracking.

Canvas approved 2026-09-12 ("`تمام موافق كمل`"), with two readings recorded because the approval
did not name them:

- **Structure (ب): one tracking block above the invoice, with tabs when the order has both kinds.**
  His approval did not pick between the two structures the canvas drew, and (ب) is the only one
  consistent with both of his earlier answers - (أ), tracking inside each item's details, makes tabs
  impossible, because an item is one kind by definition. Told to him plainly; it is a one-line change
  at the mount point if he meant (أ).
- **The EA-servers wording** is the condensation of the tracker's own sentence for that state
  (`errorMap.loginFailed`): the login failed, it is most likely EA server pressure on their side,
  press resume to try again. A fresh sentence was drafted and discarded - the tracker has copy real
  customers have already read, and that outranks an invention.

Decided 2026-09-13, on the two mappings the tracker contradicts itself about:

- **`noFunds` is our float, not the customer's coins.** The owner: "`No funds يعني حسابي مفهوش رصيد
  عشان يشحن زي insufficient funds في fft`". So `StoreStock` is right, and the customer's own shortage
  keeps its separate status (`OutOfCoins` → `InsufficientCoins`). He asked for the retry button to
  stay, which it already does — the tracker offers it and `store_stock` is the reason he ruled on
  earlier for exactly this reason: a press costs nothing and may be the moment the float was topped up.
- **Every login failure offers a retry *and* a credential correction.** His words: "`Login failed دي
  الحساب ممكن فيه مشكلة او سيرفرات ف خليه فيه اوبشن انه يعمل اعادة تشغيل او انه يغير الايميل لو حط
  ايميل غلط مثلا`". This **widens the tracker's boundary**, which allows credential editing on
  `WrongUserPass` and `WrongBA` only and enforces that in its own API
  (`includes/api-handlers.php:2106`). That gate is the tracker's product choice rather than a supplier
  constraint: the comment beside it says `retrySBCAPI` "does not reliably gate on status upstream", so
  FFT accepts a credential correction whatever the status. The family is `LoginFailed`,
  `loginFailed`, `LoginFailed401`, `LoginFailed495`, `LoginError`, `loginLoop` and
  `LoginFailedDeviceBan`; `needEmailConfirm` is my extension on his reasoning, since correcting the
  email is the only thing that resolves it.

  **Correction, same day:** I first wrote that the whole family stays `InProgress`. It does not, and
  should not. Two of them are customer-action reasons and move the order to `WaitingForCustomer`:
  `LoginFailedDeviceBan` on `AccountBanned`, because a device block is something the customer may
  have to clear and the rewritten text asks them to check their details, and `needEmailConfirm` on
  `Credentials`, because only the customer can confirm the email. The rest of the family stays
  `InProgress` on `EaServers`, where it may well be EA's side. That reason's text says we will retry
  and says nothing about the two buttons now under it, which C2 has to cover.

  **A second correction, and then a correction to the correction.** I first justified widening the
  tracker's edit boundary by reading its own comment - `retrySBCAPI` "does not reliably gate on
  status upstream" - as proof that FFT accepts a correction in any state. That is too strong: the
  comment establishes the gate is the tracker's local product choice, which is enough to widen it,
  but not that the supplier is unconditionally permissive.

  I then told Mohamed that a changed email may not reach the supplier at all, and generalised an
  SBC-only mechanism to everything. He caught it. There are **two different calls**, and only one of
  them has the problem:

  | Path | Call | Keyed by | A changed email |
  | --- | --- | --- | --- |
  | Coins | `correctCredentialsAPI` | `orderID` | lands - `user` is an ordinary optional field beside it (`api-handlers.php:1178-1200`) |
  | Challenge | `createCustomerAPI` with `updateCustomer: '1'` | `user`, the email | writes a **different** customer record while the solve stays bound to the original account (`api-handlers.php:2118-2137`) |

  So the coins case - the ordinary one - is fine, and the risk is challenges only. There the failure
  mode is the bad kind: the tracker accepts `action: 'created'` as success, so sending a new email
  would report success while the bot keeps trying the old account. **D1 must verify the challenge
  path on a real test order before the email field ships for a challenge item.**

  And my explanation of *why* the tracker locks the email was invented. The coins call takes `user`
  freely, so the lock is a product choice of theirs - or it is there for the SBC case - and not a
  technical constraint. Nothing in the code says which.
- `sessionExpired` keeps retry alone. It is not a login-data problem: the tracker's help text says
  the connection renews itself, which is why it moved to `EaServers` rather than `ActiveSession`.

**Still open for him:** `LoginFailedDeviceBan` maps to `AccountBanned`, whose text says EA stopped
the account and to message us. A device ban is not an account ban, and the tracker's own action string
for it says to enter a correct backup code. It now carries both buttons, which softens the
mismatch, but the text is still wrong for it.

## Objectives is not sellable, and that is accepted for now

Owner decision, 2026-09-12: Objectives is not needed at the moment, so this stays as it is.

**Do not "fix" this by reverting the required-secret change in `PlaceOrder`.** That would restore
a worse bug, not repair a feature.

The facts, because I got them wrong once already and wrote the wrong version into a commit
message. Every service that carries EA credentials has its own add-to-cart action that collects
them: `AddCoinsToCart`, `AddSbcToCart`, `AddRivalsToCart`, `AddFutChampionsToCart`. Objectives goes
through the generic `AddCatalogItemToCart`, which collects nothing. The cart then *displays* that
credentials are needed - `CartController::credentialsKind()` returns `'sbc'` for Objectives by
default - but no path anywhere collects them, and `CartItemCredentialsController` is limited to
Rivals and FutChampions. So Objectives is the only credential-bearing service with no way to
supply credentials.

That is why the credentials were being dropped at checkout: `PlaceOrder` had nothing to save. It
has always been this way; requiring the secret did not create the gap, it made it visible and
moved the failure before payment instead of after it. Today a customer reaching checkout is
stopped, and shown the generic "cart or prices changed" message, which cannot help them.

Making Objectives work needs an `AddObjectivesToCart` path collecting credentials the way the SBC
path does, a cart-side entry point, and `CartItemCredentialsController` widened to accept it. That
is a new interface, so it goes through a `/design` canvas before code, per `CLAUDE.md`.

## The tracker's behaviour is the specification

Owner instruction, 2026-09-12, after five corrections in one day: **port what
`track.arab-ut.com` does. Do not redesign it.** The site is mature, it has been in front of real
customers, and every one of its apparent oddities so far has turned out to encode something true
about the suppliers or the customers.

The tally, because it is the argument for the rule rather than an apology:

| I changed | The tracker had | Who was right |
| --- | --- | --- |
| `loginFailed` to a credentials hold with an edit button | EA-server hold, resume only | the tracker |
| `abort` to `Cancelled` | stopped, resumable | the tracker |
| automatic-recovery reasons to show no buttons | every reason keeps its buttons | the tracker |
| flagged the `store_stock` resume button as unhelpful | offers it anyway | the tracker |
| challenge progress to one counter | two tracks, squads and solves | the tracker |

Every deviation ran the same direction: reasoning from first principles about a domain the working
implementation already had right. So the default is now inverted. **Match the tracker unless the
owner says otherwise, and write down any deviation he approves along with his reason.**

**One place we deliberately did not port it, approved by Mohamed on 2026-09-13** ("`تمام انت صح`"). The trailing
zero-remaining override reads `remaining <= 0` where `remaining = total - delivered`, and both sides
default to 0 when the supplier has reported no numbers (`ui.js:528-531, 542`). So an order that has
reported **nothing at all** computes `0 - 0 <= 0` and renders `جاري إنهاء طلبك` - "finishing your
order" - before a single coin has moved. Our port guards the override on both counters being present
and the ordered amount being above zero, so that order falls through to its real state instead.

This is the one tracker behaviour so far that looks like an ordinary bug rather than encoded truth:
it tells a customer their order is wrapping up at the moment it has not started. The `NotReported`
presentation exists for exactly that case. He agreed, so this is the first approved deviation
from the tracker that is ours rather than his - and the standing rule holds either way: it is
written down here with who approved it and why.

This does not extend to the three things that are ours rather than the tracker's: canonical
`OrderStatus` (the store's spine, which the tracker has no concept of), what we persist (the
tracker stores nothing, we have an allowlist because the suppliers return passwords), and security
boundaries. Those are store concerns and the tracker is not evidence about them.

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
- Real end-to-end placement needs B4 **and** F1/F2; nothing reaches customers before that path
  passes an end-to-end acceptance run.
- C shows nothing real until B3 gives a job a supplier reference to read. G needs C to point its
  links at, and retiring `track.arab-ut.com` needs G. So the critical path is
  **B3 → C → G → retirement**, and everything in D, E and F hangs off it rather than blocking it.

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

**A2. Rotate the supplier keys.** Not housekeeping: both keys sit in plaintext in the tracker's
`config.php` on a host that is about to be retired, and during this work a review agent read that
file and copied the values into its own transcript. Treat them as already disclosed. Mohamed
rotates FFT and UTT and distributes them to **both** the
store's `shared/.env` and n8n — n8n still places orders and runs the pricing and catalog workflows
against these suppliers.

**The tracker repo is not touched.** Owner instruction, 2026-09-12: leave `track.arab-ut.com`
alone and retire it instead, soon. So the four write endpoints and the opt-in auth on
`admin-links.php` stay as they are, and the exposure documented in the design doc stays open for
as long as that site is up. The mitigation is the retirement date, not a patch — which is why
slice G sits on the critical path rather than in "later". If Mohamed wants it closed sooner, the
mechanism already exists on that host: run `tools/set-admin-password.php` from the CLI and set
`adminAuth.requireWhenUnset` to `true` in `config.php:111`.

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

**B1. Supplier clients — reads and actions.** `FftClient` and `UttClient` behind one interface,
covering both the status reads the sweep needs and the endpoints a customer action needs
(credential correction, resume, retry). This is wider than the previous revision said: moving
polling back into Laravel puts the read path here too.

Config in `config/services.php` with `.env.example` parity and a `configuration()` guard that
throws rather than half-works, following `PublishOrderPaidEvent.php:82-102`.

**Two timeout profiles, not one.** Polling gets `connectTimeout(3)` / `timeout(5)`: a hung
supplier must not eat the tick, and a skipped read costs nothing because the next one is seconds
away. Customer actions keep the house `connectTimeout(5)` / `timeout(12)`, because there the call
has to actually land and somebody is waiting for it.

No shared outbound limiter or circuit breaker exists anywhere in this codebase — add a
per-supplier one plus outage backoff. The sweep and the refresh button share it.

**B2. Translation layer.** One supplier observation → `{OrderStatus, OrderHoldReason|null, allowed
actions}`. Rules that are not negotiable:

- An unknown or malformed code **keeps the last known canonical state**, marks the observation
  unsupported, disables unvouched actions, and alerts. It does not become `in_progress`.
- The output is a set of allowed actions, not one boolean: edit and resume are separately gated,
  and SBC edits are gated more narrowly still.
- Supplier codes are stored for diagnosis, never rendered.

Source lists: `C:\xampp\htdocs\track\assets\js\ui.js` and `includes/functions.php:1330-1445`,
transcribed into a fixture. Test every known code, plus an unknown one, plus a regression attempt.

**B3. The one endpoint n8n needs.** `POST /api/automation/v1/fulfillment/placements` —
`{order_item_public_id, supplier, supplier_order_id}`. Idempotent by key so n8n's retries are
no-ops; a reference already bound to another item is rejected; a reused key with different data is
a conflict, not an overwrite.

That is the whole inbound surface. There is no observations endpoint and no open-jobs read: the
store polls the suppliers itself, so n8n never needs to be told what to look at and gets no read
path into the store.

**Say which HMAC convention explicitly**, because the existing routes do not share one: coins
pricing and base catalog sign `timestamp\neventId\nrawBody`; SBC catalog inserts `n8n-sbc\n`
before the body (`VerifyN8nSbcCatalogSignature.php:17`); SBC pricing read signs
`timestamp\nGET\n<path>\n` with no event header. Adopt the first. SHA-256 hex, the existing
±300-second window, its own key, 32-char secret and named limiter.

**B4. Outbound placement request.** The real gap in the first draft: today both payment paths
publish only identifiers, locale, currency, total and item count (`PlaceOrder.php:331`,
`ReconcilePaylinkPayment.php:77`). This task builds the payload — automated items selected, their
configuration, and the credential block the ADR authorises — **composed at send time** from
`order_item_secrets` with the access logged, leaving the persisted outbox row secret-free. A
retried send re-reads current credentials rather than replaying old ones. Contract written up as
`docs/api/n8n-fulfillment-v1.md`. Tests for a wallet-paid and a Paylink-paid order.

**B5. Reconciliation.** Applying an observation to canonical state is its own transactional
service, not a side effect of reading. It locks order and items the way
`TransitionAdminOrder.php:63` does, lets a manual admin hold win, refuses to move a terminal
order, aggregates items to the order conservatively, and fires completion effects (cashback,
review invite) exactly once regardless of which path completed the order. A supplier cancellation
never produces `refunded`.

**A later phase arriving on a finished job must re-open polling.** Found while building B3,
2026-09-12, and confirmed by reading rather than assumed: a challenge placement recorded against a
job whose coins phase already completed is stored correctly, but nothing resets `status` or
`next_poll_at`, so the challenge is never polled. The job looks finished and the challenge runs
unobserved at the supplier. This is the phase-progression half of reconciliation and it belongs
here: B3 deliberately does not let a second placement touch job lifecycle, because a second phase
must not make a finished first phase look unfinished either. Both halves are this task's problem -
re-open polling for the new phase without un-completing the old one.

**Deciding what not to store is part of this task.** An FFT status payload can carry the
customer's EA account email - the tracker masks it before the browser for exactly that reason - and
`RawSupplierObservation::toArray()` hands it over raw, by design, because capture and redaction are
different jobs. The raw capture stops here: whatever writes the `observation` column decides what
is masked first, and that decision is written down rather than left to whoever reads the column
next.

**Observation ordering stays a requirement here** even though the batch endpoint is gone. Dropping
that endpoint removed one source of out-of-order arrivals — a late batch — but not the other: the
sweep and a customer's refresh press can read the same job at the same time and finish in either
order. So an observation older than the one already stored is discarded, and the per-job lock from
decision 13 is what makes that check meaningful rather than racy.

**Three boundaries B5 cannot decide on its own**, found in review 2026-09-12 and left stated
rather than guessed:

- **A partially-cancelled order stalls.** Any `Cancelled` item makes "every item completed"
  unreachable, so an order whose remaining item finishes sits in `InProgress` for good - no
  cashback, no review invite - until a human acts. That follows correctly from "an observation
  never cancels an order", but somebody has to decide what a part-cancelled order's completion
  means, and it is not the reconciler.
- **Item status is not monotonic.** A `Completed` item on a still-open multi-item order can move
  backwards under the phase-progression rule. Once the ORDER completes, the terminal rule freezes
  everything, so the exposure is bounded to open orders.
- **`order_items.order_id` is immutable only by convention.** Nothing updates it today, which is
  what makes the unlocked read of it safe before the transaction opens. The day someone adds a
  "move an item between orders" writer, that read becomes wrong silently.

**A challenge id the supplier does not recognise belongs here too.** `observeChallenges()` is bulk
and answers only about the ids it knows, so an id we asked about can simply be absent. That is not
an error and not zero progress: the reader keeps the job's existing counters and applies whatever
did come back, per the same fail-closed rule that governs an unknown status code. But an id that
stays absent across several sweeps means the challenge is not where we think it is, and that must
reach Mohamed rather than spin forever.

The implementer proposed a new hold reason for it. It is not one - the enum has seventeen values,
all of them things a customer reads, and "FFT does not recognise this challenge id" is an operator
problem. It is the same shape as a paid item with no placement row, which is what this task
already exists to surface.

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

**Two items inherited from the Objectives fix, 2026-09-12, both needing an owner call:**

- **A missing EA secret at checkout tells the customer the wrong thing.** `PlaceOrder` raises
  `CheckoutUnavailable('EA account details are required.')`, but the Paylink checkout controller
  folds every non-phone `CheckoutUnavailable` into the generic "your cart or prices have changed,
  refresh and try again". A customer whose credentials went missing is told to refresh, which
  cannot help them. The fix is a distinct error for that case and copy that names the actual next
  action.
- **Objectives is the only service not held to `quantity === 1`.** It now binds to one EA
  credential snapshot per item, so a quantity above one is ambiguous: is it several completions on
  one account, or a mistake? Decide before it matters, because the ambiguity is in orders already
  placeable today.

**C2. Copy and the refund unfolding** (Claude; Mohamed approves the texts). Stop folding
`Refunded` in **both** `OrderStatus::forCustomer()` and `OrderItemStatus::forCustomer():16`. The
assertions that will fail are `tests/Feature/Account/AccountOrdersTest.php:309` and `:334` — the
parity tests check enum/label coverage, not folding. Revise the cancellation wording at
`lang/{ar,en}/orders.php:8`. Keep raw status driving financial logic. Then the hold-reason work:
separate reason text from contextual action copy, and change only the reasons that actually tell
the customer to message us - several already describe automatic recovery and must not be given a
button.

**The disagreements are already enumerated, so this decision arrives with evidence rather than as
an abstract question.** Produced by the B2 work, 2026-09-12, comparing each reason's text in
`lang/ar/orders.php:19-36` against the actions the translation layer now offers for the supplier
codes that resolve to it:

*Our text promises automatic recovery, but a Resume button appears next to it:*
`ea_servers` (via `loginFailed`), `connection` (via `FailedProxyConnectionError`,
`FailProxyUnavailable`), `no_player` (via `noSuitableSender`, `noPlayer`), and `paused` (via
`dailyReceiverLimit` and the stopped-status fallback, though not via `tempbanCooldown`,
`listingTempban` or `deactivated`, which offer nothing).

**Resolved by the owner, 2026-09-12: the text changes, not the button.** Keep every button the
tracker offers, for every reason, and revise the Arabic so it stops promising automatic recovery
where a control exists. The Salla-era wording was written for a page with no controls on it; the
tracking link has always given the customer the actions, and full customer control is the point.

`store_stock` deserves a note, because it was raised as a doubt and the owner's answer settles it.
The reason comes from the supplier telling us OUR float is short, so a press may not succeed yet -
and that is precisely why the button belongs there. The float gets topped up at any time and the
customer has no way of knowing when; pressing resume is the cheapest way for them to find out it
worked, and a press that fails costs nothing and can be repeated. So the copy only needs to avoid
promising immediacy. It must not tell the customer to wait for us.

Retry pressure is already bounded elsewhere: D1 rate limits actions per order, and B1's
per-supplier limiter sits under both the sweep and the button.

*Our text asks for something the offered buttons do not do:*
`credentials` asks the customer to correct the order form, but the three 2FA codes resolving to it
offer only Resume; `platform` and `account_banned` both say "راسلنا" while buttons appear;
`market_locked` asks the customer to play matches or supply another account, which neither button
does; `no_club` matches on Resume but carries an extra Edit.

*Text and actions already agree:* `backup_codes`, `insufficient_coins`, `active_session`,
`transfer_list_full`, `captcha`, `unassigned`, `store_stock`, `maintenance`.

Gulf-leaning simple Arabic, no Egyptian slang.

**The optimistic window after an action is not a timer, and porting it as one loses the point.**
The owner described it as "a temporary state for ten seconds", and the tracker's implementation is
better than that: `isRetryGraceActive` (`assets/js/ui.js:250`) suppresses the stale error until
**whichever comes first** - the grace deadline passes, or `statusShowsRetryStarted()` sees the real
state actually move. The moment a poll shows movement the optimistic state stands aside, and if
nothing moves the error returns on its own.

Both halves matter. A plain ten-second timer brings the error back while the retry is genuinely
under way, which reads as a failure that has not happened; and an optimistic state with no
deadline hides a real second failure indefinitely. The grace is also held in `sessionStorage`
(`ui.js:1032`) so it survives the page's own reloads, which it must, because the page reloads
while the window is open.

Port the condition, not the duration.

**Progress is capped at 100%, and the raw counters are not.** Owner decision, 2026-09-12: both
suppliers over-deliver slightly - 3,000,150 coins against 3,000,000 ordered, 502K against 500K -
and 150 coins on three million is noise rather than information. The bar stops at 100%. The
tracker already does exactly this (`Math.min((delivered / total) * 100, 100)`, `ui.js:530`), so
this is agreement rather than a new rule.

What we store stays whatever the supplier said. Clamping belongs to the display; rewriting the
observation to fit the bar would be falsifying the record to protect a progress bar.

**Challenge tracking is read but not wired, and the two counter tracks are stored under names that
say the wrong thing.** Found 2026-09-12 while briefing the canvas. `FftClient::observeChallenges()`
exists and is tested, and has **no caller in `app/` outside `app/Suppliers/`** - the placement now
records the challenge ids, and nothing asks about them. Two further defects sit in the path C3 has
to use:

- `challenges_solved` and `challenges_requested` hold `challengesDone` / `totalChallenges`, which is
  the **squads within the solve being worked now** - seven of them in the order we read - under names
  that say solves. The solve track, `timesSolved` / `timesToSolve`, survives only inside the raw
  `observation` json, and `ItemTracking` does not expose it at all. So the payload offers "1 / 2"
  semantics for a "3 / 7" number, which is the exact collapse the owner's rule forbids. Renaming and
  exposing both tracks is C3 work, not a later tidy-up.
- `SupplierStateTranslator` has no `sbcStatus` vocabulary. It decides from `status`, `accountCheck`
  and `economyState`, and an `sbcStatusBulkAPI` response carries none of the three, so a challenge
  observation cannot be translated at all today. The tracker's SBC status map and its retryable set
  have to be ported as their own decision path, fail-closed on an unknown code exactly as the coins
  path already is.

One item can carry several challenge ids, so the challenge payload is a **list** of per-challenge
objects rather than one flattened progress object - the tracker renders one card per challenge id.
Because that shape follows from the canvas, both defects land with C3 rather than ahead of it.

**What the payload still has to carry.** Established by the canvas and by two reviews of the
challenge path, 2026-09-13. Every item here is something an approved artboard shows and
`ItemTracking` cannot produce today:

- **The action box's tone**, as its own field. The skin is decided by the supplier code that arrived,
  not by the hold reason it maps to - nine `economyState` codes are amber and everything else that
  produces a message is red - so one reason wears either skin depending on how it was reached. It has
  to be decided where the code is still known.
- **A presentation state for the headline.** `status`, `phase` and `holdReason` are not enough: the
  tracker's headline comes from an ordered cascade whose branches are not mutually exclusive, and a
  client re-deriving it would be re-implementing supplier vocabulary on the wrong side of the
  boundary. Send a discriminator.
- **A failure that carries no hold reason is invisible.** `tooExpensive` and its siblings resolve with
  `holdReason: null` and a retry action, so the payload reads identically to healthy work. The UI can
  only tell them apart by inspecting the action list, which is not what an action list is for.
- **Coverage of the last answer.** A partial bulk response is applied, and the page cannot currently
  tell a fully observed job from one where two of three challenges answered. The freshness line says
  when we last heard, not how much of the job it covered.
- **The challenge list**, as a list of per-challenge objects with a stable client identity and a
  server-resolved action target, because one item can carry several challenge ids and every action
  has to name which one.
- **The account coin balance**, with the unknown case and the `-1` "being prepared" case distinct,
  and the inputs the ETA needs.
- **The completion time**, which is not `observedAt`.
- **The service kind**, explicitly. Neither the item name nor a nullable phase is a reliable
  discriminator, and a Challenge item also has a coins phase.
- **The optimistic window's state**, so the client can hold a stale error until either the grace
  deadline passes or the state actually moves - the tracker's condition, not a ten-second timer.

None of these may be filled by exposing a raw supplier observation to the client.

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

**A correction writes to our own record first, and that is what makes a two-phase order work.**
Owner's point, 2026-09-13, and it is the one that matters about credentials:

A Challenge order runs coins first, then n8n submits the solve after the coins loop finishes. So a
wrong email surfaces during the **coins** phase, where correcting it works - `correctCredentialsAPI`
is keyed on `orderID` and takes `user` as an ordinary field. The coins then complete. But when n8n
comes to place phase two, it places with whatever email the store hands it, and if the correction
only ever went to the supplier, the store still holds the original. The challenge is then submitted
against an account that does not exist and is never placed at all.

So the order of writes is the requirement, not an implementation detail:

1. the customer submits a correction
2. **the store writes `order_item_secrets`** - our record is the source of truth
3. the store forwards to the supplier for the phase running now
4. when phase two is placed, B4 composes its payload from `order_item_secrets` at send time, so it
   carries the corrected email without anyone remembering to pass it along

B4 already specifies that composition and that a retried send re-reads current credentials rather
than replaying old ones, so the second half is designed. The first half - D1 writing back before
forwarding - was not written down anywhere, which is how the gap would have shipped.

**This also settles the editable-email question.** Correcting it during the coins phase fixes phase
two, so the field stays editable for both kinds and nothing needs locking. The one case left is a
challenge **already placed** against a wrong email, which stays a support matter - and it is rare,
because coins always runs first and surfaces the bad email before the solve is ever submitted.

**D1. Self-service actions.** Each action derives from the allowed-action set, and is
re-authorised server-side when pressed — never trusted from the client. Rate limited per order.

**Credential correction is a two-step protocol, and the earlier draft of this task had it wrong.**
It said the supplier "can return HTTP 200 and still not have applied the change", and asked for
semantic validation of that response. The owner's description, 2026-09-12, is that the immediate
answer honestly means *received* and nothing more: the item then moves to trying again, the
supplier's bot attempts a fresh login later, and only that attempt reveals whether the details
work or the item returns to the same hold. See `CONTEXT.md`.

So the model is `submitted` → `acknowledged` → later `worked` or `wrong again`, where the last
step arrives in a subsequent observation rather than in the submission's response. This task
therefore does not need response-validation; it needs a **pending state with an owner**:
credential versions so a second submission during a pending attempt is ordered rather than
racing, and copy that says "received, trying again" rather than "fixed".

The reconciler must stay free to move an item from in-progress back to the same hold reason,
because that is how a second wrong password becomes visible. No no-going-backwards rule may
block it.

**A credential fix must schedule its own read.** Nothing arrives from a supplier - the verdict on
new details exists only in a poll - and a customer who has just retyped their password is watching
the screen. So accepting a credential fix sets the job's `next_poll_at` to now and stamps
`last_viewed_at`, putting it in D3's attention band instead of the background one. Without that,
the customer waits out the ordinary cadence to learn something the supplier may already know.

**A challenge retry must prove the challenge belongs to the order.** Found in review, 2026-09-12:
the tracker validates a retry twice - the challenge id's format, and that the challenge is
actually on that order (`api-handlers.php:1852-1860`, which answers `403 SBC_NOT_IN_ORDER`). The
supplier client built in B1 does neither; it strips the prefix and posts. So the ownership check
has to live here, or a crafted request retries a different customer's challenge through our own
credentials.

**D2. Signed per-order link.** A random token bound to one order, stored hashed, never expiring,
serving the C4 presenter. Read for the life of the order; actions refuse once terminal.

**D3. The sweep, stall detection, and notification.** One scheduled command owns the read loop.

- **Cadence follows attention.** Roughly every 20–30 seconds for a job whose order page was
  stamped as recently viewed, every 2–5 minutes otherwise. The page stamps the job on load; the
  sweep reads the stamp. Selection is oldest-due-first within each band.
- **An explicit wall-clock deadline inside the command**, not an assumed one. The 55-second figure
  belongs to `queue:work`, not to scheduled commands (`routes/console.php:23`, `:42`), so nothing
  stops an overrun except the deadline we write.
- **A real lease with crash recovery**, not bare `withoutOverlapping()`, whose default lock lasts
  a day — exactly what the comment at `routes/console.php:47` warns against.
- `next_poll_at` advanced on **failure as well as success**, jittered backoff, `Retry-After`
  honoured, and the per-supplier limiter from B1 shared with the refresh control.
- **Instrument it from the first day**: reads attempted and completed per tick, p50 and p95
  supplier latency, ticks that hit the deadline, and ticks skipped by the lease. The capacity
  argument for putting this in Laravel rests on latency nobody has measured yet; these numbers are
  how we find out before customers do.
- **Stall detection is the same loop** and free: an open job whose newest observation is older
  than the cadence expected for its phase is stalled.
- **Notification** is one Whapi call per message, driven by what B5 reconciled. Message catalogue
  ported from `Customer Notifier`.

Customer actions do not go through any of this. They are synchronous, on the longer timeout
profile, and never queued behind a sweep.

De-duplication needs a durable transition identifier and a unique delivery claim.
`notification_deliveries` has no unique constraint for order-plus-state today
(`2026_08_08_000004:99`), and keying on "order and state" alone would swallow a second item's
problem and a genuine recurrence after recovery. `Customer Notifier`'s own de-duplication is worse
still — `$getWorkflowStaticData` with a six-hour window, which does not survive an n8n restart —
so this is a replacement, not a port. Whapi's OTP sender is a bare HTTP call
(`WhapiVerificationSender.php:29`), not delivery machinery; that part is new.

---

## Slice G — manual orders, and retiring the tracker (Claude)

This is what makes `track.arab-ut.com` deletable, which is why it is not "later".

**G1. One admin screen, not two.** Mohamed asked for both a manual order and a bare
supplier-order link. They collapse into one feature, because `fulfillment_jobs.order_item_id` is
NOT NULL and bound to an order item (`2026_08_08_000004:41`): a link pointing at a supplier order
with no store order has nowhere to live, and making that column nullable would produce order-less
jobs floating free — the exact split that let the tracker drift away from the store.

So: **create a manual order, with the money optional.**

- Bank transfer → amount plus a manual payment record.
- Gift → no amount, no payment.
- Already placed at the supplier by hand → paste the supplier reference instead of dispatching to
  n8n, and the job is bound at creation.

It takes an `AUT-` number, runs the same fulfillment path, and gets the same tracking page and the
same signed link. Needs: a `manual` value on `orders.channel` (today only `store` and
`salla_import` exist), a payment provider for it (today only `wallet` and `paylink`), an admin
permission of its own, and staff audit on every creation. Reuse `PlaceOrder` rather than writing a
second checkout — that constraint is in the Admin skill's non-negotiables and it applies here.

**Owner decision, 2026-09-12: the form takes everything.** Pick an existing customer or add a new
one; pick an existing product or add one; and the same pattern through the rest of the form. No
narrowed subset of services.

Still open, because money is involved and the owner has not ruled on it: whether a manual order
earns cashback and loyalty spend. It should not, by the same reasoning that excludes
`salla_import`, but that is a recommendation and not yet a decision.

**G2. Retire the tracker.** Once G1 and C are live and verified: confirm no unresolved supplier
job is outstanding, redirect `track.arab-ut.com` at the store, update the assistant prompts
(`support-v6..v9` still send customers there) and the knowledge file that points at a nonexistent
`/orders` path, then take the site down. That closes the `admin-links.php` exposure by removing it.

## Slice E — operations (later)

**E1. Admin fulfillment screen.** Every item currently at a supplier: supplier, age, stall,
failure, actual cost, retry. Inside the existing Admin with its permissions, server-side filter
and sort allowlists, and staff audit, per `.agents/skills/arab-ut-admin/`.

**E2. Alarms and recovery.** Poll-age and placement-age alerts, and a written manual recovery
procedure for a paid order with no reference. `docs/operations/hostinger-rollback.md:21` covers
release rollback only and says nothing about external state; rolling Laravel back after the Sheet
writer is gone needs its own note.

---

## Slice F — the n8n side (Mohamed's instance, Claude writes the workflows)

Baseline committed at `automation/n8n/fulfillment-v14/workflow-v14-salla.json` (117 nodes) and
`automation/n8n/customer-notifier-v2/workflow-v2-salla.json`. Every change below is committed as a
new file beside them, never edited in place, so the Salla baseline stays readable.

n8n ends up with **two** workflows and no read path into the store. The three wait-loops are not
replaced by an n8n poller — they are replaced by D3, in Laravel.

**F1. `ship-coins`.** v14's placement path, kept: UTT stocks, FFT cooldown,
`Supplier Decision Engine`, then `buyCoinsAPI` or `addOrderPublic`. The budget arrives in the
request instead of being read from a sheet. Ends by reporting the reference to the store's one
endpoint. Remove every Sheets, Supabase, Salla and WhatsApp node on this path, and every wait
node. Set instance concurrency to 1, as `automation/n8n/sbc-catalog-v1/README.md` already warns.

**F2. `solve-challenge`.** v14's SBC path from `availableSBCsAPI` through `SBC: Match & Validate`,
`SBC: Supplier Decision` and `newSBCAPI` / `Submit Solve`, triggered by the store once the
shipment has landed rather than by an in-workflow poll. Same removals.

**F3. Retire.** Delete `Customer Notifier` and v14's `Forward Status Update` node once D3 is live
and verified. Disable every execution-data save mode on the credential-bearing workflows first
(the ADR's condition), and verify with synthetic credentials before any real order runs through.

## Activation gate

The placement endpoint ships before the reconciliation that makes its second phase observable, so
the order of switch-on is a correctness requirement rather than a preference.

**Owner note, 2026-09-12: the store is currently taking no orders at all, so nothing can be
stranded today and this gate is not blocking anyone.** It is written down because it stops being
free the moment orders resume, and that day will not announce itself.

**`N8N_FULFILLMENT_KEY` and `N8N_FULFILLMENT_SECRET` should stay unset until D3 and the B5
phase-progression fix are live.** While they are unset the route answers 401 before the controller
runs - `VerifyN8nFulfillmentSignature::handle()` returns `unauthorized()` ahead of
`$next($request)` when the key is not a non-empty string or the secret is under 32 characters - so
the endpoint is inert by default and no placement can be recorded. Verified 2026-09-12.

That inertness is what makes deferring the phase-progression gap safe. Set those two keys and the
endpoint starts acknowledging challenge placements that nothing will ever poll: a challenge would
run at the supplier while the job reads as finished. So the two environment variables are the
switch, and D3 plus B5 are its preconditions.

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
