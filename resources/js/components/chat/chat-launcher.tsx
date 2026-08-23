import { MessageSquare, X } from 'lucide-react';
import React, { useEffect, useState } from 'react';

type ChatLauncherProps = {
    isOpen: boolean;
    unreadCount: number;
    locale?: string;
    onToggle: () => void;
};

const GREETING_DISMISSED_KEY = 'arabut_chat_greeting_dismissed';
const CHAT_OPENED_KEY = 'arabut_chat_opened';

function isGreetingDismissed(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.sessionStorage.getItem(GREETING_DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

function persistGreetingDismissal(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(GREETING_DISMISSED_KEY, '1');
    } catch {
        // Ignore storage errors in restricted contexts
    }
}

function hasOpenedThisSession(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.sessionStorage.getItem(CHAT_OPENED_KEY) === '1';
    } catch {
        return false;
    }
}

function persistChatOpened(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(CHAT_OPENED_KEY, '1');
    } catch {
        // Ignore storage errors in restricted contexts
    }
}

export const ChatLauncher = React.forwardRef<
    HTMLButtonElement,
    ChatLauncherProps
>(({ isOpen, unreadCount, locale = 'ar', onToggle }, ref) => {
    const isEn = locale === 'en';
    const label = isOpen
        ? isEn
            ? 'Close chat'
            : 'إغلاق الشات'
        : isEn
          ? 'Open chat'
          : 'فتح الشات';

    const [showGreeting, setShowGreeting] = useState(false);
    const [hasOpened, setHasOpened] = useState(() => hasOpenedThisSession());

    // Once chat is opened, permanently disable the attention beacon
    // and dismiss the greeting bubble for this browsing session.
    useEffect(() => {
        if (isOpen) {
            setShowGreeting(false);
            persistGreetingDismissal();

            if (!hasOpened) {
                setHasOpened(true);
                persistChatOpened();
            }
        }
    }, [isOpen, hasOpened]);

    // Show greeting bubble after 3-second idle delay on wide viewports
    // only if the chat has not been opened and not previously dismissed.
    useEffect(() => {
        if (isOpen || isGreetingDismissed() || hasOpenedThisSession()) {
            setShowGreeting(false);

            return;
        }

        const timer = setTimeout(() => {
            if (!isOpen && !isGreetingDismissed()) {
                setShowGreeting(true);
            }
        }, 3000);

        return () => clearTimeout(timer);
    }, [isOpen]);

    const handleDismissGreeting = (e: React.MouseEvent) => {
        e.stopPropagation();
        setShowGreeting(false);
        persistGreetingDismissal();
    };

    return (
        <div className="relative flex items-center justify-end">
            {/* Attention animation: soft gold periodic beacon ring (desktop only, closed & unopened) */}
            {!isOpen && !hasOpened && (
                <span
                    aria-hidden="true"
                    data-testid="chat-beacon-ring"
                    className="chat-beacon-ring pointer-events-none absolute inset-0 hidden rounded-full sm:block"
                />
            )}

            {/* Greeting bubble: slides out beside launcher on desktop after 3s delay */}
            {!isOpen && showGreeting && (
                <div
                    data-testid="chat-greeting-bubble"
                    role="status"
                    onClick={onToggle}
                    className="chat-greeting-bubble absolute top-1/2 right-[calc(100%+14px)] z-10 hidden -translate-y-1/2 cursor-pointer items-center gap-2.5 rounded-2xl border border-[var(--arabut-gold)]/45 bg-[color:color-mix(in_srgb,var(--arabut-navy-raised)_92%,transparent)] px-3.5 py-2 text-xs font-medium text-[var(--arabut-ink)] shadow-[0_8px_24px_rgba(0,0,0,0.38)] backdrop-blur-md transition-all duration-200 hover:-translate-y-[calc(50%+1px)] hover:border-[var(--arabut-gold)]/75 hover:shadow-[0_10px_28px_rgba(0,0,0,0.45)] motion-reduce:transform-none motion-reduce:transition-none sm:flex sm:whitespace-nowrap"
                >
                    {/* Speech bubble pointer caret pointing toward launcher */}
                    <span
                        aria-hidden="true"
                        className="chat-greeting-bubble__caret absolute top-1/2 -right-1.5 h-2.5 w-2.5 -translate-y-1/2 rotate-45 border-t border-r border-[var(--arabut-gold)]/45 bg-[var(--arabut-navy-raised)] transition-colors duration-200"
                    />

                    <span className="font-medium text-[var(--arabut-ink)] select-none">
                        {isEn ? 'Need help? Ask me' : 'محتاج مساعدة؟ اسألني'}
                    </span>

                    <button
                        type="button"
                        data-testid="chat-greeting-dismiss"
                        onClick={handleDismissGreeting}
                        aria-label={isEn ? 'Dismiss greeting' : 'إغلاق التلميح'}
                        className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[var(--arabut-muted)] transition-colors duration-150 hover:bg-white/10 hover:text-[var(--arabut-gold-bright)] focus-visible:outline focus-visible:outline-1 focus-visible:outline-[var(--arabut-focus)]"
                    >
                        <X className="h-3 w-3 stroke-2" />
                    </button>
                </div>
            )}

            {/* Floating Launcher Button */}
            <button
                ref={ref}
                type="button"
                onClick={onToggle}
                aria-expanded={isOpen}
                aria-haspopup="dialog"
                aria-label={label}
                className={`group relative flex h-14 w-14 cursor-pointer items-center justify-center overflow-visible rounded-full border border-[var(--arabut-gold)]/45 bg-[color:color-mix(in_srgb,var(--arabut-navy-raised)_88%,transparent)] text-[var(--arabut-gold-bright)] shadow-[0_8px_24px_rgba(0,0,0,0.32)] backdrop-blur-md transition-[transform,background-color,border-color,box-shadow,width,padding] duration-200 [transition-timing-function:cubic-bezier(0.25,1,0.5,1)] hover:-translate-y-0.5 hover:border-[var(--arabut-gold)]/70 hover:bg-[color:color-mix(in_srgb,var(--arabut-navy-active)_92%,transparent)] hover:shadow-[0_10px_28px_rgba(0,0,0,0.38)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--arabut-focus)] active:translate-y-0 active:scale-[0.97] motion-reduce:transform-none motion-reduce:transition-none sm:h-[60px] sm:w-[60px] ${
                    !isOpen ? 'sm:hover:w-auto sm:hover:px-4.5' : ''
                } ${isOpen ? 'chat-launcher-open' : ''}`}
            >
                <span className="sr-only">{label}</span>

                {/* Subtle gold glow ring inside button */}
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 rounded-full shadow-[inset_0_0_12px_rgba(212,168,67,0.18)]"
                />

                {/* Main Icon (cross-fades between MessageSquare and X) */}
                <span
                    className="relative z-10 grid h-7 w-7 shrink-0 place-items-center"
                    aria-hidden="true"
                >
                    <span
                        className={`absolute h-6 w-6 transition-[opacity,transform] duration-150 motion-reduce:transition-none ${
                            isOpen
                                ? 'scale-75 rotate-45 opacity-0'
                                : 'scale-100 rotate-0 opacity-100'
                        }`}
                    >
                        <MessageSquare className="h-6 w-6 stroke-2" />
                    </span>
                    <span
                        className={`absolute h-6 w-6 transition-[opacity,transform] duration-150 motion-reduce:transition-none ${
                            isOpen
                                ? 'scale-100 rotate-0 opacity-100'
                                : 'scale-75 -rotate-45 opacity-0'
                        }`}
                    >
                        <X className="h-6 w-6 stroke-2" />
                    </span>
                </span>

                {/* Desktop hover label (expands smoothly on desktop hover when closed) */}
                <span
                    className={`hidden items-center overflow-hidden text-sm font-bold whitespace-nowrap text-[var(--arabut-gold-bright)] transition-all duration-300 ease-out motion-reduce:transition-none sm:inline-flex ${
                        isOpen
                            ? 'max-w-0 opacity-0'
                            : 'max-w-0 opacity-0 group-hover:ms-2 group-hover:max-w-[100px] group-hover:pe-1 group-hover:opacity-100'
                    }`}
                    aria-hidden="true"
                >
                    {isEn ? 'Ask Luna' : 'اسأل لونا'}
                </span>

                {/* Online status indicator dot (closed only) */}
                {!isOpen && (
                    <span
                        aria-hidden="true"
                        data-testid="chat-online-dot"
                        className="absolute right-1 bottom-1 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-[var(--arabut-navy-deep)] shadow-sm sm:right-1.5 sm:bottom-1.5 sm:h-4 sm:w-4"
                    >
                        <span className="h-2 w-2 rounded-full bg-[#22a06b] shadow-[0_0_6px_rgba(34,160,107,0.8)] sm:h-2.5 sm:w-2.5" />
                    </span>
                )}

                {/* Unread badge */}
                {!isOpen && unreadCount > 0 && (
                    <span
                        className="chat-pop-in absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full border border-[var(--arabut-gold-bright)] bg-[var(--arabut-navy-deep)] px-1 text-xs font-black text-[var(--arabut-gold-bright)] shadow-lg motion-reduce:animate-none"
                        aria-label={
                            isEn
                                ? `${unreadCount} unread messages`
                                : `${unreadCount} رسائل غير مقروءة`
                        }
                    >
                        {unreadCount > 9 ? '+9' : unreadCount}
                    </span>
                )}
            </button>
        </div>
    );
});

ChatLauncher.displayName = 'ChatLauncher';
