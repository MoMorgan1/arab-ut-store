import { beforeEach, expect, it, vi } from 'vitest';

import {
    resumePaylinkCheckout,
    startPaylinkCheckout,
} from '@/lib/paylink-checkout-api';
import type { PaylinkCheckoutError } from '@/lib/paylink-checkout-api';

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    vi.unstubAllGlobals();
});

it('posts an empty same-origin checkout request and accepts only a Paylink payment URL', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(
            JSON.stringify({
                data: {
                    orderUrl: '/orders/01K00000000000000000000000',
                    paymentUrl:
                        'https://payment.paylink.sa/pay/info/1710000000099',
                    status: 'pending',
                },
            }),
            { status: 201, headers: { 'Content-Type': 'application/json' } },
        ),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(
        startPaylinkCheckout(
            '/checkout/paylink',
            'checkout-browser-key',
            1250,
            1250,
        ),
    ).resolves.toEqual({
        orderUrl: '/orders/01K00000000000000000000000',
        paymentUrl: 'https://payment.paylink.sa/pay/info/1710000000099',
        status: 'pending',
    });
    expect(fetchMock).toHaveBeenCalledWith(
        new URL('/checkout/paylink', window.location.origin),
        expect.objectContaining({
            body: '{}',
            cache: 'no-store',
            credentials: 'same-origin',
            method: 'POST',
            headers: expect.objectContaining({
                'Idempotency-Key': 'checkout-browser-key',
                'X-CSRF-TOKEN': 'test-token',
            }),
        }),
    );
});

it('accepts an idempotent paid retry and directs the customer to the safe order URL', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        orderUrl: '/en/orders/01K00000000000000000000000',
                        paymentUrl: null,
                        status: 'paid',
                    },
                }),
                { status: 200 },
            ),
        ),
    );

    await expect(
        startPaylinkCheckout(
            '/en/checkout/paylink',
            'checkout-browser-key',
            1250,
            1250,
        ),
    ).resolves.toEqual({
        orderUrl: '/en/orders/01K00000000000000000000000',
        paymentUrl: null,
        status: 'paid',
    });
});

it('resumes a pending order without inventing a new idempotency key', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(
            JSON.stringify({
                data: {
                    orderUrl: '/en/orders/01K00000000000000000000000',
                    paymentUrl:
                        'https://payment.paylink.sa/pay/info/1710000000099',
                    status: 'pending',
                },
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(
        resumePaylinkCheckout(
            '/en/orders/01K00000000000000000000000/payments/paylink',
        ),
    ).resolves.toMatchObject({ status: 'pending' });
    expect(fetchMock).toHaveBeenCalledWith(
        new URL(
            '/en/orders/01K00000000000000000000000/payments/paylink',
            window.location.origin,
        ),
        expect.objectContaining({
            body: '{}',
            method: 'POST',
            headers: expect.not.objectContaining({
                'Idempotency-Key': expect.anything(),
            }),
        }),
    );
});

it('rejects cross-origin endpoints unsafe responses and stable server errors', async () => {
    await expect(
        startPaylinkCheckout(
            'https://attacker.test/checkout',
            'checkout-browser-key',
            1250,
            1250,
        ),
    ).rejects.toMatchObject({ code: 'unsafe_endpoint', conclusive: false });

    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        orderUrl: '/orders/01K00000000000000000000000',
                        paymentUrl: 'https://attacker.test/pay',
                        status: 'pending',
                    },
                }),
                { status: 201 },
            ),
        ),
    );
    await expect(
        startPaylinkCheckout(
            '/checkout/paylink',
            'checkout-browser-key',
            1250,
            1250,
        ),
    ).rejects.toMatchObject({ code: 'unsafe_response', conclusive: true });

    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue(
                new Response(
                    JSON.stringify({ error: { code: 'cart_changed' } }),
                    { status: 422 },
                ),
            ),
    );
    await expect(
        startPaylinkCheckout(
            '/checkout/paylink',
            'checkout-browser-key',
            1250,
            1250,
        ),
    ).rejects.toEqual(
        expect.objectContaining<Partial<PaylinkCheckoutError>>({
            code: 'cart_changed',
            conclusive: true,
            status: 422,
        }),
    );
});

it('parses a cart_repriced refusal and rejects one it cannot trust', async () => {
    const repricing = {
        couponRemoved: true,
        orderTotalHalalah: 13_000,
        payableHalalah: 13_000,
        previousOrderTotalHalalah: 12_500,
        previousPayableHalalah: 12_500,
    };

    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    error: { code: 'cart_repriced', message: 'changed' },
                    repricing,
                }),
                { status: 422 },
            ),
        ),
    );
    await expect(
        startPaylinkCheckout(
            '/checkout/paylink',
            'checkout-browser-key',
            12_500,
            12_500,
        ),
    ).rejects.toMatchObject({ code: 'cart_repriced', repricing });

    // A malformed repricing is not something to ask the customer to confirm.
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    error: { code: 'cart_repriced' },
                    repricing: { ...repricing, payableHalalah: -1 },
                }),
                { status: 422 },
            ),
        ),
    );
    await expect(
        startPaylinkCheckout(
            '/checkout/paylink',
            'checkout-browser-key',
            12_500,
            12_500,
        ),
    ).rejects.toMatchObject({ code: 'unsafe_response' });
});

it('sends both expected totals so a covered wallet cannot hide a price change', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(
            JSON.stringify({
                data: {
                    orderUrl: '/orders/01K00000000000000000000000',
                    paymentUrl: null,
                    status: 'paid',
                },
            }),
            { status: 201 },
        ),
    );
    vi.stubGlobal('fetch', fetchMock);

    await startPaylinkCheckout(
        '/checkout/paylink',
        'checkout-browser-key',
        0,
        12_500,
    );

    const headers = fetchMock.mock.calls[0][1].headers as Record<
        string,
        string
    >;

    expect(headers['X-Expected-Total-Halalah']).toBe('0');
    expect(headers['X-Expected-Order-Total-Halalah']).toBe('12500');
});
