import React, { useEffect, useRef, useState } from 'react';
import { useChat } from '@/hooks/use-chat';
import { chatServiceCards } from '@/lib/chat-cards';
import { chatCartOffer } from '@/lib/chat-cart';
import { fetchChatServicePrices } from '@/lib/chat-service-prices';
import { chatShelfItems } from '@/lib/chat-shelf';
import {
    isChatSoundEnabled,
    playChatNotification,
    setChatSoundEnabled,
} from '@/lib/chat-sound';
import type { ChatServicePrices, ChatSurface } from '@/types/chat';
import { ChatComposer } from './chat-composer';
import { ChatHandoffBanner } from './chat-handoff-banner';
import { ChatHeader } from './chat-header';
import { ChatHome } from './chat-home';
import { ChatLauncher } from './chat-launcher';
import { ChatMessageList } from './chat-message-list';

export type ChatWidgetView = 'home' | 'chat';

export type ChatWidgetProps = {
    enabled?: boolean;
    locale?: string;
    surface?: ChatSurface;
    /** Which view the widget shows when opened. Defaults to the Home screen. */
    initialView?: ChatWidgetView;
    isAuthenticated?: boolean;
    initialOpen?: boolean;
};

const CLOSE_TRANSITION_MS = 180;
const VIEW_TRANSITION_MS = 240;
const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

/** Share of the visual viewport the mobile bottom sheet occupies. */
export const SHEET_HEIGHT_RATIO = 0.88;
/** Drag distance (px) or velocity (px/ms) that dismisses the sheet. */
const SHEET_DISMISS_DISTANCE = 110;
const SHEET_DISMISS_VELOCITY = 0.6;

/**
 * Bottom-sheet geometry for the current visual viewport. With a keyboard
 * open the sheet takes the whole remaining viewport; otherwise it leaves a
 * strip of the page visible above it, like a native sheet.
 */
