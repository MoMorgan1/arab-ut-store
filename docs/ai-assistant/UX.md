# Customer experience

**Lifecycle:** Phase 1 implemented and accepted; Phase 2 UX implemented but
inactive and not accepted
**Verified:** 2026-08-22

## Conversation behavior

The launcher opens on a Home view (brand hero, greeting, a Continue card when
the conversation already has a customer message, a Send-us-a-message call to
action, and four topic cards). Start, Continue, or a topic card slides into the
chat view; the chat header's Back control returns to Home, and reopening after
close lands on Home again. A topic card sends its label as the first message.
The widget uses a light warm surface with gold accents; the AI disclaimer line
under the composer renders only when the server reports `assistantMode: agent`.
The `initialView` prop lets tests and hosts open directly in the chat view.
Assistant bubbles settle in with a soft rise and blur-to-sharp reveal, and each
newly streamed text run fades in rather than flashing; reduced motion disables
both. A short two-note chime (Web Audio, no asset) plays once per newly arrived
assistant message — never for history loaded on open — and the chat header has
a mute toggle persisted in `localStorage` (`arabut-chat-sound`); peak gain is
0.8 behind a compressor so it carries on phone speakers. While the mobile sheet
is open the page is scroll-locked (`html.chat-scroll-lock`) and the sheet tracks
`window.visualViewport` (re-synced for ~800 ms after any focus change, because
iOS settles the keyboard without a final viewport event), so an open software
keyboard shrinks the sheet instead of pushing its header off-screen. On phones
the dialog is a bottom sheet: 88% of the visual viewport with rounded top
corners and a drag handle, the page dimmed behind it (tap to close), and a
swipe down from the header or an unscrolled list dismisses it. With a keyboard
open the sheet takes the whole remaining viewport; when the keyboard closes and
iOS leaves the viewport scrolled, the sync resets that offset.

When a customer's message is primarily about a service the store sells, the
reply carries clickable service cards (`cards.v1` in the message metadata,
rendered by `ChatServiceCards`). The model never authors a card: cards are
derived server-side from the customer's own message, so a reply cannot advertise
a service or link the store does not offer. Cards carry no price — prices are
live data and belong on the product page — and the client validates the payload,
capping it at two cards and refusing any link that is not a same-origin
storefront path. Support and policy answers get no card: a card is an invitation
to buy, not decoration on a warranty explanation.

Cards show a live starting price when one is available: coins as a per-100k
rate (the cheaper of console-normal and PC), and SBC, Rivals, and FUT Champions
as their cheapest orderable option. The price is never stored on the message —
chat history is permanent, so a stored price would still be displayed months
later when it is wrong. `BuildServicePriceLabels` computes it behind a
60-second cache and the widget fetches it from `/chat/service-prices` once, and
only after a reply actually offers a card. It deliberately does not travel in
the Inertia shared props: those are built for every request, including JSON
endpoints, and the storefront enforces per-page query budgets that pricing
lookups would blow. Each service is computed independently: if one price is missing or
malformed, that card simply shows no price.

When a customer has named a service but not everything needed to price it, the
reply carries the single next question as tappable chips (`choices.v1`, built by
`BuildAssistantChoices`). A broad pricing question earns "which service?" rather
than a wall of every price; a "how much is X" question that scores the generic
pricing topic above the service it names falls through to that service.

**Every chip's message restates everything chosen so far.** A turn re-derives
its answer from the latest customer message alone, so a chip reading only
"بلايستيشن" arrives with no service attached and the funnel dead-ends. The
platform chip sends "ابي كوينز بلايستيشن", the quantity chip carries the
platform, and the delivery chip carries both. Rivals is the clearest case: it is
priced by route, so the question is asked in two steps and each target chip
carries the whole route. The cap is seven chips, the width of the division
ladder.

When the customer has named a coins configuration the store can actually
price, the reply carries an add-to-cart offer (`cart.v1` in the message
metadata, rendered by `ChatCartOffer`). Like every other block it is derived on
the server from the customer's own words — `BuildAssistantCartOffer` reads the
same typed options the cards use, and the model never authors one. The offer and
the choice chips never share a reply: chips ask while something is still
unchosen, the offer appears once nothing is. A console order is not cart-ready
without a chosen speed, because normal and fast are different products at
different prices; PC carries no speed at all and is complete without one.

The offer stores no price. The panel quotes the exact total at render time from
`/coins/quote` — the same endpoint the storefront configurator uses — for the
same reason cards store no price: chat history is permanent. When the store
cannot quote, the panel shows the selection and the button with no number
rather than a stale one.

