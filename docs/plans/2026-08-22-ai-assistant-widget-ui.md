# AI Assistant Widget — Home + Light Chat + Motion Implementation Plan

**Status:** Shipped (2026-08-22)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the storefront chat widget into a help-center style assistant: a Home screen (greeting, continue/start cards, topic grid) that leads into the existing chat view, restyled on a light warm surface with gold accents and a CSS-only motion system.

**Architecture:** `ChatWidget` gains a `view: 'home' | 'chat'` UI state and renders a new presentational `ChatHome` or the existing header/list/composer stack. All colours move to a scoped `--chat-*` token block on `.chat-widget-dialog`; all motion lives in `resources/css/app.css` under `@media (prefers-reduced-motion: no-preference)`. `resources/js/hooks/use-chat.ts` is never modified.

**Tech Stack:** React 19 + TypeScript, Tailwind v4 (arbitrary-value classes over CSS variables), Vitest + Testing Library (jsdom), lucide-react icons, Laravel/Inertia host.

**Spec:** `docs/decisions/2026-08-22-ai-assistant-widget-ui-design.md`

**Conventions for every task**
- Run a single test file: `npx vitest run resources/js/__tests__/chat/<file> --reporter=dot`
- Lint/format before each commit: `npx eslint <files> && npx prettier --write <files>`
- Never commit `resources/js/routes/**`, `resources/js/actions/**`, `resources/js/wayfinder/**`, `.superpowers/**`, `.env`.
- Commit messages end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Tests run under jsdom, which has no `prefers-reduced-motion` media query; `window.matchMedia` is stubbed in `resources/js/test/setup.ts`. Animation classes are asserted by class name, not by computed style.

---

## Deliberate deviations from the spec

- Streaming caret fade-out on `response.completed`: the completed message replaces the streaming bubble (the caret element unmounts), so a fade would need state in `use-chat.ts`. Skipped; the bubble's `chat-bubble-enter` covers the transition.
- "New messages" pill fade-out on click: the pill unmounts immediately on click in `chat-message-list.tsx`; adding exit state is not worth the logic churn. Skipped.

## File map

| Path | Responsibility |
| --- | --- |
| `resources/css/app.css` | Add `--chat-*` tokens on `.chat-widget-dialog`, the motion keyframes/classes, and update caret colour. |
| `resources/js/lib/chat-topics.ts` (new) | Single source of the four topic labels per locale. |
| `resources/js/components/chat/chat-home.tsx` (new) | Presentational Home view. |
| `resources/js/components/chat/chat-widget.tsx` | `view` state, `initialView` prop, view transition, renders Home or chat stack. |
| `resources/js/components/chat/chat-header.tsx` | Back button, light styling. |
| `resources/js/components/chat/chat-message-list.tsx` | Light bubbles, date pill, pill quick replies, uses `chat-topics`. |
| `resources/js/components/chat/chat-composer.tsx` | Light styling, animated send button, disclaimer line. |
| `resources/js/components/chat/typing-indicator.tsx` | Light styling. |
| `resources/js/components/chat/chat-launcher.tsx` | Gold ring pulse on open. |
| `resources/js/__tests__/chat/chat-home.test.tsx` (new) | Home behaviour tests. |
| `resources/js/__tests__/chat/chat-topics.test.ts` (new) | Topic constant test. |
| `resources/js/__tests__/chat/chat-widget.test.tsx` | View-state tests; existing tests get `initialView="chat"`. |
| Other `__tests__/chat/*.tsx` + `__tests__/chat/chat-root-layout.test.tsx` | Add `initialView="chat"` where the test opens the launcher and expects the composer. |

---

### Task 1: Light tokens + motion CSS

**Files:**
- Modify: `resources/css/app.css` (append after the existing `.chat-stream-caret` rule, around line 11205)
- Test: `resources/js/__tests__/chat/chat-widget.test.tsx`

- [ ] **Step 1: Write the failing test**

Append inside the `describe('ChatWidget Component', …)` block of `resources/js/__tests__/chat/chat-widget.test.tsx`:

```tsx
    it('scopes light-surface chat tokens and motion to the dialog', () => {
        const tokens = declarationsFor(appCss, '.chat-widget-dialog');

        expect(tokens).toContain('--chat-surface: #fbf8f2');
        expect(tokens).toContain('--chat-hero: var(--arabut-navy)');
        expect(tokens).toContain('--chat-ease-out: cubic-bezier(0.16, 1, 0.3, 1)');

        const motionStart = appCss.indexOf('.chat-view-enter {');
        const reducedMotionStart = appCss.lastIndexOf(
            '@media (prefers-reduced-motion: no-preference)',
            motionStart,
        );

        expect(motionStart).toBeGreaterThan(-1);
        expect(reducedMotionStart).toBeGreaterThan(-1);
    });
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/__tests__/chat/chat-widget.test.tsx -t "scopes light-surface" --reporter=dot`
Expected: FAIL with `Missing CSS selector: .chat-widget-dialog`

- [ ] **Step 3: Append the CSS**

Append to the end of `resources/css/app.css`:

