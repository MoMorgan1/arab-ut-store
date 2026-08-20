import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useChat } from '@/hooks/use-chat';

type Deferred<T> = {
    promise: Promise<T>;
    resolve: (value: T) => void;
};

function deferred<T>(): Deferred<T> {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((promiseResolve) => {
        resolve = promiseResolve;
    });

    return { promise, resolve };
}

function response(data: unknown, status = 200): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => data,
    } as Response;
}

const initialConversation = {
    publicId: 'conversation-old',
    status: 'open',
    locale: 'en',
    messages: [
        {
            publicId: 'old-onboarding',
            conversationPublicId: 'conversation-old',
            senderType: 'system',
            messageType: 'system',
            content: 'Old onboarding',
            createdAt: '2026-08-20T10:00:00.000Z',
        },
    ],
    hasMore: true,
    oldestCursor: 'old-onboarding',
};

const restartedConversation = {
    publicId: 'conversation-new',
    status: 'open',
    locale: 'en',
    messages: [
        {
            publicId: 'new-onboarding',
            conversationPublicId: 'conversation-new',
            senderType: 'system',
            messageType: 'system',
            content: 'New onboarding',
            createdAt: '2026-08-20T11:00:00.000Z',
        },
    ],
    hasMore: true,
    oldestCursor: 'new-onboarding',
};

