import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useChat } from '@/hooks/use-chat';
import type { ChatConversation, ChatMessage } from '@/types/chat';

function conversation(
    publicId: string,
    messages: ChatMessage[] = [],
): ChatConversation {
    return {
        publicId,
        status: 'open',
        locale: 'en',
        subject: null,
        lastMessageAt: '2026-08-20T10:00:00.000Z',
        messages,
        hasMore: false,
        oldestCursor: null,
    };
}

function assistantReply(publicId: string, content: string): ChatMessage {
    return {
        publicId,
        conversationPublicId: 'conv-demo-old',
        senderType: 'assistant',
        messageType: 'text',
        content,
        createdAt: '2026-08-20T10:01:00.000Z',
    };
}

function jsonResponse(data: unknown, status = 200): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => ({ data }),
    } as Response;
}

function installChatFetch(
    demoReplies: ChatMessage[],
    restartedConversation?: ChatConversation,
) {
    let sentMessageCount = 0;

    vi.mocked(fetch).mockImplementation(async (url, init) => {
        const path = String(url);

        if (path === '/chat/conversations/restart') {
            if (restartedConversation === undefined) {
                throw new Error('Unexpected restart request.');
            }

            return jsonResponse(restartedConversation);
        }

        if (path.includes('/messages')) {
            const requestBody = JSON.parse(String(init?.body)) as {
                content: string;
                client_message_id: string;
            };
            const messageNumber = sentMessageCount + 1;
            const demoReply = demoReplies[sentMessageCount] ?? null;
            sentMessageCount = messageNumber;

            return jsonResponse(
                {
                    message: {
                        publicId: `customer-${messageNumber}`,
                        conversationPublicId: 'conv-demo-old',
                        clientMessageId: requestBody.client_message_id,
                        senderType: 'customer',
                        messageType: 'text',
                        content: requestBody.content,
                        createdAt: '2026-08-20T10:00:30.000Z',
                    },
                    demoReply,
                },
                201,
            );
        }

        if (path === '/chat/conversations') {
            return jsonResponse(conversation('conv-demo-old'));
        }

        throw new Error(`Unexpected chat request: ${path}`);
    });
}

async function flushAsyncWork() {
    await act(async () => {
        for (let iteration = 0; iteration < 6; iteration += 1) {
            await Promise.resolve();
        }
    });
}

