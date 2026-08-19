import { AlertCircle, ArrowDown, RefreshCw } from 'lucide-react';
import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import { groupChatMessages } from '@/lib/chat-grouping';
import type { ChatMessage } from '@/types/chat';
import { TypingIndicator } from './typing-indicator';

type ChatMessageListProps = {
    messages: ChatMessage[];
    isLoading: boolean;
    isAssistantTyping: boolean;
    hasMore: boolean;
    isLoadingOlder: boolean;
    locale?: string;
    onLoadOlder: () => void;
    onSelectSuggestion: (text: string) => void;
    onRetry: (tempId: string) => void;
};

const SUGGESTIONS = {
    ar: ['الأسعار', 'الخدمات', 'متابعة الطلب', 'الدعم'],
    en: ['Prices', 'Services', 'Track Order', 'Support'],
};

export const ChatMessageList: React.FC<ChatMessageListProps> = ({
    messages,
    isLoading,
    isAssistantTyping,
    hasMore,
    isLoadingOlder,
    locale = 'ar',
    onLoadOlder,
    onSelectSuggestion,
    onRetry,
}) => {
    const isEn = locale === 'en';
    const suggestions = isEn ? SUGGESTIONS.en : SUGGESTIONS.ar;
    const hasCustomerMessages = messages.some(
        (m) => m.senderType === 'customer',
    );

    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const bottomSentinelRef = useRef<HTMLDivElement>(null);
    const [showScrollBottom, setShowScrollBottom] = useState(false);
    const prevMessagesLengthRef = useRef(messages.length);

    const checkScrollPosition = () => {
        const container = scrollContainerRef.current;

        if (!container) {
            return;
        }

        const distanceFromBottom =
            container.scrollHeight -
            container.scrollTop -
            container.clientHeight;
        setShowScrollBottom(distanceFromBottom > 120);
    };

    const handleScrollToBottomClick = () => {
        bottomSentinelRef.current?.scrollIntoView({ behavior: 'smooth' });
        setShowScrollBottom(false);
    };

    useEffect(() => {
        const container = scrollContainerRef.current;

        if (!container) {
            return;
        }

        const isNewMessage = messages.length > prevMessagesLengthRef.current;
        prevMessagesLengthRef.current = messages.length;

        if (isNewMessage) {
            const distanceFromBottom =
                container.scrollHeight -
                container.scrollTop -
                container.clientHeight;

            if (distanceFromBottom < 180) {
                bottomSentinelRef.current?.scrollIntoView({
                    behavior: 'smooth',
                });
            }
        }
    }, [messages.length, isAssistantTyping]);

    // Initial scroll on mount or loading complete
    useEffect(() => {
        if (!isLoading && messages.length > 0) {
            bottomSentinelRef.current?.scrollIntoView({ behavior: 'auto' });
        }
    }, [isLoading, messages.length]);

    const clusters = groupChatMessages(messages);

    return (
        <div className="relative flex flex-1 flex-col overflow-hidden bg-[var(--arabut-navy)]">
            <div
                ref={scrollContainerRef}
                onScroll={checkScrollPosition}
                role="log"
                aria-live="polite"
                tabIndex={0}
                className="flex-1 space-y-4 overflow-y-auto p-4 focus-visible:outline-none"
            >
                {/* Older messages loader button */}
                {hasMore && (
                    <div className="flex justify-center pt-1 pb-2">
                        <button
                            type="button"
                            onClick={onLoadOlder}
                            disabled={isLoadingOlder}
                            className="rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-3 py-1 text-xs text-[var(--arabut-muted)] hover:text-[var(--arabut-ink)] disabled:opacity-50"
                        >
                            {isLoadingOlder
                                ? isEn
                                    ? 'Loading older messages...'
                                    : 'جاري تحميل الرسائل السابقة...'
                                : isEn
                                  ? 'Load older messages'
                                  : 'تحميل الرسائل السابقة'}
                        </button>
                    </div>
                )}

                {/* Initial loading state */}
                {isLoading && (
                    <div className="flex h-32 items-center justify-center">
                        <span className="animate-pulse text-sm text-[var(--arabut-muted)]">
                            {isEn ? 'Loading chat...' : 'جاري فتح الشات...'}
                        </span>
                    </div>
                )}

                {/* Grouped message clusters */}
                {clusters.map((cluster) => {
                    const isCustomer = cluster.senderType === 'customer';
                    const isSystem = cluster.senderType === 'system';

                    if (isSystem) {
                        return (
                            <div
                                key={cluster.id}
                                className="my-2 flex justify-center"
                            >
                                <div className="max-w-[85%] rounded-xl border border-[var(--arabut-line)] bg-[var(--arabut-navy-deep)] px-3.5 py-2 text-center text-xs leading-relaxed text-[var(--arabut-muted)] shadow-sm">
                                    {cluster.messages.map((m) => (
                                        <p key={m.publicId}>{m.content}</p>
                                    ))}
                                </div>
                            </div>
                        );
                    }

                    return (
                        <div
                            key={cluster.id}
                            className={`flex flex-col gap-1 ${isCustomer ? 'items-end' : 'items-start'}`}
                        >
                            {cluster.messages.map((message, idx) => {
                                const isLastInCluster =
                                    idx === cluster.messages.length - 1;
                                const isSending =
                                    message.clientStatus === 'sending';
                                const isError =
                                    message.clientStatus === 'error';

                                return (
                                    <div
                                        key={message.publicId}
                                        className={`group relative max-w-[82%] text-start ${isCustomer ? 'items-end' : 'items-start'}`}
                                    >
                                        <div
                                            className={`rounded-2xl px-4 py-2.5 text-sm leading-relaxed transition-opacity ${
                                                isCustomer
                                                    ? 'rounded-br-sm bg-[var(--arabut-gold)] font-medium text-[var(--arabut-navy-deep)]'
                                                    : 'rounded-bl-sm border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] text-[var(--arabut-ink)] shadow-md'
                                            } ${isSending ? 'opacity-70' : ''}`}
                                        >
                                            <p className="break-words whitespace-pre-wrap">
                                                {message.content}
                                            </p>
                                        </div>

                                        {/* Status / retry for customer messages */}
                                        {isCustomer && isError && (
                                            <div className="mt-1 flex items-center gap-1.5 text-xs text-[var(--arabut-danger)]">
                                                <AlertCircle className="h-3.5 w-3.5" />
                                                <span>
                                                    {isEn
                                                        ? 'Failed to send'
                                                        : 'تعذر الإرسال'}
                                                </span>
                                                {message.tempId && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            onRetry(
                                                                message.tempId!,
                                                            )
                                                        }
                                                        className="inline-flex items-center gap-0.5 underline hover:text-[var(--arabut-ink)]"
                                                    >
                                                        <RefreshCw className="h-3 w-3" />
                                                        {isEn
                                                            ? 'Retry'
                                                            : 'إعادة المحاولة'}
                                                    </button>
                                                )}
                                            </div>
                                        )}

                                        {/* Timestamp on last message of cluster */}
                                        {isLastInCluster && !isError && (
                                            <div className="mt-1 px-1 text-[10px] text-[var(--arabut-muted)] opacity-60 transition-opacity group-hover:opacity-100">
                                                {new Date(
                                                    message.createdAt,
                                                ).toLocaleTimeString(
                                                    isEn ? 'en-US' : 'ar-SA',
                                                    {
                                                        hour: '2-digit',
                                                        minute: '2-digit',
                                                    },
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    );
                })}

                {/* Assistant typing indicator */}
                {isAssistantTyping && (
                    <div className="flex items-start">
                        <TypingIndicator locale={locale} />
                    </div>
                )}

                {/* Suggestion Chips (shown when no customer message exists yet) */}
                {!hasCustomerMessages && (
                    <div className="pt-2 pb-1">
                        <p className="mb-2 text-xs font-medium text-[var(--arabut-muted)]">
                            {isEn ? 'Suggested topics:' : 'المواضيع المقترحة:'}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {suggestions.map((suggestion) => (
                                <button
                                    key={suggestion}
                                    type="button"
                                    onClick={() =>
                                        onSelectSuggestion(suggestion)
                                    }
                                    className="rounded-xl border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-3 py-1.5 text-xs text-[var(--arabut-ink)] transition-colors hover:border-[var(--arabut-gold)]/50 hover:bg-[var(--arabut-navy-active)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] active:scale-95"
                                >
                                    {suggestion}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                <div ref={bottomSentinelRef} />
            </div>

            {/* Floating scroll down pill */}
            {showScrollBottom && (
                <button
                    type="button"
                    onClick={handleScrollToBottomClick}
                    aria-label={isEn ? 'Scroll to bottom' : 'الانتقال لأسفل'}
                    className="absolute start-1/2 bottom-4 flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-deep)]/95 px-3.5 py-1.5 text-xs font-semibold text-[var(--arabut-gold-bright)] shadow-xl backdrop-blur-sm transition-transform hover:scale-105 active:scale-95"
                >
                    <ArrowDown className="h-3.5 w-3.5" />
                    <span>{isEn ? 'New messages' : 'رسائل جديدة'}</span>
                </button>
            )}
        </div>
    );
};
