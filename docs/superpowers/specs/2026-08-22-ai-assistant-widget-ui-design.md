# AI assistant widget — Home + light chat redesign

**Date:** 2026-08-22
**Status:** Draft for Mohamed's approval
**Design canvas:** https://claude.ai/code/artifact/daae8ff4-0633-422c-befd-f9b80abf029b

## Goal

Make the storefront chat widget read as a help center rather than a generic
messenger, in the style of OpenAI's help-page bot: a Home screen that greets
the customer and offers entry points, then the existing chat as a second view.
Visual direction: light warm surface inside the dark store, dark brand hero,
gold reserved for primary actions.

## Decisions already made

| Decision   | Choice                                                                 |
| ---------- | ---------------------------------------------------------------------- |
| Scope      | Home + Chat. No Help/FAQ tab or article search in this iteration.      |
| Theme      | Light surface (`#fbf8f2`) with gold accents; hero stays brand dark.    |
| Navigation | No tab bar. Home → Chat with a back arrow. One conversation per user.  |
| Runtime    | `resources/js/hooks/use-chat.ts` is **not modified**. UI-only change.  |

## Non-goals

- No backend or API changes. No new endpoints, no FAQ content source.
- No change to conversation lifecycle, streaming, polling, retry, or restart
  logic. All of that stays in `use-chat.ts` and its existing tests.
- No change to the launcher's behaviour (position, unread badge, toggle).
- No remediation of the Phase 2 drift findings recorded in
  `docs/ai-assistant/STATUS.md`; those need separate owner approval.

## Architecture

`ChatWidget` gains a single piece of UI state: `view: 'home' | 'chat'`.

```
ChatWidget (chat-widget.tsx)
├── view state, open/close, focus, mobile dialog  (existing + view)
├── ChatHome (NEW chat-home.tsx)          view === 'home'
│   ├── Hero: brand mark, close, greeting
│   ├── ContinueCard   (only when messages contain a customer message)
│   ├── StartCard      ("Send us a message" → view = 'chat')
│   └── TopicGrid      (4 topics → sendMessage(topic); view = 'chat')
└── Chat view                              view === 'chat'
    ├── ChatHeader (back arrow + avatar + title + restart + close)
    ├── error banner (existing)
    ├── ChatMessageList (restyled bubbles, pill quick replies)
    └── ChatComposer (restyled) + AI disclaimer line
```

### View rules

- Opening the widget always lands on **Home**. (Simple, predictable, and
  matches the reference bot. The Continue card makes returning cheap.)
- `Home → Chat`: "Start a conversation" button, the Continue card, or any
  topic card. Topic cards call the existing `sendMessage(text)` then switch
  the view; no new API.
- `Chat → Home`: header back arrow. Escape still closes the whole widget
  (existing behaviour, unchanged).
- Closing the widget resets `view` to `home` on the next open.
- `view` is component state only — not persisted across navigation. The
  conversation itself is already persisted by the hook.

### Theming

A scoped token layer on the dialog root, so nothing outside the widget
changes and the existing `--arabut-*` store tokens stay untouched:

```css
.chat-widget-dialog {
    --chat-surface: #fbf8f2;      /* panel body */
    --chat-card: #ffffff;         /* cards, assistant bubbles, header, composer */
    --chat-tint: #f3ead6;         /* avatar disc, date pill */
    --chat-ink: #1a1610;
    --chat-muted: #6f6354;
    --chat-faint: #7a6c58;
    --chat-line: rgb(212 168 67 / 22%);
    --chat-line-strong: rgb(212 168 67 / 35%);
    --chat-accent: var(--arabut-gold);        /* #d4a843 primary buttons */
    --chat-accent-ink: #7d5f14;               /* gold text on light */
    --chat-hero: var(--arabut-navy);          /* #0d0b08 hero + customer bubble */
    --chat-hero-ink: var(--arabut-ink);
    --chat-danger: #b42318;                   /* light-surface readable red */
    --chat-success: #22a06b;
}
```

