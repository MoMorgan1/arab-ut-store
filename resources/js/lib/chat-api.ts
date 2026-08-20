import type { ChatConversation, ChatMessage } from '@/types/chat';

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

async function parseJsonPayload(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
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

async function postConversation(
    endpoint: string,
    locale: string | undefined,
    failureMessage: string,
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
        response = await fetch(endpoint, {
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

    if (
        !response.ok ||
        payload === null ||
        typeof payload !== 'object' ||
        !('data' in payload)
    ) {
        throw new ChatApiError(
            extractErrorCode(payload),
            response.status,
            failureMessage,
        );
    }

    return (payload as { data: ChatConversation }).data;
}

export async function fetchOrStartActiveConversation(
    locale?: string,
): Promise<ChatConversation> {
    return postConversation(
        '/chat/conversations',
        locale,
        'Failed to start chat conversation.',
    );
}

export async function restartConversation(
    locale: string,
): Promise<ChatConversation> {
    return postConversation(
        '/chat/conversations/restart',
        locale,
        'Failed to restart chat conversation.',
    );
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

    if (
        !response.ok ||
        payload === null ||
        typeof payload !== 'object' ||
        !('data' in payload)
    ) {
        throw new ChatApiError(
            extractErrorCode(payload),
            response.status,
            'Failed to fetch conversation.',
        );
    }

    return (payload as { data: ChatConversation }).data;
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

    if (
        !response.ok ||
        payload === null ||
        typeof payload !== 'object' ||
        !('data' in payload)
    ) {
        throw new ChatApiError(
            extractErrorCode(payload),
            response.status,
            'Failed to send message.',
        );
    }

    return (
        payload as {
            data: { message: ChatMessage; demoReply: ChatMessage | null };
        }
    ).data;
}