```css
/* ---------------------------------------------------------------------
   Chat widget: light help-center surface + motion system.
   Tokens are scoped to the dialog so the dark store tokens stay intact.
   --------------------------------------------------------------------- */
.chat-widget-dialog {
    --chat-surface: #fbf8f2;
    --chat-card: #ffffff;
    --chat-tint: #f3ead6;
    --chat-ink: #1a1610;
    --chat-muted: #6f6354;
    --chat-faint: #7a6c58;
    --chat-line: rgb(212 168 67 / 22%);
    --chat-line-strong: rgb(212 168 67 / 35%);
    --chat-accent: var(--arabut-gold);
    --chat-accent-ink: #7d5f14;
    --chat-hero: var(--arabut-navy);
    --chat-hero-ink: var(--arabut-ink);
    --chat-danger: #b42318;
    --chat-success: #22a06b;
    --chat-ease-out: cubic-bezier(0.16, 1, 0.3, 1);
    --chat-ease-in: cubic-bezier(0.7, 0, 0.84, 0);
    --chat-ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
    --chat-dur-fast: 150ms;
    --chat-dur-base: 240ms;
    --chat-dur-slow: 360ms;
}

.chat-widget-dialog .chat-stream-caret {
    background: var(--chat-accent-ink);
}

/* Every rule below animates only opacity/transform and is disabled under
   reduced motion: states then switch instantly. */
@media (prefers-reduced-motion: no-preference) {
    @keyframes chat-rise-in {
        from {
            opacity: 0;
            transform: translateY(12px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes chat-pop-in {
        from {
            opacity: 0;
            transform: scale(0.9);
        }

        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    @keyframes chat-drop-in {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes chat-ring-pulse {
        from {
            box-shadow: 0 0 0 0 rgb(212 168 67 / 55%);
        }

        to {
            box-shadow: 0 0 0 12px rgb(212 168 67 / 0%);
        }
    }

    /* Staggered entrances: set --i on each child. */
    .chat-stagger-in {
        animation: chat-rise-in var(--chat-dur-slow) var(--chat-ease-out)
            both;
        animation-delay: calc(var(--i, 0) * 50ms);
    }

    .chat-pop-in {
        animation: chat-pop-in var(--chat-dur-base) var(--chat-ease-spring)
            both;
    }

    .chat-drop-in {
        animation: chat-drop-in var(--chat-dur-base) var(--chat-ease-out)
            both;
    }

    /* Home <-> Chat switch. The slide direction follows the reading
       direction: [dir=rtl] flips the sign. */
    .chat-view-enter {
        animation: chat-view-enter var(--chat-dur-base) var(--chat-ease-out)
            both;
    }

    .chat-view-exit {
        animation: chat-view-exit var(--chat-dur-base) var(--chat-ease-in)
            both;
        pointer-events: none;
    }

    @keyframes chat-view-enter {
        from {
            opacity: 0;
            transform: translateX(var(--chat-slide, 16px));
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes chat-view-exit {
        from {
            opacity: 1;
            transform: translateX(0);
        }

        to {
            opacity: 0;
            transform: translateX(calc(var(--chat-slide, 16px) * -1));
        }
    }

    .chat-widget-dialog[dir='rtl'] {
        --chat-slide: -16px;
    }

    .chat-widget-dialog[data-view-direction='back'] {
        --chat-slide: -16px;
    }

    .chat-widget-dialog[dir='rtl'][data-view-direction='back'] {
        --chat-slide: 16px;
    }

    /* Interactive cards and pills. */
    .chat-lift {
        transition:
            transform var(--chat-dur-base) var(--chat-ease-out),
            box-shadow var(--chat-dur-base) var(--chat-ease-out),
            background-color var(--chat-dur-fast) ease,
            border-color var(--chat-dur-fast) ease;
    }

    .chat-lift:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 24px rgb(13 11 8 / 10%);
    }

    .chat-lift:active {
        transform: scale(0.98);
        transition-timing-function: var(--chat-ease-spring);
    }

    .chat-press {
        transition: transform var(--chat-dur-fast) var(--chat-ease-spring);
    }

    .chat-press:active {
        transform: scale(0.92);
    }

    .chat-launcher-open {
        animation: chat-ring-pulse 600ms var(--chat-ease-out) 1;
    }

    .chat-sending {
        animation: chat-sending-pulse 1.2s ease-in-out infinite;
    }

    @keyframes chat-sending-pulse {
        0%,
        100% {
            opacity: 0.7;
        }

        50% {
            opacity: 1;
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/__tests__/chat/chat-widget.test.tsx -t "scopes light-surface" --reporter=dot`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/css/app.css resources/js/__tests__/chat/chat-widget.test.tsx
git commit -m "feat(chat): add light chat tokens and motion system

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Shared topic constant

**Files:**
- Create: `resources/js/lib/chat-topics.ts`
- Create: `resources/js/__tests__/chat/chat-topics.test.ts`
- Modify: `resources/js/components/chat/chat-message-list.tsx:23-26,42-43`

- [ ] **Step 1: Write the failing test**

```ts
// resources/js/__tests__/chat/chat-topics.test.ts
import { describe, expect, it } from 'vitest';
import { chatTopicsFor } from '@/lib/chat-topics';

describe('chatTopicsFor', () => {
    it('returns four Arabic topics by default', () => {
        const topics = chatTopicsFor('ar');

        expect(topics.map((topic) => topic.label)).toEqual([
            'الأسعار',
            'الخدمات',
            'متابعة الطلب',
            'الدعم',
        ]);
        expect(topics.map((topic) => topic.id)).toEqual([
            'prices',
            'services',
            'track-order',
            'support',
        ]);
    });

    it('returns English topics for en and falls back to Arabic otherwise', () => {
        expect(chatTopicsFor('en')[2].label).toBe('Track Order');
        expect(chatTopicsFor('fr')[0].label).toBe('الأسعار');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/__tests__/chat/chat-topics.test.ts --reporter=dot`
Expected: FAIL — cannot resolve `@/lib/chat-topics`

- [ ] **Step 3: Create the module**

```ts
// resources/js/lib/chat-topics.ts
export type ChatTopicId = 'prices' | 'services' | 'track-order' | 'support';

export type ChatTopic = {
    id: ChatTopicId;
    label: string;
};

const TOPIC_IDS: ChatTopicId[] = ['prices', 'services', 'track-order', 'support'];

const LABELS: Record<'ar' | 'en', string[]> = {
    ar: ['الأسعار', 'الخدمات', 'متابعة الطلب', 'الدعم'],
    en: ['Prices', 'Services', 'Track Order', 'Support'],
};

export function chatTopicsFor(locale: string | undefined): ChatTopic[] {
    const labels = locale === 'en' ? LABELS.en : LABELS.ar;

    return TOPIC_IDS.map((id, index) => ({ id, label: labels[index] }));
}
```

- [ ] **Step 4: Use it in the message list**

In `resources/js/components/chat/chat-message-list.tsx` delete the `SUGGESTIONS` constant (lines 23–26) and replace line 43 (`const suggestions = isEn ? SUGGESTIONS.en : SUGGESTIONS.ar;`) with:

```tsx
    const suggestions = chatTopicsFor(locale).map((topic) => topic.label);
```

Add the import at the top:

```tsx
import { chatTopicsFor } from '@/lib/chat-topics';
```

- [ ] **Step 5: Run tests**

Run: `npx vitest run resources/js/__tests__/chat/chat-topics.test.ts resources/js/__tests__/chat/chat-widget.test.tsx --reporter=dot`
Expected: PASS (all)

- [ ] **Step 6: Commit**

```bash
git add resources/js/lib/chat-topics.ts resources/js/__tests__/chat/chat-topics.test.ts resources/js/components/chat/chat-message-list.tsx
git commit -m "refactor(chat): share topic labels between home and suggestions

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: ChatHome component

**Files:**
- Create: `resources/js/components/chat/chat-home.tsx`
- Create: `resources/js/__tests__/chat/chat-home.test.tsx`

- [ ] **Step 1: Write the failing tests**

```tsx
// resources/js/__tests__/chat/chat-home.test.tsx
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatHome } from '@/components/chat/chat-home';

function renderHome(
    overrides: Partial<React.ComponentProps<typeof ChatHome>> = {},
) {
    const props = {
        locale: 'ar',
        hasConversation: false,
        lastMessage: null,
        disabled: false,
        onStart: vi.fn(),
        onContinue: vi.fn(),
        onSelectTopic: vi.fn(),
        onClose: vi.fn(),
        ...overrides,
    };

    render(<ChatHome {...props} />);

    return props;
}

