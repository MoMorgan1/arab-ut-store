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
        const replacementMessages = result.current.messages;

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
        expect(result.current.messages).toBe(replacementMessages);

        act(() => vi.advanceTimersByTime(1100));

        expect(
            result.current.messages.some(
                (message) => message.publicId === 'late-old-reply',
            ),
        ).toBe(false);
        expect(result.current.messages).toEqual([onboarding]);
    });

    it('ignores an old send rejection after a successful generation transition', async () => {
        const replacementMessage = assistantReply(
            'rejection-onboarding',
            'Replacement after rejected send',
        );
        replacementMessage.conversationPublicId = 'conv-rejection-new';
        let rejectMessageRequest: ((reason?: unknown) => void) | undefined;
        const pendingMessageRequest = new Promise<Response>((_, reject) => {
            rejectMessageRequest = reject;
        });

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                return jsonResponse(conversation('conv-demo-old'));
            }

            if (path.includes('/messages')) {
                return pendingMessageRequest;
            }

            if (path === '/chat/conversations/restart') {
                return jsonResponse(
                    conversation('conv-rejection-new', [replacementMessage]),
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

        act(() => void result.current.sendMessage('Rejected old question'));
        await flushAsyncWork();
        await act(async () => {
            await restartBeforePendingSend();
        });

        const replacementMessages = result.current.messages;
        const replacementErrorAnnouncementId =
            result.current.errorAnnouncementId;
        rejectMessageRequest?.(new Error('Old request failed.'));
        await flushAsyncWork();

        expect(result.current.messages).toBe(replacementMessages);
        expect(result.current.error).toBeNull();
        expect(result.current.errorAnnouncementId).toBe(
            replacementErrorAnnouncementId,
        );
    });

    it('ignores a send response after unmount and schedules no work', async () => {
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

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result, unmount } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        act(() => void result.current.sendMessage('Unmounted send'));
        await flushAsyncWork();
        unmount();

        resolveMessageResponse?.(
            jsonResponse(
                {
                    message: {
                        publicId: 'unmounted-customer',
                        conversationPublicId: 'conv-demo-old',
                        clientMessageId: 'unmounted-client',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'Unmounted send',
                        createdAt: '2026-08-20T10:00:30.000Z',
                    },
                    demoReply: assistantReply(
                        'unmounted-success-reply',
                        'Reply must not schedule',
                    ),
                },
                201,
            ),
        );
        await flushAsyncWork();

        expect(vi.getTimerCount()).toBe(0);
    });

    it('leaves the last observable snapshot untouched when restart resolves after unmount', async () => {
        let resolveRestartResponse: ((response: Response) => void) | undefined;
        const pendingRestartResponse = new Promise<Response>((resolve) => {
            resolveRestartResponse = resolve;
        });

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                return jsonResponse(conversation('conv-demo-old'));
            }

            if (path === '/chat/conversations/restart') {
                return pendingRestartResponse;
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result, unmount } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();

        let restartRequest: Promise<void> | undefined;
        act(() => {
            restartRequest = result.current.restartChat();
        });
        expect(result.current.isRestarting).toBe(true);

        const lastMountedSnapshot = result.current;
        unmount();
        resolveRestartResponse?.(
            jsonResponse(conversation('conv-restart-after-unmount')),
        );
        await act(async () => {
            await restartRequest;
        });

        expect(result.current).toBe(lastMountedSnapshot);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('keeps localized retry and error state for a current-generation failure', async () => {
        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                return jsonResponse(conversation('conv-demo-old'));
            }

            if (path.includes('/messages')) {
                throw new Error('Current request failed.');
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        act(() => void result.current.sendMessage('Current failed question'));
        await flushAsyncWork();

        expect(result.current.messages).toHaveLength(1);
        expect(result.current.messages[0]).toMatchObject({
            content: 'Current failed question',
            clientStatus: 'error',
        });
        expect(result.current.error).toBe(
            'Failed to send message. Please retry.',
        );
        expect(result.current.errorAnnouncementId).toBe(1);
    });

    it('keeps a new-generation send queue owned while an old processor finishes', async () => {
        let resolveOldSend: ((response: Response) => void) | undefined;
        let resolveNewSend: ((response: Response) => void) | undefined;
        const oldSendResponse = new Promise<Response>((resolve) => {
            resolveOldSend = resolve;
        });
        const newSendResponse = new Promise<Response>((resolve) => {
            resolveNewSend = resolve;
        });
        let messageCallCount = 0;

        vi.mocked(fetch).mockImplementation(async (url, init) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                return jsonResponse(conversation('conv-demo-old'));
            }

            if (path === '/chat/conversations/restart') {
                return jsonResponse(conversation('conv-queue-new'));
            }

            if (path.includes('/messages')) {
                messageCallCount += 1;

                if (messageCallCount === 1) {
                    return oldSendResponse;
                }

                if (messageCallCount === 2) {
                    return newSendResponse;
                }

                const requestBody = JSON.parse(String(init?.body)) as {
                    content: string;
                    client_message_id: string;
                };

                return jsonResponse(
                    {
                        message: {
                            publicId: 'third-current-message',
                            conversationPublicId: 'conv-queue-new',
                            clientMessageId: requestBody.client_message_id,
                            senderType: 'customer',
                            messageType: 'text',
                            content: requestBody.content,
                            createdAt: '2026-08-20T10:00:40.000Z',
                        },
                        demoReply: null,
                    },
                    201,
                );
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        const restartBeforeOldSend = result.current.restartChat;

        act(() => void result.current.sendMessage('Old generation send'));
        await flushAsyncWork();
        await act(async () => {
            await restartBeforeOldSend();
        });

        act(() => void result.current.sendMessage('New generation second'));
        await flushAsyncWork();
        expect(messageCallCount).toBe(2);

        resolveOldSend?.(
            jsonResponse(
                {
                    message: {
                        publicId: 'old-completed-message',
                        conversationPublicId: 'conv-demo-old',
                        clientMessageId: 'old-client',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'Old generation send',
                        createdAt: '2026-08-20T10:00:20.000Z',
                    },
                    demoReply: null,
                },
                201,
            ),
        );
        await flushAsyncWork();

        act(() => void result.current.sendMessage('New generation third'));
        await flushAsyncWork();
        expect(messageCallCount).toBe(2);

        resolveNewSend?.(
            jsonResponse(
                {
                    message: {
                        publicId: 'second-current-message',
                        conversationPublicId: 'conv-queue-new',
                        clientMessageId: 'second-client',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'New generation second',
                        createdAt: '2026-08-20T10:00:30.000Z',
                    },
                    demoReply: null,
                },
                201,
            ),
        );
        await flushAsyncWork();

        expect(messageCallCount).toBe(3);
    });

    it('clears pending delayed reply timers on unmount', async () => {
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
