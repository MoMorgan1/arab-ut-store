import React, {
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { useChat } from '@/hooks/use-chat';
import type { ChatSurface } from '@/types/chat';
import { ChatComposer } from './chat-composer';
import { ChatHeader } from './chat-header';
import { ChatLauncher } from './chat-launcher';
import { ChatMessageList } from './chat-message-list';

export type ChatWidgetProps = {
    enabled?: boolean;
    demoAssistant?: boolean;
    locale?: string;
    surface?: ChatSurface;
};

const CLOSE_TRANSITION_MS = 180;
const MOBILE_SHEET_QUERY = '(max-width: 47.99rem)';

function mobileSheetMatches(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia(MOBILE_SHEET_QUERY).matches
    );
}

function subscribeToMobileSheet(onStoreChange: () => void): () => void {
    if (
        typeof window === 'undefined' ||
        typeof window.matchMedia !== 'function'
    ) {
        return () => undefined;
    }

    const mediaQuery = window.matchMedia(MOBILE_SHEET_QUERY);
    mediaQuery.addEventListener('change', onStoreChange);

    return () => mediaQuery.removeEventListener('change', onStoreChange);
}

function ChatStatusRegion({
    id,
    message,
}: {
    id: number;
    message: string | null;
}) {
    return (
        <div
            aria-live="polite"
            aria-atomic="true"
            className="sr-only"
            role="status"
        >
            {message !== null && <span key={id}>{message}</span>}
        </div>
    );
}

