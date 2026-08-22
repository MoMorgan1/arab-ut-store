import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatWidget } from '@/components/chat/chat-widget';

describe('Rapid Consecutive Messages & Idempotency', () => {
    const mockConversation = {
        publicId: 'conv-rapid-1',
        status: 'open',
        locale: 'ar',
        messages: [],
        hasMore: false,
        oldestCursor: null,
    };

    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('allows rapid consecutive messages without locking the composer and dispatches them in FIFO order', async () => {
        const networkCalls: Array<{
            content: string;
            client_message_id: string;
        }> = [];

        vi.mocked(fetch).mockImplementation(async (url, init) => {
            const path = String(url);

            if (
                path.includes('/chat/conversations') &&
                !path.includes('/messages')
            ) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ data: mockConversation }),
                } as Response;
            }

            if (path.includes('/messages')) {
                const body = JSON.parse(String(init?.body));
                networkCalls.push(body);

                return {
                    ok: true,
                    status: 201,
                    json: async () => ({
                        data: {
                            message: {
                                publicId: `msg-${networkCalls.length}`,
                                conversationPublicId: 'conv-rapid-1',
                                clientMessageId: body.client_message_id,
                                senderType: 'customer',
                                messageType: 'text',
                                content: body.content,
                                createdAt: new Date().toISOString(),
                            },
                            demoReply: null,
                        },
                    }),
                } as Response;
            }

            return { ok: false, status: 404 } as Response;
        });

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        // Open chat
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await waitFor(() => {
            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        const sendBtn = screen.getByRole('button', { name: /إرسال الرسالة/i });

        // Send 4 rapid consecutive messages
        const messagesToSend = ['عايز كوينز', '2 مليون', 'بلايستيشن', 'سريع'];

        for (const msg of messagesToSend) {
            fireEvent.change(textarea, { target: { value: msg } });
            fireEvent.click(sendBtn);
        }

        // All 4 messages should appear optimistically in the DOM immediately
        for (const msg of messagesToSend) {
            expect(screen.getByText(msg)).toBeInTheDocument();
        }

        // Composer is NOT disabled
        expect(textarea).not.toBeDisabled();

        // Wait for all network calls to resolve
        await waitFor(() => {
            expect(networkCalls).toHaveLength(4);
        });

        // Verify FIFO order and unique client_message_id for each
        expect(networkCalls[0].content).toBe('عايز كوينز');
        expect(networkCalls[1].content).toBe('2 مليون');
        expect(networkCalls[2].content).toBe('بلايستيشن');
        expect(networkCalls[3].content).toBe('سريع');

        const clientIds = networkCalls.map((c) => c.client_message_id);
        const uniqueIds = new Set(clientIds);
        expect(uniqueIds.size).toBe(4);
    });

    it('retrying a failed message reuses the stable client_message_id', async () => {
        let callCount = 0;
        const recordedClientIds: string[] = [];

        vi.mocked(fetch).mockImplementation(async (url, init) => {
            const path = String(url);

            if (
                path.includes('/chat/conversations') &&
                !path.includes('/messages')
            ) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ data: mockConversation }),
                } as Response;
            }

            if (path.includes('/messages')) {
                callCount++;
                const body = JSON.parse(String(init?.body));
                recordedClientIds.push(body.client_message_id);

                if (callCount === 1) {
                    // First call fails with 500
                    return {
                        ok: false,
                        status: 500,
                        json: async () => ({
                            error: { code: 'server_error' },
                        }),
                    } as Response;
                }

                // Retry succeeds
                return {
                    ok: true,
                    status: 201,
                    json: async () => ({
                        data: {
                            message: {
                                publicId: 'msg-retry-success',
                                conversationPublicId: 'conv-rapid-1',
                                clientMessageId: body.client_message_id,
                                senderType: 'customer',
                                messageType: 'text',
                                content: body.content,
                                createdAt: new Date().toISOString(),
                            },
                            demoReply: null,
                        },
                    }),
                } as Response;
            }

            return { ok: false, status: 404 } as Response;
        });

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await waitFor(() => {
            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        fireEvent.change(textarea, { target: { value: 'طلب تجربة' } });
        fireEvent.click(screen.getByRole('button', { name: /إرسال الرسالة/i }));

        // Wait for retry button to appear on failure
        const retryBtn = await screen.findByRole('button', {
            name: /إعادة المحاولة/i,
        });
        expect(retryBtn).toBeInTheDocument();

        // Click retry
        fireEvent.click(retryBtn);

        await waitFor(() => {
            expect(callCount).toBe(2);
        });

        // The retry call reused the exact same client_message_id
        expect(recordedClientIds).toHaveLength(2);
        expect(recordedClientIds[0]).toBe(recordedClientIds[1]);
    });

    it('in agent mode, rapid messages coalesce into exactly one agent turn after the queue empties', async () => {
        vi.useFakeTimers();
        const agentTurnStarts: string[] = [];
        let msgCount = 0;

        vi.mocked(fetch).mockImplementation(async (url, init) => {
            const path = String(url);

            if (
                path.includes('/chat/conversations') &&
                !path.includes('/messages') &&
                !path.includes('/agent-turns')
            ) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        data: {
                            ...mockConversation,
                            assistantMode: 'agent',
                        },
                    }),
                } as Response;
            }

            if (path.includes('/messages')) {
                msgCount++;
                const body = JSON.parse(String(init?.body));

                return {
                    ok: true,
                    status: 201,
                    json: async () => ({
                        data: {
                            message: {
                                publicId: `msg-${msgCount}`,
                                conversationPublicId: 'conv-rapid-1',
                                clientMessageId: body.client_message_id,
                                senderType: 'customer',
                                messageType: 'text',
                                content: body.content,
                                createdAt: new Date().toISOString(),
                            },
                            demoReply: null,
                        },
                    }),
                } as Response;
            }

            if (path.includes('/agent-turns')) {
                agentTurnStarts.push(path);

                return {
                    ok: true,
                    status: 204,
                } as Response;
            }

            return { ok: false, status: 404 } as Response;
        });

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));
        await vi.advanceTimersByTimeAsync(10);

        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        const sendBtn = screen.getByRole('button', { name: /إرسال الرسالة/i });

        for (const msg of ['رسالة 1', 'رسالة 2', 'رسالة 3']) {
            fireEvent.change(textarea, { target: { value: msg } });
            fireEvent.click(sendBtn);
        }

        // Advance through microtasks so messages are persisted
        await vi.advanceTimersByTimeAsync(50);
        expect(agentTurnStarts).toHaveLength(0);

        // Before 1500ms quiet timer elapses, send another message
        await vi.advanceTimersByTimeAsync(1000);
        expect(agentTurnStarts).toHaveLength(0);

        fireEvent.change(textarea, { target: { value: 'رسالة 4' } });
        fireEvent.click(sendBtn);

        await vi.advanceTimersByTimeAsync(50);
        expect(agentTurnStarts).toHaveLength(0);

        // 1499ms after message 4 -> still quiet
        await vi.advanceTimersByTimeAsync(1449);
        expect(agentTurnStarts).toHaveLength(0);

        // 1500ms after message 4 -> single agent turn starts
        await vi.advanceTimersByTimeAsync(2);
        expect(agentTurnStarts).toHaveLength(1);
    });
});
