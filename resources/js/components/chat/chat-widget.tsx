import React, { useEffect, useRef } from 'react';
import { useChat } from '@/hooks/use-chat';
import { ChatComposer } from './chat-composer';
import { ChatHeader } from './chat-header';
import { ChatLauncher } from './chat-launcher';
import { ChatMessageList } from './chat-message-list';

type ChatWidgetProps = {
    locale?: string;
};

export const ChatWidget: React.FC<ChatWidgetProps> = ({ locale = 'ar' }) => {
    const {
        isChatEnabled,
        isOpen,
        toggleOpen,
        closeChat,
        messages,
        isLoading,
        isSending,
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
    } = useChat({ locale });

    const launcherRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const wasOpenRef = useRef(isOpen);

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
        <div className="fixed end-5 bottom-5 z-50 font-sans" dir="auto">
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
            {isOpen && (
                <div
                    ref={panelRef}
                    role="dialog"
                    aria-modal="false"
                    aria-label={
                        locale === 'en'
                            ? 'Arab UT Chat Assistant'
                            : 'شات مساعد عرب التيميت'
                    }
                    className={`fixed inset-0 z-50 flex flex-col bg-[var(--arabut-navy)] transition-all duration-200 ease-out motion-reduce:transition-none sm:inset-auto sm:end-0 sm:bottom-20 sm:h-[650px] sm:max-h-[85vh] sm:w-[420px] sm:overflow-hidden sm:rounded-3xl sm:border sm:border-[var(--arabut-line)] sm:shadow-2xl ${
                        isOpen ? 'scale-100 opacity-100' : 'scale-95 opacity-0'
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
                        isSending={isSending}
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