Chat components switch from `--arabut-*` to `--chat-*` classes. The
launcher keeps its current dark glass style (it sits on the dark store page).

### Component changes

| File                                   | Change                                                                                                                                                          |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `components/chat/chat-widget.tsx`      | Add `view` state; render `ChatHome` or chat stack; reset on close; pass `onBack`. Dialog root gets light tokens.                                                 |
| `components/chat/chat-home.tsx` (new)  | Presentational. Props: `locale`, `hasConversation`, `lastMessage` (preview text + time), `disabled`, `onStart`, `onContinue`, `onSelectTopic`, `onClose`, `closeButtonRef`. |
| `components/chat/chat-header.tsx`      | Add `onBack` + back button (44px). Light styling. Avatar disc with online dot. Restart keeps its tooltip behaviour.                                              |
| `components/chat/chat-message-list.tsx`| Restyle: assistant = white card `16/16/16/4` radius; customer = dark `--chat-hero` bubble; date pill "Today"; quick replies become gold-outline pills. Scroll/stream/retry logic unchanged. |
| `components/chat/chat-composer.tsx`    | Light styling; send button gold square; disclaimer line below when `assistantMode === 'agent'` (prop `showDisclaimer`).                                         |
| `components/chat/typing-indicator.tsx` | Recolour dots to `--chat-accent`.                                                                                                                               |
| `resources/css/app.css`                | Add the `--chat-*` token block and keep `chat-bubble-enter` / `chat-stream-caret` (caret colour → `--chat-accent-ink`).                                          |

Topic labels and suggestion chips reuse the existing `SUGGESTIONS` constant
(moved to `lib/chat-topics.ts` so Home and the list share it).

### Copy (both locales)

| Key              | العربية                                   | English                                   |
| ---------------- | ----------------------------------------- | ----------------------------------------- |
| greeting         | أهلًا بك                                  | Hi there                                  |
| subgreeting      | كيف نقدر نساعدك اليوم؟                    | How can we help you today?                |
| start.title      | أرسل لنا رسالة                            | Send us a message                         |
| start.subtitle   | عادة نرد فورًا                            | Usually replies instantly                 |
| start.cta        | ابدأ محادثة                               | Start a conversation                      |
| continue.title   | متابعة المحادثة                           | Continue your conversation                |
| topics.heading   | مواضيع شائعة                              | Popular topics                            |
| back             | رجوع                                      | Back                                      |
| disclaimer       | مساعد ذكي — قد يخطئ، تحقق من المعلومات المهمة | AI assistant — may make mistakes. Verify important info. |

The disclaimer only renders when the server reports `assistantMode: 'agent'`.
In the current production demo mode it is hidden.

## Motion system

Animation is a first-class requirement. Everything below is CSS-only
(transitions + keyframes, no animation library) and every rule is wrapped in
`@media (prefers-reduced-motion: no-preference)`; with reduced motion, states
switch instantly and the existing behaviour/tests hold.

Shared tokens (added to the `--chat-*` block):

```css
--chat-ease-out: cubic-bezier(0.16, 1, 0.3, 1);   /* entrances */
--chat-ease-in:  cubic-bezier(0.7, 0, 0.84, 0);   /* exits */
--chat-ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1); /* taps, badges */
--chat-dur-fast: 150ms; --chat-dur-base: 240ms; --chat-dur-slow: 360ms;
```

