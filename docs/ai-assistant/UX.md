# Customer experience

**Lifecycle:** Local implementation verified; deployed owner acceptance pending

**Verified:** 2026-08-20

## Account placement and responsive behavior

- `ChatRootLayout` identifies `account/*` Inertia pages and passes the account surface to `ChatWidget`. On mobile, `.chat-widget-root--account` is placed above the account navigation with a safe-area-aware `88px` bottom offset and a `z-index` of 70. The account navigation uses `z-index` 60.
- The open mobile sheet is a fixed full-screen dialog at `z-index` 70. The desktop panel begins at the `md` breakpoint and is physically right-anchored, 420px wide, and at most 650px/85vh high.
- The launcher remains physically bottom-right in Arabic RTL and English LTR. Customer bubbles stay physically right and assistant/typing bubbles stay physically left; bubble text uses automatic direction for mixed language.
- The mobile composer has a safe-area-aware bottom padding, a 16px text size, a 44px minimum height, and a 120px auto-growth ceiling.

## Conversation controls

Opening is lazy. The localized **New conversation / محادثة جديدة** control is disabled while a restart or send is in progress, then calls `POST /chat/conversations/restart`. It replaces the current visible conversation only after the replacement succeeds. Closing does not restart a conversation; it returns focus to the launcher. `Escape` also closes the widget.

The mobile sheet is modal: the page outside it is inert, focus is contained, and focus returns to the launcher on close. Desktop remains a non-modal panel. The composer has an explicit localized accessible name. Close, restart, send, retry, load-older, and scroll controls use 44px minimum targets. Motion-related transitions respect reduced-motion preferences.

## Local browser fixture

`tests/Browser/storefront-smoke.spec.ts` uses the real local registration flow and a disposable local database. At 390px it verifies that the authenticated account launcher is above the mobile navigation, the sheet stacks above that navigation, and closing restores launcher focus. The same fixture visits `/en/my-account` and checks English `lang`/`dir` plus launcher geometry. It also exercises Arabic and English account chat behavior at 320px, 390px, 768px, and 1440px.

This is local Chromium evidence, not production-browser evidence and not Safari/iPhone proof.

## Mohamed manual acceptance gate

Acceptance remains pending until Mohamed confirms the deployed release:

- account launcher is visible above navigation; the full sheet covers it and closes back to the focused launcher;
- Arabic, English, and mixed-language messages preserve their intended direction and physical alignment;
- New conversation creates a new public ID; refresh, navigation, and login preserve the current allowed conversation;
- iPhone zoom, safe area, keyboard behavior, touch targets, reduced motion, overflow, focus, and browser-console behavior are acceptable;
- an explicit old conversation does not automatically reopen.
