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

function errorResponse(code: string, status: number): Response {
    return {
        ok: false,
        status,
        json: async () => ({ error: { code } }),
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

    it('reacquires the canonical conversation after another tab closes the active thread', async () => {
        const onboarding = assistantReply(
            'replacement-onboarding',
            'Replacement onboarding',
        );
        onboarding.conversationPublicId = 'conv-recovered-new';
        const delayedReply = assistantReply(
            'old-delayed-reply',
            'Old delayed reply',
        );
        let acquisitionCount = 0;
        let messageCount = 0;

        vi.mocked(fetch).mockImplementation(async (url, init) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                return jsonResponse(
                    acquisitionCount === 1
                        ? conversation('conv-demo-old')
                        : conversation('conv-recovered-new', [onboarding]),
                );
            }

            if (path.includes('/messages')) {
                messageCount += 1;

                if (messageCount === 2) {
                    return errorResponse('conversation_closed', 409);
                }

                const requestBody = JSON.parse(String(init?.body)) as {
                    content: string;
                    client_message_id: string;
                };

                return jsonResponse(
                    {
                        message: {
                            publicId: 'old-customer-message',
                            conversationPublicId: 'conv-demo-old',
                            clientMessageId: requestBody.client_message_id,
                            senderType: 'customer',
                            messageType: 'text',
                            content: requestBody.content,
                            createdAt: '2026-08-20T10:00:30.000Z',
                        },
                        demoReply: delayedReply,
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
        act(() => void result.current.sendMessage('First old question'));
        await flushAsyncWork();
        expect(result.current.isAssistantTyping).toBe(true);
        expect(vi.getTimerCount()).toBe(1);

        act(() => void result.current.sendMessage('Closed-thread question'));
        await flushAsyncWork();

        expect(acquisitionCount).toBe(2);
        expect(result.current.conversation?.publicId).toBe(
            'conv-recovered-new',
        );
        expect(result.current.messages).toEqual([
            onboarding,
            expect.objectContaining({
                content: 'Closed-thread question',
                conversationPublicId: 'conv-recovered-new',
                clientStatus: 'error',
            }),
        ]);
        expect(result.current.unreadCount).toBe(0);
        expect(result.current.error).toBe(
            "The conversation changed. Your unsent messages are saved. Choose Retry when you're ready.",
        );
        expect(result.current.isAssistantTyping).toBe(false);
        expect(vi.getTimerCount()).toBe(0);

        act(() => vi.advanceTimersByTime(1100));
        expect(
            result.current.messages.some(
                (message) => message.publicId === 'old-delayed-reply',
            ),
        ).toBe(false);
    });

    it('preserves a closed-send item and queued tail as FIFO drafts until explicit retry', async () => {
        const onboarding = assistantReply(
            'closed-recovery-onboarding',
            'Recovered conversation onboarding',
        );
        onboarding.conversationPublicId = 'conv-closed-recovered';
        let acquisitionCount = 0;
        let resolveClosedSend: ((response: Response) => void) | undefined;
        const closedSendResponse = new Promise<Response>((resolve) => {
            resolveClosedSend = resolve;
        });
        const messageRequests: Array<{
            path: string;
            body: { content: string; client_message_id: string };
        }> = [];

        vi.mocked(fetch).mockImplementation(async (url, init) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                return jsonResponse(
                    acquisitionCount === 1
                        ? conversation('conv-demo-old')
                        : conversation('conv-closed-recovered', [onboarding]),
                );
            }

            if (path.includes('/messages')) {
                const body = JSON.parse(String(init?.body)) as {
                    content: string;
                    client_message_id: string;
                };
                messageRequests.push({ path, body });

                if (messageRequests.length === 1) {
                    return closedSendResponse;
                }

                return jsonResponse(
                    {
                        message: {
                            publicId: 'retried-first-message',
                            conversationPublicId: 'conv-closed-recovered',
                            clientMessageId: body.client_message_id,
                            senderType: 'customer',
                            messageType: 'text',
                            content: body.content,
                            createdAt: '2026-08-20T10:02:00.000Z',
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

        act(() => void result.current.sendMessage('Failed first draft'));
        await flushAsyncWork();
        const firstDraft = result.current.messages[0];

        act(() => void result.current.sendMessage('Queued second draft'));
        await flushAsyncWork();
        const secondDraft = result.current.messages[1];

        expect(messageRequests).toHaveLength(1);

        resolveClosedSend?.(errorResponse('conversation_closed', 409));
        await flushAsyncWork();

        expect(acquisitionCount).toBe(2);
        expect(result.current.conversation?.publicId).toBe(
            'conv-closed-recovered',
        );
        expect(messageRequests).toHaveLength(1);
        expect(result.current.messages).toEqual([
            onboarding,
            expect.objectContaining({
                content: 'Failed first draft',
                tempId: firstDraft.tempId,
                publicId: firstDraft.tempId,
                clientMessageId: firstDraft.clientMessageId,
                conversationPublicId: 'conv-closed-recovered',
                clientStatus: 'error',
            }),
            expect.objectContaining({
                content: 'Queued second draft',
                tempId: secondDraft.tempId,
                publicId: secondDraft.tempId,
                clientMessageId: secondDraft.clientMessageId,
                conversationPublicId: 'conv-closed-recovered',
                clientStatus: 'error',
            }),
        ]);
        expect(result.current.error).toBe(
            "The conversation changed. Your unsent messages are saved. Choose Retry when you're ready.",
        );

        act(() => void result.current.retryMessage(firstDraft.tempId!));
        await flushAsyncWork();

        expect(messageRequests).toHaveLength(2);
        expect(messageRequests[1]).toEqual({
            path: '/chat/conversations/conv-closed-recovered/messages',
            body: {
                content: 'Failed first draft',
                client_message_id: firstDraft.clientMessageId,
            },
        });
        expect(result.current.messages).toEqual([
            onboarding,
            expect.objectContaining({
                publicId: 'retried-first-message',
                clientMessageId: firstDraft.clientMessageId,
                content: 'Failed first draft',
                clientStatus: 'sent',
            }),
            expect.objectContaining({
                tempId: secondDraft.tempId,
                clientMessageId: secondDraft.clientMessageId,
                content: 'Queued second draft',
                clientStatus: 'error',
            }),
        ]);
    });

    it('preserves prior error drafts when a later queued send adopts the canonical conversation', async () => {
        const firstOnboarding = assistantReply(
            'first-adoption-onboarding',
            'First recovered conversation',
        );
        firstOnboarding.conversationPublicId = 'conv-recovered-first';
        const secondOnboarding = assistantReply(
            'second-adoption-onboarding',
            'Second recovered conversation',
        );
        secondOnboarding.conversationPublicId = 'conv-recovered-second';
        let acquisitionCount = 0;
        let resolveFirstRecovery: ((response: Response) => void) | undefined;
        const firstRecoveryResponse = new Promise<Response>((resolve) => {
            resolveFirstRecovery = resolve;
        });
        const messageRequests: Array<{
            path: string;
            body: { content: string; client_message_id: string };
        }> = [];

        vi.mocked(fetch).mockImplementation(async (url, init) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                if (acquisitionCount === 1) {
                    return jsonResponse(conversation('conv-demo-old'));
                }

                if (acquisitionCount === 2) {
                    return firstRecoveryResponse;
                }

                return jsonResponse(
                    acquisitionCount === 3
                        ? conversation('conv-recovered-first', [
                              firstOnboarding,
                          ])
                        : conversation('conv-recovered-second', [
                              secondOnboarding,
                          ]),
                );
            }

            if (path.includes('/messages')) {
                const body = JSON.parse(String(init?.body)) as {
                    content: string;
                    client_message_id: string;
                };
                messageRequests.push({ path, body });

                if (
                    messageRequests.length <= 2 ||
                    messageRequests.length === 4
                ) {
                    return errorResponse('conversation_closed', 409);
                }

                return jsonResponse(
                    {
                        message: {
                            publicId:
                                messageRequests.length === 3
                                    ? 'retried-first-draft'
                                    : 'retried-second-draft',
                            conversationPublicId:
                                messageRequests.length === 3
                                    ? 'conv-recovered-first'
                                    : 'conv-recovered-second',
                            clientMessageId: body.client_message_id,
                            senderType: 'customer',
                            messageType: 'text',
                            content: body.content,
                            createdAt:
                                messageRequests.length === 3
                                    ? '2026-08-20T10:03:00.000Z'
                                    : '2026-08-20T10:04:00.000Z',
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

        act(() => void result.current.sendMessage('Earlier failed draft'));
        await flushAsyncWork();
        const firstDraft = result.current.messages[0];

        act(() => void result.current.sendMessage('Later queued draft'));
        await flushAsyncWork();
        const secondDraft = result.current.messages[1];

        resolveFirstRecovery?.(errorResponse('chat_unavailable', 503));
        await flushAsyncWork();

        expect(acquisitionCount).toBe(3);
        expect(messageRequests).toHaveLength(2);
        expect(result.current.conversation?.publicId).toBe(
            'conv-recovered-first',
        );
        expect(result.current.messages).toEqual([
            firstOnboarding,
            expect.objectContaining({
                publicId: firstDraft.tempId,
                tempId: firstDraft.tempId,
                clientMessageId: firstDraft.clientMessageId,
                content: 'Earlier failed draft',
                createdAt: firstDraft.createdAt,
                conversationPublicId: 'conv-recovered-first',
                clientStatus: 'error',
            }),
            expect.objectContaining({
                publicId: secondDraft.tempId,
                tempId: secondDraft.tempId,
                clientMessageId: secondDraft.clientMessageId,
                content: 'Later queued draft',
                createdAt: secondDraft.createdAt,
                conversationPublicId: 'conv-recovered-first',
                clientStatus: 'error',
            }),
        ]);

        act(() => void result.current.retryMessage(firstDraft.tempId!));
        await flushAsyncWork();

        expect(messageRequests[2]).toEqual({
            path: '/chat/conversations/conv-recovered-first/messages',
            body: {
                content: 'Earlier failed draft',
                client_message_id: firstDraft.clientMessageId,
            },
        });
        expect(result.current.messages).toEqual([
            firstOnboarding,
            expect.objectContaining({
                publicId: 'retried-first-draft',
                clientMessageId: firstDraft.clientMessageId,
                clientStatus: 'sent',
            }),
            expect.objectContaining({
                tempId: secondDraft.tempId,
                clientMessageId: secondDraft.clientMessageId,
                clientStatus: 'error',
            }),
        ]);

        act(() => void result.current.retryMessage(secondDraft.tempId!));
        await flushAsyncWork();

        expect(messageRequests[3]).toEqual({
            path: '/chat/conversations/conv-recovered-first/messages',
            body: {
                content: 'Later queued draft',
                client_message_id: secondDraft.clientMessageId,
            },
        });
        expect(result.current.conversation?.publicId).toBe(
            'conv-recovered-second',
        );
        expect(result.current.messages).toEqual([
            secondOnboarding,
            expect.objectContaining({
                publicId: secondDraft.tempId,
                tempId: secondDraft.tempId,
                clientMessageId: secondDraft.clientMessageId,
                createdAt: secondDraft.createdAt,
                conversationPublicId: 'conv-recovered-second',
                clientStatus: 'error',
            }),
        ]);

        act(() => void result.current.retryMessage(secondDraft.tempId!));
        await flushAsyncWork();

        expect(messageRequests[4]).toEqual({
            path: '/chat/conversations/conv-recovered-second/messages',
            body: {
                content: 'Later queued draft',
                client_message_id: secondDraft.clientMessageId,
            },
        });
        expect(result.current.messages).toEqual([
            secondOnboarding,
            expect.objectContaining({
                publicId: 'retried-second-draft',
                clientMessageId: secondDraft.clientMessageId,
                clientStatus: 'sent',
            }),
        ]);
    });

    it('adopts a committed restart recovered after the response is lost', async () => {
        const onboarding = assistantReply(
            'committed-onboarding',
            'Committed restart onboarding',
        );
        onboarding.conversationPublicId = 'conv-committed-new';
        let acquisitionCount = 0;

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                return jsonResponse(
                    acquisitionCount === 1
                        ? conversation('conv-demo-old')
                        : conversation('conv-committed-new', [onboarding]),
                );
            }

            if (path === '/chat/conversations/restart') {
                throw new Error('Restart response was lost.');
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        await act(async () => {
            await result.current.restartChat();
        });

        expect(acquisitionCount).toBe(2);
        expect(result.current.conversation?.publicId).toBe(
            'conv-committed-new',
        );
        expect(result.current.messages).toEqual([onboarding]);
        expect(result.current.error).toBeNull();
        expect(result.current.statusAnnouncement?.message).toBe(
            'New conversation started.',
        );
    });

    it('reacquires and adopts a different active conversation after a valid-JSON restart 500', async () => {
        const onboarding = assistantReply(
            'server-error-onboarding',
            'Recovered restart onboarding',
        );
        onboarding.conversationPublicId = 'conv-server-error-new';
        let acquisitionCount = 0;

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                return jsonResponse(
                    acquisitionCount === 1
                        ? conversation('conv-demo-old')
                        : conversation('conv-server-error-new', [onboarding]),
                );
            }

            if (path === '/chat/conversations/restart') {
                return errorResponse('chat_error', 500);
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        await act(async () => {
            await result.current.restartChat();
        });

        expect(acquisitionCount).toBe(2);
        expect(result.current.conversation?.publicId).toBe(
            'conv-server-error-new',
        );
        expect(result.current.messages).toEqual([onboarding]);
        expect(result.current.error).toBeNull();
        expect(result.current.statusAnnouncement?.message).toBe(
            'New conversation started.',
        );
    });

    it('preserves current state when an ambiguous restart failed before commit', async () => {
        const existingMessage = assistantReply(
            'existing-message',
            'Existing conversation message',
        );
        const oldConversation = conversation('conv-demo-old', [
            existingMessage,
        ]);
        let acquisitionCount = 0;

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                return jsonResponse(oldConversation);
            }

            if (path === '/chat/conversations/restart') {
                throw new Error('Restart failed before commit.');
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        const originalConversation = result.current.conversation;
        const originalMessages = result.current.messages;
        await act(async () => {
            await result.current.restartChat();
        });

        expect(acquisitionCount).toBe(2);
        expect(result.current.conversation).toBe(originalConversation);
        expect(result.current.messages).toBe(originalMessages);
        expect(result.current.error).toBe(
            'Failed to start a new conversation. Please try again.',
        );
    });

    it('preserves current state and reports recovery failure after two network errors', async () => {
        const oldConversation = conversation('conv-demo-old');
        let acquisitionCount = 0;

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                if (acquisitionCount === 1) {
                    return jsonResponse(oldConversation);
                }

                throw new Error('Recovery request failed.');
            }

            if (path === '/chat/conversations/restart') {
                throw new Error('Restart request failed.');
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        const originalConversation = result.current.conversation;
        const originalMessages = result.current.messages;
        await act(async () => {
            await result.current.restartChat();
        });

        expect(acquisitionCount).toBe(2);
        expect(result.current.conversation).toBe(originalConversation);
        expect(result.current.messages).toBe(originalMessages);
        expect(result.current.error).toBe(
            'Failed to confirm the new conversation. Your current chat is unchanged. Please try again.',
        );
    });

    it('ignores stale older-history and restart outcomes after lifecycle recovery adopts a new generation', async () => {
        const onboarding = assistantReply(
            'race-onboarding',
            'Recovered generation onboarding',
        );
        onboarding.conversationPublicId = 'conv-race-new';
        const oldConversation = {
            ...conversation('conv-demo-old'),
            hasMore: true,
            oldestCursor: 'oldest-old',
        };
        let acquisitionCount = 0;
        let resolveOlder: ((response: Response) => void) | undefined;
        let resolveRestart: ((response: Response) => void) | undefined;
        const olderResponse = new Promise<Response>((resolve) => {
            resolveOlder = resolve;
        });
        const restartResponse = new Promise<Response>((resolve) => {
            resolveRestart = resolve;
        });

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (path === '/chat/conversations') {
                acquisitionCount += 1;

                return jsonResponse(
                    acquisitionCount === 1
                        ? oldConversation
                        : conversation('conv-race-new', [onboarding]),
                );
            }

            if (path.startsWith('/chat/conversations/conv-demo-old?')) {
                return olderResponse;
            }

            if (path === '/chat/conversations/restart') {
                return restartResponse;
            }

            if (path.includes('/messages')) {
                return errorResponse('conversation_closed', 409);
            }

            throw new Error(`Unexpected chat request: ${path}`);
        });
        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );

        act(() => result.current.openChat());
        await flushAsyncWork();
        const restartFromOldGeneration = result.current.restartChat;
        act(() => void result.current.loadOlderMessages());
        let oldRestartRequest: Promise<void> | undefined;
        act(() => {
            oldRestartRequest = restartFromOldGeneration();
            void result.current.sendMessage('Trigger lifecycle recovery');
        });
        await flushAsyncWork();

        expect(result.current.conversation?.publicId).toBe('conv-race-new');
        expect(result.current.messages).toEqual([
            onboarding,
            expect.objectContaining({
                content: 'Trigger lifecycle recovery',
                conversationPublicId: 'conv-race-new',
                clientStatus: 'error',
            }),
        ]);
        const recoveredMessages = result.current.messages;
        const recoveryError = result.current.error;

        resolveOlder?.(
            jsonResponse(
                conversation('conv-demo-old', [
                    assistantReply('stale-older', 'Stale older message'),
                ]),
            ),
        );
        resolveRestart?.(
            jsonResponse(conversation('conv-stale-restart-success')),
        );
        await act(async () => {
            await oldRestartRequest;
        });
        await flushAsyncWork();

        expect(result.current.conversation?.publicId).toBe('conv-race-new');
        expect(result.current.messages).toBe(recoveredMessages);
        expect(result.current.error).toBe(recoveryError);
        expect(result.current.isLoadingOlder).toBe(false);
        expect(result.current.isRestarting).toBe(false);
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