describe('ChatHome', () => {
    afterEach(() => {
        cleanup();
    });

    it('greets in Arabic with rtl direction by default', () => {
        renderHome();

        const greeting = screen.getByRole('heading', { name: 'أهلًا بك' });
        expect(greeting).toBeInTheDocument();
        expect(greeting.closest('[dir]')).toHaveAttribute('dir', 'rtl');
        expect(screen.getByText('كيف نقدر نساعدك اليوم؟')).toBeInTheDocument();
    });

    it('greets in English with ltr direction', () => {
        renderHome({ locale: 'en' });

        const greeting = screen.getByRole('heading', { name: 'Hi there' });
        expect(greeting.closest('[dir]')).toHaveAttribute('dir', 'ltr');
        expect(
            screen.getByRole('button', { name: 'Start a conversation' }),
        ).toBeInTheDocument();
    });

    it('hides the continue card without a conversation and shows it with one', () => {
        renderHome({ locale: 'en' });
        expect(
            screen.queryByRole('button', { name: /Continue your conversation/ }),
        ).not.toBeInTheDocument();

        cleanup();

        const props = renderHome({
            locale: 'en',
            hasConversation: true,
            lastMessage: {
                preview: 'Order #4821 is being prepared…',
                createdAt: '2026-08-22T10:42:00Z',
            },
        });

        const card = screen.getByRole('button', {
            name: /Continue your conversation/,
        });
        expect(card).toHaveTextContent('Order #4821 is being prepared…');
        fireEvent.click(card);
        expect(props.onContinue).toHaveBeenCalledTimes(1);
    });

    it('starts a conversation and selects topics by label', () => {
        const props = renderHome({ locale: 'en' });

        fireEvent.click(
            screen.getByRole('button', { name: 'Start a conversation' }),
        );
        expect(props.onStart).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Track Order' }));
        expect(props.onSelectTopic).toHaveBeenCalledWith('Track Order');
    });

    it('disables all actions when disabled', () => {
        renderHome({ locale: 'en', disabled: true, hasConversation: true });

        for (const name of [
            'Start a conversation',
            'Prices',
            /Continue your conversation/,
        ]) {
            expect(screen.getByRole('button', { name })).toBeDisabled();
        }
    });

    it('applies staggered entrance classes to cards', () => {
        renderHome({ locale: 'en' });

        const start = screen.getByRole('button', { name: 'Start a conversation' });
        const card = start.closest('.chat-stagger-in');
        expect(card).not.toBeNull();
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run resources/js/__tests__/chat/chat-home.test.tsx --reporter=dot`
Expected: FAIL — cannot resolve `@/components/chat/chat-home`

- [ ] **Step 3: Create the component**

```tsx
// resources/js/components/chat/chat-home.tsx
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Info,
    LayoutGrid,
    ListOrdered,
    Send,
    Sparkles,
    Truck,
    X,
} from 'lucide-react';
import type React from 'react';
import { chatTopicsFor, type ChatTopicId } from '@/lib/chat-topics';

export type ChatHomeLastMessage = {
    preview: string;
    createdAt: string;
};

export type ChatHomeProps = {
    locale?: string;
    hasConversation: boolean;
    lastMessage: ChatHomeLastMessage | null;
    disabled?: boolean;
    isMobileDialog?: boolean;
    closeButtonRef?: React.Ref<HTMLButtonElement>;
    onStart: () => void;
    onContinue: () => void;
    onSelectTopic: (label: string) => void;
    onClose: () => void;
};

const TOPIC_ICONS: Record<ChatTopicId, React.ComponentType<{ className?: string }>> = {
    prices: ListOrdered,
    services: LayoutGrid,
    'track-order': Truck,
    support: Info,
};

const CARD_BASE =
    'chat-lift rounded-2xl border border-[var(--chat-line)] bg-[var(--chat-card)] text-start text-[var(--chat-ink)] shadow-[0_6px_18px_rgb(13_11_8/0.06)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transform-none';

function relativeTime(iso: string, isEn: boolean): string {
    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 1) {
        return isEn ? 'Just now' : 'الآن';
    }

    if (minutes < 60) {
        return isEn ? `${minutes} min ago` : `قبل ${minutes} د`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return isEn ? `${hours} h ago` : `قبل ${hours} س`;
    }

    return new Date(iso).toLocaleDateString(isEn ? 'en-US' : 'ar-SA', {
        month: 'short',
        day: 'numeric',
    });
}

export const ChatHome: React.FC<ChatHomeProps> = ({
    locale = 'ar',
    hasConversation,
    lastMessage,
    disabled = false,
    isMobileDialog = false,
    closeButtonRef,
    onStart,
    onContinue,
    onSelectTopic,
    onClose,
}) => {
    const isEn = locale === 'en';
    const dir = isEn ? 'ltr' : 'rtl';
    const Chevron = isEn ? ChevronRight : ChevronLeft;
    const topics = chatTopicsFor(locale);

    const copy = {
        greeting: isEn ? 'Hi there' : 'أهلًا بك',
        subgreeting: isEn ? 'How can we help you today?' : 'كيف نقدر نساعدك اليوم؟',
        startTitle: isEn ? 'Send us a message' : 'أرسل لنا رسالة',
        startSubtitle: isEn ? 'Usually replies instantly' : 'عادة نرد فورًا',
        startCta: isEn ? 'Start a conversation' : 'ابدأ محادثة',
        continueTitle: isEn ? 'Continue your conversation' : 'متابعة المحادثة',
        topics: isEn ? 'Popular topics' : 'مواضيع شائعة',
        close: isEn ? 'Close chat' : 'إغلاق الشات',
    };

    let cardIndex = 0;

    return (
        <div
            dir={dir}
            className="flex h-full min-h-0 flex-col bg-[var(--chat-surface)] text-[var(--chat-ink)]"
        >
            {/* Hero */}
            <div className="flex flex-col gap-5 bg-[var(--chat-hero)] px-6 pt-6 pb-7 text-[var(--chat-hero-ink)]">
                <div className="flex items-center justify-between">
                    <div className="chat-pop-in flex items-center gap-2.5">
                        <span
                            aria-hidden="true"
                            className="flex h-9 w-9 items-center justify-center rounded-xl bg-[var(--chat-accent)] text-[15px] font-bold text-[var(--chat-hero)]"
                        >
                            AU
                        </span>
                        <span className="text-[13px] font-semibold tracking-wide text-[var(--arabut-muted)]">
                            Arab UT
                        </span>
                    </div>
                    <button
                        ref={closeButtonRef}
                        type="button"
                        onClick={onClose}
                        aria-label={copy.close}
                        className="chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-white/10 hover:text-[var(--chat-hero-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                    >
                        {isMobileDialog ? (
                            <ChevronDown aria-hidden="true" className="h-5 w-5" />
                        ) : (
                            <X aria-hidden="true" className="h-5 w-5" />
                        )}
                    </button>
                </div>
                <div className="flex flex-col gap-1.5">
                    <h2
                        className="chat-stagger-in text-[28px] leading-tight font-bold text-[#fbf8f2]"
                        style={{ ['--i' as string]: 0 }}
                    >
                        {copy.greeting}
                    </h2>
                    <p
                        className="chat-stagger-in text-[17px] leading-snug text-[var(--arabut-muted)]"
                        style={{ ['--i' as string]: 1 }}
                    >
                        {copy.subgreeting}
                    </p>
                </div>
            </div>

            {/* Body */}
            <div className="-mt-3.5 flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-4 pb-4">
                {hasConversation && (
                    <button
                        type="button"
                        onClick={onContinue}
                        disabled={disabled}
                        className={`chat-stagger-in ${CARD_BASE} flex items-center gap-3 px-4 py-3.5`}
                        style={{ ['--i' as string]: cardIndex++ }}
                    >
                        <span
                            aria-hidden="true"
                            className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-[var(--chat-tint)] text-[var(--chat-accent-ink)]"
                        >
                            <Sparkles className="h-5 w-5" />
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="flex items-baseline justify-between gap-2">
                                <span className="text-sm font-semibold">
                                    {copy.continueTitle}
                                </span>
                                {lastMessage && (
                                    <span className="text-xs text-[var(--chat-faint)]">
                                        {relativeTime(lastMessage.createdAt, isEn)}
                                    </span>
                                )}
                            </span>
                            {lastMessage && (
                                <span
                                    dir="auto"
                                    className="truncate text-[13px] text-[var(--chat-muted)]"
                                >
                                    {lastMessage.preview}
                                </span>
                            )}
                        </span>
                        <Chevron
                            aria-hidden="true"
                            className="h-5 w-5 flex-shrink-0 text-[var(--chat-faint)]"
                        />
                    </button>
                )}

                <div
                    className={`chat-stagger-in ${CARD_BASE} flex items-center justify-between gap-3 p-4 hover:translate-y-0 hover:shadow-[0_6px_18px_rgb(13_11_8/0.06)]`}
                    style={{ ['--i' as string]: cardIndex++ }}
                >
                    <div className="flex flex-col gap-0.5">
                        <span className="text-[15px] font-semibold">
                            {copy.startTitle}
                        </span>
                        <span className="text-[13px] text-[var(--chat-muted)]">
                            {copy.startSubtitle}
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={onStart}
                        disabled={disabled}
                        className="chat-press flex h-11 flex-shrink-0 items-center gap-2 rounded-xl bg-[var(--chat-accent)] px-4 text-sm font-bold text-[var(--chat-hero)] transition-opacity hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span>{copy.startCta}</span>
                        <Send aria-hidden="true" className="h-4 w-4 rtl:-scale-x-100" />
                    </button>
                </div>

                <div className="flex flex-col gap-2 px-1 pt-1">
                    <span className="text-xs font-semibold tracking-wide text-[var(--chat-faint)]">
                        {copy.topics}
                    </span>
                    <div className="grid grid-cols-2 gap-2">
                        {topics.map((topic) => {
                            const Icon = TOPIC_ICONS[topic.id];

                            return (
                                <button
                                    key={topic.id}
                                    type="button"
                                    onClick={() => onSelectTopic(topic.label)}
                                    disabled={disabled}
                                    className={`chat-stagger-in ${CARD_BASE} flex min-h-[52px] items-center gap-2.5 rounded-[14px] px-3.5 py-3 text-sm font-medium shadow-none`}
                                    style={{ ['--i' as string]: cardIndex++ }}
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="h-5 w-5 flex-shrink-0 text-[var(--chat-accent-ink)]"
                                    />
                                    <span>{topic.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
};
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/__tests__/chat/chat-home.test.tsx --reporter=dot`
Expected: PASS (6 tests)

- [ ] **Step 5: Lint, format, commit**

```bash
npx eslint resources/js/components/chat/chat-home.tsx resources/js/__tests__/chat/chat-home.test.tsx && npx prettier --write resources/js/components/chat/chat-home.tsx resources/js/__tests__/chat/chat-home.test.tsx
git add resources/js/components/chat/chat-home.tsx resources/js/__tests__/chat/chat-home.test.tsx
git commit -m "feat(chat): add help-center home view

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: View state in ChatWidget (+ `initialView` prop)

**Files:**
- Modify: `resources/js/components/chat/chat-widget.tsx`
- Modify: `resources/js/components/chat/chat-header.tsx` (add `onBack` prop only — styling comes in Task 5)
- Modify: `resources/js/__tests__/chat/chat-widget.test.tsx`
- Modify: every other test that opens the launcher and expects the composer: `agent-stream.test.tsx`, `chat-navigation-persistence.test.tsx`, `chat-rapid-send.test.tsx`, `chat-scroll-preservation.test.tsx`

- [ ] **Step 1: Add `initialView="chat"` to existing chat-behaviour tests**

In each of these files, every `<ChatWidget` JSX element that does not already set `initialView` gets `initialView="chat"`:

```bash
cd resources/js/__tests__/chat
sed -i 's/<ChatWidget enabled={true}/<ChatWidget initialView="chat" enabled={true}/g; s/<ChatWidget enabled /<ChatWidget initialView="chat" enabled /g' agent-stream.test.tsx chat-navigation-persistence.test.tsx chat-rapid-send.test.tsx chat-scroll-preservation.test.tsx chat-widget.test.tsx
grep -n "<ChatWidget" *.tsx | grep -v initialView
```

The final `grep` must print nothing except the `enabled={false}` render in `chat-widget.test.tsx` (which never opens). `chat-root-layout.test.tsx` only asserts the launcher exists and never opens the panel, so it needs no change.

- [ ] **Step 2: Write the failing view-state tests**

Append to `resources/js/__tests__/chat/chat-widget.test.tsx` inside the main `describe`:

```tsx
    describe('home view', () => {
        function mockConversationResponse(
            assistantMode: 'demo' | 'agent' = 'demo',
        ) {
            vi.mocked(fetch).mockResolvedValue({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        publicId: `conv-home-${assistantMode}`,
                        status: 'open',
                        locale: 'en',
                        subject: null,
                        lastMessageAt: null,
                        assistantMode,
                        messages: [],
                        hasMore: false,
                        oldestCursor: null,
                    },
                }),
            } as Response);
        }

        function mockEmptyConversation() {
            mockConversationResponse('demo');
        }

        it('lands on Home when opened and hides the composer', async () => {
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="ar" />);

            fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

            expect(
                await screen.findByRole('heading', { name: 'أهلًا بك' }),
            ).toBeInTheDocument();
            expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        });

        it('moves to chat on Start and back to Home on the back button', async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);

            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            fireEvent.click(
                await screen.findByRole('button', { name: 'Start a conversation' }),
            );

            expect(await screen.findByRole('textbox')).toBeInTheDocument();
            const dialog = screen.getByRole('dialog');
            expect(dialog).toHaveAttribute('data-view-direction', 'forward');

            fireEvent.click(screen.getByRole('button', { name: 'Back' }));
            expect(dialog).toHaveAttribute('data-view-direction', 'back');
            expect(
                await screen.findByRole('heading', { name: 'Hi there' }),
            ).toBeInTheDocument();

            await act(async () => {
                vi.advanceTimersByTime(300);
            });
            expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        });

        it('sends the topic and switches to chat when a topic is chosen', async () => {
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);

            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            fireEvent.click(await screen.findByRole('button', { name: 'Prices' }));

            expect(await screen.findByRole('textbox')).toBeInTheDocument();
            expect(screen.getByText('Prices')).toBeInTheDocument();
        });

        it('returns to Home after close and reopen', async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);

            const launcher = screen.getByRole('button', { name: /Open chat/i });
            fireEvent.click(launcher);
            fireEvent.click(
                await screen.findByRole('button', { name: 'Start a conversation' }),
            );
            await screen.findByRole('textbox');

            fireEvent.keyDown(window, { key: 'Escape' });
            await act(async () => {
                vi.advanceTimersByTime(300);
            });
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

            expect(
                await screen.findByRole('heading', { name: 'Hi there' }),
            ).toBeInTheDocument();
        });

        it('honours initialView="chat"', async () => {
            mockEmptyConversation();
            render(<ChatWidget initialView="chat" enabled={true} locale="en" />);

            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

            expect(await screen.findByRole('textbox')).toBeInTheDocument();
            expect(
                screen.queryByRole('heading', { name: 'Hi there' }),
            ).not.toBeInTheDocument();
        });
    });
```

This mock shape (`{ ok, status, json }` cast to `Response`) matches the file's existing mocks (see the `treats the %ipx account sheet` test). The hook only reads `data`; `assistantMode` drives the disclaimer in Task 7.

- [ ] **Step 3: Run to verify failure**

Run: `npx vitest run resources/js/__tests__/chat/chat-widget.test.tsx -t "home view" --reporter=dot`
Expected: FAIL — heading `أهلًا بك` not found

- [ ] **Step 4: Add `onBack` to ChatHeader**

In `resources/js/components/chat/chat-header.tsx`:

Add to `ChatHeaderProps`:

```tsx
    onBack: () => void;
```

Destructure `onBack` in the component, add `const backLabel = isEn ? 'Back' : 'رجوع';`, and insert as the FIRST child of the left `<div className="flex items-center gap-3">`:

```tsx
                <button
                    type="button"
                    onClick={onBack}
                    aria-label={backLabel}
                    className="chat-back-button flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-[var(--arabut-navy-active)] hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                >
                    <ChevronLeft
                        aria-hidden="true"
                        className="h-5 w-5 rtl:-scale-x-100"
                    />
                </button>
```

Add `ChevronLeft` to the lucide import.

- [ ] **Step 5: Implement the view state in ChatWidget**

Edit `resources/js/components/chat/chat-widget.tsx`:

1. Imports — add:

```tsx
import { ChatHome } from './chat-home';
```

2. Props:

```tsx
export type ChatWidgetView = 'home' | 'chat';

export type ChatWidgetProps = {
    enabled?: boolean;
    locale?: string;
    surface?: ChatSurface;
    /** Which view the widget shows when opened. Defaults to the Home screen. */
    initialView?: ChatWidgetView;
};
```

Add a constant next to `CLOSE_TRANSITION_MS`:

```tsx
const VIEW_TRANSITION_MS = 240;
```

3. Component signature — add `initialView = 'home'` to the destructured props.

4. State — after the `isMobileDialog` state add:

```tsx
    const [view, setView] = useState<ChatWidgetView>(initialView);
    const [exitingView, setExitingView] = useState<ChatWidgetView | null>(null);
    const [viewDirection, setViewDirection] = useState<'forward' | 'back'>(
        'forward',
    );

    const switchView = (next: ChatWidgetView) => {
        if (next === view) {
            return;
        }

        setViewDirection(next === 'chat' ? 'forward' : 'back');
        setExitingView(view);
        setView(next);
    };

    // Unmount the exiting view after the slide completes.
    useEffect(() => {
        if (exitingView === null) {
            return;
        }

        const timeout = setTimeout(
            () => setExitingView(null),
            isReducedMotion ? 0 : VIEW_TRANSITION_MS,
        );

        return () => clearTimeout(timeout);
    }, [exitingView, isReducedMotion]);

    // Reset to the initial view when the widget fully closes.
    useEffect(() => {
        if (!isMounted) {
            setView(initialView);
            setExitingView(null);
        }
    }, [initialView, isMounted]);
```

Note: `isMounted` is declared as `const isMounted = isOpen || isVisible;` further down in the current file — move that line up so it sits directly after the `isVisible` state declaration (before these effects).

5. Derived values — after the `useChat` destructure add:

```tsx
    const lastMessage = messages[messages.length - 1] ?? null;
    const hasCustomerMessages = messages.some((m) => m.senderType === 'customer');
    const homeLastMessage =
        lastMessage !== null
            ? { preview: lastMessage.content, createdAt: lastMessage.createdAt }
            : null;
    const showDisclaimer = conversation?.assistantMode === 'agent';
```

6. Dialog element — add `data-view-direction={viewDirection}` to the `role="dialog"` div, and change its background class `bg-[var(--arabut-navy)]` to `bg-[var(--chat-surface)]`.

7. Replace the dialog's children (`<ChatHeader …/>` through `<ChatComposer …/>`) with:

```tsx
                    {(view === 'home' || exitingView === 'home') && (
                        <div
                            key="home"
                            className={`absolute inset-0 flex flex-col ${
                                view === 'home' ? 'chat-view-enter' : 'chat-view-exit'
                            }`}
                            aria-hidden={view !== 'home'}
                        >
                            <ChatHome
                                locale={locale}
                                hasConversation={hasCustomerMessages}
                                lastMessage={homeLastMessage}
                                disabled={isLoading || isRestarting}
                                isMobileDialog={isMobileDialog}
                                closeButtonRef={view === 'home' ? closeButtonRef : undefined}
                                onClose={closeChat}
                                onStart={() => switchView('chat')}
                                onContinue={() => switchView('chat')}
                                onSelectTopic={(label) => {
                                    sendMessage(label);
                                    switchView('chat');
                                }}
                            />
                        </div>
                    )}

                    {(view === 'chat' || exitingView === 'chat') && (
                        <div
                            key="chat"
                            className={`absolute inset-0 flex flex-col ${
                                view === 'chat' ? 'chat-view-enter' : 'chat-view-exit'
                            }`}
                            aria-hidden={view !== 'chat'}
                        >
                            <ChatHeader
                                canRestart={canRestart}
                                closeButtonRef={view === 'chat' ? closeButtonRef : undefined}
                                isRestarting={isRestarting}
                                locale={locale}
                                onBack={() => switchView('home')}
                                onClose={closeChat}
                                onRestart={restartChat}
                            />

                            {error !== null && (
                                <div
                                    key={errorAnnouncementId}
                                    aria-atomic="true"
                                    className="chat-drop-in flex items-center justify-between border-b border-[var(--chat-danger)]/30 bg-[var(--chat-danger)]/10 px-4 py-2 text-xs text-[var(--chat-danger)]"
                                    role="alert"
                                >
                                    <span>{error}</span>
                                    <button
                                        type="button"
                                        onClick={clearError}
                                        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-2 underline hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                                    >
                                        {locale === 'en' ? 'Dismiss' : 'إغلاق'}
                                    </button>
                                </div>
                            )}

                            <ChatMessageList
                                key={conversation?.publicId ?? 'chat-pending'}
                                disabled={isRestarting}
                                messages={messages}
                                isLoading={isLoading}
                                isAssistantTyping={isAssistantTyping}
                                hasMore={hasMore}
                                isLoadingOlder={isLoadingOlder}
                                locale={locale}
                                onLoadOlder={loadOlderMessages}
                                onSelectSuggestion={sendMessage}
                                onRetry={retryMessage}
                                retryableTurn={retryableTurn}
                                onRetryAgentTurn={retryAgentTurn}
                            />

                            <ChatComposer
                                disabled={isLoading || isRestarting}
                                locale={locale}
                                onSend={sendMessage}
                                showDisclaimer={showDisclaimer}
                            />
                        </div>
                    )}
```

`ChatComposer` does not accept `showDisclaimer` yet — add the prop now as `showDisclaimer?: boolean;` in `chat-composer.tsx` (unused until Task 7) so TypeScript compiles.

8. The dialog root needs `relative` positioning for the absolute children: the existing class string starts with `chat-widget-dialog fixed inset-0 …` — `fixed` already establishes a containing block, so nothing else is required.

- [ ] **Step 6: Run the full chat suite**

Run: `npx vitest run resources/js/__tests__/chat --reporter=dot`
Expected: PASS. If an existing test fails because it opened the widget and expected the textbox, that file is missing `initialView="chat"` — fix the render call, not the component.

- [ ] **Step 7: Lint, format, commit**

```bash
npx eslint resources/js/components/chat resources/js/__tests__/chat && npx prettier --write resources/js/components/chat resources/js/__tests__/chat
git add resources/js/components/chat resources/js/__tests__/chat
git commit -m "feat(chat): open on home view and slide into chat

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Light header

**Files:**
- Modify: `resources/js/components/chat/chat-header.tsx`
- Test: `resources/js/__tests__/chat/chat-widget.test.tsx`

- [ ] **Step 1: Write the failing test**

Append inside `describe('home view', …)` in `chat-widget.test.tsx`:

```tsx
        it('renders the chat header on the light card surface with a back control', async () => {
            mockEmptyConversation();
            render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            await screen.findByRole('textbox');

            const back = screen.getByRole('button', { name: 'Back' });
            expect(back).toHaveClass('h-11', 'w-11');
            expect(back.parentElement?.parentElement).toHaveClass(
                'bg-[var(--chat-card)]',
            );
        });
```

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run resources/js/__tests__/chat/chat-widget.test.tsx -t "light card surface" --reporter=dot`
Expected: FAIL on the `bg-[var(--chat-card)]` class

- [ ] **Step 3: Restyle the header**

Replace the JSX of `chat-header.tsx`'s return with:

```tsx
    return (
        <div className="flex items-center justify-between gap-2 border-b border-[var(--chat-line)] bg-[var(--chat-card)] px-3 py-2.5">
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={onBack}
                    aria-label={backLabel}
                    className="chat-back-button chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--chat-muted)] transition-colors hover:bg-[var(--chat-tint)] hover:text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                >
                    <ChevronLeft
                        aria-hidden="true"
                        className="h-5 w-5 transition-transform duration-150 group-hover:-translate-x-0.5 rtl:-scale-x-100"
                    />
                </button>

                <div className="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-[var(--chat-tint)] text-[var(--chat-accent-ink)]">
                    <Sparkles className="h-[18px] w-[18px]" aria-hidden="true" />
                    <span
                        className="absolute end-[-1px] bottom-[-1px] h-2.5 w-2.5 rounded-full border-2 border-[var(--chat-card)] bg-[var(--chat-success)]"
                        title={isEn ? 'Online' : 'متصل'}
                        aria-hidden="true"
                    />
                </div>

                <div className="flex flex-col text-start">
                    <h2 className="text-[15px] leading-tight font-semibold text-[var(--chat-ink)]">
                        {title}
                    </h2>
                    <p className="text-xs leading-tight text-[var(--chat-muted)]">
                        {subtitle}
                    </p>
                </div>
            </div>

            <div className="flex flex-shrink-0 items-center gap-1">
                <div className="chat-restart-group group relative">
                    <button
                        type="button"
                        onClick={onRestart}
                        disabled={!canRestart}
                        aria-busy={isRestarting}
                        aria-describedby={restartTooltipId}
                        aria-label={restartLabel}
                        className="chat-restart-button chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--chat-muted)] transition-colors hover:bg-[var(--chat-tint)] hover:text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-45"
                    >
                        <MessageSquarePlus
                            aria-hidden="true"
                            className={`h-5 w-5 ${
                                isRestarting
                                    ? 'animate-pulse motion-reduce:animate-none'
                                    : ''
                            }`}
                        />
                    </button>
                    <span
                        id={restartTooltipId}
                        role="tooltip"
                        className="chat-restart-tooltip pointer-events-none absolute end-0 top-full z-30 mt-2 w-max max-w-48 rounded-lg border border-[var(--chat-line)] bg-[var(--chat-card)] px-2.5 py-1.5 text-xs text-[var(--chat-ink)] shadow-lg"
                    >
                        {restartLabel}
                    </span>
                </div>

                <button
                    ref={closeButtonRef}
                    type="button"
                    onClick={onClose}
                    aria-label={closeLabel}
                    className="chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--chat-muted)] transition-colors hover:bg-[var(--chat-tint)] hover:text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                >
                    <X aria-hidden="true" className="h-5 w-5" />
                </button>
            </div>
        </div>
    );
```

Change the subtitle text to `isEn ? 'Usually replies instantly' : 'عادة نرد فورًا'`.

- [ ] **Step 4: Run the chat suite**

Run: `npx vitest run resources/js/__tests__/chat --reporter=dot`
Expected: PASS. If `chat-widget.test.tsx` asserts the old subtitle `Usually replies quickly`/`عادة يرد فورًا`, update that assertion to the new copy.

- [ ] **Step 5: Commit**

```bash
npx eslint resources/js/components/chat/chat-header.tsx && npx prettier --write resources/js/components/chat/chat-header.tsx resources/js/__tests__/chat/chat-widget.test.tsx
git add resources/js/components/chat/chat-header.tsx resources/js/__tests__/chat/chat-widget.test.tsx
git commit -m "feat(chat): light header with back control

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Light message list, typing indicator, pills

**Files:**
- Modify: `resources/js/components/chat/chat-message-list.tsx`
- Modify: `resources/js/components/chat/typing-indicator.tsx`
- Test: `resources/js/__tests__/chat/chat-direction.test.tsx`, `resources/js/__tests__/chat/typing-indicator.test.tsx`

- [ ] **Step 1: Write the failing tests**

Append to `chat-direction.test.tsx` (it renders `ChatMessageList` directly — copy the render helper already in the file):

```tsx
    it('uses the light bubble palette', () => {
        renderList([
            customer('c1', 'مرحبا'),
            assistant('a1', 'أهلًا'),
        ]);

        const customerBubble = screen.getByText('مرحبا').parentElement;
        const assistantBubble = screen.getByText('أهلًا').parentElement;

        expect(customerBubble).toHaveClass('bg-[var(--chat-hero)]');
        expect(assistantBubble).toHaveClass('bg-[var(--chat-card)]');
        expect(customerBubble?.parentElement).toHaveClass('chat-bubble-enter');
    });

    it('renders quick replies as gold pills with stagger', () => {
        renderList([assistant('a1', 'أهلًا')]);

        const pill = screen.getByRole('button', { name: 'الأسعار' });
        expect(pill).toHaveClass('rounded-full', 'chat-stagger-in');
        expect(pill).toHaveClass('border-[var(--chat-accent)]');
    });
```

(`customer()`/`assistant()`/`renderList()` are whatever helpers the file already uses to build `ChatMessage` objects and render the list — if it has none, define them at the top of the file:)

```tsx
function customer(id: string, content: string): ChatMessage {
    return {
        publicId: id,
        senderType: 'customer',
        messageType: 'text',
        content,
        createdAt: '2026-08-22T10:00:00Z',
    };
}

function assistant(id: string, content: string): ChatMessage {
    return { ...customer(id, content), senderType: 'assistant' };
}

function renderList(messages: ChatMessage[]) {
    return render(
        <ChatMessageList
            messages={messages}
            isLoading={false}
            isAssistantTyping={false}
            hasMore={false}
            isLoadingOlder={false}
            locale="ar"
            onLoadOlder={() => {}}
            onSelectSuggestion={() => {}}
            onRetry={() => {}}
        />,
    );
}
```

Append to `typing-indicator.test.tsx`:

```tsx
    it('uses the light card palette and accent dots', () => {
        const { container } = render(<TypingIndicator locale="en" />);

        expect(container.firstChild).toHaveClass('bg-[var(--chat-card)]');
        expect(container.querySelectorAll('.bg-\\[var\\(--chat-accent\\)\\]')).toHaveLength(3);
    });
```

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run resources/js/__tests__/chat/chat-direction.test.tsx resources/js/__tests__/chat/typing-indicator.test.tsx --reporter=dot`
Expected: FAIL on the class assertions

- [ ] **Step 3: Restyle `typing-indicator.tsx`**

```tsx
import React from 'react';

type TypingIndicatorProps = {
    locale?: string;
};

const DOT =
    'h-2 w-2 animate-bounce rounded-full bg-[var(--chat-accent)] motion-reduce:animate-none';

export const TypingIndicator: React.FC<TypingIndicatorProps> = () => {
    return (
        <div
            className="chat-bubble-enter flex items-center gap-1.5 rounded-2xl rounded-bl-sm border border-[var(--chat-line)] bg-[var(--chat-card)] px-4 py-3 shadow-[0_2px_8px_rgb(13_11_8/0.05)]"
            aria-hidden="true"
        >
            <span className={DOT} style={{ animationDelay: '0ms', animationDuration: '900ms' }} />
            <span className={DOT} style={{ animationDelay: '180ms', animationDuration: '900ms' }} />
            <span className={DOT} style={{ animationDelay: '360ms', animationDuration: '900ms' }} />
        </div>
    );
};
```

- [ ] **Step 4: Restyle `chat-message-list.tsx`**

Make these exact replacements (logic untouched):

1. Outer wrapper (line ~164): `bg-[var(--arabut-navy)]` → `bg-[var(--chat-surface)]`.
2. Older-messages button classes → `chat-press inline-flex min-h-11 items-center justify-center rounded-full border border-[var(--chat-line)] bg-[var(--chat-card)] px-4 py-2 text-xs text-[var(--chat-muted)] hover:text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50`.
3. Loading text: `text-[var(--arabut-muted)]` → `text-[var(--chat-muted)]`.
4. System cluster bubble classes → `max-w-[85%] rounded-full bg-[var(--chat-tint)] px-3.5 py-1.5 text-center text-[11px] leading-relaxed text-[var(--chat-faint)]`.
5. Message wrapper (the `group relative max-w-[82%] text-start …` div): apply `chat-bubble-enter` to **both** customer and assistant:
   ```tsx
   className="chat-bubble-enter group relative max-w-[85%] text-start"
   ```
6. Bubble classes:
   ```tsx
   className={`rounded-2xl px-3.5 py-3 text-sm leading-relaxed ${
       isCustomer
           ? 'rounded-br-[4px] bg-[var(--chat-hero)] text-[#fbf8f2]'
           : 'rounded-bl-[4px] border border-[var(--chat-line)] bg-[var(--chat-card)] text-[var(--chat-ink)] shadow-[0_2px_8px_rgb(13_11_8/0.05)]'
   } ${isSending ? 'chat-sending opacity-70' : ''}`}
   ```
7. Streaming placeholder dots: `bg-[var(--arabut-gold-bright)]` → `bg-[var(--chat-accent)]` (three occurrences).
8. Error text/retry: `text-[var(--arabut-danger)]` → `text-[var(--chat-danger)]`; `hover:text-[var(--arabut-ink)]` → `hover:text-[var(--chat-ink)]`.
9. Timestamp: `text-[var(--arabut-muted)]` → `text-[var(--chat-faint)]`.
10. Retryable-turn card: border/bg/text → `border-[var(--chat-line)] bg-[var(--chat-card)] … text-[var(--chat-danger)]`, add `chat-drop-in`.
11. Suggestions block — replace the whole `{!hasCustomerMessages && (…)}` block with:
    ```tsx
                    {!hasCustomerMessages && (
                        <div className="flex flex-wrap gap-2 pt-1 pb-1">
                            {suggestions.map((suggestion, index) => (
                                <button
                                    key={suggestion}
                                    type="button"
                                    onClick={() => onSelectSuggestion(suggestion)}
                                    disabled={disabled}
                                    style={{ ['--i' as string]: index }}
                                    className="chat-stagger-in chat-press min-h-11 rounded-full border border-[var(--chat-accent)] bg-[var(--chat-card)] px-3.5 py-2 text-[13px] font-semibold text-[var(--chat-accent-ink)] transition-colors hover:bg-[var(--chat-tint)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {suggestion}
                                </button>
                            ))}
                        </div>
                    )}
    ```
    The "Suggested topics:" label is removed (Home carries the heading now). If `chat-widget.test.tsx` asserts `المواضيع المقترحة`/`Suggested topics:`, delete that assertion.
12. Scroll-to-bottom pill classes → `chat-drop-in chat-press absolute bottom-4 left-1/2 flex min-h-11 -translate-x-1/2 items-center gap-1.5 rounded-full border border-[var(--chat-line)] bg-[var(--chat-card)]/95 px-3.5 py-2 text-xs font-semibold text-[var(--chat-accent-ink)] shadow-xl backdrop-blur-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]`.

- [ ] **Step 5: Run the chat suite**

Run: `npx vitest run resources/js/__tests__/chat --reporter=dot`
Expected: PASS. Any failure must be a colour-class or removed-label assertion; update the assertion, never the scroll/stream logic.

- [ ] **Step 6: Commit**

```bash
npx eslint resources/js/components/chat resources/js/__tests__/chat && npx prettier --write resources/js/components/chat resources/js/__tests__/chat
git add resources/js/components/chat/chat-message-list.tsx resources/js/components/chat/typing-indicator.tsx resources/js/__tests__/chat
git commit -m "feat(chat): light message bubbles and pill quick replies

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Light composer with animated send + disclaimer

**Files:**
- Modify: `resources/js/components/chat/chat-composer.tsx`
- Test: `resources/js/__tests__/chat/chat-widget.test.tsx`

- [ ] **Step 1: Write the failing tests**

Append inside `describe('home view', …)`:

```tsx
        it('shows the AI disclaimer only in agent mode', async () => {
            mockConversationResponse('agent');
            render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

            expect(
                await screen.findByText(/AI assistant — may make mistakes/),
            ).toBeInTheDocument();
        });

        it('hides the AI disclaimer in demo mode', async () => {
            mockEmptyConversation();
            render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);
            fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));
            await screen.findByRole('textbox');

            expect(screen.queryByText(/مساعد ذكي/)).not.toBeInTheDocument();
        });

        it('reveals the send button with a pop when text is typed', async () => {
            mockEmptyConversation();
            render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            const textbox = await screen.findByRole('textbox');
            const send = screen.getByRole('button', { name: 'Send message' });

            expect(send).toHaveClass('scale-90', 'opacity-40');
            fireEvent.change(textbox, { target: { value: 'hello' } });
            expect(send).toHaveClass('scale-100', 'opacity-100');
        });
```

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run resources/js/__tests__/chat/chat-widget.test.tsx -t "disclaimer|pop" --reporter=dot`
Expected: FAIL

- [ ] **Step 3: Restyle the composer**

Replace `chat-composer.tsx` from the `type ChatComposerProps` declaration through the end of the file:

```tsx
type ChatComposerProps = {
    disabled?: boolean;
    locale?: string;
    onSend: (content: string) => void;
    showDisclaimer?: boolean;
};

const MAX_LENGTH = 4000;

export const ChatComposer: React.FC<ChatComposerProps> = ({
    disabled = false,
    locale = 'ar',
    onSend,
    showDisclaimer = false,
}) => {
    const [content, setContent] = useState('');
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const isEn = locale === 'en';

    const placeholder = isEn ? 'Type a message…' : 'اكتب رسالتك هنا…';
    const inputLabel = isEn ? 'Type your message' : 'اكتب رسالتك';
    const sendLabel = isEn ? 'Send message' : 'إرسال الرسالة';
    const disclaimer = isEn
        ? 'AI assistant — may make mistakes. Verify important info.'
        : 'مساعد ذكي — قد يخطئ، تحقق من المعلومات المهمة';

    useEffect(() => {
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.style.height = `${Math.min(textareaRef.current.scrollHeight, 120)}px`;
        }
    }, [content]);

    const handleSubmit = (e?: React.FormEvent) => {
        if (e) {
            e.preventDefault();
        }

        const trimmed = content.trim();

        if (trimmed === '' || disabled) {
            return;
        }

        onSend(trimmed);
        setContent('');

        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.focus();
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSubmit();
        }
    };

    const hasText = content.trim().length > 0;
    const canSubmit = hasText && !disabled;

    return (
        <form
            onSubmit={handleSubmit}
            className="chat-composer--mobile-safe flex flex-col gap-2 border-t border-[var(--chat-line)] bg-[var(--chat-card)] p-3"
        >
            <div
                dir="ltr"
                className="relative flex items-end gap-2 rounded-2xl border border-[var(--chat-line-strong)] bg-[var(--chat-surface)] p-1.5 transition-[border-color,box-shadow] duration-150 focus-within:border-[var(--chat-accent)] focus-within:shadow-[0_0_0_2px_var(--chat-accent)] motion-reduce:transition-none"
            >
                <textarea
                    ref={textareaRef}
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder={placeholder}
                    rows={1}
                    maxLength={MAX_LENGTH}
                    disabled={disabled}
                    aria-label={inputLabel}
                    dir="auto"
                    className="max-h-32 min-h-[44px] flex-1 resize-none bg-transparent px-3 py-2.5 text-base text-[var(--chat-ink)] placeholder-[var(--chat-faint)] focus:outline-none disabled:opacity-60 lg:text-sm"
                />

                <button
                    type="submit"
                    disabled={!canSubmit}
                    aria-label={sendLabel}
                    className={`chat-press flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[var(--chat-accent)] text-[var(--chat-hero)] transition-[transform,opacity] duration-200 [transition-timing-function:var(--chat-ease-spring)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed motion-reduce:transition-none ${
                        hasText ? 'scale-100 opacity-100' : 'scale-90 opacity-40'
                    }`}
                >
                    <ArrowUp className="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            {content.length > 3500 && (
                <div className="text-end text-[11px] text-[var(--chat-faint)]">
                    {content.length} / {MAX_LENGTH}
                </div>
            )}

            {showDisclaimer && (
                <p className="chat-drop-in text-center text-[11px] leading-snug text-[var(--chat-faint)]">
                    {disclaimer}
                </p>
            )}
        </form>
    );
};
```

Change the lucide import from `Send` to `ArrowUp`.

- [ ] **Step 4: Run the chat suite**

Run: `npx vitest run resources/js/__tests__/chat --reporter=dot`
Expected: PASS. If a test asserted the old placeholder `Type a message...` (three dots) update it to the ellipsis character `…`; the composer's accessible name (`Type your message` / `اكتب رسالتك`) is unchanged.

- [ ] **Step 5: Commit**

```bash
npx eslint resources/js/components/chat/chat-composer.tsx && npx prettier --write resources/js/components/chat/chat-composer.tsx resources/js/__tests__/chat/chat-widget.test.tsx
git add resources/js/components/chat/chat-composer.tsx resources/js/__tests__/chat/chat-widget.test.tsx
git commit -m "feat(chat): light composer with animated send and agent disclaimer

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Launcher ring pulse and unread pop

**Files:**
- Modify: `resources/js/components/chat/chat-launcher.tsx`
- Test: `resources/js/__tests__/chat/chat-widget.test.tsx`

- [ ] **Step 1: Write the failing test**

Append inside `describe('home view', …)`:

```tsx
        it('pulses the launcher ring once when opened', async () => {
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);
            const launcher = screen.getByRole('button', { name: /Open chat/i });

            expect(launcher).not.toHaveClass('chat-launcher-open');
            fireEvent.click(launcher);
            expect(
                screen.getByRole('button', { name: /Close chat/i, expanded: true }),
            ).toHaveClass('chat-launcher-open');
        });
```

(The launcher's `aria-label` flips to "Close chat" when open; the Home hero also has a "Close chat" button, hence the `expanded: true` filter which matches only the launcher's `aria-expanded`.)

- [ ] **Step 2: Run to verify failure**

Run: `npx vitest run resources/js/__tests__/chat/chat-widget.test.tsx -t "pulses the launcher" --reporter=dot`
Expected: FAIL

- [ ] **Step 3: Implement**

In `chat-launcher.tsx`, change the button `className` string to prepend the conditional class:

```tsx
            className={`${isOpen ? 'chat-launcher-open ' : ''}relative flex h-14 w-14 … (keep the rest of the existing string unchanged)`}
```

And the unread badge: replace `animate-[pulse_1.5s_ease-in-out_3]` with `chat-pop-in`.

The existing test "uses the approved quiet launcher geometry" asserts classes with `toHaveClass` (order-independent), so it keeps passing.

- [ ] **Step 4: Run the chat suite, commit**

Run: `npx vitest run resources/js/__tests__/chat --reporter=dot` → PASS

```bash
npx prettier --write resources/js/components/chat/chat-launcher.tsx resources/js/__tests__/chat/chat-widget.test.tsx
git add resources/js/components/chat/chat-launcher.tsx resources/js/__tests__/chat/chat-widget.test.tsx
git commit -m "feat(chat): launcher ring pulse and badge pop

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: Full gates + browser check

**Files:** none new.

- [ ] **Step 1: Frontend gate**

Run: `npm run ci:check`
Expected: vitest all green (≥ 503 passed + new tests, 4 skipped), eslint clean, prettier clean, `tsc` clean for app and e2e, `vite build` succeeds.

- [ ] **Step 2: PHP gates (unchanged code, sanity only)**

Run: `php artisan test --compact` (or `composer ci:check` if composer is on PATH)
Expected: all passing; no PHP files were changed.

- [ ] **Step 3: Browser check**

Start the dev server (`npm run dev` + `php artisan serve`, or the project's `.claude/launch.json` entry if present) and, at 390px and 1440px, in `ar` and `en`:
- launcher → Home (hero + cards staggered in), Start → chat slides in the reading direction, Back → Home slides back;
- topic card sends the label and lands in chat;
- demo reply streams in with white bubble and caret; customer bubble dark;
- send button pops when typing; Escape closes; reopen lands on Home;
- with DevTools "Emulate CSS prefers-reduced-motion: reduce": no motion, all states instant.

Capture one screenshot per breakpoint/locale into the scratchpad for the PR.

- [ ] **Step 4: Final commit / status**

```bash
git status --short   # must be empty except ignored files
git log --oneline main..HEAD
```

Hand off with `superpowers:finishing-a-development-branch`.
