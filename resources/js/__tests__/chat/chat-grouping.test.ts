import { describe, expect, it } from 'vitest';
import { groupChatMessages } from '@/lib/chat-grouping';
import type { ChatMessage } from '@/types/chat';

describe('chat grouping logic', () => {
    it('returns empty array when messages array is empty', () => {
        expect(groupChatMessages([])).toEqual([]);
    });

    it('groups adjacent messages from the same sender within 90 seconds on the same day', () => {
        const baseTime = new Date(2026, 7, 20, 10, 0, 0).getTime();

        const messages: ChatMessage[] = [
            {
                publicId: 'msg-1',
                senderType: 'customer',
                messageType: 'text',
                content: 'First message',
                createdAt: new Date(baseTime).toISOString(),
            },
            {
                publicId: 'msg-2',
                senderType: 'customer',
                messageType: 'text',
                content: 'Second message 30s later',
                createdAt: new Date(baseTime + 30 * 1000).toISOString(),
            },
            {
                publicId: 'msg-3',
                senderType: 'customer',
                messageType: 'text',
                content: 'Third message 80s after first (50s after second)',
                createdAt: new Date(baseTime + 80 * 1000).toISOString(),
            },
        ];

        const clusters = groupChatMessages(messages);
        expect(clusters).toHaveLength(1);
        expect(clusters[0].senderType).toBe('customer');
        expect(clusters[0].messages).toHaveLength(3);
        expect(clusters[0].messages.map((m) => m.publicId)).toEqual([
            'msg-1',
            'msg-2',
            'msg-3',
        ]);
    });

    it('breaks grouping when an interleaving sender arrives', () => {
        const baseTime = new Date(2026, 7, 20, 10, 0, 0).getTime();

        const messages: ChatMessage[] = [
            {
                publicId: 'msg-1',
                senderType: 'customer',
                messageType: 'text',
                content: 'Customer 1',
                createdAt: new Date(baseTime).toISOString(),
            },
            {
                publicId: 'msg-2',
                senderType: 'assistant',
                messageType: 'text',
                content: 'Assistant reply',
                createdAt: new Date(baseTime + 10 * 1000).toISOString(),
            },
            {
                publicId: 'msg-3',
                senderType: 'customer',
                messageType: 'text',
                content: 'Customer 2',
                createdAt: new Date(baseTime + 20 * 1000).toISOString(),
            },
        ];

        const clusters = groupChatMessages(messages);
        expect(clusters).toHaveLength(3);
        expect(clusters[0].senderType).toBe('customer');
        expect(clusters[1].senderType).toBe('assistant');
        expect(clusters[2].senderType).toBe('customer');
    });

    it('breaks grouping when gap exceeds 90 seconds', () => {
        const baseTime = new Date(2026, 7, 20, 10, 0, 0).getTime();

        const messages: ChatMessage[] = [
            {
                publicId: 'msg-1',
                senderType: 'customer',
                messageType: 'text',
                content: 'Message 1',
                createdAt: new Date(baseTime).toISOString(),
            },
            {
                publicId: 'msg-2',
                senderType: 'customer',
                messageType: 'text',
                content: 'Message 2 sent 91 seconds later',
                createdAt: new Date(baseTime + 91 * 1000).toISOString(),
            },
        ];

        const clusters = groupChatMessages(messages);
        expect(clusters).toHaveLength(2);
        expect(clusters[0].messages).toHaveLength(1);
        expect(clusters[1].messages).toHaveLength(1);
    });

    it('breaks grouping across midnight calendar boundary', () => {
        const messages: ChatMessage[] = [
            {
                publicId: 'msg-1',
                senderType: 'customer',
                messageType: 'text',
                content: 'Late night message',
                createdAt: new Date(2026, 7, 20, 23, 59, 50).toISOString(),
            },
            {
                publicId: 'msg-2',
                senderType: 'customer',
                messageType: 'text',
                content: 'Next day message 20s later',
                createdAt: new Date(2026, 7, 21, 0, 0, 10).toISOString(),
            },
        ];

        const clusters = groupChatMessages(messages);
        expect(clusters).toHaveLength(2);
    });
});