export const ChatWidget: React.FC<ChatWidgetProps> = ({
    enabled,
    demoAssistant,
    locale = 'ar',
    surface = 'store',
}) => {
    const {
        isChatEnabled,
        isOpen,
        toggleOpen,
        closeChat,
        messages,
        isLoading,
        isAssistantTyping,
        isLoadingOlder,
        isRestarting,
        canRestart,
        hasMore,
        unreadCount,
        error,
        clearError,
        statusAnnouncement,
        statusAnnouncementId,
        sendMessage,
        retryMessage,
        loadOlderMessages,
        restartChat,
    } = useChat({ enabled, demoAssistant, locale });

    const launcherRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const rootRef = useRef<HTMLDivElement>(null);
    const wasOpenRef = useRef(isOpen);
    const wasModalPresenceRef = useRef(false);

    const [isVisible, setIsVisible] = useState(isOpen);
    const isMobileSheet = useSyncExternalStore(
        subscribeToMobileSheet,
        mobileSheetMatches,
        () => false,
    );

    const isReducedMotion =
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Presence animation management
    useEffect(() => {
        if (isOpen) {
            const raf = requestAnimationFrame(() => {
                setIsVisible(true);
            });

            return () => cancelAnimationFrame(raf);
        }

        if (!isOpen && isVisible) {
            const timeout = setTimeout(
                () => {
                    setIsVisible(false);
                },
                isReducedMotion ? 0 : CLOSE_TRANSITION_MS,
            );

            return () => clearTimeout(timeout);
        }
    }, [isOpen, isVisible, isReducedMotion]);

    const isMounted = isOpen || isVisible;
    const isModalPresence = isMobileSheet && isMounted;

    useEffect(() => {
        if (isOpen && isMobileSheet) {
            panelRef.current?.focus({ preventScroll: true });
        }
    }, [isOpen, isMobileSheet]);

    useEffect(() => {
        if (!isModalPresence) {
            return;
        }

        const root = rootRef.current;
        const parent = root?.parentElement;
        const panel = panelRef.current;

        if (!root || !parent || !panel) {
            return;
        }

        const outsideElements = new Set([
            ...Array.from(parent.children).filter(
                (element): element is HTMLElement =>
                    element instanceof HTMLElement && element !== root,
            ),
            ...Array.from(root.children).filter(
                (element): element is HTMLElement =>
                    element instanceof HTMLElement && element !== panel,
            ),
        ]);
        const alreadyInert = new Set(
            Array.from(outsideElements).filter((element) =>
                element.hasAttribute('inert'),
            ),
        );

        for (const element of outsideElements) {
            element.setAttribute('inert', '');
        }

        return () => {
            for (const element of outsideElements) {
                if (!alreadyInert.has(element)) {
                    element.removeAttribute('inert');
                }
            }
        };
    }, [isModalPresence]);

    // Focus restoration to launcher on close
    useEffect(() => {
        if (
            (isMobileSheet &&
                wasModalPresenceRef.current &&
                !isModalPresence) ||
            (!isMobileSheet && wasOpenRef.current && !isOpen)
        ) {
            launcherRef.current?.focus();
        }

        wasOpenRef.current = isOpen;
        wasModalPresenceRef.current = isModalPresence;
    }, [isOpen, isMobileSheet, isModalPresence]);

    // Handle Escape and contain keyboard focus in the mobile full sheet.
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && isOpen) {
                closeChat();

                return;
            }

            if (e.key !== 'Tab' || !isModalPresence) {
                return;
            }

            const panel = panelRef.current;

            if (!panel) {
                return;
            }

            const focusableElements = Array.from(
                panel.querySelectorAll<HTMLElement>(
                    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
                ),
            ).filter(
                (element) => element.getAttribute('aria-hidden') !== 'true',
            );

            if (focusableElements.length === 0) {
                e.preventDefault();
                panel.focus({ preventScroll: true });

                return;
            }

            const first = focusableElements[0];
            const last = focusableElements[focusableElements.length - 1];
            const activeElement = document.activeElement;

            if (
                activeElement === panel ||
                !panel.contains(activeElement) ||
                (!e.shiftKey && activeElement === last) ||
                (e.shiftKey && activeElement === first)
            ) {
                e.preventDefault();
                (e.shiftKey ? last : first).focus({ preventScroll: true });
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isOpen, isModalPresence, closeChat]);

    if (!isChatEnabled) {
        return null;
    }

    return (
        <div
            ref={rootRef}
            className={`chat-widget-root fixed right-4 bottom-4 z-50 font-sans ${
                surface === 'account' ? 'chat-widget-root--account' : ''
            }`}
            dir={locale === 'en' ? 'ltr' : 'rtl'}
        >
            {!isMounted && (
                <ChatStatusRegion
                    id={statusAnnouncementId}
                    message={statusAnnouncement}
                />
            )}

            {/* Chat Panel / Sheet */}
            {isMounted && (
                <div
                    ref={panelRef}
                    role="dialog"
                    aria-modal={isModalPresence}
                    tabIndex={isModalPresence ? -1 : undefined}
                    aria-label={
                        locale === 'en'
                            ? 'Arab UT Chat Assistant'
                            : 'شات مساعد عرب التيميت'
                    }
                    className={`chat-widget-dialog fixed inset-0 z-[70] flex origin-bottom flex-col overscroll-contain bg-[var(--arabut-navy)] transition-[transform,opacity] motion-reduce:transition-none md:inset-auto md:right-6 md:bottom-24 md:h-[650px] md:max-h-[85vh] md:w-[420px] md:origin-bottom-right md:overflow-hidden md:rounded-3xl md:border md:border-[var(--arabut-line)] md:shadow-2xl ${
                        isVisible
                            ? 'pointer-events-auto translate-y-0 scale-100 opacity-100 duration-[280ms] [transition-timing-function:cubic-bezier(0.16,1,0.3,1)]'
                            : 'pointer-events-none translate-y-3 scale-[0.98] opacity-0 duration-[180ms] [transition-timing-function:cubic-bezier(0.7,0,0.84,0)] md:scale-[0.96]'
                    }`}
                >
                    <ChatStatusRegion
                        id={statusAnnouncementId}
                        message={statusAnnouncement}
                    />

                    {/* Header */}
                    <ChatHeader
                        locale={locale}
                        canRestart={canRestart}
                        isRestarting={isRestarting}
                        onClose={closeChat}
                        onRestart={restartChat}
                    />

                    {/* Error Banner if any */}
                    {error !== null && (
                        <div className="flex items-center justify-between border-b border-[var(--arabut-danger)]/30 bg-[var(--arabut-danger)]/10 px-4 py-2 text-xs text-[var(--arabut-danger)]">
                            <span>{error}</span>
                            <button
                                type="button"
                                onClick={clearError}
                                className="min-h-11 px-2 underline hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                            >
                                {locale === 'en' ? 'Dismiss' : 'إغلاق'}
                            </button>
                        </div>
                    )}

                    {/* Message List */}
                    <ChatMessageList
                        messages={messages}
                        isLoading={isLoading}
                        isAssistantTyping={isAssistantTyping}
                        hasMore={hasMore}
                        isLoadingOlder={isLoadingOlder}
                        interactionLocked={isRestarting}
                        locale={locale}
                        onLoadOlder={loadOlderMessages}
                        onSelectSuggestion={sendMessage}
                        onRetry={retryMessage}
                    />

                    {/* Composer */}
                    <ChatComposer
                        disabled={isLoading || isRestarting}
                        locale={locale}
                        onSend={sendMessage}
                    />
                </div>
            )}

            {/* Floating Launcher Button */}
            <ChatLauncher
                ref={launcherRef}
                isOpen={isOpen}
                unreadCount={unreadCount}
                locale={locale}
                onToggle={toggleOpen}
            />
        </div>
    );
};
