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

describe('Chat Scroll Preservation', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('preserves scroll position when loading older messages and does not scroll to bottom', async () => {
        const initialMessages = [
            {
                publicId: 'msg-50',
                conversationPublicId: 'conv-scroll-1',
                senderType: 'customer' as const,
                messageType: 'text' as const,
                content: 'Recent message 50',
                createdAt: '2026-08-20T10:50:00Z',
            },
        ];

        const olderMessages = [
            {
                publicId: 'msg-1',
                conversationPublicId: 'conv-scroll-1',
                senderType: 'customer' as const,
                messageType: 'text' as const,
                content: 'Older message 1',
                createdAt: '2026-08-20T10:01:00Z',
            },
        ];

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        publicId: 'conv-scroll-1',
                        status: 'open',
                        locale: 'en',
                        messages: initialMessages,
                        hasMore: true,
                        oldestCursor: 'msg-50',
                    },
                }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        publicId: 'conv-scroll-1',
                        status: 'open',
                        locale: 'en',
                        messages: olderMessages,
                        hasMore: false,
                        oldestCursor: 'msg-1',
                    },
                }),
            } as Response);

        render(<ChatWidget enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        await waitFor(() => {
            expect(screen.getByText('Recent message 50')).toBeInTheDocument();
        });

        const loadOlderBtn = screen.getByRole('button', {
            name: /Load older messages/i,
        });
        expect(loadOlderBtn).toBeInTheDocument();

        // Click load older
        fireEvent.click(loadOlderBtn);

        await waitFor(() => {
            expect(screen.getByText('Older message 1')).toBeInTheDocument();
        });

        expect(screen.getByText('Recent message 50')).toBeInTheDocument();
    });
});
