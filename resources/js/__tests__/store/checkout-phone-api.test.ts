import { beforeEach, expect, it, vi } from 'vitest';

import {
    sendCheckoutPhoneCode,
    verifyCheckoutPhoneCode,
} from '@/lib/checkout-phone-api';

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    vi.unstubAllGlobals();
});

it('sends and verifies a canonical phone through same-origin JSON endpoints', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(
            new Response(JSON.stringify({ data: { sent: true } }), {
                status: 200,
            }),
        )
        .mockResolvedValueOnce(
            new Response(JSON.stringify({ data: { verified: true } }), {
                status: 200,
            }),
        );
    vi.stubGlobal('fetch', fetchMock);

    await expect(
        sendCheckoutPhoneCode('/checkout/phone/code', '+966501234567'),
    ).resolves.toBeUndefined();
    await expect(
        verifyCheckoutPhoneCode(
            '/checkout/phone/verify',
            '+966501234567',
            '123456',
        ),
    ).resolves.toBeUndefined();

    expect(fetchMock).toHaveBeenNthCalledWith(
        1,
        new URL('/checkout/phone/code', window.location.origin),
        expect.objectContaining({
            body: JSON.stringify({ phone: '+966501234567' }),
            credentials: 'same-origin',
            method: 'POST',
        }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
        2,
        new URL('/checkout/phone/verify', window.location.origin),
        expect.objectContaining({
            body: JSON.stringify({ code: '123456', phone: '+966501234567' }),
        }),
    );
});

it('rejects unsafe endpoints invalid input and stable server errors', async () => {
    await expect(
        sendCheckoutPhoneCode('https://attacker.test/code', '+966501234567'),
    ).rejects.toMatchObject({ code: 'unsafe_endpoint' });
    await expect(
        verifyCheckoutPhoneCode('/checkout/phone/verify', '+966501234567', '1'),
    ).rejects.toMatchObject({ code: 'invalid_input' });

    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue(
                new Response(
                    JSON.stringify({ error: { code: 'phone_unavailable' } }),
                    { status: 422 },
                ),
            ),
    );

    await expect(
        sendCheckoutPhoneCode('/checkout/phone/code', '+966501234567'),
    ).rejects.toMatchObject({ code: 'phone_unavailable', status: 422 });
});
