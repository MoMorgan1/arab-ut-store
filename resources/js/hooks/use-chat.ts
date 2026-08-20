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
};

type StatusAnnouncement = {
    id: number;
    message: string;
};

type DemoReplyTimeoutId = ReturnType<typeof setTimeout>;

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

    useEffect(
        () => () => {
            startConversationGeneration();
        },
        [startConversationGeneration],
    );

    const appendDeliveredDemoReply = useCallback(
        (demoReply: ChatMessage) => {
            setMessages((previousMessages) => [...previousMessages, demoReply]);

            if (!isOpenRef.current) {
                setUnreadCount((count) => count + 1);
            }

            announceStatus(
                pageLocale === 'en'
                    ? 'New message from assistant.'
                    : 'وصلت رسالة جديدة من المساعد.',
            );
        },
        [announceStatus, pageLocale],
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

        while (queueRef.current.length > 0) {
            const item = queueRef.current.shift()!;

            let currentConv: ChatConversation;

            try {
                currentConv = await getOrInitConversation();
            } catch {
                setMessages((prev) =>
                    prev.map((msg) =>
                        msg.tempId === item.tempId
                            ? { ...msg, clientStatus: 'error' }
                            : msg,
                    ),
                );

                continue;
            }

            try {
                const messageGeneration = conversationGenerationRef.current;
                const result = await sendChatMessage(
                    currentConv.publicId,
                    item.content,
                    item.clientMessageId,
                );

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
                    scheduleDemoReply(result.demoReply, messageGeneration);
                }
            } catch (err) {
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
                showError(errorMessage);
            }
        }

        isProcessingQueueRef.current = false;
    }, [getOrInitConversation, pageLocale, scheduleDemoReply, showError]);

    const sendMessage = useCallback(
        async (content: string) => {
            const trimmed = content.trim();

            if (trimmed === '') {
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
            });

            void processQueue();
        },
        [processQueue],
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
            });

            void processQueue();
        },
        [processQueue],
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

        try {
            const data = await fetchConversation(
                conversationRef.current.publicId,
                oldestCursor,
                50,
            );
            setMessages((prev) => [...data.messages, ...prev]);
            setHasMore(data.hasMore);
            setOldestCursor(data.oldestCursor ?? null);
        } catch {
            showError(
                pageLocale === 'en'
                    ? 'Failed to load older messages.'
                    : 'تعذر تحميل الرسائل السابقة.',
            );
        } finally {
            setIsLoadingOlder(false);
        }
    }, [isLoadingOlder, hasMore, oldestCursor, pageLocale, showError]);

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
        if (!canRestart) {
            return;
        }

        setIsRestarting(true);

        try {
            const data = await restartConversation(pageLocale);

            startConversationGeneration();
            queueRef.current = [];
            isProcessingQueueRef.current = false;
            initializationPromiseRef.current = null;
            setConversation(data);
            conversationRef.current = data;
            setMessages(data.messages);
            messagesRef.current = data.messages;
            setHasMore(data.hasMore);
            setOldestCursor(data.oldestCursor ?? null);
            setUnreadCount(0);
            setError(null);
            setIsAssistantTyping(false);
            announceStatus(
                pageLocale === 'en'
                    ? 'New conversation started.'
                    : 'بدأت محادثة جديدة.',
            );
        } catch {
            const errorMessage =
                pageLocale === 'en'
                    ? 'Failed to start a new conversation. Please try again.'
                    : 'تعذر بدء محادثة جديدة. حاول مرة ثانية.';
            showError(errorMessage);
        } finally {
            setIsRestarting(false);
        }
    }, [
        announceStatus,
        canRestart,
        pageLocale,
        showError,
        startConversationGeneration,
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
