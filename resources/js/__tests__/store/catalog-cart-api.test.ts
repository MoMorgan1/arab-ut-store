import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    CatalogCartRequestError,
    submitCatalogCart,
} from '@/lib/catalog-cart-api';

const request = () =>
    submitCatalogCart({
        cartUrl: '/en/cart/items/catalog',
        idempotencyKey: 'catalog-attempt-1',
        variantId: '01K00000000000000000000000',
    });

afterEach(() => {
    document.head.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('catalog cart API', () => {
    it('posts only the public variant id with CSRF and one idempotency key', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        const fetchMock = vi.fn<
            (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>
        >((...requestArguments) => {
            void requestArguments;

            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        data: {
                            cartCount: 1,
                            cartItemId: '01K00000000000000000000001',
                            cartUrl: '/en/cart',
                        },
                    }),
                    { status: 201 },
                ),
            );
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(request()).resolves.toEqual({
            cartCount: 1,
            cartItemId: '01K00000000000000000000001',
            cartUrl: '/en/cart',
        });

        const [url, init] = fetchMock.mock.calls[0];
        expect(String(url)).toBe('http://localhost:3000/en/cart/items/catalog');
        expect(init?.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'Idempotency-Key': 'catalog-attempt-1',
            'X-CSRF-TOKEN': 'csrf',
        });
        expect(JSON.parse(String(init?.body))).toEqual({
            variantId: '01K00000000000000000000000',
        });
        expect(localStorage).toHaveLength(0);
        expect(sessionStorage).toHaveLength(0);
    });

    it.each([409, 422, 500, 503])(
        'fails conclusively for HTTP %s',
        async (status) => {
            document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
            vi.stubGlobal(
                'fetch',
                vi.fn(() =>
                    Promise.resolve(
                        new Response(
                            JSON.stringify({
                                error: { code: `error_${status}` },
                            }),
                            { status },
                        ),
                    ),
                ),
            );

            await expect(request()).rejects.toMatchObject({
                code: `error_${status}`,
                conclusive: true,
                status,
            });
        },
    );

    it('keeps a transport failure retryable without storing the key', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );

        const error = await request().catch((failure: unknown) => failure);

        expect(error).toBeInstanceOf(CatalogCartRequestError);
        expect(error).toMatchObject({
            code: 'transport_error',
            conclusive: false,
        });
        expect(localStorage).toHaveLength(0);
        expect(sessionStorage).toHaveLength(0);
    });

    it('fails closed on malformed success JSON and unsafe endpoints', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(new Response('{}', { status: 201 }))),
        );

        await expect(request()).rejects.toMatchObject({
            code: 'unsafe_response',
        });
        await expect(
            submitCatalogCart({
                cartUrl: 'https://attacker.example/cart',
                idempotencyKey: 'key',
                variantId: '01K00000000000000000000000',
            }),
        ).rejects.toMatchObject({ code: 'unsafe_endpoint' });
    });
});
