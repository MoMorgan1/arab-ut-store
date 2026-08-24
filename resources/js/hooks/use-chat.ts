import { useCallback, useEffect, useRef, useState } from 'react';
import {
    ChatApiError,
    fetchAgentTurn,
    fetchConversation,
    fetchConversationHistory,
    fetchOrStartActiveConversation,
    requestSupportTicket,
    restartConversation,
    retryAgentTurn,
    sendChatMessage,
    startAgentTurn,
} from '@/lib/chat-api';
import type { AgentTurnStartResult } from '@/lib/chat-api';
import type {
    AgentTurnState,
    AppStreamEvent,
    ChatConversation,
    ChatConversationSummary,
    ChatMessage,
} from '@/types/chat';

export type UseChatOptions = {
    enabled?: boolean;
    locale?: string;
};

type QueueItem = {
    tempId: string;
    content: string;
    clientMessageId: string;
    createdAt: string;
};

type StatusAnnouncement = {
    id: number;
    message: string;
};

type DemoReplyTimeoutId = ReturnType<typeof setTimeout>;

function isAmbiguousRestartFailure(error: unknown): boolean {
    return (
        error instanceof ChatApiError &&
        (error.code === 'network_error' ||
            error.code === 'invalid_response' ||
            error.status >= 500)
    );
}

function collectRecoveryDrafts(
    localMessages: ChatMessage[],
    pendingQueueItems: QueueItem[],
    conversationPublicId: string,
): ChatMessage[] {
    const candidateDrafts: ChatMessage[] = [
        ...localMessages
            .filter(
                (message) =>
                    message.senderType === 'customer' &&
                    message.clientStatus === 'error',
            )
            .map((message) => ({
                ...message,
                conversationPublicId,
                clientStatus: 'error' as const,
            })),
        ...pendingQueueItems.map((queueItem) => ({
            publicId: queueItem.tempId,
            conversationPublicId,
            clientMessageId: queueItem.clientMessageId,
            senderType: 'customer' as const,
            messageType: 'text' as const,
            content: queueItem.content,
            createdAt: queueItem.createdAt,
            clientStatus: 'error' as const,
            tempId: queueItem.tempId,
        })),
    ];
    const seenTempIds = new Set<string>();
    const seenClientMessageIds = new Set<string>();

    return candidateDrafts.filter((draft) => {
        const duplicateTempId =
            draft.tempId !== undefined && seenTempIds.has(draft.tempId);
        const duplicateClientMessageId =
            draft.clientMessageId !== undefined &&
            seenClientMessageIds.has(draft.clientMessageId);

        if (duplicateTempId || duplicateClientMessageId) {
            return false;
        }

        if (draft.tempId !== undefined) {
            seenTempIds.add(draft.tempId);
        }

        if (draft.clientMessageId !== undefined) {
            seenClientMessageIds.add(draft.clientMessageId);
        }

        return true;
    });
}