describe('useChat restart race isolation', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('keeps restart unavailable while older messages load and ignores their stale completion', async () => {
        const older = deferred<Response>();
        const restart = deferred<Response>();

        vi.mocked(fetch).mockImplementation((input) => {
            const path = String(input);

            if (path === '/chat/conversations/restart') {
                return restart.promise;
            }

            if (path.startsWith('/chat/conversations/conversation-old?')) {
                return older.promise;
            }

            return Promise.resolve(response({ data: initialConversation }));
        });

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );
        act(() => result.current.openChat());
        await waitFor(() =>
            expect(result.current.conversation?.publicId).toBe(
                'conversation-old',
            ),
        );

        const staleRestart = result.current.restartChat;
        let loadRun!: Promise<void>;
        act(() => {
            loadRun = result.current.loadOlderMessages();
        });
        await waitFor(() => expect(result.current.isLoadingOlder).toBe(true));
        expect(result.current.canRestart).toBe(false);

        let restartRun!: Promise<void>;
        act(() => {
            restartRun = staleRestart();
        });
        restart.resolve(response({ data: restartedConversation }));
        await act(async () => restartRun);

        expect(result.current.conversation?.publicId).toBe('conversation-new');
        expect(result.current.isLoadingOlder).toBe(false);

        older.resolve(
            response({
                data: {
                    ...initialConversation,
                    messages: [
                        {
                            publicId: 'stale-older-message',
                            conversationPublicId: 'conversation-old',
                            senderType: 'assistant',
                            messageType: 'text',
                            content: 'Stale older message',
                            createdAt: '2026-08-20T09:00:00.000Z',
                        },
                    ],
                    hasMore: false,
                    oldestCursor: 'stale-older-message',
                },
            }),
        );
        await act(async () => loadRun);

        expect(
            result.current.messages.map((message) => message.publicId),
        ).toEqual(['new-onboarding']);
        expect(result.current.hasMore).toBe(true);
    });

    it('discards an old queued item after deferred initialization without posting to the replacement conversation', async () => {
        const initialization = deferred<Response>();
        const restart = deferred<Response>();
        const messageCalls: string[] = [];

        vi.mocked(fetch).mockImplementation((input) => {
            const path = String(input);

            if (path === '/chat/conversations') {
                return initialization.promise;
            }

            if (path === '/chat/conversations/restart') {
                return restart.promise;
            }

            if (path.includes('/messages')) {
                messageCalls.push(path);

                return Promise.resolve(
                    response({
                        data: {
                            message: {
                                publicId: 'ghost-server-message',
                                conversationPublicId: 'conversation-new',
                                senderType: 'customer',
                                messageType: 'text',
                                content: 'Ghost queued message',
                                createdAt: '2026-08-20T11:01:00.000Z',
                            },
                            demoReply: null,
                        },
                    }),
                );
            }

            throw new Error(`Unexpected fetch: ${path}`);
        });

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );
        const staleRestart = result.current.restartChat;

        act(() => result.current.openChat());
        act(() => void result.current.sendMessage('Ghost queued message'));

        let restartRun!: Promise<void>;
        act(() => {
            restartRun = staleRestart();
        });
        restart.resolve(response({ data: restartedConversation }));
        await act(async () => restartRun);

        initialization.resolve(response({ data: initialConversation }));
        await act(async () => Promise.resolve());
        await act(async () => Promise.resolve());

        expect(messageCalls).toEqual([]);
        expect(
            result.current.messages.map((message) => message.publicId),
        ).toEqual(['new-onboarding']);
        expect(result.current.isAssistantTyping).toBe(false);
        expect(result.current.canRestart).toBe(true);
    });

    it('does not let an old send completion unlock restart while a new send is pending', async () => {
        const oldSend = deferred<Response>();
        const newSend = deferred<Response>();
        const restart = deferred<Response>();

        vi.mocked(fetch).mockImplementation((input, init) => {
            const path = String(input);

            if (path === '/chat/conversations/restart') {
                return restart.promise;
            }

            if (path.includes('/messages')) {
                const body = JSON.parse(String(init?.body)) as {
                    content: string;
                };

                return body.content === 'Old pending message'
                    ? oldSend.promise
                    : newSend.promise;
            }

            return Promise.resolve(response({ data: initialConversation }));
        });

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );
        act(() => result.current.openChat());
        await waitFor(() => expect(result.current.canRestart).toBe(true));
        const staleRestart = result.current.restartChat;

        act(() => void result.current.sendMessage('Old pending message'));
        let restartRun!: Promise<void>;
        act(() => {
            restartRun = staleRestart();
        });
        restart.resolve(response({ data: restartedConversation }));
        await act(async () => restartRun);

        act(() => void result.current.sendMessage('New pending message'));
        oldSend.resolve(
            response({
                data: {
                    message: {
                        publicId: 'old-completed-message',
                        conversationPublicId: 'conversation-old',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'Old pending message',
                        createdAt: '2026-08-20T10:01:00.000Z',
                    },
                    demoReply: null,
                },
            }),
        );

        await waitFor(() =>
            expect(
                vi
                    .mocked(fetch)
                    .mock.calls.filter(([input]) =>
                        String(input).includes('/conversation-new/messages'),
                    ),
            ).toHaveLength(1),
        );
        expect(result.current.canRestart).toBe(false);

        newSend.resolve(
            response({
                data: {
                    message: {
                        publicId: 'new-completed-message',
                        conversationPublicId: 'conversation-new',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'New pending message',
                        createdAt: '2026-08-20T11:01:00.000Z',
                    },
                    demoReply: null,
                },
            }),
        );
        await waitFor(() => expect(result.current.canRestart).toBe(true));
        expect(
            result.current.messages.map((message) => message.publicId),
        ).toEqual(['new-onboarding', 'new-completed-message']);
    });

    it('ignores a stale demo reply from the replaced conversation', async () => {
        vi.useFakeTimers();
        const oldSend = deferred<Response>();
        const restart = deferred<Response>();

        vi.mocked(fetch).mockImplementation((input) => {
            const path = String(input);

            if (path === '/chat/conversations/restart') {
                return restart.promise;
            }

            if (path.includes('/messages')) {
                return oldSend.promise;
            }

            return Promise.resolve(response({ data: initialConversation }));
        });

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );
        act(() => result.current.openChat());
        await act(async () => Promise.resolve());
        await act(async () => Promise.resolve());
        const staleRestart = result.current.restartChat;

        act(() => void result.current.sendMessage('Old demo request'));
        let restartRun!: Promise<void>;
        act(() => {
            restartRun = staleRestart();
        });
        restart.resolve(response({ data: restartedConversation }));
        await act(async () => restartRun);

        oldSend.resolve(
            response({
                data: {
                    message: {
                        publicId: 'old-customer-message',
                        conversationPublicId: 'conversation-old',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'Old demo request',
                        createdAt: '2026-08-20T10:01:00.000Z',
                    },
                    demoReply: {
                        publicId: 'stale-demo-reply',
                        conversationPublicId: 'conversation-old',
                        senderType: 'assistant',
                        messageType: 'text',
                        content: 'Stale demo reply',
                        createdAt: '2026-08-20T10:02:00.000Z',
                    },
                },
            }),
        );
        await act(async () => Promise.resolve());
        act(() => vi.advanceTimersByTime(1200));

        expect(result.current.isAssistantTyping).toBe(false);
        expect(
            result.current.messages.map((message) => message.publicId),
        ).toEqual(['new-onboarding']);
        expect(result.current.statusAnnouncement).toBe(
            'A new conversation has started.',
        );
    });

    it('ignores an old retry failure after the replacement succeeds', async () => {
        const retry = deferred<Response>();
        const restart = deferred<Response>();
        const failedConversation = {
            ...initialConversation,
            messages: [
                {
                    publicId: 'failed-old-message',
                    conversationPublicId: 'conversation-old',
                    clientMessageId: 'client-failed-old',
                    senderType: 'customer',
                    messageType: 'text',
                    content: 'Retry old message',
                    createdAt: '2026-08-20T10:00:00.000Z',
                    clientStatus: 'error',
                    tempId: 'failed-old-message',
                },
            ],
        };

        vi.mocked(fetch).mockImplementation((input) => {
            const path = String(input);

            if (path === '/chat/conversations/restart') {
                return restart.promise;
            }

            if (path.includes('/messages')) {
                return retry.promise;
            }

            return Promise.resolve(response({ data: failedConversation }));
        });

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'en' }),
        );
        act(() => result.current.openChat());
        await waitFor(() => expect(result.current.canRestart).toBe(true));
        const staleRestart = result.current.restartChat;

        act(() => void result.current.retryMessage('failed-old-message'));
        let restartRun!: Promise<void>;
        act(() => {
            restartRun = staleRestart();
        });
        restart.resolve(response({ data: restartedConversation }));
        await act(async () => restartRun);

        retry.resolve(response({ error: { code: 'chat_unavailable' } }, 500));
        await waitFor(() => expect(result.current.isRestarting).toBe(false));
        await act(async () => Promise.resolve());

        expect(result.current.error).toBeNull();
        expect(
            result.current.messages.map((message) => message.publicId),
        ).toEqual(['new-onboarding']);
    });
});
