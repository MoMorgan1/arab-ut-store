import { describe, expect, it } from 'vitest';
import { chatCartOffer } from '@/lib/chat-cart';
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

const consoleOffer = {
    version: 'cart.v1',
    service: 'coins',
    selection: {
        platform: 'playstation',
        delivery: 'fast',
        quantity: 1_000_000,
    },
};

const pcOffer = {
    version: 'cart.v1',
    service: 'coins',
    selection: { platform: 'pc', quantity: 500_000 },
};

describe('chatCartOffer', () => {
    it('reads a well-formed console offer', () => {
        expect(chatCartOffer(message({ cart: consoleOffer }))).toEqual({
            service: 'coins',
            platform: 'playstation',
            delivery: 'fast',
            quantity: 1_000_000,
        });
    });

    it('reads a PC offer as having no delivery', () => {
        expect(chatCartOffer(message({ cart: pcOffer }))).toEqual({
            service: 'coins',
            platform: 'pc',
            delivery: null,
            quantity: 500_000,
        });
    });

    it('ignores an offer from a newer server version', () => {
        expect(
            chatCartOffer(
                message({ cart: { ...consoleOffer, version: 'cart.v2' } }),
            ),
        ).toBeNull();
    });

    it('ignores an offer on a customer message', () => {
        expect(
            chatCartOffer(message({ cart: consoleOffer }, 'customer')),
        ).toBeNull();
    });

    it('rejects a console offer with no delivery, because the price differs', () => {
        expect(
            chatCartOffer(
                message({
                    cart: {
                        ...consoleOffer,
                        selection: {
                            platform: 'playstation',
                            quantity: 1_000_000,
                        },
                    },
                }),
            ),
        ).toBeNull();
    });

    it('rejects a PC offer carrying a delivery it cannot have', () => {
        expect(
            chatCartOffer(
                message({
                    cart: {
                        ...pcOffer,
                        selection: {
                            platform: 'pc',
                            delivery: 'fast',
                            quantity: 500_000,
                        },
                    },
                }),
            ),
        ).toBeNull();
    });

    it.each([
        ['xbox', 'a platform the cart endpoint does not accept'],
        ['', 'an empty platform'],
    ])('rejects %s (%s)', (platform) => {
        expect(
            chatCartOffer(
                message({
                    cart: {
                        ...consoleOffer,
                        selection: {
                            platform,
                            delivery: 'fast',
                            quantity: 1_000_000,
                        },
                    },
                }),
            ),
        ).toBeNull();
    });

    it.each([0, -1, 1.5, 20_000_001, '1000000', null])(
        'rejects the quantity %p',
        (quantity) => {
            expect(
                chatCartOffer(
                    message({
                        cart: {
                            ...consoleOffer,
                            selection: {
                                platform: 'playstation',
                                delivery: 'fast',
                                quantity,
                            },
                        },
                    }),
                ),
            ).toBeNull();
        },
    );

    it.each([null, undefined, 'nonsense', 42, [], { cart: null }, {}])(
        'yields no offer for the metadata %p',
        (metadata) => {
            expect(chatCartOffer(message(metadata))).toBeNull();
        },
    );

    it('ignores a service this client cannot add to a cart', () => {
        expect(
            chatCartOffer(
                message({ cart: { ...consoleOffer, service: 'rivals' } }),
            ),
        ).toBeNull();
    });
});
