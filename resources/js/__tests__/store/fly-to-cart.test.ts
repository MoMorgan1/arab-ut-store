import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flyToCart } from '@/lib/fly-to-cart';

const originalAnimate = Element.prototype.animate;

function mockMatchMedia(matches: boolean) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn().mockImplementation(() => ({
            matches,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    );
}

function viewportRect(partial: Partial<DOMRect>): DOMRect {
    return {
        bottom: 0,
        height: 0,
        left: 0,
        right: 0,
        top: 0,
        width: 0,
        x: 0,
        y: 0,
        toJSON: () => ({}),
        ...partial,
    } as DOMRect;
}

afterEach(() => {
    cleanup();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    vi.restoreAllMocks();

    if (originalAnimate === undefined) {
        delete (Element.prototype as { animate?: unknown }).animate;
    } else {
        Element.prototype.animate = originalAnimate;
    }
});

describe('flyToCart', () => {
    beforeEach(() => {
        mockMatchMedia(false);
    });

    it('skips the flight under reduced motion without touching the DOM', async () => {
        mockMatchMedia(true);
        document.body.innerHTML = '<a data-cart-icon="" href="/cart">Cart</a>';

        await expect(
            flyToCart({
                from: viewportRect({
                    left: 10,
                    top: 10,
                    width: 40,
                    height: 40,
                }),
                imageUrl: '/images/store/coins/ut-coin-160.webp',
                imageAlt: 'Coins',
            }),
        ).resolves.toBeUndefined();
        expect(document.querySelector('.store-fly-chip')).toBeNull();
    });

    it('skips the flight when the header cart icon is missing', async () => {
        const button = document.createElement('button');
        document.body.appendChild(button);

        await expect(
            flyToCart({
                from: button,
                imageUrl: '/images/store/coins/ut-coin-160.webp',
                imageAlt: 'Coins',
            }),
        ).resolves.toBeUndefined();
        expect(document.querySelector('.store-fly-chip')).toBeNull();
    });

    it('skips the flight when the cart icon is outside the viewport', async () => {
        const icon = document.createElement('a');
        icon.setAttribute('data-cart-icon', '');
        icon.href = '/cart';
        vi.spyOn(icon, 'getBoundingClientRect').mockReturnValue(
            viewportRect({
                top: window.innerHeight + 500,
                bottom: window.innerHeight + 520,
                left: 10,
                right: 30,
                width: 20,
                height: 20,
            }),
        );
        document.body.appendChild(icon);

        const button = document.createElement('button');
        document.body.appendChild(button);

        await expect(
            flyToCart({
                from: button,
                imageUrl: '/images/store/coins/ut-coin-160.webp',
                imageAlt: 'Coins',
            }),
        ).resolves.toBeUndefined();
        expect(document.querySelector('.store-fly-chip')).toBeNull();
    });

    it('creates the chip, animates the arc, and removes it on finish', async () => {
        const icon = document.createElement('a');
        icon.setAttribute('data-cart-icon', '');
        icon.href = '/cart';
        vi.spyOn(icon, 'getBoundingClientRect').mockReturnValue(
            viewportRect({
                top: 100,
                bottom: 120,
                left: 100,
                right: 120,
                width: 20,
                height: 20,
                x: 100,
                y: 100,
            }),
        );
        document.body.appendChild(icon);

        const button = document.createElement('button');
        vi.spyOn(button, 'getBoundingClientRect').mockReturnValue(
            viewportRect({
                top: 400,
                bottom: 440,
                left: 40,
                right: 120,
                width: 80,
                height: 40,
                x: 40,
                y: 400,
            }),
        );
        document.body.appendChild(button);

        let resolveFlight: () => void = () => {};
        const finished = new Promise<void>((resolve) => {
            resolveFlight = resolve;
        });
        const animate = vi.fn().mockReturnValue({ finished });
        Object.assign(Element.prototype, { animate });

        const flight = flyToCart({
            from: button,
            imageUrl: '/images/store/coins/ut-coin-160.webp',
            imageAlt: 'Coins',
        });

        const chip = document.querySelector(
            '.store-fly-chip',
        ) as HTMLElement | null;
        expect(chip).not.toBeNull();
        expect(chip?.querySelector('img')).toHaveAttribute(
            'src',
            '/images/store/coins/ut-coin-160.webp',
        );
        expect(animate).toHaveBeenCalledTimes(1);

        const keyframes = animate.mock.calls[0]?.[0] as Keyframe[];
        expect(keyframes).toHaveLength(4);
        expect(animate.mock.calls[0]?.[1]).toMatchObject({ duration: 420 });

        resolveFlight();
        await expect(flight).resolves.toBeUndefined();
        expect(document.querySelector('.store-fly-chip')).toBeNull();
    });
});