The store requires EA account details at the moment an item enters the cart
(`CoinsCartRequest`), so the panel collects them. They are typed into the widget
and posted straight to `/cart/items/coins` over the customer's own session, and
they are cleared from component state as soon as the item is in the cart. They
are never sent as a chat message, never written to a transcript, and never
reach model context — a credential typed as a message would persist in history
indefinitely and travel in every later prompt. The panel reports its own
validation errors in the store's language rather than the browser's, and maps
the endpoint's 422 back onto the same fields. Rivals and FUT Champions have no
in-chat offer: both require a squad screenshot upload at cart-add time, which
belongs on the service page.

The launcher initializes chat lazily. One owner has one open conversation.
Hourly maintenance closes it after 24 hours without a message. A later open
request reuses only an inactivity-closed conversation inside the seven-day
last-activity window. Legacy null activity falls back to `closed_at`, then
`updated_at`; reopen/reclose without a message does not refresh that anchor.
New conversation calls `POST /chat/conversations/restart`: it closes the old
open thread and returns a new public ID with onboarding. Explicitly restarted
threads never reopen.

If a send discovers that another tab or maintenance closed its conversation,
the hook reacquires the canonical active conversation and replaces messages,
cursors, unread state, queue ownership, and delayed-reply ownership as one new
generation. If a restart response is lost or malformed, the hook reacquires:
a different active public ID confirms success, the same ID preserves the
current state and reports the restart failure, and a second failure preserves
state with a localized recovery error.

The widget preserves the active public ID across Inertia navigation and resolves
it after refresh. Login claims matching guest history transactionally. Closed
history has no customer-facing picker in this release.

## Agent response behavior

When server mode is `agent`, a persisted message receives no demo reply. The
browser shows typing feedback immediately when a message is enqueued. After the
persistence FIFO empties, it starts the 1.5-second quiet window and then the
durable turn. `turn.created` creates an empty assistant bubble; text deltas fill
it, and terminal completion replaces it with the canonical persisted message.
Assistant bubbles use a short entrance transition and streamed text uses a
subtle caret; reduced-motion users receive neither animation.

Polling recovers an active or terminal turn after disconnect/reload and stops
after five consecutive polling failures instead of retrying forever. Retry is
shown only for a retryable terminal turn. The browser blocks New conversation
during quiet wait, streaming, polling, pending send, or restart, and the disabled
control does not expose its tooltip strip. A terminal pending signal starts one
successor only after the FIFO empties.

Production currently resolves new messages to the deterministic Phase 1 demo:
the mandatory public Luna evaluation failed and Mohamed selected disable and
remediate. A post-disable public probe received a demo reply with no agent stream
or agent turn.

## Account and mobile placement

`ChatRootLayout` marks the account surface so `chat-widget-root--account` uses
the fixed mobile offset `calc(88px + env(safe-area-inset-bottom))` and appears
above `.account-mobile-bottom-nav`.
The open mobile chat is a fixed full-screen dialog; closing restores focus to
the launcher. On storefront/auth surfaces it becomes a bottom-right panel at
the `sm` breakpoint. On account surfaces it remains full-screen through
47.99rem and anchors only at 48rem.

The accepted Phase 1 Chromium fixture created a synthetic local user, never a
production user. Within one authenticated scenario it checked Arabic and
English at 320px and 390px as full modal dialogs, then at 768px and 1440px as
anchored nonmodal panels. It covered safe-area geometry/reset, 44px controls,
computed layers, hit testing, keyboard focus, Escape/focus restoration,
outside-panel actionability, reduced motion, overflow, and runtime errors.

Current repository coverage additionally exercises fake-agent streaming,
terminal recovery, and New conversation after a completed reply. CI runs the
storefront and fake-agent browser suites; the real 16-case Luna evidence is a
separate production measurement, not a CI network test.

## Direction and accessibility

- Customer bubbles are physically right and assistant bubbles physically left
  in Arabic RTL and English LTR; bubbles use `dir="auto"` for mixed-language text.
- The composer has a persistent accessible name, 16px mobile text, a 44px
  minimum height, and grows up to 120px.
- Launcher, close, back, send, restart, retry, topic/quick-reply, older-message, dismiss,
  and scroll controls have 44px minimum touch dimensions. Dialog/live
  semantics, visible focus, and reduced-motion classes are present.

On 2026-08-21, Mohamed accepted the deployed real-account experience on a
physical iPhone/Safari device, including keyboard, safe-area, home-indicator,
touch-target, sheet, launcher, locale, continuity, and New conversation
behavior. This closes the `AI-F06` owner/device gate; it does not turn Chromium
emulation into Safari automation. `AI-F04` remains a P3 test-precision item for
scroll geometry and unread state. See [STATUS.md](STATUS.md) for the acceptance
record.

See [AGENT-RUNTIME.md](AGENT-RUNTIME.md), [EVALS.md](EVALS.md), and
[DECISIONS.md](DECISIONS.md).
