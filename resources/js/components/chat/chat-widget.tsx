import React, { useEffect, useRef, useState } from 'react';
import { useChat } from '@/hooks/use-chat';
import type { ChatSurface } from '@/types/chat';
import { ChatComposer } from './chat-composer';
import { ChatHeader } from './chat-header';
import { ChatLauncher } from './chat-launcher';
import { ChatMessageList } from './chat-message-list';

export type ChatWidgetProps = {
    enabled?: boolean;
    locale?: string;
    surface?: ChatSurface;
};

const CLOSE_TRANSITION_MS = 180;
const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function mobileDialogQuery(surface: ChatSurface): string {
    return surface === 'account'
        ? '(max-width: 47.99rem)'
        : '(max-width: 39.99rem)';
}

function matchesMobileDialog(surface: ChatSurface): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    if (typeof window.matchMedia === 'function') {
        return window.matchMedia(mobileDialogQuery(surface)).matches;
    }

    return window.innerWidth <= (surface === 'account' ? 767 : 639);
}

export const ChatWidget: React.FC<ChatWidgetProps> = ({
    enabled,
    locale = 'ar',
    surface = 'store',
}) => {
    const {
        isChatEnabled,
        isOpen,
        toggleOpen,
        closeChat,
        messages,
        conversation,
        isLoading,
        isAssistantTyping,
        isLoadingOlder,
        isRestarting,
        hasMore,
        unreadCount,
        error,
        errorAnnouncementId,
        clearError,
        statusAnnouncement,
        canRestart,
        restartChat,
        sendMessage,
        retryMessage,
        retryableTurn,
        retryAgentTurn,
        loadOlderMessages,
    } = useChat({ enabled, locale });

    const launcherRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const closeButtonRef = useRef<HTMLButtonElement>(null);
    const wasOpenRef = useRef(isOpen);

    const [isVisible, setIsVisible] = useState(isOpen);
    const [isMobileDialog, setIsMobileDialog] = useState(() =>
        matchesMobileDialog(surface),
    );

    const isReducedMotion =
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    useEffect(() => {
        const updateMobileDialog = () => {
            setIsMobileDialog(matchesMobileDialog(surface));
        };
        const mediaQuery =
            typeof window.matchMedia === 'function'
                ? window.matchMedia(mobileDialogQuery(surface))
                : null;
        let removeMediaQueryListener = () => {};

        if (
            typeof mediaQuery?.addEventListener === 'function' &&
            typeof mediaQuery.removeEventListener === 'function'
        ) {
            mediaQuery.addEventListener('change', updateMobileDialog);
            removeMediaQueryListener = () => {
                mediaQuery.removeEventListener('change', updateMobileDialog);
            };
        } else if (
            typeof mediaQuery?.addListener === 'function' &&
            typeof mediaQuery.removeListener === 'function'
        ) {
            mediaQuery.addListener(updateMobileDialog);
            removeMediaQueryListener = () => {
                mediaQuery.removeListener(updateMobileDialog);
            };
        }

        window.addEventListener('resize', updateMobileDialog);
        updateMobileDialog();

        return () => {
            removeMediaQueryListener();
            window.removeEventListener('resize', updateMobileDialog);
        };
    }, [surface]);

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

    // Focus restoration to launcher on close
    useEffect(() => {
        if (wasOpenRef.current && !isOpen) {
            launcherRef.current?.focus();
        }

        wasOpenRef.current = isOpen;
    }, [isOpen]);

    useEffect(() => {
        if (isOpen && isMobileDialog) {
            closeButtonRef.current?.focus({ preventScroll: true });
        }
    }, [isMobileDialog, isOpen]);

    // Handle Escape key
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && isOpen) {
                closeChat();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isOpen, closeChat]);

    if (!isChatEnabled) {
        return null;
    }

    const handleDialogKeyDown = (
        event: React.KeyboardEvent<HTMLDivElement>,
    ) => {
        if (!isOpen || !isMobileDialog || event.key !== 'Tab') {
            return;
        }

        const panel = panelRef.current;

        if (panel === null) {
            return;
        }

        const focusableElements = Array.from(
            panel.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
        ).filter((element) => element.getAttribute('aria-hidden') !== 'true');

        if (focusableElements.length === 0) {
            event.preventDefault();
            closeButtonRef.current?.focus({ preventScroll: true });

            return;
        }

        const first = focusableElements[0];
        const last = focusableElements[focusableElements.length - 1];
        const activeElement = document.activeElement;

        if (
            event.shiftKey &&
            (activeElement === first || !panel.contains(activeElement))
        ) {
            event.preventDefault();
            last.focus({ preventScroll: true });
        } else if (
            !event.shiftKey &&
            (activeElement === last || !panel.contains(activeElement))
        ) {
            event.preventDefault();
            first.focus({ preventScroll: true });
        }
    };

    return (
        <div
            className={`chat-widget-root fixed right-4 bottom-4 z-50 font-sans sm:right-6 sm:bottom-6 ${
                surface === 'account' ? 'chat-widget-root--account' : ''
            }`}
            dir={locale === 'en' ? 'ltr' : 'rtl'}
        >
            {/* Screen reader live announcements */}
            <div
                aria-live="polite"
                aria-atomic="true"
                className="sr-only"
                role="status"
            >
                {statusAnnouncement !== null && (
                    <span key={statusAnnouncement.id}>
                        {statusAnnouncement.message}
                    </span>
                )}
            </div>

            {/* Chat Panel / Sheet */}
            {isMounted && (
                <div
                    ref={panelRef}
                    role="dialog"
                    aria-modal={isOpen && isMobileDialog}
                    aria-label={
                        locale === 'en'
                            ? 'Arab UT Chat Assistant'
                            : 'شات مساعد عرب التيميت'
                    }
                    onKeyDown={handleDialogKeyDown}
                    className={`chat-widget-dialog fixed inset-0 z-[70] flex origin-bottom flex-col bg-[var(--arabut-navy)] transition-[transform,opacity] motion-reduce:transition-none sm:inset-auto sm:right-6 sm:bottom-24 sm:h-[650px] sm:max-h-[85vh] sm:w-[420px] sm:origin-bottom-right sm:overflow-hidden sm:rounded-3xl sm:border sm:border-[var(--arabut-line)] sm:shadow-2xl ${
                        isVisible
                            ? 'pointer-events-auto translate-y-0 scale-100 opacity-100 duration-[280ms] [transition-timing-function:cubic-bezier(0.16,1,0.3,1)]'
                            : 'pointer-events-none translate-y-3 scale-[0.98] opacity-0 duration-[180ms] [transition-timing-function:cubic-bezier(0.7,0,0.84,0)] sm:scale-[0.96]'
                    }`}
                >
                    {/* Header */}
                    <ChatHeader
                        canRestart={canRestart}
                        closeButtonRef={closeButtonRef}
                        isRestarting={isRestarting}
                        locale={locale}
                        onClose={closeChat}
                        onRestart={restartChat}
                    />

                    {/* Error Banner if any */}
                    {error !== null && (
                        <div
                            key={errorAnnouncementId}
                            aria-atomic="true"
                            className="flex items-center justify-between border-b border-[var(--arabut-danger)]/30 bg-[var(--arabut-danger)]/10 px-4 py-2 text-xs text-[var(--arabut-danger)]"
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

                    {/* Message List */}
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
