import { useCallback, useEffect, useRef, useState } from 'react';
import {
    ChatApiError,
    fetchConversation,
    fetchOrStartActiveConversation,
    restartConversation,
    sendChatMessage,
} from '@/lib/chat-api';
import type { ChatConversation, ChatMessage } from '@/types/chat';

export type UseChatOptions = {
    enabled?: boolean;
    demoAssistant?: boolean;
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
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [isRestarting, setIsRestarting] = useState(false);
    const [hasMore, setHasMore] = useState(false);
    const [oldestCursor, setOldestCursor] = useState<string | null>(null);
    const [unreadCount, setUnreadCount] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [errorAnnouncementId, setErrorAnnouncementId] = useState(0);
    const [statusAnnouncement, setStatusAnnouncement] =
        useState<StatusAnnouncement | null>(null);

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
    }, []);

    const startConversationGeneration = useCallback(() => {
        conversationGenerationRef.current += 1;
        clearPendingDemoReplyTimers();
    }, [clearPendingDemoReplyTimers]);

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

    const adoptConversation = useCallback(
        (conversationSnapshot: ChatConversation) => {
            startConversationGeneration();
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
        },
        [startConversationGeneration],
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

            const promise = fetchOrStartActiveConversation(pageLocale)
                .then((data) => {
                    setConversation(data);
                    conversationRef.current = data;
                    setMessages(data.messages);
                    messagesRef.current = data.messages;
                    setHasMore(data.hasMore);
                    setOldestCursor(data.oldestCursor ?? null);

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
                    setIsLoading(false);
                    initializationPromiseRef.current = null;
                });

            initializationPromiseRef.current = promise;

            return promise;
        }, [pageLocale, showError]);

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
            } catch (err) {
                if (!ownsAsyncGeneration(queueItemGeneration)) {
                    continue;
                }

                if (
                    err instanceof ChatApiError &&
                    err.code === 'conversation_closed'
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
        updateMessages,
    ]);

    const sendMessage = useCallback(
        async (content: string) => {
            const trimmed = content.trim();

            if (trimmed === '') {
                return;
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
    const canRestart =
        !isLoading &&
        !isLoadingOlder &&
        !isRestarting &&
        !isAssistantTyping &&
        !hasPendingSends;

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
                return;
            }

            if (isAmbiguousRestartFailure(error)) {
                let activeConversation: ChatConversation;

                try {
                    activeConversation =
                        await fetchOrStartActiveConversation(pageLocale);
                } catch {
                    if (!ownsAsyncGeneration(restartGeneration)) {
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

    return {
        isChatEnabled,
        isOpen,
        setIsOpen,
        openChat,
        closeChat,
        toggleOpen,
        conversation,
        messages,
        isLoading,
        isAssistantTyping,
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
    };
}
