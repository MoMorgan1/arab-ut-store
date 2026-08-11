import { afterEach, describe, expect, it, vi } from 'vitest';

import { CoinsCartRequestError, submitCoinsCart } from '@/lib/coins-cart-api';
import type { CoinsCredentials } from '@/types/coins';

const credentials: CoinsCredentials = {
    eaEmail: 'player@example.com',
    eaPassword: 'opaque EA password',
    backupCodes: ['10000001', '10000002', '10000003', '10000004', '10000005'],
};

function request() {
    return submitCoinsCart({
        cartUrl: '/en/cart/items/coins',
        credentials,
        delivery: null,
        idempotencyKey: '3dc56ae8-6ed2-4dde-92fd-d170cefa8a3d',
        platform: 'pc',
        quantity: 50_000,
    });
}

afterEach(() => {
    document.head.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('secure Coins cart API', () => {
    it('posts same-origin JSON with CSRF and the supplied idempotency key', async () => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="csrf-token-value">';
        const fetchMock = vi.fn<
            (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>
        >((...requestArguments) => {
            void requestArguments;

            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        data: {
                            cartCount: 1,
                            cartItemId: '01K00000000000000000000000',
                            cartUrl: '/en/cart',
                            quote: {
                                delivery: null,
                                market: 'pc',
                                platform: 'pc',
                                pricedAt: '2026-08-10T12:00:00Z',
                                quantity: 50_000,
                                total: {
                                    amountHalalah: 600,
                                    currency: 'SAR',
                                },
                            },
                        },
                    }),
                    {
                        headers: { 'Content-Type': 'application/json' },
                        status: 201,
                    },
                ),
            );
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(request()).resolves.toMatchObject({
            cartCount: 1,
            cartUrl: '/en/cart',
        });

        const [url, init] = fetchMock.mock.calls[0];
        expect(String(url)).toBe('http://localhost:3000/en/cart/items/coins');
        expect(init).toMatchObject({
            cache: 'no-store',
            credentials: 'same-origin',
            method: 'POST',
        });
        expect(init?.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'Idempotency-Key': '3dc56ae8-6ed2-4dde-92fd-d170cefa8a3d',
            'X-CSRF-TOKEN': 'csrf-token-value',
        });
        expect(JSON.parse(String(init?.body))).toEqual({
            credentials: {
                ea_email: credentials.eaEmail,
                ea_password: credentials.eaPassword,
                backup_codes: credentials.backupCodes,
            },
            platform: 'pc',
            quantity: 50_000,
        });
    });

    it('rejects a cross-origin endpoint before sending credentials', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        await expect(
            submitCoinsCart({
                cartUrl: 'https://attacker.example/cart',
                credentials,
                delivery: null,
                idempotencyKey: '3dc56ae8-6ed2-4dde-92fd-d170cefa8a3d',
                platform: 'pc',
                quantity: 50_000,
            }),
        ).rejects.toMatchObject({ code: 'unsafe_endpoint' });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('classifies a transport failure without embedding credentials', async () => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="csrf-token-value">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );

        const failure = await request().catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(CoinsCartRequestError);
        expect(failure).toMatchObject({
            code: 'transport_error',
            conclusive: false,
        });
        expect(String(failure)).not.toContain(credentials.eaPassword);

        for (const code of credentials.backupCodes) {
            expect(String(failure)).not.toContain(code);
        }
    });

    it('fails closed on a malformed success response', async () => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="csrf-token-value">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    new Response(JSON.stringify({ data: credentials }), {
                        headers: { 'Content-Type': 'application/json' },
                        status: 201,
                    }),
                ),
            ),
        );

        await expect(request()).rejects.toMatchObject({
            code: 'unsafe_response',
            conclusive: true,
        });
    });
});
