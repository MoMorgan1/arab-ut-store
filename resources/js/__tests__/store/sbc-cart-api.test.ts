import { afterEach, describe, expect, it, vi } from 'vitest';

import { SbcCartRequestError, submitSbcCart } from '@/lib/sbc-cart-api';
import type { CoinsCredentials } from '@/types/coins';

const credentials: CoinsCredentials = {
    eaEmail: 'sbc-owner@example.test',
    eaPassword: 'opaque SBC password',
    backupCodes: ['93000001', '93000002', '93000003'],
};

const request = () =>
    submitSbcCart({
        cartUrl: '/en/cart/items/sbc',
        credentials,
        idempotencyKey: 'sbc-attempt-1',
        variantId: '01K00000000000000000000000',
    });

afterEach(() => {
    document.head.innerHTML = '';
    localStorage.clear();
    sessionStorage.clear();
    vi.unstubAllGlobals();
});

describe('secure SBC cart API', () => {
    it('posts the selected variant and credentials as same origin no store JSON', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        const fetchMock = vi.fn(() =>
            Promise.resolve(
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
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        await expect(request()).resolves.toEqual({
            cartCount: 1,
            cartItemId: '01K00000000000000000000001',
            cartUrl: '/en/cart',
        });

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            URL,
            RequestInit,
        ];
        expect(String(url)).toBe('http://localhost:3000/en/cart/items/sbc');
        expect(init).toMatchObject({
            cache: 'no-store',
            credentials: 'same-origin',
            method: 'POST',
        });
        expect(init.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'Idempotency-Key': 'sbc-attempt-1',
            'X-CSRF-TOKEN': 'csrf',
        });
        expect(JSON.parse(String(init.body))).toEqual({
            variantId: '01K00000000000000000000000',
            credentials: {
                ea_email: credentials.eaEmail,
                ea_password: credentials.eaPassword,
                backup_codes: credentials.backupCodes,
            },
        });
        expect(localStorage).toHaveLength(0);
        expect(sessionStorage).toHaveLength(0);
    });

    it('keeps transport failures retryable without exposing or storing credentials', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );

        const error = await request().catch((failure: unknown) => failure);

        expect(error).toBeInstanceOf(SbcCartRequestError);
        expect(error).toMatchObject({
            code: 'transport_error',
            conclusive: false,
        });
        expect(String(error)).not.toContain(credentials.eaPassword);
        expect(localStorage).toHaveLength(0);
        expect(sessionStorage).toHaveLength(0);
    });

    it('maps only allowlisted credential validation fields', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    new Response(
                        JSON.stringify({
                            errors: {
                                'credentials.ea_email': ['invalid'],
                                'credentials.backup_codes.2': ['invalid'],
                                internal_secret: ['must not reflect'],
                            },
                        }),
                        { status: 422 },
                    ),
                ),
            ),
        );

        await expect(request()).rejects.toMatchObject({
            code: 'validation_error',
            conclusive: true,
            validationFields: ['email', 'code-2'],
        });
    });

    it('fails closed on unsafe endpoints and malformed success responses', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(new Response('{}', { status: 201 }))),
        );

        await expect(request()).rejects.toMatchObject({
            code: 'unsafe_response',
            conclusive: true,
        });
        await expect(
            submitSbcCart({
                cartUrl: 'https://attacker.example/cart',
                credentials,
                idempotencyKey: 'key',
                variantId: '01K00000000000000000000000',
            }),
        ).rejects.toMatchObject({ code: 'unsafe_endpoint' });
    });
});
