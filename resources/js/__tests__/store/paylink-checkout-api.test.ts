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
        startPaylinkCheckout('/checkout/paylink', 'checkout-browser-key'),
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
        startPaylinkCheckout('/en/checkout/paylink', 'checkout-browser-key'),
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
        startPaylinkCheckout('/checkout/paylink', 'checkout-browser-key'),
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
        startPaylinkCheckout('/checkout/paylink', 'checkout-browser-key'),
    ).rejects.toEqual(
        expect.objectContaining<Partial<PaylinkCheckoutError>>({
            code: 'cart_changed',
            conclusive: true,
            status: 422,
        }),
    );
});
