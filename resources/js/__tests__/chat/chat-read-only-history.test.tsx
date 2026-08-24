import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useChat } from '@/hooks/use-chat';
import * as chatApi from '@/lib/chat-api';
import type { ChatConversation } from '@/types/chat';

const liveConversation: ChatConversation = {
    publicId: 'conv-live',
    status: 'open',
    locale: 'ar',
    handoffState: 'active',
    assistantMode: 'demo',
    messages: [],
    hasMore: false,
};

const closedConversation: ChatConversation = {
    publicId: 'conv-past',
    status: 'closed',
    locale: 'ar',
    handoffState: 'none',
    assistantMode: 'demo',
    messages: [],
    hasMore: false,
};

describe('read-only history threads', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    /**
     * Opening a past thread disables both the composer and restart. Before the
     * exit existed, nothing cleared isReadOnly again — a customer who browsed
     * their history was locked out of their own live ticket until they reloaded
     * the page, which is exactly when a human might be replying to them.
     */
    it('goes read-only on a closed thread and comes back out to the live one', async () => {
        vi.spyOn(chatApi, 'fetchOrStartActiveConversation').mockResolvedValue(
            liveConversation,
        );
        vi.spyOn(chatApi, 'fetchConversation').mockResolvedValue(
            closedConversation,
        );

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'ar' }),
        );

        await act(async () => {
            await result.current.openPastConversation('conv-past');
        });

        await waitFor(() => {
            expect(result.current.isReadOnly).toBe(true);
        });
        expect(result.current.conversation?.publicId).toBe('conv-past');

        await act(async () => {
            await result.current.leaveReadOnlyConversation();
        });

        await waitFor(() => {
            expect(result.current.isReadOnly).toBe(false);
        });
        expect(result.current.conversation?.publicId).toBe('conv-live');
    });

    it('stays writable when the thread it adopts is still open', async () => {
        vi.spyOn(chatApi, 'fetchOrStartActiveConversation').mockResolvedValue(
            liveConversation,
        );
        vi.spyOn(chatApi, 'fetchConversation').mockResolvedValue({
            ...closedConversation,
            publicId: 'conv-open-past',
            status: 'open',
        });

        const { result } = renderHook(() =>
            useChat({ enabled: true, locale: 'ar' }),
        );

        await act(async () => {
            await result.current.openPastConversation('conv-open-past');
        });

        await waitFor(() => {
            expect(result.current.conversation?.publicId).toBe(
                'conv-open-past',
            );
        });
        expect(result.current.isReadOnly).toBe(false);
    });
});