function generateClientMessageId(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export function useChat(options: UseChatOptions = {}) {
    const isChatEnabled = options.enabled === true;
    const pageLocale = options.locale || 'ar';

    const [isOpen, setIsOpen] = useState(false);
    const [conversation, setConversation] = useState<ChatConversation | null>(
        null,
    );
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isAssistantTyping, setIsAssistantTyping] = useState(false);
    const [isStreaming, setIsStreaming] = useState(false);
    const [retryableTurn, setRetryableTurn] = useState<AgentTurnState | null>(
        null,
    );
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [isRestarting, setIsRestarting] = useState(false);
    const [hasMore, setHasMore] = useState(false);
    const [oldestCursor, setOldestCursor] = useState<string | null>(null);
    const [unreadCount, setUnreadCount] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [errorAnnouncementId, setErrorAnnouncementId] = useState(0);
    const [statusAnnouncement, setStatusAnnouncement] =
        useState<StatusAnnouncement | null>(null);
    const [historyConversations, setHistoryConversations] = useState<
        ChatConversationSummary[]
    >([]);
    const [isReadOnly, setIsReadOnly] = useState(false);

    const isOpenRef = useRef(isOpen);
    const conversationRef = useRef<ChatConversation | null>(conversation);
    const messagesRef = useRef<ChatMessage[]>(messages);
    const initializationPromiseRef = useRef<Promise<ChatConversation> | null>(
        null,
    );
    const queueRef = useRef<QueueItem[]>([]);
    const isProcessingQueueRef = useRef(false);
    const announcementIdRef = useRef(0);
    const demoReplyTimeoutsRef = useRef<Set<DemoReplyTimeoutId>>(new Set());
    const pendingDemoReplyCountRef = useRef(0);
    const conversationGenerationRef = useRef(0);
    const isMountedRef = useRef(true);

    const isStreamingRef = useRef(false);
    const streamingTurnIdRef = useRef<string | null>(null);
    const quietTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const pollingTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const handoffPollingTimerRef = useRef<ReturnType<typeof setTimeout> | null>(
        null,
    );
    // Null until the first message arrives: Date.now() in a useRef initialiser
    // is a render-time impurity the React compiler rejects, and it would also
    // start the polling backoff clock at mount rather than at the last message.
    const lastReceivedMessageAtRef = useRef<number | null>(null);
    const pollingTurnIdRef = useRef<string | null>(null);
    const nextStartScheduledForTurnRef = useRef<string | null>(null);
    const streamAbortControllerRef = useRef<AbortController | null>(null);
    const triggerAgentTurnRef = useRef<
        ((generation: number, retryTurnId?: string) => Promise<void>) | null
    >(null);
    const [isQuietWaiting, setIsQuietWaiting] = useState(false);
    const [isPollingTurn, setIsPollingTurn] = useState(false);

    const updateMessages = useCallback(
        (updater: (currentMessages: ChatMessage[]) => ChatMessage[]) => {
            const nextMessages = updater(messagesRef.current);
            messagesRef.current = nextMessages;
            setMessages(nextMessages);
        },
        [],
    );

    useEffect(() => {
        isOpenRef.current = isOpen;
    }, [isOpen]);

    useEffect(() => {
        conversationRef.current = conversation;
    }, [conversation]);

    const announceStatus = useCallback((message: string) => {
        announcementIdRef.current += 1;
        setStatusAnnouncement({
            id: announcementIdRef.current,
            message,
        });
    }, []);

    const showError = useCallback((message: string) => {
        setError(message);
        setErrorAnnouncementId((id) => id + 1);
    }, []);

    const clearPendingDemoReplyTimers = useCallback(() => {
        demoReplyTimeoutsRef.current.forEach((timeoutId) => {
            clearTimeout(timeoutId);
        });
        demoReplyTimeoutsRef.current.clear();
        pendingDemoReplyCountRef.current = 0;
        setIsAssistantTyping(false);
    }, []);

    const clearAgentState = useCallback(() => {
        if (quietTimerRef.current !== null) {
            clearTimeout(quietTimerRef.current);
            quietTimerRef.current = null;
        }

        if (pollingTimerRef.current !== null) {
            clearTimeout(pollingTimerRef.current);
            pollingTimerRef.current = null;
        }

        if (handoffPollingTimerRef.current !== null) {
            clearTimeout(handoffPollingTimerRef.current);
            handoffPollingTimerRef.current = null;
        }

        if (streamAbortControllerRef.current !== null) {
            streamAbortControllerRef.current.abort();
            streamAbortControllerRef.current = null;
        }

        streamingTurnIdRef.current = null;
        pollingTurnIdRef.current = null;
        nextStartScheduledForTurnRef.current = null;
        isStreamingRef.current = false;
        setIsStreaming(false);
        setIsQuietWaiting(false);
        setIsPollingTurn(false);
        setRetryableTurn(null);
    }, []);

    const startConversationGeneration = useCallback(() => {
        conversationGenerationRef.current += 1;
        clearPendingDemoReplyTimers();
        clearAgentState();
    }, [clearPendingDemoReplyTimers, clearAgentState]);

    useEffect(() => {
        isMountedRef.current = true;

        return () => {
            isMountedRef.current = false;
            queueRef.current = [];
            isProcessingQueueRef.current = false;
            startConversationGeneration();
        };
    }, [startConversationGeneration]);

    const ownsAsyncGeneration = useCallback(
        (generation: number) =>
            isMountedRef.current &&
            generation === conversationGenerationRef.current,
        [],
    );

    const handleTerminalTurnBacklog = useCallback(
        (turn: AgentTurnState, generation: number) => {
            if (!ownsAsyncGeneration(generation)) {
                return;
            }

            if (turn.hasPendingMessages) {
                if (
                    queueRef.current.length === 0 &&
                    !isProcessingQueueRef.current
                ) {
                    if (
                        nextStartScheduledForTurnRef.current !== turn.publicId
                    ) {
                        nextStartScheduledForTurnRef.current = turn.publicId;
                        void triggerAgentTurnRef.current?.(generation);
                    }
                }
            } else {
                nextStartScheduledForTurnRef.current = null;
            }
        },
        [ownsAsyncGeneration],
    );

    const startPollingTurn = useCallback(
        (
            conversationPublicId: string,
            turnPublicId: string,
            generation: number,
        ) => {
            if (!ownsAsyncGeneration(generation)) {
                return;
            }

            if (pollingTimerRef.current !== null) {
                clearTimeout(pollingTimerRef.current);
                pollingTimerRef.current = null;
            }

            pollingTurnIdRef.current = turnPublicId;
            setIsPollingTurn(true);

            let consecutivePollFailures = 0;

            const poll = async () => {
                if (
                    !ownsAsyncGeneration(generation) ||
                    pollingTurnIdRef.current !== turnPublicId
                ) {
                    return;
                }

                try {
                    const turnState = await fetchAgentTurn(
                        conversationPublicId,
                        turnPublicId,
                    );

                    if (
                        !ownsAsyncGeneration(generation) ||
                        pollingTurnIdRef.current !== turnPublicId
                    ) {
                        return;
                    }

                    consecutivePollFailures = 0;

                    if (
                        turnState.status === 'waiting' ||
                        turnState.status === 'running'
                    ) {
                        pollingTimerRef.current = setTimeout(poll, 1000);
                    } else {
                        pollingTurnIdRef.current = null;
                        setIsPollingTurn(false);
                        streamingTurnIdRef.current = null;
                        isStreamingRef.current = false;
                        setIsStreaming(false);

                        if (turnState.status === 'completed') {
                            if (turnState.message !== null) {
                                const completedMsg = turnState.message;
                                const streamTempId = `stream-${turnPublicId}`;
                                updateMessages((prev) => {
                                    const hasStream = prev.some(
                                        (m) =>
                                            m.publicId === streamTempId ||
                                            (m.streamStatus === 'streaming' &&
                                                m.tempId === streamTempId),
                                    );

                                    if (hasStream) {
                                        return prev.map((m) =>
                                            m.publicId === streamTempId ||
                                            (m.streamStatus === 'streaming' &&
                                                m.tempId === streamTempId)
                                                ? {
                                                      ...completedMsg,
                                                      streamStatus: undefined,
                                                  }
                                                : m,
                                        );
                                    }

                                    if (
                                        prev.some(
                                            (m) =>
                                                m.publicId ===
                                                completedMsg.publicId,
                                        )
                                    ) {
                                        return prev;
                                    }

                                    return [...prev, completedMsg];
                                });

                                if (!isOpenRef.current) {
                                    setUnreadCount((c) => c + 1);
                                }

                                announceStatus(
                                    pageLocale === 'en'
                                        ? 'New message from assistant.'
                                        : 'وصلت رسالة جديدة من المساعد.',
                                );
                            }

                            setRetryableTurn(null);
                            handleTerminalTurnBacklog(turnState, generation);
                        } else if (turnState.status === 'failed') {
                            updateMessages((prev) =>
                                prev.filter(
                                    (m) => m.streamStatus !== 'streaming',
                                ),
                            );

                            if (turnState.retryable) {
                                setRetryableTurn(turnState);
                            } else {
                                setRetryableTurn(null);
                            }

                            handleTerminalTurnBacklog(turnState, generation);
                        } else if (turnState.status === 'cancelled') {
                            updateMessages((prev) =>
                                prev.filter(
                                    (m) => m.streamStatus !== 'streaming',
                                ),
                            );
                            setRetryableTurn(null);
                            nextStartScheduledForTurnRef.current = null;
                        }
                    }
                } catch {
                    if (
                        !ownsAsyncGeneration(generation) ||
                        pollingTurnIdRef.current !== turnPublicId
                    ) {
                        return;
                    }

                    consecutivePollFailures += 1;

                    if (consecutivePollFailures >= 5) {
                        pollingTurnIdRef.current = null;
                        setIsPollingTurn(false);
                        streamingTurnIdRef.current = null;
                        isStreamingRef.current = false;
                        setIsStreaming(false);
                        showError(
                            pageLocale === 'en'
                                ? 'Lost connection while waiting for the response. Please try again.'
                                : 'انقطع الاتصال أثناء انتظار الرد. حاول مرة ثانية.',
                        );

                        return;
                    }

                    pollingTimerRef.current = setTimeout(poll, 1000);
                }
            };

            void poll();
        },
        [
            announceStatus,
            handleTerminalTurnBacklog,
            ownsAsyncGeneration,
            pageLocale,
            showError,
            updateMessages,
        ],
    );

    const triggerAgentTurn = useCallback(
        async (triggeredGeneration: number, retryTurnId?: string) => {
            if (!ownsAsyncGeneration(triggeredGeneration)) {
                return;
            }

            if (queueRef.current.length > 0 || isProcessingQueueRef.current) {
                return;
            }

            if (isStreamingRef.current || pollingTurnIdRef.current !== null) {
                return;
            }

            const currentConv = conversationRef.current;

            if (currentConv === null || currentConv.assistantMode !== 'agent') {
                return;
            }

            const conversationPublicId = currentConv.publicId;
            const abortController = new AbortController();
            streamAbortControllerRef.current = abortController;

            const onEvent = (event: AppStreamEvent) => {
                if (!ownsAsyncGeneration(triggeredGeneration)) {
                    return;
                }

                if (event.event === 'turn.created') {
                    const turn = event.data.turn;
                    streamingTurnIdRef.current = turn.publicId;
                    isStreamingRef.current = true;
                    setIsStreaming(true);
                    setIsAssistantTyping(false);
                    setRetryableTurn(null);

                    const streamTempId = `stream-${turn.publicId}`;
                    const streamingMessage: ChatMessage = {
                        publicId: streamTempId,
                        tempId: streamTempId,
                        conversationPublicId,
                        senderType: 'assistant',
                        messageType: 'text',
                        content: '',
                        createdAt: new Date().toISOString(),
                        streamStatus: 'streaming',
                    };

                    updateMessages((prev) => {
                        if (
                            prev.some(
                                (m) =>
                                    m.streamStatus === 'streaming' ||
                                    m.publicId === streamTempId,
                            )
                        ) {
                            return prev;
                        }

                        return [...prev, streamingMessage];
                    });

                    announceStatus(
                        pageLocale === 'en'
                            ? 'Assistant is responding.'
                            : 'المساعد يرد الآن.',
                    );
                } else if (event.event === 'response.delta') {
                    const { turnPublicId, delta } = event.data;
                    const streamTempId = `stream-${turnPublicId}`;

                    updateMessages((prev) =>
                        prev.map((msg) => {
                            if (
                                msg.publicId === streamTempId ||
                                (msg.streamStatus === 'streaming' &&
                                    msg.tempId === streamTempId)
                            ) {
                                const newContent = (msg.content + delta).slice(
                                    0,
                                    4000,
                                );

                                return { ...msg, content: newContent };
                            }

                            return msg;
                        }),
                    );
                } else if (event.event === 'response.completed') {
                    const { turn, message } = event.data;
                    streamingTurnIdRef.current = null;
                    isStreamingRef.current = false;
                    setIsStreaming(false);
                    setRetryableTurn(null);

                    const streamTempId = `stream-${turn.publicId}`;
                    updateMessages((prev) => {
                        const hasStream = prev.some(
                            (m) =>
                                m.publicId === streamTempId ||
                                (m.streamStatus === 'streaming' &&
                                    m.tempId === streamTempId),
                        );

                        if (hasStream) {
                            return prev.map((m) =>
                                m.publicId === streamTempId ||
                                (m.streamStatus === 'streaming' &&
                                    m.tempId === streamTempId)
                                    ? { ...message, streamStatus: undefined }
                                    : m,
                            );
                        }

                        if (prev.some((m) => m.publicId === message.publicId)) {
                            return prev;
                        }

                        return [...prev, message];
                    });

                    if (!isOpenRef.current) {
                        setUnreadCount((c) => c + 1);
                    }

                    announceStatus(
                        pageLocale === 'en'
                            ? 'New message from assistant.'
                            : 'وصلت رسالة جديدة من المساعد.',
                    );

                    handleTerminalTurnBacklog(turn, triggeredGeneration);
                } else if (event.event === 'response.failed') {
                    const { turn, message } = event.data;
                    streamingTurnIdRef.current = null;
                    isStreamingRef.current = false;
                    setIsStreaming(false);

                    updateMessages((prev) =>
                        prev.filter((m) => m.streamStatus !== 'streaming'),
                    );

                    if (turn.retryable) {
                        setRetryableTurn(turn);
                    } else {
                        setRetryableTurn(null);
                    }

                    const displayError =
                        message ||
                        (pageLocale === 'en'
                            ? 'Assistant could not respond. Please try again.'
                            : 'تعذر على المساعد الرد. يرجى المحاولة مرة أخرى.');
                    showError(displayError);

                    handleTerminalTurnBacklog(turn, triggeredGeneration);
                }
            };

            try {
                let result: AgentTurnStartResult;

                if (retryTurnId !== undefined && retryTurnId !== '') {
                    result = await retryAgentTurn(
                        conversationPublicId,
                        retryTurnId,
                        onEvent,
                        abortController.signal,
                    );
                } else {
                    result = await startAgentTurn(
                        conversationPublicId,
                        onEvent,
                        abortController.signal,
                    );
                }

                if (!ownsAsyncGeneration(triggeredGeneration)) {
                    return;
                }

                if (result.state === 'waiting_for_quiet') {
                    if (quietTimerRef.current !== null) {
                        clearTimeout(quietTimerRef.current);
                    }

                    setIsQuietWaiting(true);
                    quietTimerRef.current = setTimeout(() => {
                        quietTimerRef.current = null;
                        void triggerAgentTurnRef.current?.(triggeredGeneration);
                    }, result.retryAfterMs);
                } else if (result.state === 'turn_in_progress') {
                    startPollingTurn(
                        conversationPublicId,
                        result.turn.publicId,
                        triggeredGeneration,
                    );
                } else if (result.state === 'idle') {
                    nextStartScheduledForTurnRef.current = null;
                }
            } catch {
                if (!ownsAsyncGeneration(triggeredGeneration)) {
                    return;
                }

                if (streamingTurnIdRef.current !== null) {
                    const turnId = streamingTurnIdRef.current;
                    startPollingTurn(
                        conversationPublicId,
                        turnId,
                        triggeredGeneration,
                    );
                }
            } finally {
                setIsQuietWaiting(false);

                if (quietTimerRef.current === null) {
                    setIsAssistantTyping(false);
                }

                if (streamAbortControllerRef.current === abortController) {
                    streamAbortControllerRef.current = null;
                }
            }
        },
        [
            announceStatus,
            handleTerminalTurnBacklog,
            ownsAsyncGeneration,
            pageLocale,
            showError,
            startPollingTurn,
            updateMessages,
        ],
    );

    useEffect(() => {
        triggerAgentTurnRef.current = triggerAgentTurn;
    });

    const handleLoadedConversation = useCallback(
        (data: ChatConversation, generation: number) => {
            const latestTurn = data.latestTurn ?? data.latestTurnState ?? null;

            if (data.assistantMode === 'agent' && latestTurn !== null) {
                if (
                    latestTurn.status === 'waiting' ||
                    latestTurn.status === 'running'
                ) {
                    startPollingTurn(
                        data.publicId,
                        latestTurn.publicId,
                        generation,
                    );
                } else if (
                    latestTurn.status === 'completed' ||
                    latestTurn.status === 'failed'
                ) {
                    if (
                        latestTurn.status === 'failed' &&
                        latestTurn.retryable
                    ) {
                        setRetryableTurn(latestTurn);
                    }

                    if (
                        latestTurn.status === 'completed' &&
                        latestTurn.message !== null
                    ) {
                        const msg = latestTurn.message;
                        updateMessages((prev) => {
                            if (prev.some((m) => m.publicId === msg.publicId)) {
                                return prev;
                            }

                            return [...prev, msg];
                        });
                    }

                    handleTerminalTurnBacklog(latestTurn, generation);
                }
            }
        },
        [handleTerminalTurnBacklog, startPollingTurn, updateMessages],
    );

    const adoptConversation = useCallback(
        (conversationSnapshot: ChatConversation) => {
            startConversationGeneration();
            const generation = conversationGenerationRef.current;
            queueRef.current = [];
            isProcessingQueueRef.current = false;
            initializationPromiseRef.current = null;
            setConversation(conversationSnapshot);
            conversationRef.current = conversationSnapshot;
            setMessages(conversationSnapshot.messages);
            messagesRef.current = conversationSnapshot.messages;
            setHasMore(conversationSnapshot.hasMore);
            setOldestCursor(conversationSnapshot.oldestCursor ?? null);
            setUnreadCount(0);
            setError(null);
            setIsAssistantTyping(false);
            setIsLoadingOlder(false);
            setIsRestarting(false);
            // Both of these belong to the conversation being left, not to the
            // one being adopted. The backoff clock carried a previous thread's
            // last-message time, so a fresh handoff could start polling at 15s;
            // the read-only lock, once set by opening a past thread, had no
            // other way back off and disabled the composer and restart together.
            lastReceivedMessageAtRef.current = null;
            setIsReadOnly(conversationSnapshot.status !== 'open');
            handleLoadedConversation(conversationSnapshot, generation);
        },
        [handleLoadedConversation, startConversationGeneration],
    );

    const appendDeliveredDemoReply = useCallback(
        (demoReply: ChatMessage) => {
            updateMessages((previousMessages) => [
                ...previousMessages,
                demoReply,
            ]);

            if (!isOpenRef.current) {
                setUnreadCount((count) => count + 1);
            }

            announceStatus(
                pageLocale === 'en'
                    ? 'New message from assistant.'
                    : 'وصلت رسالة جديدة من المساعد.',
            );
        },
        [announceStatus, pageLocale, updateMessages],
    );

    const finishDemoReply = useCallback(
        (
            timeoutId: DemoReplyTimeoutId,
            scheduledGeneration: number,
            demoReply: ChatMessage,
        ) => {
            if (scheduledGeneration !== conversationGenerationRef.current) {
                demoReplyTimeoutsRef.current.delete(timeoutId);

                return;
            }

            if (!demoReplyTimeoutsRef.current.delete(timeoutId)) {
                return;
            }

            pendingDemoReplyCountRef.current -= 1;
            setIsAssistantTyping(pendingDemoReplyCountRef.current > 0);
            appendDeliveredDemoReply(demoReply);
        },
        [appendDeliveredDemoReply],
    );

    const scheduleDemoReply = useCallback(
        (demoReply: ChatMessage, scheduledGeneration: number) => {
            if (scheduledGeneration !== conversationGenerationRef.current) {
                return;
            }

            pendingDemoReplyCountRef.current += 1;
            setIsAssistantTyping(true);
            announceStatus(
                pageLocale === 'en'
                    ? 'Assistant is typing...'
                    : 'المساعد يكتب الآن...',
            );

            const timeoutId = setTimeout(() => {
                finishDemoReply(timeoutId, scheduledGeneration, demoReply);
            }, 1100);
            demoReplyTimeoutsRef.current.add(timeoutId);
        },
        [announceStatus, finishDemoReply, pageLocale],
    );

    const getOrInitConversation =
        useCallback(async (): Promise<ChatConversation> => {
            if (conversationRef.current !== null) {
                return conversationRef.current;
            }

            if (initializationPromiseRef.current !== null) {
                return initializationPromiseRef.current;
            }

            setIsLoading(true);
            setError(null);

            const generation = conversationGenerationRef.current;
            const promise = fetchOrStartActiveConversation(pageLocale)
                .then((data) => {
                    if (!ownsAsyncGeneration(generation)) {
                        return data;
                    }

                    setConversation(data);
                    conversationRef.current = data;
                    setMessages(data.messages);
                    messagesRef.current = data.messages;
                    setHasMore(data.hasMore);
                    setOldestCursor(data.oldestCursor ?? null);
                    handleLoadedConversation(data, generation);

                    return data;
                })
                .catch((err) => {
                    const errorMessage =
                        pageLocale === 'en'
                            ? 'Failed to connect to chat. Please try again.'
                            : 'تعذر الاتصال بالشات. يرجى المحاولة مرة أخرى.';
                    showError(errorMessage);

                    throw err;
                })
                .finally(() => {
                    if (ownsAsyncGeneration(generation)) {
                        setIsLoading(false);
                    }

                    initializationPromiseRef.current = null;
                });

            initializationPromiseRef.current = promise;

            return promise;
        }, [
            handleLoadedConversation,
            ownsAsyncGeneration,
            pageLocale,
            showError,
        ]);

    const initializeChat = useCallback(async () => {
        if (!isChatEnabled || isLoading || conversationRef.current !== null) {
            return;
        }

        try {
            await getOrInitConversation();
        } catch {
            // Error state handled inside getOrInitConversation
        }
    }, [isChatEnabled, isLoading, getOrInitConversation]);

    const openChat = useCallback(() => {
        setIsOpen(true);
        setUnreadCount(0);

        if (conversationRef.current === null) {
            void initializeChat();
        }
    }, [initializeChat]);

    const closeChat = useCallback(() => {
        setIsOpen(false);
    }, []);

    const toggleOpen = useCallback(() => {
        if (isOpen) {
            closeChat();
        } else {
            openChat();
        }
    }, [isOpen, closeChat, openChat]);

    const processQueue = useCallback(async () => {
        if (isProcessingQueueRef.current) {
            return;
        }

        isProcessingQueueRef.current = true;
        const queueProcessorGeneration = conversationGenerationRef.current;

        while (
            queueRef.current.length > 0 &&
            ownsAsyncGeneration(queueProcessorGeneration)
        ) {
            const item = queueRef.current.shift()!;
            const queueItemGeneration = queueProcessorGeneration;

            let currentConv: ChatConversation;

            try {
                currentConv = await getOrInitConversation();
            } catch {
                if (!ownsAsyncGeneration(queueItemGeneration)) {
                    continue;
                }

                setIsAssistantTyping(false);
                updateMessages((prev) =>
                    prev.map((msg) =>
                        msg.tempId === item.tempId
                            ? { ...msg, clientStatus: 'error' }
                            : msg,
                    ),
                );

                continue;
            }

            if (!ownsAsyncGeneration(queueItemGeneration)) {
                continue;
            }

            try {
                const result = await sendChatMessage(
                    currentConv.publicId,
                    item.content,
                    item.clientMessageId,
                );

                if (!ownsAsyncGeneration(queueItemGeneration)) {
                    continue;
                }

                updateMessages((prev) =>
                    prev.map((msg) =>
                        msg.tempId === item.tempId
                            ? {
                                  ...result.message,
                                  clientStatus: 'sent',
                                  clientMessageId: item.clientMessageId,
                              }
                            : msg,
                    ),
                );

                if (result.demoReply !== null) {
                    scheduleDemoReply(result.demoReply, queueItemGeneration);
                }

                if (
                    result.handoffState !== undefined &&
                    conversationRef.current
                ) {
                    const updated: ChatConversation = {
                        ...conversationRef.current,
                        handoffState: result.handoffState,
                    };
                    conversationRef.current = updated;
                    setConversation(updated);
                    lastReceivedMessageAtRef.current = Date.now();
                }

                if (
                    queueRef.current.length === 0 &&
                    currentConv.assistantMode === 'agent'
                ) {
                    if (quietTimerRef.current !== null) {
                        clearTimeout(quietTimerRef.current);
                    }

                    setIsQuietWaiting(true);
                    quietTimerRef.current = setTimeout(() => {
                        quietTimerRef.current = null;
                        void triggerAgentTurn(queueItemGeneration);
                    }, 1500);
                }
            } catch (err) {
                if (!ownsAsyncGeneration(queueItemGeneration)) {
                    continue;
                }

                if (
                    err instanceof ChatApiError &&
                    (err.code === 'conversation_closed' ||
                        err.code === 'conversation_not_found' ||
                        err.status === 404)
                ) {
                    try {
                        const recoveredConversation =
                            await fetchOrStartActiveConversation(pageLocale);

                        if (!ownsAsyncGeneration(queueItemGeneration)) {
                            continue;
                        }

                        const preservedDrafts = collectRecoveryDrafts(
                            messagesRef.current,
                            [item, ...queueRef.current],
                            recoveredConversation.publicId,
                        );

                        adoptConversation(recoveredConversation);
                        const recoveredMessages = [
                            ...recoveredConversation.messages,
                            ...preservedDrafts,
                        ];
                        setMessages(recoveredMessages);
                        messagesRef.current = recoveredMessages;
                        showError(
                            pageLocale === 'en'
                                ? "The conversation changed. Your unsent messages are saved. Choose Retry when you're ready."
                                : 'تغيّرت المحادثة. رسائلك اللي ما انرسلت محفوظة. اضغط إعادة المحاولة وقت ما تكون جاهز.',
                        );
                        announceStatus(
                            pageLocale === 'en'
                                ? 'Conversation updated.'
                                : 'تم تحديث المحادثة.',
                        );
                    } catch {
                        if (!ownsAsyncGeneration(queueItemGeneration)) {
                            continue;
                        }

                        setIsAssistantTyping(false);
                        updateMessages((prev) =>
                            prev.map((msg) =>
                                msg.tempId === item.tempId
                                    ? { ...msg, clientStatus: 'error' }
                                    : msg,
                            ),
                        );
                        showError(
                            pageLocale === 'en'
                                ? 'Failed to recover the current conversation. Please close and reopen chat.'
                                : 'تعذر استعادة المحادثة الحالية. أغلق الشات وافتحه مرة ثانية.',
                        );
                    }

                    continue;
                }

                setIsAssistantTyping(false);
                updateMessages((prev) =>
                    prev.map((msg) =>
                        msg.tempId === item.tempId
                            ? { ...msg, clientStatus: 'error' }
                            : msg,
                    ),
                );

                const errorMessage =
                    err instanceof ChatApiError &&
                    err.code === 'validation_error'
                        ? pageLocale === 'en'
                            ? 'Message could not be sent. Please check your message.'
                            : 'تعذر إرسال الرسالة. يرجى التحقق من النص.'
                        : pageLocale === 'en'
                          ? 'Failed to send message. Please retry.'
                          : 'تعذر إرسال الرسالة. يرجى إعادة المحاولة.';
                showError(errorMessage);
            }
        }

        if (ownsAsyncGeneration(queueProcessorGeneration)) {
            isProcessingQueueRef.current = false;
        }
    }, [
        adoptConversation,
        announceStatus,
        getOrInitConversation,
        ownsAsyncGeneration,
        pageLocale,
        scheduleDemoReply,
        showError,
        triggerAgentTurn,
        updateMessages,
    ]);

    const sendMessage = useCallback(
        async (content: string) => {
            const trimmed = content.trim();

            if (trimmed === '') {
                return;
            }

            if (quietTimerRef.current !== null) {
                clearTimeout(quietTimerRef.current);
                quietTimerRef.current = null;
                setIsQuietWaiting(false);
            }

            const tempId = `temp-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const clientMessageId = generateClientMessageId();
            const createdAt = new Date().toISOString();

            const optimisticMessage: ChatMessage = {
                publicId: tempId,
                conversationPublicId: conversationRef.current?.publicId,
                clientMessageId,
                senderType: 'customer',
                messageType: 'text',
                content: trimmed,
                createdAt,
                clientStatus: 'sending',
                tempId,
            };

            updateMessages((prev) => [...prev, optimisticMessage]);
            setError(null);

            if (conversationRef.current?.assistantMode === 'agent') {
                setIsAssistantTyping(true);
            }

            queueRef.current.push({
                tempId,
                content: trimmed,
                clientMessageId,
                createdAt,
            });

            void processQueue();
        },
        [processQueue, updateMessages],
    );

    const retryMessage = useCallback(
        async (tempId: string) => {
            const failedMsg = messagesRef.current.find(
                (m) => m.tempId === tempId || m.publicId === tempId,
            );

            if (failedMsg === undefined) {
                return;
            }

            if (quietTimerRef.current !== null) {
                clearTimeout(quietTimerRef.current);
                quietTimerRef.current = null;
                setIsQuietWaiting(false);
            }

            const clientMessageId =
                failedMsg.clientMessageId || generateClientMessageId();
            const messageTempId = failedMsg.tempId || failedMsg.publicId;

            updateMessages((prev) =>
                prev.map((m) =>
                    m.tempId === messageTempId || m.publicId === messageTempId
                        ? { ...m, clientStatus: 'sending', clientMessageId }
                        : m,
                ),
            );

            queueRef.current.push({
                tempId: messageTempId,
                content: failedMsg.content,
                clientMessageId,
                createdAt: failedMsg.createdAt,
            });

            void processQueue();
        },
        [processQueue, updateMessages],
    );

    const retryAgentTurnAction = useCallback(async () => {
        if (
            retryableTurn === null ||
            isStreaming ||
            pollingTurnIdRef.current !== null
        ) {
            return;
        }

        const turnId = retryableTurn.publicId;
        setRetryableTurn(null);
        void triggerAgentTurn(conversationGenerationRef.current, turnId);
    }, [isStreaming, retryableTurn, triggerAgentTurn]);

    const loadOlderMessages = useCallback(async () => {
        if (
            conversationRef.current === null ||
            isLoadingOlder ||
            !hasMore ||
            oldestCursor === null
        ) {
            return;
        }

        setIsLoadingOlder(true);
        const loadingGeneration = conversationGenerationRef.current;
        const conversationPublicId = conversationRef.current.publicId;

        try {
            const data = await fetchConversation(
                conversationPublicId,
                oldestCursor,
                50,
            );

            if (!ownsAsyncGeneration(loadingGeneration)) {
                return;
            }

            updateMessages((prev) => [...data.messages, ...prev]);
            setHasMore(data.hasMore);
            setOldestCursor(data.oldestCursor ?? null);
        } catch {
            if (!ownsAsyncGeneration(loadingGeneration)) {
                return;
            }

            showError(
                pageLocale === 'en'
                    ? 'Failed to load older messages.'
                    : 'تعذر تحميل الرسائل السابقة.',
            );
        } finally {
            if (ownsAsyncGeneration(loadingGeneration)) {
                setIsLoadingOlder(false);
            }
        }
    }, [
        hasMore,
        isLoadingOlder,
        oldestCursor,
        ownsAsyncGeneration,
        pageLocale,
        showError,
        updateMessages,
    ]);

    const hasPendingSends = messages.some(
        (message) => message.clientStatus === 'sending',
    );
    const isAgentTurnActive = isStreaming || isPollingTurn || isQuietWaiting;

    const canRestart =
        !isLoading &&
        !isLoadingOlder &&
        !isRestarting &&
        !isAssistantTyping &&
        !hasPendingSends &&
        !isAgentTurnActive;

    const restartChat = useCallback(async () => {
        if (!isMountedRef.current || !canRestart) {
            return;
        }

        const restartGeneration = conversationGenerationRef.current;
        const previousPublicId = conversationRef.current?.publicId ?? null;
        setIsRestarting(true);

        try {
            const restartedConversation = await restartConversation(pageLocale);

            if (!ownsAsyncGeneration(restartGeneration)) {
                setIsRestarting(false);

                return;
            }

            adoptConversation(restartedConversation);
            announceStatus(
                pageLocale === 'en'
                    ? 'New conversation started.'
                    : 'بدأت محادثة جديدة.',
            );
        } catch (error) {
            if (!ownsAsyncGeneration(restartGeneration)) {
                setIsRestarting(false);

                return;
            }

            if (isAmbiguousRestartFailure(error)) {
                let activeConversation: ChatConversation;

                try {
                    activeConversation =
                        await fetchOrStartActiveConversation(pageLocale);
                } catch {
                    if (!ownsAsyncGeneration(restartGeneration)) {
                        setIsRestarting(false);

                        return;
                    }

                    showError(
                        pageLocale === 'en'
                            ? 'Failed to confirm the new conversation. Your current chat is unchanged. Please try again.'
                            : 'تعذر التأكد من المحادثة الجديدة. الشات الحالي محفوظ. حاول مرة ثانية.',
                    );
                    setIsRestarting(false);

                    return;
                }

                if (!ownsAsyncGeneration(restartGeneration)) {
                    setIsRestarting(false);

                    return;
                }

                if (activeConversation.publicId !== previousPublicId) {
                    adoptConversation(activeConversation);
                    announceStatus(
                        pageLocale === 'en'
                            ? 'New conversation started.'
                            : 'بدأت محادثة جديدة.',
                    );

                    return;
                }
            }

            const errorMessage =
                pageLocale === 'en'
                    ? 'Failed to start a new conversation. Please try again.'
                    : 'تعذر بدء محادثة جديدة. حاول مرة ثانية.';
            showError(errorMessage);
            setIsRestarting(false);
        }
    }, [
        adoptConversation,
        announceStatus,
        canRestart,
        ownsAsyncGeneration,
        pageLocale,
        showError,
    ]);

    // 5s while the conversation is moving, 15s after two quiet minutes
    // (design 5.4). A thread that has not seen a message yet counts as moving.
    const nextPollDelay = useCallback((): number => {
        const lastAt = lastReceivedMessageAtRef.current;

        if (lastAt === null) {
            return 5_000;
        }

        return Date.now() - lastAt >= 120_000 ? 15_000 : 5_000;
    }, []);

    // Handoff polling lifecycle
    useEffect(() => {
        if (!isOpen || !conversation) {
            if (handoffPollingTimerRef.current !== null) {
                clearTimeout(handoffPollingTimerRef.current);
                handoffPollingTimerRef.current = null;
            }

            return;
        }

        const handoffState = conversation.handoffState;

        if (handoffState !== 'requested' && handoffState !== 'active') {
            if (handoffPollingTimerRef.current !== null) {
                clearTimeout(handoffPollingTimerRef.current);
                handoffPollingTimerRef.current = null;
            }

            return;
        }

        const generation = conversationGenerationRef.current;
        const convPublicId = conversation.publicId;

        // Cleanup clears the shared timer ref, but a poll already awaiting its
        // fetch holds no timer to clear — it would come back, pass the
        // generation check (unchanged, since no conversation was adopted) and
        // schedule a second chain alongside the one the re-run effect started.
        // Every handoffState transition, visibility restore and reopen inside
        // one round trip added another chain, and five of them exceed the
        // 60/min read limit that makes staff replies stop arriving at all.
        let cancelled = false;

        const pollHandoff = async () => {
            if (
                cancelled ||
                !ownsAsyncGeneration(generation) ||
                !isOpenRef.current
            ) {
                return;
            }

            if (typeof document !== 'undefined' && document.hidden) {
                return;
            }

            try {
                const refreshed = await fetchConversation(convPublicId);

                if (!ownsAsyncGeneration(generation)) {
                    return;
                }

                // A turn that is still streaming holds a placeholder bubble
                // keyed by turn id, not by the public id the poll sees. Merging
                // now appends the finalized copy, and the completion handler
                // then renames the placeholder to that same id — one reply,
                // twice on screen. The completion path merges it anyway.
                if (streamingTurnIdRef.current !== null) {
                    return;
                }

                updateMessages((prev) => {
                    const existingIds = new Set(prev.map((m) => m.publicId));
                    const newMsgs = refreshed.messages.filter(
                        (m) => m.publicId && !existingIds.has(m.publicId),
                    );

                    if (newMsgs.length > 0) {
                        lastReceivedMessageAtRef.current = Date.now();

                        if (!isOpenRef.current) {
                            setUnreadCount((c) => c + newMsgs.length);
                        }

                        return [...prev, ...newMsgs];
                    }

                    return prev;
                });

                if (
                    refreshed.handoffState !==
                        conversationRef.current?.handoffState ||
                    refreshed.status !== conversationRef.current?.status
                ) {
                    const updated: ChatConversation = {
                        ...(conversationRef.current || refreshed),
                        handoffState: refreshed.handoffState,
                        ticket: refreshed.ticket,
                        status: refreshed.status,
                        lastMessageAt: refreshed.lastMessageAt,
                    };
                    conversationRef.current = updated;
                    setConversation(updated);

                    if (
                        refreshed.handoffState !== 'requested' &&
                        refreshed.handoffState !== 'active'
                    ) {
                        return;
                    }
                }
            } catch {
                // Ignore transient network errors during polling
            }

            if (
                cancelled ||
                !ownsAsyncGeneration(generation) ||
                !isOpenRef.current
            ) {
                return;
            }

            handoffPollingTimerRef.current = setTimeout(
                pollHandoff,
                nextPollDelay(),
            );
        };

        // The backoff is measured from the last message, or from when polling
        // started if none has arrived yet — otherwise a silent thread would
        // never reach the two-minute mark and would poll at 5s forever.
        lastReceivedMessageAtRef.current ??= Date.now();

        handoffPollingTimerRef.current = setTimeout(
            pollHandoff,
            nextPollDelay(),
        );

        const handleVisibilityChange = () => {
            if (cancelled) {
                return;
            }

            if (typeof document !== 'undefined' && !document.hidden) {
                if (handoffPollingTimerRef.current !== null) {
                    clearTimeout(handoffPollingTimerRef.current);
                    handoffPollingTimerRef.current = null;
                }

                void pollHandoff();
            }
        };

        if (typeof document !== 'undefined') {
            document.addEventListener(
                'visibilitychange',
                handleVisibilityChange,
            );
        }

        return () => {
            cancelled = true;

            if (handoffPollingTimerRef.current !== null) {
                clearTimeout(handoffPollingTimerRef.current);
                handoffPollingTimerRef.current = null;
            }

            if (typeof document !== 'undefined') {
                document.removeEventListener(
                    'visibilitychange',
                    handleVisibilityChange,
                );
            }
        };
    }, [
        conversation?.handoffState,
        conversation?.publicId,
        isOpen,
        nextPollDelay,
        ownsAsyncGeneration,
        updateMessages,
    ]);

    /**
     * Deliberately not fired from an effect in this hook.
     *
     * `useChat` mounts with the page, so a self-triggering effect issued a
     * `GET /chat/conversations` on every single page load — for guests, who
     * always get an empty list, and for anyone who never opens the widget. It
     * spends the chat-read throttle on a panel nobody asked for. The widget
     * calls this when the customer actually reaches the home view instead.
     */
    const loadHistory = useCallback(async () => {
        if (!isChatEnabled) {
            return;
        }

        try {
            const res = await fetchConversationHistory(10);
            setHistoryConversations(res.conversations);
        } catch {
            setHistoryConversations([]);
        }
    }, [isChatEnabled]);

    const openPastConversation = useCallback(
        async (publicId: string) => {
            setIsLoading(true);

            try {
                const pastConv = await fetchConversation(publicId);
                adoptConversation(pastConv);
            } catch {
                showError(
                    pageLocale === 'en'
                        ? 'Failed to load conversation.'
                        : 'تعذر تحميل المحادثة.',
                );
            } finally {
                setIsLoading(false);
            }
        },
        [adoptConversation, pageLocale, showError],
    );

    /**
     * Leave a past thread opened read-only and return to the live one.
     *
     * Design 5.3 asks for a "Start a new conversation" control beside the
     * read-only view. It is not decoration: opening a past thread disables both
     * the composer and restart, so without this the customer is stranded on an
     * old transcript — including while a human is replying to their live ticket
     * — with only a page reload as a way out.
     */
    const leaveReadOnlyConversation = useCallback(async () => {
        setIsLoading(true);

        try {
            const live = await fetchOrStartActiveConversation(pageLocale);
            adoptConversation(live);
        } catch {
            showError(
                pageLocale === 'en'
                    ? 'Could not reopen your current conversation.'
                    : 'تعذر فتح محادثتك الحالية.',
            );
        } finally {
            setIsLoading(false);
        }
    }, [adoptConversation, pageLocale, showError]);

    const requestTicket = useCallback(async () => {
        const conv = conversationRef.current;

        if (!conv) {
            return;
        }

        try {
            const result = await requestSupportTicket(conv.publicId);
            const updated: ChatConversation = {
                ...conv,
                handoffState: result.handoffState,
                ticket: result.ticket,
            };
            conversationRef.current = updated;
            setConversation(updated);
            lastReceivedMessageAtRef.current = Date.now();
        } catch (err) {
            if (
                err instanceof ChatApiError &&
                err.code === 'handoff_requires_login'
            ) {
                showError(
                    pageLocale === 'en'
                        ? 'Please log in to contact support.'
                        : 'يرجى تسجيل الدخول للتواصل مع الدعم.',
                );
            } else {
                showError(
                    pageLocale === 'en'
                        ? 'Failed to open support ticket.'
                        : 'تعذر فتح تذكرة الدعم.',
                );
            }
        }
    }, [pageLocale, showError]);

    return {
        isChatEnabled,
        isOpen,
        setIsOpen,
        openChat,
        closeChat,
        toggleOpen,
        conversation,
        messages,
        historyConversations,
        isReadOnly,
        isLoading,
        isAssistantTyping,
        isStreaming,
        retryableTurn,
        retryAgentTurn: retryAgentTurnAction,
        isLoadingOlder,
        isRestarting,
        hasMore,
        unreadCount,
        error,
        errorAnnouncementId,
        clearError: () => setError(null),
        statusAnnouncement,
        canRestart,
        restartChat,
        sendMessage,
        retryMessage,
        loadOlderMessages,
        loadHistory,
        openPastConversation,
        leaveReadOnlyConversation,
        requestTicket,
    };
}