| Moment | Animation |
| --- | --- |
| Panel open / close | Keep existing scale+translate fade (280ms out / 180ms in). Mobile sheet slides up from `translateY(24px)`. |
| Home hero | Greeting and sub-greeting fade+rise 12px, staggered 60ms; brand mark scales in from 0.9. |
| Home cards | Continue, Start, topic cards fade+rise with 50ms stagger (`animation-delay` via `--i` custom property). Hover: lift `-2px` + shadow deepen (base dur). Press: scale 0.98 (spring). |
| Home ↔ Chat view switch | Cross-fade + horizontal slide 16px in the reading direction (RTL-aware via `dir`: Home exits toward start, Chat enters from end; back reverses). Old view unmounts after `--chat-dur-base`. Implemented with a `data-view-state="entering|exiting"` attribute, same pattern as the panel's `isVisible` timing. |
| Back arrow | On hover, nudges 2px toward the Home direction. |
| Assistant bubble | Keep `chat-bubble-enter` (fade + rise + scale 0.96→1). |
| Customer bubble | Rise 8px + fade on send; `sending` state pulses opacity 0.7→1 until confirmed. |
| Streaming | Keep caret blink; on `response.completed` the caret fades out (fast) instead of vanishing. |
| Typing indicator | Three dots with offset bounce (existing 900ms); entrance fade. |
| Quick-reply pills | Stagger in 40ms; hover fills tint `--chat-tint`; press spring scale. On selection the chosen pill briefly fills gold before the row fades out. |
| Composer | Focus ring grows from 1px to 2px with colour transition; send button appears/disables with scale 0.9→1 + opacity when text becomes non-empty. |
| Send button press | Spring scale 0.92 → 1; icon nudges up 2px. |
| "New messages" pill | Slide up + fade (exists); add fade-out on click. |
| Error banner | Slide down from -8px + fade; dismiss fades out. |
| Launcher | Keep icon cross-fade/rotate; on open add a soft gold ring pulse once (`box-shadow` 0→12px transparent, 600ms). Unread badge pops with spring. |
| Date pill / status text | Fade only (no movement). |

Rules: entrances use `--chat-ease-out`, exits `--chat-ease-in`, taps the
spring; never animate `width`/`height` of the scroll container (reflow); all
keyframes animate only `opacity` and `transform`; `will-change` is not used.
Total motion per interaction stays under 400ms so the widget feels quick.

Testing: reduced-motion paths keep the existing timing tests green. Add one
test that the view switch unmounts the exiting view after the base duration
(fake timers), mirroring `chat-widget.test.tsx`'s close-transition test.

## Accessibility and RTL (must hold)

- All controls ≥ 44px; visible focus rings (`--arabut-focus` remains valid
  on light: gold on off-white ≥ 3:1 against `#fbf8f2`).
- Text contrast: `--chat-ink` on `--chat-surface` ≈ 15:1; `--chat-muted`
  ≈ 5.6:1; `--chat-faint` ≈ 4.7:1 for ≥ 11px secondary text.
- Arabic RTL: Home uses `dir` from locale; bubbles keep the existing
  physical left/right + `dir="auto"` rule.
- Mobile: Home sheet header carries the close chevron; initial focus moves to
  it (existing behaviour via `closeButtonRef`). Back arrow is not shown on
  Home.
- Reduced motion: every animation in the Motion system is disabled; states switch instantly.

## Error handling

Unchanged. The error banner still renders above the message list in the chat
view. If an error is present while on Home (e.g. restart failed), switching
to chat shows it; Home itself shows nothing extra.

## Testing

- New `__tests__/chat/chat-home.test.tsx`: renders greeting per locale; shows
  Continue card only when a customer message exists; topic click calls
  `onSelectTopic` with the label; Start calls `onStart`; all controls have
  accessible names; RTL `dir` for Arabic.
- `chat-widget.test.tsx`: opening lands on Home; Start switches to chat;
  back returns Home; close then reopen lands on Home; Escape still closes.
- Existing chat tests (direction, grouping, scroll, rapid send, typing,
  demo reply lifecycle, navigation persistence) must pass unchanged except
  where they assert on a colour class — update selectors, not behaviour.
- `npm run ci:check` (vitest, eslint, prettier, tsc, build) green.
- Manual: Arabic + English at 390px and 1440px in the browser before PR.

## Implementation notes

- Implementation is delegated to Antigravity (`/agy-delegate`) per
  Mohamed's request; the diff is reviewed here before landing.
- Branch: `claude/ai-assistant-ui-frontend-3f05a9` (from `main`). The
  unpushed docs branch `hotfix/admin-plan` is independent.
- Do not commit `resources/js/routes/**` or `.superpowers/**`.
