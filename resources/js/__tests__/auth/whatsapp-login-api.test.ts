import { afterEach, expect, test, vi } from 'vitest';

import {
    sendWhatsAppLoginCode,
    verifyWhatsAppLoginCode,
} from '@/lib/whatsapp-login-api';

afterEach(() => {
    vi.restoreAllMocks();
    document.head.innerHTML = '';
});

test('sends only the canonical phone to a same-origin endpoint with CSRF', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-test">';
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(JSON.stringify({ data: { sent: true } }), {
            status: 200,
        }),
    );

    await sendWhatsAppLoginCode('/auth/whatsapp/code', '+201001234567');

    const [requestUrl, options] = fetchMock.mock.calls[0] ?? [];
    expect(new URL(String(requestUrl)).pathname).toBe('/auth/whatsapp/code');
    expect(options?.headers).toMatchObject({ 'X-CSRF-TOKEN': 'csrf-test' });
    expect(JSON.parse(String(options?.body))).toEqual({
        phone: '+201001234567',
    });
});

test('accepts only a same-origin redirect from a successful verification', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-test">';
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(
            JSON.stringify({ data: { redirectUrl: '/cart?claimed=1' } }),
            {
                status: 200,
            },
        ),
    );

    await expect(
        verifyWhatsAppLoginCode(
            '/auth/whatsapp/verify',
            '+201001234567',
            '123456',
        ),
    ).resolves.toBe('/cart?claimed=1');
});

test('rejects cross-origin endpoints and redirect responses', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-test">';

    await expect(
        sendWhatsAppLoginCode('https://attacker.test/send', '+201001234567'),
    ).rejects.toThrow('unsafe_endpoint');

    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(
            JSON.stringify({ data: { redirectUrl: 'https://attacker.test' } }),
            { status: 200 },
        ),
    );
    await expect(
        verifyWhatsAppLoginCode(
            '/auth/whatsapp/verify',
            '+201001234567',
            '123456',
        ),
    ).rejects.toThrow('unsafe_endpoint');
});
