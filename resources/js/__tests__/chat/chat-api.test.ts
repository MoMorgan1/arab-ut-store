import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { restartConversation, sendChatMessage } from '@/lib/chat-api';
import type { ChatApiError } from '@/lib/chat-api';

function response(payload: unknown, status: number): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => payload,
    } as Response;
}

describe('chat API lifecycle errors', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('classifies a malformed successful restart response as ambiguous', async () => {
        vi.mocked(fetch).mockResolvedValue(response({ unexpected: true }, 200));

        await expect(restartConversation('en')).rejects.toMatchObject({
            code: 'invalid_response',
            status: 200,
        } satisfies Partial<ChatApiError>);
    });

    it('classifies invalid JSON on an error response as an ambiguous parse failure', async () => {
        vi.mocked(fetch).mockResolvedValue(
            new Response('<html>Bad gateway</html>', {
                status: 502,
                headers: { 'Content-Type': 'text/html' },
            }),
        );

        await expect(restartConversation('en')).rejects.toMatchObject({
            code: 'invalid_response',
            status: 502,
        } satisfies Partial<ChatApiError>);
    });

    it('rejects a restart data envelope without a conversation object', async () => {
        vi.mocked(fetch).mockResolvedValue(response({ data: null }, 200));

        await expect(restartConversation('en')).rejects.toMatchObject({
            code: 'invalid_response',
            status: 200,
        } satisfies Partial<ChatApiError>);
    });

    it('preserves the typed conversation_closed lifecycle code from send', async () => {
        vi.mocked(fetch).mockResolvedValue(
            response(
                {
                    error: {
                        code: 'conversation_closed',
                        message: 'Conversation closed.',
                    },
                },
                409,
            ),
        );

        await expect(
            sendChatMessage('conversation-old', 'Hello', 'client-one'),
        ).rejects.toMatchObject({
            code: 'conversation_closed',
            status: 409,
        } satisfies Partial<ChatApiError>);
    });
});
