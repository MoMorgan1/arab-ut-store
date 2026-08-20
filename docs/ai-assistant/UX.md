# Customer experience

**Lifecycle:** Implemented; physical iPhone acceptance pending
**Verified:** 2026-08-20

## Conversation behavior

The launcher initializes chat lazily. One owner has one open conversation.
Hourly maintenance closes it after 24 hours without a message. A later open
request reuses only an inactivity-closed conversation made within seven days.
New conversation calls `POST /chat/conversations/restart`: it closes the old
open thread and returns a new public ID with onboarding. Explicitly restarted
threads never reopen.

The widget preserves the active public ID across Inertia navigation and resolves
it after refresh. Login claims matching guest history transactionally. Closed
history has no customer-facing picker in this release.

## Account and mobile placement

`ChatRootLayout` marks the account surface so `chat-widget-root--account` uses
a bottom offset of the account navigation height plus
`env(safe-area-inset-bottom)` and appears above `.account-mobile-bottom-nav`.
The open mobile chat is a fixed full-screen dialog; closing restores focus to
the launcher. At `sm` and above it becomes a bottom-right panel.

The Chromium fixture creates a synthetic local user, never a production user.
It checks Arabic and English account pages, 390px safe-area geometry, 44px
controls, layering, keyboard focus, Escape/focus restoration, restart, overflow,
and runtime console errors.

## Direction and accessibility

- Customer bubbles are physically right and assistant bubbles physically left
  in Arabic RTL and English LTR; bubbles use `dir="auto"` for mixed-language text.
- The composer has a persistent accessible name, 16px mobile text, a 44px
  minimum height, and grows up to 120px.
- Launcher, close, send, restart, retry, suggestion, older-message, dismiss,
  and scroll controls have 44px minimum touch dimensions. Dialog/live
  semantics, visible focus, and reduced-motion classes are present.

`AI-F06` is narrowed, not closed: Chromium exercises emulated safe-area layout,
but Mohamed must accept real iPhone/Safari keyboard and home-indicator behavior.
`AI-F04` remains a P3 test-precision item for scroll geometry and unread state.
See [STATUS.md](STATUS.md) for the acceptance list.
