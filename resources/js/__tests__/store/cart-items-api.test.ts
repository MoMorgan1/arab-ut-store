import { beforeEach, expect, it, vi } from 'vitest';

import { removeCartItem } from '@/lib/cart-items-api';

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
