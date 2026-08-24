export type ChatSenderType = 'customer' | 'assistant' | 'system' | 'staff';

export type ChatMessageType = 'text' | 'system' | 'internal_note';

export type ChatHandoffState =
    'none' | 'offered' | 'requested' | 'active' | 'resolved';

export type ChatConversationStatus = 'open' | 'closed' | 'archived';

export type ChatSurface = 'store' | 'account';

export type AgentTurnStatus =
    'waiting' | 'running' | 'completed' | 'failed' | 'cancelled';

export type ChatConversationTicket = {
    number: string;
    status: 'open' | 'resolved' | 'closed' | string;
    responderName?: string | null;
    subject?: string | null;
    publicId?: string;
};

export type ChatConversationSummary = {
    publicId: string;
    subject: string;
    lastMessageAt: string | null;
    status: ChatConversationStatus;
    ticketNumber?: string | null;
};

/** Payload of `GET /chat/conversations` — the widget's history list. */
export type ChatConversationHistoryResponse = {
    conversations: ChatConversationSummary[];
    hasMore: boolean;
    oldestCursor: string | null;
};

export type ChatMessage = {
    publicId: string;
    conversationPublicId?: string;
    clientMessageId?: string;
    senderType: ChatSenderType;
    messageType: ChatMessageType;
    content: string;
    metadata?: Record<string, unknown> | null;
    staffName?: string | null;
    createdAt: string;
    clientStatus?: 'sending' | 'sent' | 'error';
    tempId?: string;
    streamStatus?: 'streaming';
};

export type AgentTurnState = {
    publicId: string;
    status: AgentTurnStatus;
    attemptCount: number;
    retryable: boolean;
    hasPendingMessages: boolean;
    errorCode: string | null;
    handoffState?: ChatHandoffState;
    message: ChatMessage | null;
};

export type AppStreamEvent =
    | { event: 'turn.created'; data: { turn: AgentTurnState } }
    | { event: 'response.delta'; data: { turnPublicId: string; delta: string } }
    | {
          event: 'response.completed';
          data: { turn: AgentTurnState; message: ChatMessage };
      }
    | {
          event: 'response.failed';
          data: { turn: AgentTurnState; code: string; message: string };
      };

export type ChatConversation = {
    publicId: string;
    status: ChatConversationStatus;
    locale: string;
    subject?: string | null;
    lastMessageAt?: string | null;
    assistantMode?: 'agent' | 'demo' | 'none';
    handoffState?: ChatHandoffState;
    ticket?: ChatConversationTicket | null;
    messages: ChatMessage[];
    latestTurn?: AgentTurnState | null;
    latestTurnState?: AgentTurnState | null;
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

export type ChatServicePrice = {
    amountMinor: number;
    currency: string;
    unit: string;
};

export type ChatServicePrices = Record<string, ChatServicePrice>;

export type ChatSharedProps = {
    enabled: boolean;
    servicePrices?: ChatServicePrices;
};
