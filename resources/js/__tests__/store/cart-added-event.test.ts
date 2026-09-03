import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    announceCartAddition,
    announceCartDuplicate,
    CART_ADDED_EVENT,
} from '@/lib/cart-added-event';
import type { CartAddedDetail } from '@/lib/cart-added-event';

const flyMocks = vi.hoisted(() => ({ flyToCart: vi.fn() }));

vi.mock('@/lib/fly-to-cart', () => ({ flyToCart: flyMocks.flyToCart }));

function events(): CartAddedDetail[] {
    const received: CartAddedDetail[] = [];
    const listener = (event: Event) => {
        received.push((event as CustomEvent<CartAddedDetail>).detail);
    };
    window.addEventListener(CART_ADDED_EVENT, listener);

    return received;
}

afterEach(() => {
    vi.restoreAllMocks();
    flyMocks.flyToCart.mockReset();
});

describe('announceCartAddition', () => {
    it('dispatches synchronously in the same call when there is no flight', () => {
        const listener = vi.fn();
        window.addEventListener(CART_ADDED_EVENT, listener);

        // Deliberately un-awaited: analytics asserts the dataLayer right
        // after the call returns.
        void announceCartAddition({
            cartUrl: '/cart',
            imageAlt: 'Coins',
            imageUrl: '/images/coin.webp',
            itemLabel: 'Coins',
        });

        expect(listener).toHaveBeenCalledTimes(1);
        expect(flyMocks.flyToCart).not.toHaveBeenCalled();
        window.removeEventListener(CART_ADDED_EVENT, listener);
    });

    it('runs the flight first and dispatches when it resolves', async () => {
        let resolveFlight: () => void = () => {};
        flyMocks.flyToCart.mockReturnValue(
            new Promise<void>((resolve) => {
                resolveFlight = resolve;
            }),
        );

        const received = events();
        const button = document.createElement('button');
        const pending = announceCartAddition({
            cartUrl: '/cart',
            from: button,
            imageAlt: 'Coins',
            imageUrl: '/images/coin.webp',
            itemLabel: 'Coins',
        });

        expect(flyMocks.flyToCart).toHaveBeenCalledTimes(1);
        expect(received).toHaveLength(0);

        resolveFlight();
        await pending;

        expect(received).toHaveLength(1);
        expect(received[0]).toMatchObject({
            cartUrl: '/cart',
            itemLabel: 'Coins',
            variant: 'added',
        });
        expect(received[0]).not.toHaveProperty('from');
    });
});

describe('announceCartDuplicate', () => {
    it('dispatches the duplicate variant at once without a flight', () => {
        const received = events();

        announceCartDuplicate({
            cartUrl: '/cart',
            imageAlt: 'Coins',
            imageUrl: '/images/coin.webp',
            itemLabel: 'Coins',
        });

        expect(flyMocks.flyToCart).not.toHaveBeenCalled();
        expect(received).toHaveLength(1);
        expect(received[0]).toMatchObject({
            cartUrl: '/cart',
            variant: 'duplicate',
        });
    });
});
