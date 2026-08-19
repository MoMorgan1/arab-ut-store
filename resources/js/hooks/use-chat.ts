import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    ChatApiError,
    fetchConversation,
    fetchOrStartActiveConversation,
    sendChatMessage,
} from '@/lib/chat-api';
import type { ChatConversation, ChatMessage } from '@/types/chat';

export type UseChatOptions = {
    locale?: string;
};

export function useChat(options: UseChatOptions = {}) {
    const { props } = usePage();
    const chatConfig = props.chat;
    const isChatEnabled = chatConfig?.enabled === true;
    const pageLocale = (props.locale as string) || options.locale || 'ar';

    const [isOpen, setIsOpen] = useState(false);
    const [conversation, setConversation] = useState<ChatConversation | null>(
        null,
    );
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isSending, setIsSending] = useState(false);
    const [isAssistantTyping, setIsAssistantTyping] = useState(false);
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [hasMore, setHasMore] = useState(false);
    const [oldestCursor, setOldestCursor] = useState<string | null>(null);
    const [unreadCount, setUnreadCount] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [statusAnnouncement, setStatusAnnouncement] = useState<string | null>(
        null,
    );

    const activeConversationIdRef = useRef<string | null>(null);
    const isOpenRef = useRef(isOpen);

    useEffect(() => {
        isOpenRef.current = isOpen;
    }, [isOpen]);

    const announceStatus = useCallback((message: string) => {
        setStatusAnnouncement(message);
    }, []);

    const initializeChat = useCallback(async () => {
        if (!isChatEnabled || isLoading || conversation !== null) {
            return;
        }

        setIsLoading(true);
        setError(null);

        try {
            const data = await fetchOrStartActiveConversation(pageLocale);
            setConversation(data);
            setMessages(data.messages);
            setHasMore(data.hasMore);
            setOldestCursor(data.oldestCursor ?? null);
            activeConversationIdRef.current = data.publicId;
        } catch {
            const errorMessage =
                pageLocale === 'en'
                    ? 'Failed to connect to chat. Please try again.'
                    : 'تعذر الاتصال بالشات. يرجى المحاولة مرة أخرى.';
            setError(errorMessage);
        } finally {
            setIsLoading(false);
        }
    }, [isChatEnabled, isLoading, conversation, pageLocale]);

    const openChat = useCallback(() => {
        setIsOpen(true);
        setUnreadCount(0);

        if (conversation === null) {
            void initializeChat();
        }
    }, [conversation, initializeChat]);

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

    const sendMessage = useCallback(
        async (content: string) => {
            const trimmed = content.trim();

            if (trimmed === '' || isSending) {
                return;
            }

            let currentConv = conversation;

            if (currentConv === null) {
                try {
                    currentConv =
                        await fetchOrStartActiveConversation(pageLocale);
                    setConversation(currentConv);
                    setMessages(currentConv.messages);
                    setHasMore(currentConv.hasMore);
                    setOldestCursor(currentConv.oldestCursor ?? null);
                } catch {
                    setError(
                        pageLocale === 'en'
                            ? 'Failed to start conversation.'
                            : 'تعذر بدء المحادثة.',
                    );

                    return;
                }
            }

            const tempId = `temp-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const optimisticMessage: ChatMessage = {
                publicId: tempId,
                conversationPublicId: currentConv.publicId,
                senderType: 'customer',
                messageType: 'text',
                content: trimmed,
                createdAt: new Date().toISOString(),
                clientStatus: 'sending',
                tempId,
            };

            setMessages((prev) => [...prev, optimisticMessage]);
            setIsSending(true);
            setError(null);

            try {
                const result = await sendChatMessage(
                    currentConv.publicId,
                    trimmed,
                );

                setMessages((prev) =>
                    prev.map((msg) =>
                        msg.tempId === tempId
                            ? { ...result.message, clientStatus: 'sent' }
                            : msg,
                    ),
                );

                if (result.demoReply !== null) {
                    setIsAssistantTyping(true);
                    announceStatus(
                        pageLocale === 'en'
                            ? 'Assistant is typing...'
                            : 'المساعد يكتب الآن...',
                    );

                    setTimeout(() => {
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
                setMessages((prev) =>
                    prev.map((msg) =>
                        msg.tempId === tempId
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
                setIsSending(false);
            }
        },
        [conversation, isSending, pageLocale, announceStatus],
    );

    const retryMessage = useCallback(
        async (tempId: string) => {
            const failedMsg = messages.find((m) => m.tempId === tempId);

            if (failedMsg === undefined) {
                return;
            }

            setMessages((prev) => prev.filter((m) => m.tempId !== tempId));
            await sendMessage(failedMsg.content);
        },
        [messages, sendMessage],
    );

    const loadOlderMessages = useCallback(async () => {
        if (
            conversation === null ||
            isLoadingOlder ||
            !hasMore ||
            oldestCursor === null
        ) {
            return;
        }

        setIsLoadingOlder(true);

        try {
            const data = await fetchConversation(
                conversation.publicId,
                oldestCursor,
                50,
            );
            setMessages((prev) => [...data.messages, ...prev]);
            setHasMore(data.hasMore);
            setOldestCursor(data.oldestCursor ?? null);
        } catch {
            setError(
                pageLocale === 'en'
                    ? 'Failed to load older messages.'
                    : 'تعذر تحميل الرسائل السابقة.',
            );
        } finally {
            setIsLoadingOlder(false);
        }
    }, [conversation, isLoadingOlder, hasMore, oldestCursor, pageLocale]);

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
        isSending,
        isAssistantTyping,
        isLoadingOlder,
        hasMore,
        unreadCount,
        error,
        clearError: () => setError(null),
        statusAnnouncement,
        sendMessage,
        retryMessage,
        loadOlderMessages,
    };
}
