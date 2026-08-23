import { describe, expect, it } from 'vitest';
import { chatServiceCards } from '@/lib/chat-cards';
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

const card = {
    id: 'coins',
    title: 'شحن كوينز FC',
    subtitle: 'اختر منصتك والكمية',
    cta: 'اطلب الآن',
    url: '/#coins',
    image: '/images/store/coins/ut-coin-240.webp',
};

describe('chatServiceCards', () => {
    it('reads a valid v1 payload', () => {
        expect(
            chatServiceCards(
                message({ cards: { version: 'cards.v1', items: [card] } }),
            ),
        ).toEqual([card]);
    });

    it('ignores messages without metadata', () => {
        expect(chatServiceCards(message(null))).toEqual([]);
        expect(chatServiceCards(message(undefined))).toEqual([]);
        expect(chatServiceCards(message({}))).toEqual([]);
    });

    it('ignores a version this client does not understand', () => {
        expect(
            chatServiceCards(
                message({ cards: { version: 'cards.v2', items: [card] } }),
            ),
        ).toEqual([]);
    });

    it('drops entries with a missing or empty field', () => {
        const items = [
            { ...card, title: '' },
            { ...card, url: undefined },
            { ...card, id: 42 },
            card,
        ];

        expect(
            chatServiceCards(
                message({ cards: { version: 'cards.v1', items } }),
            ),
        ).toEqual([card]);
    });

    it('refuses to render an off-site link', () => {
        const offsite = [
            { ...card, url: 'https://evil.example/checkout' },
            { ...card, url: '//evil.example' },
            { ...card, url: 'javascript:alert(1)' },
        ];

        expect(
            chatServiceCards(
                message({ cards: { version: 'cards.v1', items: offsite } }),
            ),
        ).toEqual([]);
    });

    it('refuses an image that is not a local asset', () => {
        const remote = [
            { ...card, image: 'https://evil.example/pixel.png' },
            { ...card, image: '//evil.example/pixel.png' },
            { ...card, image: '/uploads/pixel.png' },
        ];

        expect(
            chatServiceCards(
                message({ cards: { version: 'cards.v1', items: remote } }),
            ),
        ).toEqual([]);
    });

    it('never renders cards on a customer message', () => {
        expect(
            chatServiceCards(
                message(
                    { cards: { version: 'cards.v1', items: [card] } },
                    'customer',
                ),
            ),
        ).toEqual([]);
    });

    it('caps how many cards one reply can carry', () => {
        const many = Array.from({ length: 6 }, (_, index) => ({
            ...card,
            id: `card-${index}`,
        }));

        // Four: "what do you sell?" is answered by the whole menu, and the
        // store sells four things.
        expect(
            chatServiceCards(
                message({ cards: { version: 'cards.v1', items: many } }),
            ),
        ).toHaveLength(4);
    });

    it('survives a payload that is not shaped like cards at all', () => {
        expect(chatServiceCards(message({ cards: 'nope' }))).toEqual([]);
        expect(
            chatServiceCards(
                message({ cards: { version: 'cards.v1', items: 'no' } }),
            ),
        ).toEqual([]);
    });
});
