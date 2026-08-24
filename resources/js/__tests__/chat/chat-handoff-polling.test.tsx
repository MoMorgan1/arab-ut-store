import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useChat } from '@/hooks/use-chat';
import * as chatApi from '@/lib/chat-api';
import type { ChatConversation } from '@/types/chat';

describe('useChat handoff polling and recovery', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.clearAllTimers();
    });

    it('polls every 5s when handoff state is requested/active, backs off to 15s after 2 minutes, and stops when resolved', async () => {
        const mockConv: ChatConversation = {
            publicId: 'conv-test-123',
            status: 'open',
            locale: 'ar',
            handoffState: 'requested',
            assistantMode: 'demo',
            messages: [
                {
                    publicId: 'msg-1',
                    senderType: 'customer',
                    messageType: 'text',
                    content: 'Need help from a human',
                    createdAt: '2026-08-24T10:00:00Z',
                },
            ],
            hasMore: false,
            ticket: {
                number: 'TKT-100001',
                status: 'open',
            },
        };

        const fetchOrStartSpy = vi
            .spyOn(chatApi, 'fetchOrStartActiveConversation')
            .mockResolvedValue(mockConv);

        const fetchConvSpy = vi
            .spyOn(chatApi, 'fetchConversation')
            .mockResolvedValue(mockConv);

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'ar' }),
        );

        // Open chat
        await act(async () => {
            result.current.openChat();
        });

        expect(fetchOrStartSpy).toHaveBeenCalledTimes(1);

        // Advance 5 seconds -> first poll fires
        await act(async () => {
            vi.advanceTimersByTime(5000);
        });

        expect(fetchConvSpy).toHaveBeenCalledTimes(1);

        // Advance another 5s -> second poll fires
        await act(async () => {
            vi.advanceTimersByTime(5000);
        });

        expect(fetchConvSpy).toHaveBeenCalledTimes(2);

        // Advance past 2 minutes (120s) with no new message -> backoff to 15s
        await act(async () => {
            vi.advanceTimersByTime(120_000);
        });

        const callCountBeforeBackoff = fetchConvSpy.mock.calls.length;

        // Advance only 5s - should NOT fire because interval backed off to 15s
        await act(async () => {
            vi.advanceTimersByTime(5000);
        });
        expect(fetchConvSpy.mock.calls.length).toBe(callCountBeforeBackoff);

        // Advance remaining 10s (total 15s) -> fires!
        await act(async () => {
            vi.advanceTimersByTime(10000);
        });
        expect(fetchConvSpy.mock.calls.length).toBe(callCountBeforeBackoff + 1);

        // Now simulate staff resolving the conversation
        const resolvedConv: ChatConversation = {
            ...mockConv,
            handoffState: 'resolved',
            ticket: {
                number: 'TKT-100001',
                status: 'resolved',
            },
        };
        fetchConvSpy.mockResolvedValue(resolvedConv);

        // Advance 15s to trigger poll that receives resolved state
        await act(async () => {
            vi.advanceTimersByTime(15000);
        });

        expect(result.current.conversation?.handoffState).toBe('resolved');

        const callCountAfterResolved = fetchConvSpy.mock.calls.length;

        // Advance 30s -> no more polls should occur
        await act(async () => {
            vi.advanceTimersByTime(30000);
        });

        expect(fetchConvSpy.mock.calls.length).toBe(callCountAfterResolved);
    });

    it('transparently recovers when message send returns a 404 conversation_not_found', async () => {
        const initialConv: ChatConversation = {
            publicId: 'conv-expired-404',
            status: 'open',
            locale: 'ar',
            handoffState: 'none',
            assistantMode: 'demo',
            messages: [],
            hasMore: false,
        };

        const freshConv: ChatConversation = {
            publicId: 'conv-new-fresh-999',
            status: 'open',
            locale: 'ar',
            handoffState: 'none',
            assistantMode: 'demo',
            messages: [],
            hasMore: false,
        };

        vi.spyOn(chatApi, 'fetchOrStartActiveConversation')
            .mockResolvedValueOnce(initialConv)
            .mockResolvedValueOnce(freshConv);

        vi.spyOn(chatApi, 'sendChatMessage').mockRejectedValueOnce(
            new chatApi.ChatApiError(
                'conversation_not_found',
                404,
                'The requested conversation was not found.',
            ),
        );

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'ar' }),
        );

        await act(async () => {
            result.current.openChat();
        });

        expect(result.current.conversation?.publicId).toBe('conv-expired-404');

        // Send a message on the expired conversation
        await act(async () => {
            await result.current.sendMessage('Hello after long idle');
        });

        // It should transparently recover to freshConv
        expect(result.current.conversation?.publicId).toBe(
            'conv-new-fresh-999',
        );
        // It does NOT show a raw error like "Network request failed" or crash
        expect(result.current.error).toContain('تغيّرت المحادثة');
    });
});
