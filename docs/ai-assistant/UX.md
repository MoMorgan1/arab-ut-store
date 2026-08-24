# Customer experience

**Lifecycle:** Phases 1-3 and Support Handoff & Ticketing implemented and accepted
**Verified:** 2026-08-24

## Conversation behavior and Home view

The launcher opens on a Home view (brand hero, greeting, a Continue card when
the conversation already has a customer message, a Send-us-a-message call to
action, four topic cards, and a "Previous conversations" list for authenticated customers).

### Previous Conversations List (Home View)
- Visible only for authenticated customers with past threads (guests never see this section).
- Up to 10 rows headed by "محادثاتك السابقة" / "Previous conversations" with a hairline rule.
- Each row presents subject preview with ellipsis, 11.5px muted relative date, an optional 10.5px gold ticket-number chip (`TKT-XXXXXX`), and a trailing chevron.
- Tapping a row opens that thread in read-only inspection mode with a clear control to return or start a fresh active conversation.

## Support Handoff & Ticketing UX

When a customer needs human assistance, the chat UI transitions smoothly:

1. **Pinned Ticket Banner (Top of Thread):**
   - **`requested` state:** Gold tint band (`#f3ead6`) with a 1px `rgb(212 168 67 / 30%)` bottom border, 26px circular white icon chip with clock glyph, bold 13.5px title "طلبك وصل للفريق" / "Your request reached the team", and 12px muted second line with ticket number (`TKT-XXXXXX`).
   - **`active` state:** Gold band with responder's initial in a gold avatar chip, title naming responder, e.g. "محمد من الفريق يرد عليك" / "Mohamed from the team is replying", and ticket number beneath.
   - **`resolved` state:** Clean white band with green check chip, title "تم حل التذكرة" / "Ticket resolved", ticket number beneath, and a 44px min-touch-target "Still need help?" / "تحتاج مساعدة أكثر؟" pill button that reopens a support ticket on the same conversation.
2. **Paused Thread Pill:**
   - Centered inside the thread: "نواف متوقف مؤقتًا — الفريق يتابع محادثتك" / "Nawaf is paused — the team is following your chat".
3. **Staff Bubbles:**
   - Positioned on the assistant side (`items-start`), visually distinct from Nawaf: crisp white background, 1.5px solid `#d4a843` gold border, soft gold shadow (`rgba(212,168,67,0.15)`), and a dedicated header row with gold circular initial avatar + `:name · فريق عرب التيميت` / `:name · Arab Ultimate Team`.
   - Nawaf's AI bubbles remain flat cream with hairline borders.

## Copy Rule (Strict Invariant)

No banner, notification, or system string may promise a response time. Words such as "soon", "shortly", "within", "قريبًا", and "خلال" are strictly prohibited across all locales.

## Direction and accessibility

- Customer bubbles are physically right and assistant/staff bubbles physically left in Arabic RTL and English LTR; bubbles use `dir="auto"` for mixed-language text.
- The composer has a persistent accessible name, 16px mobile text, a 44px minimum height, and grows up to 120px.
- Launcher, close, back, send, restart, retry, topic/quick-reply, older-message, dismiss, reopen, and scroll controls have 44px minimum touch dimensions.
- Full support for reduced motion, keyboard focus rings, and screen readers.
