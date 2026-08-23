import { describe, expect, it } from 'vitest';
import { chatShelfItems } from '@/lib/chat-shelf';
import type { ChatMessage } from '@/types/chat';

function message(metadata: unknown, senderType = 'assistant'): ChatMessage {
    return {
        publicId: 'msg-1',
        senderType: senderType as ChatMessage['senderType'],
        messageType: 'text',
        content: 'reply',
        metadata: metadata as ChatMessage['metadata'],
        createdAt: '2026-08-23T00:00:00Z',
    };
}

const item = {
    id: 'sbc-1-of-5-84-player-pick',
    title: 'اختيار 1 من 5 لاعبين +84',
    url: '/sbc/sbc-1-of-5-84-player-pick',
    image: '/storage/catalog/abc.png',
};

const shelf = { version: 'shelf.v1', items: [item] };

describe('chatShelfItems', () => {
    it('reads a well-formed shelf', () => {
        expect(chatShelfItems(message({ shelf }))).toHaveLength(1);
    });

    it('ignores a shelf from a newer server version', () => {
        expect(
            chatShelfItems(
                message({ shelf: { ...shelf, version: 'shelf.v2' } }),
            ),
        ).toEqual([]);
    });

    it('ignores a shelf on a customer message', () => {
        expect(chatShelfItems(message({ shelf }, 'customer'))).toEqual([]);
    });

    it.each([
        null,
        undefined,
        'nonsense',
        7,
        {},
        { shelf: null },
        { shelf: {} },
    ])('returns nothing for malformed metadata %s', (metadata) => {
        expect(chatShelfItems(message(metadata))).toEqual([]);
    });

    it.each([
        ['an absolute link', { ...item, url: 'https://evil.example/sbc' }],
        ['a scheme-relative link', { ...item, url: '//evil.example/sbc' }],
        ['an off-site image', { ...item, image: 'https://evil.example/x.png' }],
        ['a scheme-relative image', { ...item, image: '//evil.example/x.png' }],
        ['a missing title', { ...item, title: '' }],
    ])('drops a card with %s', (_label, bad) => {
        expect(
            chatShelfItems(message({ shelf: { ...shelf, items: [bad] } })),
        ).toEqual([]);
    });

    it('caps the shelf so it stays swipeable', () => {
        const many = Array.from({ length: 9 }, (_, i) => ({
            ...item,
            id: `sbc-${i}`,
        }));

        expect(
            chatShelfItems(message({ shelf: { ...shelf, items: many } })),
        ).toHaveLength(5);
    });
});
