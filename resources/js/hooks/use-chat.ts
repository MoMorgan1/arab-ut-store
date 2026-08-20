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
    generation: number;
};

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
    const [pendingSendCount, setPendingSendCount] = useState(0);
    const [hasMore, setHasMore] = useState(false);
    const [oldestCursor, setOldestCursor] = useState<string | null>(null);
    const [unreadCount, setUnreadCount] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [statusAnnouncement, setStatusAnnouncement] = useState<{
        id: number;
        message: string | null;
    }>({ id: 0, message: null });

    const isOpenRef = useRef(isOpen);
    const conversationRef = useRef<ChatConversation | null>(conversation);
    const messagesRef = useRef<ChatMessage[]>(messages);
    const initializationPromiseRef = useRef<Promise<ChatConversation> | null>(
        null,
    );
    const queueRef = useRef<QueueItem[]>([]);
    const isProcessingQueueRef = useRef(false);
    const isRestartingRef = useRef(false);
    const conversationGenerationRef = useRef(0);

    useEffect(() => {
        isOpenRef.current = isOpen;
    }, [isOpen]);

    useEffect(() => {
        conversationRef.current = conversation;
    }, [conversation]);

    useEffect(() => {
        messagesRef.current = messages;
    }, [messages]);

    const announceStatus = useCallback((message: string) => {
        setStatusAnnouncement((current) => ({
            id: current.id + 1,
            message,
        }));
    }, []);

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
            const requestGeneration = conversationGenerationRef.current;

            const promise = fetchOrStartActiveConversation(pageLocale)
                .then((data) => {
                    if (
                        requestGeneration !== conversationGenerationRef.current
                    ) {
                        return conversationRef.current ?? data;
                    }

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

                    if (
                        requestGeneration === conversationGenerationRef.current
                    ) {
                        setError(errorMessage);
                    }

                    throw err;
                })
                .finally(() => {
                    if (
                        requestGeneration === conversationGenerationRef.current
                    ) {
                        setIsLoading(false);
                    }

                    if (initializationPromiseRef.current === promise) {
                        initializationPromiseRef.current = null;
                    }
                });

            initializationPromiseRef.current = promise;

            return promise;
        }, [pageLocale]);

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

        while (queueRef.current.length > 0) {
            const item = queueRef.current.shift()!;

            let currentConv: ChatConversation;

            try {
                currentConv = await getOrInitConversation();
            } catch {
                if (item.generation === conversationGenerationRef.current) {
                    setMessages((prev) =>
                        prev.map((msg) =>
                            msg.tempId === item.tempId
                                ? { ...msg, clientStatus: 'error' }
                                : msg,
                        ),
                    );
                    setPendingSendCount((count) => Math.max(0, count - 1));
                }

                continue;
            }

            if (
                item.generation !== conversationGenerationRef.current ||
                conversationRef.current?.publicId !== currentConv.publicId
            ) {
                if (item.generation === conversationGenerationRef.current) {
                    setPendingSendCount((count) => Math.max(0, count - 1));
                }

                continue;
            }

            try {
                if (
                    item.generation !== conversationGenerationRef.current ||
                    conversationRef.current?.publicId !== currentConv.publicId
                ) {
                    continue;
                }

                const result = await sendChatMessage(
                    currentConv.publicId,
                    item.content,
                    item.clientMessageId,
                );
                const operationIsCurrent =
                    item.generation === conversationGenerationRef.current &&
                    conversationRef.current?.publicId === currentConv.publicId;

                if (!operationIsCurrent) {
                    continue;
                }

                setMessages((prev) =>
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
                    setIsAssistantTyping(true);
                    announceStatus(
                        pageLocale === 'en'
                            ? 'Assistant is typing…'
                            : 'المساعد يكتب الآن…',
                    );

                    setTimeout(() => {
                        if (
                            item.generation !==
                                conversationGenerationRef.current ||
                            conversationRef.current?.publicId !==
                                currentConv.publicId
                        ) {
                            return;
                        }

                        setIsAssistantTyping(false);

                        if (result.demoReply !== null) {
                            setMessages((prev) => [
                                ...prev,
                                result.demoReply as ChatMessage,
                            ]);

                            if (!isOpenRef.current) {
                                setUnreadCount((c) => c + 1);
                            }

                            announceStatus(
                                pageLocale === 'en'
                                    ? 'New message from assistant.'
                                    : 'وصلت رسالة جديدة من المساعد.',
                            );
                        }
                    }, 1100);
                }
            } catch (err) {
                if (
                    item.generation !== conversationGenerationRef.current ||
                    conversationRef.current?.publicId !== currentConv.publicId
                ) {
                    continue;
                }

                setMessages((prev) =>
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
                setError(errorMessage);
            } finally {
                if (item.generation === conversationGenerationRef.current) {
                    setPendingSendCount((count) => Math.max(0, count - 1));
                }
            }
        }

        isProcessingQueueRef.current = false;
    }, [getOrInitConversation, pageLocale, announceStatus]);

    const sendMessage = useCallback(
        async (content: string) => {
            const trimmed = content.trim();

            if (trimmed === '' || isRestartingRef.current) {
                return;
            }

            const tempId = `temp-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const clientMessageId = generateClientMessageId();

            const optimisticMessage: ChatMessage = {
                publicId: tempId,
                conversationPublicId: conversationRef.current?.publicId,
                clientMessageId,
                senderType: 'customer',
                messageType: 'text',
                content: trimmed,
                createdAt: new Date().toISOString(),
                clientStatus: 'sending',
                tempId,
            };

            setMessages((prev) => [...prev, optimisticMessage]);
            setError(null);

            queueRef.current.push({
                tempId,
                content: trimmed,
                clientMessageId,
                generation: conversationGenerationRef.current,
            });
            setPendingSendCount((count) => count + 1);

            void processQueue();
        },
        [processQueue],
    );

    const retryMessage = useCallback(
        async (tempId: string) => {
            if (isRestartingRef.current) {
                return;
            }

            const failedMsg = messagesRef.current.find(
                (m) => m.tempId === tempId || m.publicId === tempId,
            );

            if (failedMsg === undefined) {
                return;
            }

            const clientMessageId =
                failedMsg.clientMessageId || generateClientMessageId();
            const messageTempId = failedMsg.tempId || failedMsg.publicId;

            setMessages((prev) =>
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
                generation: conversationGenerationRef.current,
            });
            setPendingSendCount((count) => count + 1);

            void processQueue();
        },
        [processQueue],
    );

    const loadOlderMessages = useCallback(async () => {
        if (
            conversationRef.current === null ||
            isRestartingRef.current ||
            isLoadingOlder ||
            !hasMore ||
            oldestCursor === null
        ) {
            return;
        }

        setIsLoadingOlder(true);
        const conversationPublicId = conversationRef.current.publicId;
        const operationGeneration = conversationGenerationRef.current;

        try {
            const data = await fetchConversation(
                conversationPublicId,
                oldestCursor,
                50,
            );

            if (
                operationGeneration !== conversationGenerationRef.current ||
                conversationRef.current?.publicId !== conversationPublicId
            ) {
                return;
            }

            setMessages((prev) => [...data.messages, ...prev]);
            setHasMore(data.hasMore);
            setOldestCursor(data.oldestCursor ?? null);
        } catch {
            if (
                operationGeneration === conversationGenerationRef.current &&
                conversationRef.current?.publicId === conversationPublicId
            ) {
                setError(
                    pageLocale === 'en'
                        ? 'Failed to load older messages.'
                        : 'تعذر تحميل الرسائل السابقة.',
                );
            }
        } finally {
            if (
                operationGeneration === conversationGenerationRef.current &&
                conversationRef.current?.publicId === conversationPublicId
            ) {
                setIsLoadingOlder(false);
            }
        }
    }, [isLoadingOlder, hasMore, oldestCursor, pageLocale]);

    const canRestart =
        !isLoading &&
        !isLoadingOlder &&
        !isRestarting &&
        !isAssistantTyping &&
        pendingSendCount === 0;

    const restartChat = useCallback(async () => {
        if (!canRestart || isRestartingRef.current) {
            return;
        }

        const restartGeneration = conversationGenerationRef.current;
        isRestartingRef.current = true;
        setIsRestarting(true);

        try {
            const data = await restartConversation(pageLocale);

            if (restartGeneration !== conversationGenerationRef.current) {
                return;
            }

            conversationGenerationRef.current += 1;
            setConversation(data);
            conversationRef.current = data;
            setMessages(data.messages);
            messagesRef.current = data.messages;
            setHasMore(data.hasMore);
            setOldestCursor(data.oldestCursor ?? null);
            setIsLoading(false);
            setIsLoadingOlder(false);
            setIsAssistantTyping(false);
            setUnreadCount(0);
            setError(null);
            queueRef.current = [];
            setPendingSendCount(0);
            announceStatus(
                pageLocale === 'en'
                    ? 'A new conversation has started.'
                    : 'بدأت محادثة جديدة.',
            );
        } catch {
            if (restartGeneration === conversationGenerationRef.current) {
                const errorMessage =
                    pageLocale === 'en'
                        ? 'Failed to start a new conversation. Please try again.'
                        : 'تعذر بدء محادثة جديدة. حاول مرة أخرى.';
                setError(errorMessage);
                announceStatus(errorMessage);
            }
        } finally {
            isRestartingRef.current = false;
            setIsRestarting(false);
        }
    }, [announceStatus, canRestart, pageLocale]);

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
        canRestart,
        hasMore,
        unreadCount,
        error,
        clearError: () => setError(null),
        statusAnnouncement: statusAnnouncement.message,
        statusAnnouncementId: statusAnnouncement.id,
        sendMessage,
        retryMessage,
        loadOlderMessages,
        restartChat,
    };
}