describe('demo reply lifecycle', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        cleanup();
        vi.clearAllTimers();
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('keeps restart disabled until two overlapping delayed replies settle', async () => {
        installChatFetch([
            assistantReply('reply-one', 'First delayed reply'),
            assistantReply('reply-two', 'Second delayed reply'),
        ]);
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();

        act(() => void result.current.sendMessage('First question'));
        await flushAsyncWork();
        expect(result.current.isAssistantTyping).toBe(true);

        act(() => vi.advanceTimersByTime(100));
        act(() => void result.current.sendMessage('Second question'));
        await flushAsyncWork();
        expect(result.current.isAssistantTyping).toBe(true);

        act(() => vi.advanceTimersByTime(1000));

        expect(
            result.current.messages.some(
                (message) => message.publicId === 'reply-one',
            ),
        ).toBe(true);
        expect(
            result.current.messages.some(
                (message) => message.publicId === 'reply-two',
            ),
        ).toBe(false);
        expect(result.current.isAssistantTyping).toBe(true);
        expect(result.current.canRestart).toBe(false);

        act(() => vi.advanceTimersByTime(100));

        expect(
            result.current.messages.some(
                (message) => message.publicId === 'reply-two',
            ),
        ).toBe(true);
        expect(result.current.isAssistantTyping).toBe(false);
        expect(result.current.canRestart).toBe(true);
    });

    it('blocks an old reply callback after a successful generation transition', async () => {
        const onboarding = assistantReply(
            'new-onboarding',
            'New conversation onboarding',
        );
        onboarding.conversationPublicId = 'conv-demo-new';
        installChatFetch(
            [assistantReply('stale-reply', 'Old conversation reply')],
            conversation('conv-demo-new', [onboarding]),
        );
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        const restartBeforePendingReply = result.current.restartChat;

        act(() => void result.current.sendMessage('Old question'));
        await flushAsyncWork();
        expect(result.current.isAssistantTyping).toBe(true);

        const clearTimeoutSpy = vi
            .spyOn(globalThis, 'clearTimeout')
            .mockImplementation(() => undefined);

        await act(async () => {
            await restartBeforePendingReply();
        });

        expect(result.current.conversation?.publicId).toBe('conv-demo-new');
        expect(clearTimeoutSpy).toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(1100));

        expect(
            result.current.messages.some(
                (message) => message.publicId === 'stale-reply',
            ),
        ).toBe(false);
        expect(result.current.messages).toEqual([onboarding]);
        expect(result.current.isAssistantTyping).toBe(false);
    });

    it('does not schedule an old response that arrives after a generation transition', async () => {
        const onboarding = assistantReply(
            'late-response-onboarding',
            'Replacement conversation onboarding',
        );
        onboarding.conversationPublicId = 'conv-late-response-new';
        const lateReply = assistantReply(
            'late-old-reply',
            'Late old conversation reply',
        );
        let resolveMessageResponse: ((response: Response) => void) | undefined;
        const pendingMessageResponse = new Promise<Response>((resolve) => {
            resolveMessageResponse = resolve;
        });

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                return jsonResponse(conversation('conv-demo-old'));
            }

            if (path.includes('/messages')) {
                return pendingMessageResponse;
            }

            if (path === '/chat/conversations/restart') {
                return jsonResponse(
                    conversation('conv-late-response-new', [onboarding]),
                );
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        const restartBeforePendingSend = result.current.restartChat;

        act(() => void result.current.sendMessage('Question still in flight'));
        await flushAsyncWork();

        await act(async () => {
            await restartBeforePendingSend();
        });
        expect(result.current.conversation?.publicId).toBe(
            'conv-late-response-new',
        );

        resolveMessageResponse?.(
            jsonResponse(
                {
                    message: {
                        publicId: 'late-customer-message',
                        conversationPublicId: 'conv-demo-old',
                        clientMessageId: 'late-client-message',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'Question still in flight',
                        createdAt: '2026-08-20T10:00:30.000Z',
                    },
                    demoReply: lateReply,
                },
                201,
            ),
        );
        await flushAsyncWork();

        expect(result.current.isAssistantTyping).toBe(false);
        expect(vi.getTimerCount()).toBe(0);

        act(() => vi.advanceTimersByTime(1100));

        expect(
            result.current.messages.some(
                (message) => message.publicId === 'late-old-reply',
            ),
        ).toBe(false);
        expect(result.current.messages).toEqual([onboarding]);
    });

    it('clears pending delayed replies on unmount without a state update', async () => {
        installChatFetch([
            assistantReply('unmounted-reply', 'Reply after unmount'),
        ]);
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => undefined);
        const { result, unmount } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        act(() => void result.current.sendMessage('Unmount question'));
        await flushAsyncWork();

        expect(result.current.isAssistantTyping).toBe(true);
        expect(vi.getTimerCount()).toBe(1);

        unmount();

        expect(vi.getTimerCount()).toBe(0);
        act(() => vi.runAllTimers());
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('delivers one normal delayed reply and resolves typing', async () => {
        installChatFetch([
            assistantReply('normal-reply', 'Normal delayed reply'),
        ]);
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        act(() => void result.current.sendMessage('Normal question'));
        await flushAsyncWork();

        expect(result.current.isAssistantTyping).toBe(true);
        expect(result.current.canRestart).toBe(false);

        act(() => vi.advanceTimersByTime(1099));
        expect(
            result.current.messages.some(
                (message) => message.publicId === 'normal-reply',
            ),
        ).toBe(false);
        expect(result.current.isAssistantTyping).toBe(true);

        act(() => vi.advanceTimersByTime(1));

        expect(
            result.current.messages.some(
                (message) => message.publicId === 'normal-reply',
            ),
        ).toBe(true);
        expect(result.current.isAssistantTyping).toBe(false);
        expect(result.current.canRestart).toBe(true);
    });
});
