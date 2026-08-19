import React, { useEffect, useRef, useState } from 'react';
import { useChat } from '@/hooks/use-chat';
import { ChatComposer } from './chat-composer';
import { ChatHeader } from './chat-header';
import { ChatLauncher } from './chat-launcher';
import { ChatMessageList } from './chat-message-list';

export type ChatWidgetProps = {
    enabled?: boolean;
    demoAssistant?: boolean;
    locale?: string;
};

export const ChatWidget: React.FC<ChatWidgetProps> = ({
    enabled,
    demoAssistant,
    locale = 'ar',
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
        hasMore,
        unreadCount,
        error,
        clearError,
        statusAnnouncement,
        sendMessage,
        retryMessage,
        loadOlderMessages,
    } = useChat({ enabled, demoAssistant, locale });

    const launcherRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const wasOpenRef = useRef(isOpen);

    const [isVisible, setIsVisible] = useState(isOpen);

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
                isReducedMotion ? 0 : 200,
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

    return (
        <div
            className="fixed right-4 bottom-4 z-50 font-sans sm:right-6 sm:bottom-6"
            dir={locale === 'en' ? 'ltr' : 'rtl'}
        >
            {/* Screen reader live announcements */}
            <div
                aria-live="polite"
                aria-atomic="true"
                className="sr-only"
                role="status"
            >
                {statusAnnouncement}
            </div>

            {/* Chat Panel / Sheet */}
            {isMounted && (
                <div
                    ref={panelRef}
                    role="dialog"
                    aria-modal="false"
                    aria-label={
                        locale === 'en'
                            ? 'Arab UT Chat Assistant'
                            : 'شات مساعد عرب التيميت'
                    }
                    className={`fixed inset-0 z-50 flex flex-col bg-[var(--arabut-navy)] transition-all duration-200 ease-out motion-reduce:transition-none sm:inset-auto sm:right-0 sm:bottom-20 sm:h-[650px] sm:max-h-[85vh] sm:w-[420px] sm:overflow-hidden sm:rounded-3xl sm:border sm:border-[var(--arabut-line)] sm:shadow-2xl ${
                        isVisible
                            ? 'translate-y-0 opacity-100 sm:scale-100'
                            : 'translate-y-full opacity-0 sm:translate-y-4 sm:scale-95'
                    }`}
                >
                    {/* Header */}
                    <ChatHeader locale={locale} onClose={closeChat} />

                    {/* Error Banner if any */}
                    {error !== null && (
                        <div className="flex items-center justify-between border-b border-[var(--arabut-danger)]/30 bg-[var(--arabut-danger)]/10 px-4 py-2 text-xs text-[var(--arabut-danger)]">
                            <span>{error}</span>
                            <button
                                type="button"
                                onClick={clearError}
                                className="underline hover:opacity-80"
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
                        locale={locale}
                        onLoadOlder={loadOlderMessages}
                        onSelectSuggestion={sendMessage}
                        onRetry={retryMessage}
                    />

                    {/* Composer */}
                    <ChatComposer
                        disabled={isLoading}
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
