export type ChatSenderType = 'customer' | 'assistant' | 'system';

export type ChatMessageType = 'text' | 'system';

export type ChatConversationStatus = 'open' | 'closed' | 'archived';

export type ChatSurface = 'store' | 'account';

export type ChatMessage = {
    publicId: string;
    conversationPublicId?: string;
    clientMessageId?: string;
    senderType: ChatSenderType;
    messageType: ChatMessageType;
    content: string;
    metadata?: Record<string, unknown> | null;
    createdAt: string;
    clientStatus?: 'sending' | 'sent' | 'error';
    tempId?: string;
};

export type ChatConversation = {
    publicId: string;
    status: ChatConversationStatus;
    locale: string;
    subject?: string | null;
    lastMessageAt?: string | null;
    messages: ChatMessage[];
    hasMore: boolean;
    oldestCursor?: string | null;
};

export type ChatGroupedCluster = {
    id: string;
    senderType: ChatSenderType;
    messages: ChatMessage[];
    firstMessageAt: string;
    lastMessageAt: string;
};

export type ChatSharedProps = {
    enabled: boolean;
    demoAssistant: boolean;
};
