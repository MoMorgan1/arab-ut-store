import { AlertCircle, ArrowDown, RefreshCw } from 'lucide-react';
import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import { groupChatMessages } from '@/lib/chat-grouping';
import { chatTopicsFor } from '@/lib/chat-topics';
import type { AgentTurnState, ChatMessage } from '@/types/chat';
import { TypingIndicator } from './typing-indicator';

type ChatMessageListProps = {
    disabled?: boolean;
    messages: ChatMessage[];
    isLoading: boolean;
    isAssistantTyping: boolean;
    hasMore: boolean;
    isLoadingOlder: boolean;
    locale?: string;
    onLoadOlder: () => void;
    onSelectSuggestion: (text: string) => void;
    onRetry: (tempId: string) => void;
    retryableTurn?: AgentTurnState | null;
    onRetryAgentTurn?: () => void;
};

export const ChatMessageList: React.FC<ChatMessageListProps> = ({
    disabled = false,
    messages,
    isLoading,
    isAssistantTyping,
    hasMore,
    isLoadingOlder,
    locale = 'ar',
    onLoadOlder,
    onSelectSuggestion,
    onRetry,
    retryableTurn,
    onRetryAgentTurn,
}) => {
    const isEn = locale === 'en';
    const suggestions = chatTopicsFor(locale).map((topic) => topic.label);
    const hasCustomerMessages = messages.some(
        (m) => m.senderType === 'customer',
    );

    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const bottomSentinelRef = useRef<HTMLDivElement>(null);
    const [showScrollBottom, setShowScrollBottom] = useState(false);

    const prevOldestIdRef = useRef<string | null>(
        messages[0]?.publicId ?? null,
    );
    const prevNewestIdRef = useRef<string | null>(
        messages[messages.length - 1]?.publicId ?? null,
    );
    const scrollSnapshotRef = useRef<{
        scrollHeight: number;
        scrollTop: number;
    } | null>(null);
    const isInitialScrollDoneRef = useRef(false);

    const isReducedMotion =
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
        bottomSentinelRef.current?.scrollIntoView({
            behavior: isReducedMotion ? 'auto' : 'smooth',
        });
        setShowScrollBottom(false);
    };

    const handleLoadOlderClick = () => {
        const container = scrollContainerRef.current;

        if (container) {
            scrollSnapshotRef.current = {
                scrollHeight: container.scrollHeight,
                scrollTop: container.scrollTop,
            };
        }

        onLoadOlder();
    };

    useEffect(() => {
        const container = scrollContainerRef.current;

        if (!container || messages.length === 0) {
            return;
        }

        const currentOldestId = messages[0]?.publicId ?? null;
        const currentNewestId = messages[messages.length - 1]?.publicId ?? null;

        // Case 1: Initial load / mount
        if (!isInitialScrollDoneRef.current && !isLoading) {
            bottomSentinelRef.current?.scrollIntoView({ behavior: 'auto' });
            isInitialScrollDoneRef.current = true;
            prevOldestIdRef.current = currentOldestId;
            prevNewestIdRef.current = currentNewestId;

            return;
        }

        // Case 2: Prepending older messages -> preserve scroll anchor
        if (
            scrollSnapshotRef.current !== null &&
            currentOldestId !== prevOldestIdRef.current
        ) {
            const { scrollHeight: oldHeight, scrollTop: oldTop } =
                scrollSnapshotRef.current;
            const newHeight = container.scrollHeight;
            container.scrollTop = newHeight - oldHeight + oldTop;
            scrollSnapshotRef.current = null;
            prevOldestIdRef.current = currentOldestId;
            prevNewestIdRef.current = currentNewestId;

            return;
        }

        // Case 3: Appending new messages -> scroll to bottom if close
        if (currentNewestId !== prevNewestIdRef.current) {
            const distanceFromBottom =
                container.scrollHeight -
                container.scrollTop -
                container.clientHeight;
            const lastMessage = messages[messages.length - 1];

            if (
                distanceFromBottom < 180 ||
                lastMessage?.senderType === 'customer'
            ) {
                bottomSentinelRef.current?.scrollIntoView({
                    behavior: isReducedMotion ? 'auto' : 'smooth',
                });
            }

            prevOldestIdRef.current = currentOldestId;
            prevNewestIdRef.current = currentNewestId;
        }
    }, [messages, isLoading, isAssistantTyping, isReducedMotion]);

    const clusters = groupChatMessages(messages);

    return (
        <div className="relative flex flex-1 flex-col overflow-hidden bg-[var(--arabut-navy)]">
            <div
                ref={scrollContainerRef}
                onScroll={checkScrollPosition}
                role="log"
                aria-live="polite"
                tabIndex={0}
                className="flex-1 space-y-4 overflow-y-auto p-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--arabut-focus)]"
            >
                {/* Older messages loader button */}
                {hasMore && (
                    <div className="flex justify-center pt-1 pb-2">
                        <button
                            type="button"
                            onClick={handleLoadOlderClick}
                            disabled={disabled || isLoadingOlder}
                            className="inline-flex min-h-11 items-center justify-center rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-4 py-2 text-xs text-[var(--arabut-muted)] hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50"
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
                                <div
                                    dir="auto"
                                    className="max-w-[85%] rounded-xl border border-[var(--arabut-line)] bg-[var(--arabut-navy-deep)] px-3.5 py-2 text-center text-xs leading-relaxed text-[var(--arabut-muted)] shadow-sm"
                                >
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
                            dir="ltr"
                            className={`flex w-full flex-col gap-1 ${isCustomer ? 'items-end' : 'items-start'}`}
                        >
                            {cluster.messages.map((message, idx) => {
                                const isLastInCluster =
                                    idx === cluster.messages.length - 1;
                                const isSending =
                                    message.clientStatus === 'sending';
                                const isError =
                                    message.clientStatus === 'error';
                                const isStreaming =
                                    message.senderType === 'assistant' &&
                                    message.streamStatus === 'streaming';

                                return (
                                    <div
                                        key={message.publicId}
                                        dir="auto"
                                        className={`group relative max-w-[82%] text-start ${
                                            isCustomer
                                                ? ''
                                                : 'chat-bubble-enter'
                                        }`}
                                    >
                                        <div
                                            data-stream-status={
                                                isStreaming
                                                    ? 'streaming'
                                                    : undefined
                                            }
                                            className={`rounded-2xl px-4 py-2.5 text-sm leading-relaxed transition-opacity ${
                                                isCustomer
                                                    ? 'rounded-br-sm bg-[var(--arabut-gold)] font-medium text-[var(--arabut-navy-deep)]'
                                                    : 'rounded-bl-sm border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] text-[var(--arabut-ink)] shadow-md'
                                            } ${isSending ? 'opacity-70' : ''}`}
                                        >
                                            {isStreaming && (
                                                <span className="sr-only">
                                                    {isEn
                                                        ? 'Assistant is responding'
                                                        : 'المساعد يرد الآن'}
                                                </span>
                                            )}
                                            {isStreaming &&
                                            message.content === '' ? (
                                                <span
                                                    aria-hidden="true"
                                                    className="flex items-center gap-1.5 py-0.5"
                                                >
                                                    <span
                                                        className="h-2 w-2 animate-bounce rounded-full bg-[var(--arabut-gold-bright)] motion-reduce:animate-none"
                                                        style={{
                                                            animationDelay:
                                                                '0ms',
                                                            animationDuration:
                                                                '900ms',
                                                        }}
                                                    />
                                                    <span
                                                        className="h-2 w-2 animate-bounce rounded-full bg-[var(--arabut-gold-bright)] motion-reduce:animate-none"
                                                        style={{
                                                            animationDelay:
                                                                '180ms',
                                                            animationDuration:
                                                                '900ms',
                                                        }}
                                                    />
                                                    <span
                                                        className="h-2 w-2 animate-bounce rounded-full bg-[var(--arabut-gold-bright)] motion-reduce:animate-none"
                                                        style={{
                                                            animationDelay:
                                                                '360ms',
                                                            animationDuration:
                                                                '900ms',
                                                        }}
                                                    />
                                                </span>
                                            ) : (
                                                <p className="break-words whitespace-pre-wrap">
                                                    {message.content}
                                                    {isStreaming &&
                                                        message.content !==
                                                            '' && (
                                                            <span
                                                                aria-hidden="true"
                                                                className="chat-stream-caret"
                                                            />
                                                        )}
                                                </p>
                                            )}
                                        </div>

                                        {/* Status / retry for customer messages */}
                                        {isCustomer && isError && (
                                            <div className="mt-1 flex items-center gap-1.5 text-xs text-[var(--arabut-danger)]">
                                                <AlertCircle
                                                    aria-hidden="true"
                                                    className="h-3.5 w-3.5"
                                                />
                                                <span>
                                                    {isEn
                                                        ? 'Failed to send'
                                                        : 'تعذر الإرسال'}
                                                </span>
                                                {(message.tempId ||
                                                    message.publicId) && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            onRetry(
                                                                message.tempId ||
                                                                    message.publicId,
                                                            )
                                                        }
                                                        disabled={disabled}
                                                        className="inline-flex min-h-11 items-center gap-1 rounded-lg px-2 underline hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        <RefreshCw
                                                            aria-hidden="true"
                                                            className="h-3 w-3"
                                                        />
                                                        {isEn
                                                            ? 'Retry'
                                                            : 'إعادة المحاولة'}
                                                    </button>
                                                )}
                                            </div>
                                        )}

                                        {/* Timestamp on last message of cluster */}
                                        {isLastInCluster &&
                                            !isError &&
                                            !isStreaming && (
                                                <div className="mt-1 px-1 text-[10px] text-[var(--arabut-muted)] opacity-60 transition-opacity group-hover:opacity-100">
                                                    {new Date(
                                                        message.createdAt,
                                                    ).toLocaleTimeString(
                                                        isEn
                                                            ? 'en-US'
                                                            : 'ar-SA',
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
                    <div dir="ltr" className="flex w-full justify-start">
                        <TypingIndicator locale={locale} />
                    </div>
                )}

                {/* Assistant retryable turn affordance */}
                {retryableTurn?.retryable === true && (
                    <div dir="ltr" className="my-1 flex w-full justify-start">
                        <div
                            dir="auto"
                            className="flex items-center gap-1.5 rounded-xl border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-3 py-2 text-xs text-[var(--arabut-danger)] shadow-sm"
                        >
                            <AlertCircle
                                aria-hidden="true"
                                className="h-3.5 w-3.5"
                            />
                            <span>
                                {isEn
                                    ? 'Assistant could not complete response'
                                    : 'تعذر على المساعد إكمال الرد'}
                            </span>
                            {onRetryAgentTurn && (
                                <button
                                    type="button"
                                    onClick={onRetryAgentTurn}
                                    disabled={disabled}
                                    className="inline-flex min-h-11 items-center gap-1 rounded-lg px-2 underline hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <RefreshCw
                                        aria-hidden="true"
                                        className="h-3 w-3"
                                    />
                                    {isEn ? 'Retry' : 'إعادة المحاولة'}
                                </button>
                            )}
                        </div>
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
                                    disabled={disabled}
                                    className="min-h-11 rounded-xl border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-3 py-2 text-xs text-[var(--arabut-ink)] transition-colors hover:border-[var(--arabut-gold)]/50 hover:bg-[var(--arabut-navy-active)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transform-none"
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
                    className="absolute bottom-4 left-1/2 flex min-h-11 -translate-x-1/2 items-center gap-1.5 rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-deep)]/95 px-3.5 py-2 text-xs font-semibold text-[var(--arabut-gold-bright)] shadow-xl backdrop-blur-sm transition-transform hover:scale-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] active:scale-95 motion-reduce:transition-none motion-reduce:hover:scale-100 motion-reduce:active:scale-100"
                >
                    <ArrowDown aria-hidden="true" className="h-3.5 w-3.5" />
                    <span>{isEn ? 'New messages' : 'رسائل جديدة'}</span>
                </button>
            )}
        </div>
    );
};
