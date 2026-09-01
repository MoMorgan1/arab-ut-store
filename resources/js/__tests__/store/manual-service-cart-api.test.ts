import { beforeEach, expect, it, vi } from 'vitest';

import {
    ManualServiceCartError,
    submitManualServiceCart,
} from '@/lib/manual-service-cart-api';

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-test">';
    vi.unstubAllGlobals();
});

it('posts multipart data once with same-origin security and idempotency headers', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(
            JSON.stringify({
                data: {
                    cartCount: 1,
                    cartItemId: '01K00000000000000000000000',
                    cartUrl: '/en/cart',
                },
            }),
            { status: 201, headers: { 'Content-Type': 'application/json' } },
        ),
    );
    vi.stubGlobal('fetch', fetchMock);
    const data = new FormData();
    data.set('rank', '3');

    await expect(
        submitManualServiceCart(
            '/en/cart/items/fut-champions',
            data,
            'request-1',
        ),
    ).resolves.toEqual({
        cartCount: 1,
        cartItemId: '01K00000000000000000000000',
        cartUrl: '/en/cart',
    });
    expect(fetchMock).toHaveBeenCalledWith(
        new URL('/en/cart/items/fut-champions', window.location.origin),
        expect.objectContaining({
            body: data,
            cache: 'no-store',
            credentials: 'same-origin',
            method: 'POST',
            headers: expect.objectContaining({
                Accept: 'application/json',
                'Idempotency-Key': 'request-1',
                'X-CSRF-TOKEN': 'csrf-test',
            }),
        }),
    );
    expect(fetchMock.mock.calls[0]?.[1]?.headers).not.toHaveProperty(
        'Content-Type',
    );
});

it('rejects cross-origin endpoints and unsafe success bodies', async () => {
    const form = new FormData();

    await expect(
        submitManualServiceCart('https://evil.example/cart', form, 'request-2'),
    ).rejects.toMatchObject({ code: 'unsafe_endpoint', conclusive: false });

    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({ data: { cartCount: 1, password: 'x' } }),
                {
                    status: 201,
                },
            ),
        ),
    );
    await expect(
        submitManualServiceCart('/cart/items/rivals', form, 'request-3'),
    ).rejects.toMatchObject({
        code: 'unsafe_response',
        conclusive: true,
    });
});

it.each([400, 409, 422, 500, 503])(
    'fails conclusively for HTTP %s',
    async (status) => {
        const form = new FormData();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        error: { code: `error_${status}` },
                    }),
                    { status },
                ),
            ),
        );

        await expect(
            submitManualServiceCart('/cart/items/rivals', form, 'request-err'),
        ).rejects.toMatchObject({
            code: `error_${status}`,
            conclusive: true,
            status,
        });
    },
);

it('keeps a transport failure retryable with conclusive: false', async () => {
    const form = new FormData();
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));

    const error = await submitManualServiceCart(
        '/cart/items/rivals',
        form,
        'request-transport',
    ).catch((failure: unknown) => failure);

    expect(error).toBeInstanceOf(ManualServiceCartError);
    expect(error).toMatchObject({
        code: 'transport_error',
        conclusive: false,
    });
});
