# Customer experience

**Lifecycle:** Implemented and owner accepted
**Verified:** 2026-08-21

## Conversation behavior

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

## Account and mobile placement

`ChatRootLayout` marks the account surface so `chat-widget-root--account` uses
a bottom offset of the account navigation height plus
`env(safe-area-inset-bottom)` and appears above `.account-mobile-bottom-nav`.
The open mobile chat is a fixed full-screen dialog; closing restores focus to
the launcher. On storefront/auth surfaces it becomes a bottom-right panel at
the `sm` breakpoint. On account surfaces it remains full-screen through
47.99rem and anchors only at 48rem.

The Chromium fixture creates a synthetic local user, never a production user.
Within one authenticated scenario it checks Arabic and English at 320px and
390px as full modal dialogs, then at 768px and 1440px as anchored nonmodal
panels. It covers nonzero safe-area geometry and reset, 44px controls, computed
layer/position/size, hit testing, keyboard focus, Escape/focus restoration,
outside-panel actionability, reduced motion, overflow, and runtime request/
console errors. It does not invoke restart or prove replacement behavior;
backend/component tests cover that behavior.

## Direction and accessibility

- Customer bubbles are physically right and assistant bubbles physically left
  in Arabic RTL and English LTR; bubbles use `dir="auto"` for mixed-language text.
- The composer has a persistent accessible name, 16px mobile text, a 44px
  minimum height, and grows up to 120px.
- Launcher, close, send, restart, retry, suggestion, older-message, dismiss,
  and scroll controls have 44px minimum touch dimensions. Dialog/live
  semantics, visible focus, and reduced-motion classes are present.

`AI-F06` is closed by Mohamed's acceptance of the deployed real-account and
iPhone/Safari checklist on 2026-08-21. `AI-F04` remains a P3 test-precision
item for scroll geometry and unread state. See [STATUS.md](STATUS.md) for the
acceptance record.
