import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    CartRestoreConflict,
    removeCartItem,
    restoreCartItem,
} from '@/lib/cart-items-api';

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    vi.unstubAllGlobals();
});

it('removes only through the same-origin CSRF-protected endpoint', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ data: { cartCount: 2 } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(removeCartItem('/cart/items/01KSAFE')).resolves.toEqual({
        cartCount: 2,
        restoreUrl: null,
    });
    expect(fetchMock).toHaveBeenCalledWith('/cart/items/01KSAFE', {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': 'test-token',
        },
        method: 'DELETE',
    });
});

it('fails closed for cross-origin or malformed removal responses', async () => {
    await expect(
        removeCartItem('https://attacker.example/cart/items/01KSAFE'),
    ).rejects.toThrow('Unsafe cart item endpoint.');

    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: { cartCount: -1 } }), {
                status: 200,
            }),
        ),
    );
    await expect(removeCartItem('/cart/items/01KSAFE')).rejects.toThrow(
        'Cart item could not be removed.',
    );
});

describe('cart item restore', () => {
    it('parses the restore URL from the removal response', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        data: {
                            cartCount: 0,
                            restoreUrl: '/cart/items/01KSAFE/restore',
                        },
                    }),
                    { status: 200 },
                ),
            ),
        );

        await expect(removeCartItem('/cart/items/01KSAFE')).resolves.toEqual({
            cartCount: 0,
            restoreUrl: '/cart/items/01KSAFE/restore',
        });
    });

    it('restores only through the same-origin CSRF-protected endpoint', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: { cartCount: 1 } }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        await expect(
            restoreCartItem('/cart/items/01KSAFE/restore'),
        ).resolves.toEqual({ cartCount: 1 });
        expect(fetchMock).toHaveBeenCalledWith('/cart/items/01KSAFE/restore', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': 'test-token',
            },
            method: 'POST',
        });
    });

    it('reports a duplicate restore as a conflict', async () => {
        await expect(
            restoreCartItem(
                'https://attacker.example/cart/items/01KSAFE/restore',
            ),
        ).rejects.toThrow('Unsafe cart item endpoint.');

        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    new Response(
                        JSON.stringify({ error: { code: 'already_in_cart' } }),
                        { status: 409 },
                    ),
                ),
        );
        await expect(
            restoreCartItem('/cart/items/01KSAFE/restore'),
        ).rejects.toBeInstanceOf(CartRestoreConflict);
    });
});
