import type { ChatConversation, ChatMessage } from '@/types/chat';

const INVALID_JSON = Symbol('invalid-chat-json');

export class ChatApiError extends Error {
    readonly code: string;
    readonly status: number;

    constructor(
        code: string,
        status: number,
        message = 'Chat request failed.',
    ) {
        super(message);
        this.name = 'ChatApiError';
        this.code = code;
        this.status = status;
    }
}

function csrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return token === undefined || token === '' ? null : token;
}

async function parseJsonPayload(
    response: Response,
): Promise<unknown | typeof INVALID_JSON> {
    try {
        return await response.json();
    } catch {
        return INVALID_JSON;
    }
}

function extractErrorCode(payload: unknown): string {
    if (
        typeof payload === 'object' &&
        payload !== null &&
        'error' in payload &&
        typeof (payload as { error: unknown }).error === 'object' &&
        (payload as { error: { code?: unknown } }).error !== null &&
        typeof (payload as { error: { code?: unknown } }).error.code ===
            'string'
    ) {
        return (payload as { error: { code: string } }).error.code;
    }

    return 'chat_error';
}

function hasDataPayload(payload: unknown): payload is { data: unknown } {
    return typeof payload === 'object' && payload !== null && 'data' in payload;
}

function hasConversationData(
    payload: unknown,
): payload is { data: ChatConversation } {
    if (!hasDataPayload(payload)) {
        return false;
    }

    const conversation = payload.data;

    return (
        typeof conversation === 'object' &&
        conversation !== null &&
        'publicId' in conversation &&
        typeof conversation.publicId === 'string' &&
        'messages' in conversation &&
        Array.isArray(conversation.messages) &&
        'hasMore' in conversation &&
        typeof conversation.hasMore === 'boolean' &&
        'oldestCursor' in conversation &&
        (conversation.oldestCursor === null ||
            typeof conversation.oldestCursor === 'string')
    );
}

export async function fetchOrStartActiveConversation(
    locale?: string,
): Promise<ChatConversation> {
    const token = csrfToken();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    if (token !== null) {
        headers['X-CSRF-TOKEN'] = token;
    }

    let response: Response;

    try {
        response = await fetch('/chat/conversations', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers,
            body: JSON.stringify({ locale }),
        });
    } catch {
        throw new ChatApiError('network_error', 0, 'Network request failed.');
    }

    const payload = await parseJsonPayload(response);

    if (payload === INVALID_JSON) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid conversation response.',
        );
    }

    if (!response.ok) {
        throw new ChatApiError(
            extractErrorCode(payload),
            response.status,
            'Failed to start chat conversation.',
        );
    }

    if (!hasConversationData(payload)) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid conversation response.',
        );
    }

    return payload.data;
}

export async function restartConversation(
    locale: string,
): Promise<ChatConversation> {
    const token = csrfToken();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    if (token !== null) {
        headers['X-CSRF-TOKEN'] = token;
    }

    let response: Response;

    try {
        response = await fetch('/chat/conversations/restart', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers,
            body: JSON.stringify({ locale }),
        });
    } catch {
        throw new ChatApiError('network_error', 0, 'Network request failed.');
    }

    const payload = await parseJsonPayload(response);

    if (payload === INVALID_JSON) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid restart response.',
        );
    }

    if (!response.ok) {
        throw new ChatApiError(
            extractErrorCode(payload),
            response.status,
            'Failed to restart chat conversation.',
        );
    }

    if (!hasConversationData(payload)) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid restart response.',
        );
    }

    return payload.data;
}

export async function fetchConversation(
    conversationPublicId: string,
    beforeId?: string,
    limit = 50,
): Promise<ChatConversation> {
    const url = new URL(
        `/chat/conversations/${encodeURIComponent(conversationPublicId)}`,
        window.location.origin,
    );

    url.searchParams.set('limit', String(limit));

    if (beforeId !== undefined && beforeId !== '') {
        url.searchParams.set('before_id', beforeId);
    }

    let response: Response;

    try {
        response = await fetch(url.pathname + url.search, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
            },
        });
    } catch {
        throw new ChatApiError('network_error', 0, 'Network request failed.');
    }

    const payload = await parseJsonPayload(response);

    if (payload === INVALID_JSON) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid history response.',
        );
    }

    if (!response.ok) {
        throw new ChatApiError(
            extractErrorCode(payload),
            response.status,
            'Failed to fetch conversation.',
        );
    }

    if (!hasConversationData(payload)) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid history response.',
        );
    }

    return payload.data;
}

export async function sendChatMessage(
    conversationPublicId: string,
    content: string,
    clientMessageId: string,
): Promise<{ message: ChatMessage; demoReply: ChatMessage | null }> {
    const token = csrfToken();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    if (token !== null) {
        headers['X-CSRF-TOKEN'] = token;
    }

    let response: Response;

    try {
        response = await fetch(
            `/chat/conversations/${encodeURIComponent(conversationPublicId)}/messages`,
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers,
                body: JSON.stringify({
                    content,
                    client_message_id: clientMessageId,
                }),
            },
        );
    } catch {
        throw new ChatApiError('network_error', 0, 'Network request failed.');
    }

    const payload = await parseJsonPayload(response);

    if (payload === INVALID_JSON) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid message response.',
        );
    }

    if (!response.ok) {
        throw new ChatApiError(
            extractErrorCode(payload),
            response.status,
            'Failed to send message.',
        );
    }

    if (!hasDataPayload(payload)) {
        throw new ChatApiError(
            'invalid_response',
            response.status,
            'Chat returned an invalid message response.',
        );
    }

    return payload.data as {
        message: ChatMessage;
        demoReply: ChatMessage | null;
    };
}
