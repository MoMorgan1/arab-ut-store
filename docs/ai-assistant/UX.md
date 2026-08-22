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
a mute toggle persisted in `localStorage` (`arabut-chat-sound`). While the mobile
sheet is open it tracks `window.visualViewport`, so an open software keyboard
shrinks the sheet instead of pushing its header off-screen.

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
