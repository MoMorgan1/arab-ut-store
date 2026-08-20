# Customer experience

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Layout and direction

- Customer messages are physically right-aligned and assistant messages are
  physically left-aligned in both Arabic RTL and English LTR pages. The message
  row fixes physical geometry with `dir="ltr"`; each bubble uses `dir="auto"`
  so mixed-language content selects its own text direction.
- The typing indicator remains physically left.
- On mobile, the open chat is a full-screen fixed sheet. From the `sm`
  breakpoint, it becomes a bottom-right anchored panel measuring 420px wide and
  up to 650px or 85vh high.
- The composer textarea is 16px on mobile to avoid iOS input zoom, becomes 14px
  at the `lg` breakpoint, has a 44px minimum height, and auto-grows to 120px.

## Arabic and English copy intent

Arabic is the default storefront language and uses the familiar Arab UT voice.
English communicates the same actions and limitations rather than introducing
a different product promise. The onboarding text invites a message and explains
that sign-in can support later order tracking. The current demo reply identifies
the experience as a foundation demo; it must not be represented as a smart or
human answer.

## Interaction and accessibility behavior

- Opening is lazy: no conversation request is made until the customer opens the
  widget.
- `Escape` closes the open panel. Closing restores focus to the launcher.
  Sending returns focus to the composer.
- The launcher, close button, send button, dialog, message log, and status
  announcements expose semantics. The known accessible-name and secondary
  touch-target gaps remain recorded as `AI-F07`.
- Panel, launcher, typing, and scroll behavior respect reduced-motion signals
  where implemented. Smooth scrolling becomes immediate when reduced motion is
  requested.
- Older messages prepend while preserving the scroll anchor. Customer sends are
  optimistic, FIFO, and retry with the same client message ID.

## Mohamed manual device checklist

Owner acceptance is `Pending Mohamed manual test`. Check Arabic RTL and English
LTR at 320px, 390px, 768px, and 1440px, including:

- launcher and panel open/close, visible focus, keyboard order, and `Escape`;
- physical customer-right and assistant/typing-left placement, including a
  mixed-language outgoing message;
- composer focus, iPhone keyboard zoom, home-indicator/safe-area clearance, and
  44px touch targets;
- send, retry, older-history loading, scroll preservation, unread state,
  navigation persistence, and full refresh continuity;
- reduced motion, no horizontal overflow, and no browser console errors.