export function mobileSheetGeometry(
    viewport: { offsetTop: number; height: number },
    layoutHeight: number,
): { top: number; height: number; keyboardOpen: boolean } {
    const keyboardOpen = viewport.height < layoutHeight * 0.75;
    const height = keyboardOpen
        ? viewport.height
        : Math.round(viewport.height * SHEET_HEIGHT_RATIO);

    return {
        top: viewport.offsetTop + viewport.height - height,
        height,
        keyboardOpen,
    };
}

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
    initialView = 'home',
    isAuthenticated = false,
    initialOpen = false,
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
        historyConversations,
        isReadOnly,
        loadHistory,
        openPastConversation,
        leaveReadOnlyConversation,
        requestTicket,
    } = useChat({ enabled, locale, defaultOpen: initialOpen });

    const launcherRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const closeButtonRef = useRef<HTMLButtonElement>(null);
    const wasOpenRef = useRef(isOpen);

    const [isVisible, setIsVisible] = useState(isOpen || initialOpen);
    const isMounted = isOpen || isVisible;
    const [isMobileDialog, setIsMobileDialog] = useState(() =>
        matchesMobileDialog(surface),
    );

    const isReducedMotion =
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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

    // Previous conversations are fetched the first time the customer actually
    // reaches the home view, not on page load — see loadHistory in use-chat.
    const historyRequestedRef = useRef(false);

    useEffect(() => {
        if (
            !isAuthenticated ||
            !isOpen ||
            view !== 'home' ||
            historyRequestedRef.current
        ) {
            return;
        }

        historyRequestedRef.current = true;
        void loadHistory();
    }, [isAuthenticated, isOpen, view, loadHistory]);

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

    // Notification sound: chime once per newly arrived assistant message,
    // never for history loaded on open and never while a reply is streaming.
    const [soundEnabled, setSoundEnabled] = useState(() =>
        isChatSoundEnabled(),
    );
    const lastNotifiedIdRef = useRef<string | null>(null);
    const toggleSound = () => {
        setSoundEnabled((current) => {
            setChatSoundEnabled(!current);

            return !current;
        });
    };

    useEffect(() => {
        const latestAssistant = [...messages]
            .reverse()
            .find(
                (m) =>
                    m.senderType === 'assistant' &&
                    m.streamStatus !== 'streaming',
            );
        const latestId = latestAssistant?.publicId ?? null;

        if (isLoading || latestId === null) {
            return;
        }

        if (lastNotifiedIdRef.current === null) {
            // First settled list (history): remember, do not chime.
            lastNotifiedIdRef.current = latestId;

            return;
        }

        if (latestId !== lastNotifiedIdRef.current) {
            lastNotifiedIdRef.current = latestId;

            if (soundEnabled) {
                playChatNotification();
            }
        }
    }, [isLoading, messages, soundEnabled]);

    const lastMessage = messages[messages.length - 1] ?? null;
    const hasCustomerMessages = messages.some(
        (m) => m.senderType === 'customer',
    );
    const homeLastMessage =
        lastMessage !== null
            ? { preview: lastMessage.content, createdAt: lastMessage.createdAt }
            : null;
    const showDisclaimer = conversation?.assistantMode === 'agent';

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

    // On phones the dialog is a bottom sheet pinned to the *visual* viewport
    // (position: fixed follows the layout viewport, which iOS scrolls when a
    // keyboard opens). Lock the page, track window.visualViewport, and resync
    // for ~800 ms after any focus change because iOS often settles the
    // keyboard animation without a final `scroll`/`resize` event. When the
    // keyboard closes iOS can leave the visual viewport scrolled, so the
    // sync also resets that offset.
    useEffect(() => {
        const panel = panelRef.current;
        const viewport = window.visualViewport;

        if (!isOpen || !isMobileDialog || panel === null) {
            return;
        }

        const root = document.documentElement;
        const body = document.body;
        const scrollY = window.scrollY;
        root.classList.add('chat-scroll-lock');
        body.style.top = `-${scrollY}px`;

        const releaseScrollLock = () => {
            root.classList.remove('chat-scroll-lock');
            body.style.removeProperty('top');
            window.scrollTo(0, scrollY);
        };

        if (!viewport) {
            return releaseScrollLock;
        }

        const sync = () => {
            const geometry = mobileSheetGeometry(viewport, window.innerHeight);

            if (!geometry.keyboardOpen && viewport.offsetTop > 0) {
                // Keyboard is gone but iOS left the viewport scrolled.
                window.scrollTo(0, 0);
                geometry.top -= viewport.offsetTop;
            }

            panel.style.setProperty('--chat-vv-top', `${geometry.top}px`);
            panel.style.setProperty('--chat-vv-height', `${geometry.height}px`);
            panel.classList.add('chat-widget-dialog--viewport-tracked');
        };

        const timers: number[] = [];
        const resyncDuringKeyboardAnimation = () => {
            for (const delay of [50, 150, 300, 500, 800]) {
                timers.push(window.setTimeout(sync, delay));
            }
        };

        sync();
        viewport.addEventListener('resize', sync);
        viewport.addEventListener('scroll', sync);
        panel.addEventListener('focusin', resyncDuringKeyboardAnimation);
        panel.addEventListener('focusout', resyncDuringKeyboardAnimation);

        return () => {
            for (const timer of timers) {
                window.clearTimeout(timer);
            }

            viewport.removeEventListener('resize', sync);
            viewport.removeEventListener('scroll', sync);
            panel.removeEventListener('focusin', resyncDuringKeyboardAnimation);
            panel.removeEventListener(
                'focusout',
                resyncDuringKeyboardAnimation,
            );
            panel.classList.remove('chat-widget-dialog--viewport-tracked');
            panel.style.removeProperty('--chat-vv-top');
            panel.style.removeProperty('--chat-vv-height');
            releaseScrollLock();
        };
    }, [isMobileDialog, isOpen]);

    // Swipe down to dismiss the mobile sheet. Native listeners because React
    // registers touch events as passive and we must prevent the inner list
    // from scrolling while the sheet follows the finger.
    const closeChatRef = useRef(closeChat);

    useEffect(() => {
        closeChatRef.current = closeChat;
    }, [closeChat]);

    useEffect(() => {
        const panel = panelRef.current;

        if (!isOpen || !isMobileDialog || panel === null) {
            return;
        }

        let startY = 0;
        let startX = 0;
        let startedAt = 0;
        let dragging = false;
        let eligible = false;
        // Set once a swipe has committed to closing: the cleanup below must
        // then leave the outgoing transform alone so the sheet can finish
        // sliding away instead of snapping back.
        let dismissing = false;

        const onTouchStart = (event: TouchEvent) => {
            const touch = event.touches[0];
            const target = event.target as Element | null;
            const scroller = target?.closest('.overflow-y-auto');
            eligible = !scroller || scroller.scrollTop <= 0;
            dragging = false;
            startY = touch.clientY;
            startX = touch.clientX;
            startedAt = event.timeStamp;
        };

        const onTouchMove = (event: TouchEvent) => {
            if (!eligible) {
                return;
            }

            const touch = event.touches[0];
            const dy = touch.clientY - startY;
            const dx = touch.clientX - startX;

            if (!dragging) {
                if (dy < 8 || Math.abs(dx) > dy) {
                    if (Math.abs(dx) > 8 || dy < -8) {
                        eligible = false;
                    }

                    return;
                }

                dragging = true;
                panel.classList.add('chat-widget-dialog--dragging');
            }

            event.preventDefault();
            panel.style.transform = `translateY(${Math.max(0, dy)}px)`;
        };

        const finish = (event: TouchEvent) => {
            if (!dragging) {
                return;
            }

            dragging = false;
            eligible = false;
            const touch = event.changedTouches[0];
            const dy = Math.max(0, touch.clientY - startY);
            const velocity = dy / Math.max(1, event.timeStamp - startedAt);
            panel.classList.remove('chat-widget-dialog--dragging');

            if (
                dy > SHEET_DISMISS_DISTANCE ||
                velocity > SHEET_DISMISS_VELOCITY
            ) {
                // Keep travelling down from wherever the finger let go
                // instead of snapping back to the top first.
                dismissing = true;
                panel.classList.add('chat-widget-dialog--dismissing');
                panel.style.transform = 'translateY(100%)';
                closeChatRef.current();

                return;
            }

            // Not far enough: spring back to the resting position.
            panel.style.removeProperty('transform');
        };

        panel.addEventListener('touchstart', onTouchStart, { passive: true });
        panel.addEventListener('touchmove', onTouchMove, { passive: false });
        panel.addEventListener('touchend', finish);
        panel.addEventListener('touchcancel', finish);

        return () => {
            panel.removeEventListener('touchstart', onTouchStart);
            panel.removeEventListener('touchmove', onTouchMove);
            panel.removeEventListener('touchend', finish);
            panel.removeEventListener('touchcancel', finish);
            panel.classList.remove('chat-widget-dialog--dragging');

            if (!dismissing) {
                panel.classList.remove('chat-widget-dialog--dismissing');
                panel.style.removeProperty('transform');
            }
        };
    }, [isMobileDialog, isOpen]);

    // Service-card prices are fetched once, and only after a reply actually
    // offers a card, so a page that never shows one pays nothing. They are not
    // stored on the message: chat history is permanent and a stored price would
    // go stale.
    const [servicePrices, setServicePrices] = useState<ChatServicePrices>({});
    const hasServiceCard = messages.some(
        (message) =>
            message.senderType === 'assistant' &&
            (chatServiceCards(message).length > 0 ||
                chatShelfItems(message).length > 0 ||
                chatCartOffer(message) !== null),
    );

    useEffect(() => {
        if (!hasServiceCard || Object.keys(servicePrices).length > 0) {
            return;
        }

        let cancelled = false;

        void fetchChatServicePrices().then((prices) => {
            if (!cancelled) {
                setServicePrices(prices);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [hasServiceCard, servicePrices]);

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
                    // Reset to the initial view once the widget fully closes.
                    setView(initialView);
                    setExitingView(null);
                },
                isReducedMotion ? 0 : CLOSE_TRANSITION_MS,
            );

            return () => clearTimeout(timeout);
        }
    }, [initialView, isOpen, isVisible, isReducedMotion]);

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
            className={`chat-widget-root fixed right-4 bottom-4 z-50 sm:right-6 sm:bottom-6 ${
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

            {/* Dimmed page behind the mobile sheet; tap to close */}
            {isMounted && isMobileDialog && (
                <div
                    data-testid="chat-widget-backdrop"
                    aria-hidden="true"
                    onClick={closeChat}
                    className={`chat-widget-backdrop fixed inset-0 z-[69] bg-black/45 transition-opacity motion-reduce:transition-none ${
                        isVisible
                            ? 'pointer-events-auto opacity-100 duration-[280ms]'
                            : 'pointer-events-none opacity-0 duration-[180ms]'
                    }`}
                />
            )}

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
                    data-view-direction={viewDirection}
                    onKeyDown={handleDialogKeyDown}
                    className={`chat-widget-dialog ${isMobileDialog ? 'chat-widget-dialog--sheet' : ''} fixed inset-0 z-[70] flex origin-bottom flex-col bg-[var(--chat-surface)] transition-[transform,opacity] motion-reduce:transition-none sm:inset-auto sm:right-6 sm:bottom-24 sm:h-[650px] sm:max-h-[85vh] sm:w-[420px] sm:origin-bottom-right sm:overflow-hidden sm:rounded-3xl sm:border sm:border-[var(--arabut-line)] sm:shadow-2xl ${
                        isVisible
                            ? 'pointer-events-auto translate-y-0 scale-100 opacity-100 duration-[280ms] [transition-timing-function:cubic-bezier(0.16,1,0.3,1)]'
                            : 'pointer-events-none translate-y-3 scale-[0.98] opacity-0 duration-[180ms] [transition-timing-function:cubic-bezier(0.7,0,0.84,0)] sm:scale-[0.96]'
                    }`}
                >
                    {isMobileDialog && (
                        <div
                            aria-hidden="true"
                            className="chat-sheet-handle pointer-events-none absolute top-1.5 left-1/2 z-10 h-1.5 w-10 -translate-x-1/2 rounded-full bg-[var(--chat-line-strong)]"
                        />
                    )}
                    {(view === 'home' || exitingView === 'home') && (
                        <div
                            key="home"
                            className={`absolute inset-0 flex flex-col ${
                                view === 'home'
                                    ? 'chat-view-enter'
                                    : 'chat-view-exit'
                            }`}
                            aria-hidden={view !== 'home'}
                        >
                            <ChatHome
                                locale={locale}
                                hasConversation={hasCustomerMessages}
                                lastMessage={homeLastMessage}
                                conversations={historyConversations}
                                disabled={isLoading || isRestarting}
                                isMobileDialog={isMobileDialog}
                                closeButtonRef={
                                    view === 'home' ? closeButtonRef : undefined
                                }
                                onClose={closeChat}
                                onStart={() => switchView('chat')}
                                onContinue={() => switchView('chat')}
                                onSelectTopic={(label) => {
                                    sendMessage(label);
                                    switchView('chat');
                                }}
                                onSelectConversation={(publicId) => {
                                    void openPastConversation(publicId);
                                    switchView('chat');
                                }}
                            />
                        </div>
                    )}

                    {(view === 'chat' || exitingView === 'chat') && (
                        <div
                            key="chat"
                            className={`absolute inset-0 flex flex-col ${
                                view === 'chat'
                                    ? 'chat-view-enter'
                                    : 'chat-view-exit'
                            }`}
                            aria-hidden={view !== 'chat'}
                        >
                            <ChatHeader
                                canRestart={canRestart && !isReadOnly}
                                closeButtonRef={
                                    view === 'chat' ? closeButtonRef : undefined
                                }
                                isRestarting={isRestarting}
                                locale={locale}
                                onBack={() => switchView('home')}
                                onClose={closeChat}
                                onRestart={restartChat}
                                soundEnabled={soundEnabled}
                                onToggleSound={toggleSound}
                            />

                            {isReadOnly && (
                                <div
                                    dir="auto"
                                    data-testid="chat-read-only-notice"
                                    className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--chat-line)] bg-[var(--chat-tint)] px-4 py-2.5 text-start"
                                >
                                    <span className="text-[12.5px] text-[var(--chat-muted)]">
                                        {locale === 'en'
                                            ? 'You are reading an earlier conversation.'
                                            : 'أنت تطالع محادثة سابقة.'}
                                    </span>
                                    <button
                                        type="button"
                                        className="chat-press inline-flex min-h-11 items-center rounded-xl px-3 text-[13px] font-semibold text-[var(--chat-hero)] hover:bg-[var(--chat-card)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                                        onClick={() => {
                                            void leaveReadOnlyConversation();
                                        }}
                                    >
                                        {locale === 'en'
                                            ? 'Start a new conversation'
                                            : 'ابدأ محادثة جديدة'}
                                    </button>
                                </div>
                            )}

                            {conversation?.handoffState &&
                                conversation.handoffState !== 'none' && (
                                    <ChatHandoffBanner
                                        handoffState={conversation.handoffState}
                                        ticket={conversation.ticket}
                                        locale={locale}
                                        disabled={isLoading || isRestarting}
                                        onRequestNewTicket={requestTicket}
                                    />
                                )}

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
                                servicePrices={servicePrices}
                                handoffState={conversation?.handoffState}
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
                                onCardNavigate={
                                    // The phone sheet covers the page it just
                                    // opened, so tapping a card steps aside.
                                    isMobileDialog ? closeChat : undefined
                                }
                                onChoose={sendMessage}
                            />

                            <ChatComposer
                                disabled={
                                    isLoading || isRestarting || isReadOnly
                                }
                                locale={locale}
                                onSend={sendMessage}
                                showDisclaimer={showDisclaimer}
                            />
                        </div>
                    )}
                </div>
            )}

            {/* Floating Launcher Button */}
            <ChatLauncher
                ref={launcherRef}
                isOpen={isOpen}
                unreadCount={unreadCount}
                locale={locale}
                canGreet={surface !== 'account'}
                onToggle={toggleOpen}
            />
        </div>
    );
};
