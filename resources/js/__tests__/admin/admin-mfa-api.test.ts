import { beforeEach, expect, it, vi } from 'vitest';

import type { AdminMfaApiError } from '@/lib/admin-mfa-api';
import {
    confirmAdminMfa,
    enableAdminMfa,
    loadAdminMfaQrCode,
    loadAdminMfaRecoveryCodes,
    regenerateAdminMfaRecoveryCodes,
} from '@/lib/admin-mfa-api';

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
    document.head.innerHTML =
        '<meta name="csrf-token" content="csrf-admin-token">';
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    localStorage.clear();
    sessionStorage.clear();
});

it('rejects non-relative and cross-origin MFA endpoints before sending credentials', async () => {
    await expect(
        loadAdminMfaQrCode('https://example.test/user/two-factor-qr-code'),
    ).rejects.toMatchObject({ code: 'invalid_endpoint' });
    await expect(
        enableAdminMfa('//example.test/user/two-factor-authentication'),
    ).rejects.toMatchObject({ code: 'invalid_endpoint' });

    expect(fetchMock).not.toHaveBeenCalled();
});

it('loads only the exact Fortify QR response shape without caching credentials', async () => {
    fetchMock.mockResolvedValueOnce(
        jsonResponse({
            svg: '<svg viewBox="0 0 1 1"></svg>',
            url: 'otpauth://safe',
        }),
    );

    await expect(
        loadAdminMfaQrCode('/user/two-factor-qr-code'),
    ).resolves.toEqual({
        svg: '<svg viewBox="0 0 1 1"></svg>',
        url: 'otpauth://safe',
    });

    expect(fetchMock).toHaveBeenCalledWith('/user/two-factor-qr-code', {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        method: 'GET',
    });
    expect(localStorage).toHaveLength(0);
    expect(sessionStorage).toHaveLength(0);

    fetchMock.mockResolvedValueOnce(
        jsonResponse({
            svg: '<svg></svg>',
            url: 'otpauth://safe',
            secret: 'must-not-be-accepted',
        }),
    );

    await expect(
        loadAdminMfaQrCode('/user/two-factor-qr-code'),
    ).rejects.toMatchObject({ code: 'invalid_response' });
});

it('sends CSRF-protected exact mutation requests for enable and confirmation', async () => {
    fetchMock
        .mockResolvedValueOnce(jsonResponse(''))
        .mockResolvedValueOnce(jsonResponse(''));

    await enableAdminMfa('/user/two-factor-authentication');
    await confirmAdminMfa(
        '/user/confirmed-two-factor-authentication',
        '123456',
    );

    expect(fetchMock).toHaveBeenNthCalledWith(
        1,
        '/user/two-factor-authentication',
        expect.objectContaining({
            body: '{}',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'csrf-admin-token',
            },
            method: 'POST',
        }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
        2,
        '/user/confirmed-two-factor-authentication',
        expect.objectContaining({ body: '{"code":"123456"}', method: 'POST' }),
    );
});

it('requires exact recovery-code JSON and never persists returned codes', async () => {
    fetchMock.mockResolvedValueOnce(
        jsonResponse(['recovery-one', 'recovery-two']),
    );

    await expect(
        loadAdminMfaRecoveryCodes('/user/two-factor-recovery-codes'),
    ).resolves.toEqual(['recovery-one', 'recovery-two']);
    expect(localStorage).toHaveLength(0);
    expect(sessionStorage).toHaveLength(0);

    fetchMock.mockResolvedValueOnce(jsonResponse(['safe', 42]));

    await expect(
        loadAdminMfaRecoveryCodes('/user/two-factor-recovery-codes'),
    ).rejects.toMatchObject({ code: 'invalid_response' });
});

it('regenerates codes with CSRF and rejects a missing CSRF token before fetch', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(''));

    await regenerateAdminMfaRecoveryCodes('/user/two-factor-recovery-codes');
    expect(fetchMock).toHaveBeenCalledWith(
        '/user/two-factor-recovery-codes',
        expect.objectContaining({
            body: '{}',
            method: 'POST',
        }),
    );

    document.head.innerHTML = '';

    await expect(
        regenerateAdminMfaRecoveryCodes('/user/two-factor-recovery-codes'),
    ).rejects.toMatchObject({ code: 'csrf_missing' });
    expect(fetchMock).toHaveBeenCalledTimes(1);
});

it('maps password-confirmation and validation responses to typed failures', async () => {
    fetchMock.mockResolvedValueOnce(
        jsonResponse({ message: 'Password confirmation required.' }, 423),
    );

    await expect(
        loadAdminMfaQrCode('/user/two-factor-qr-code'),
    ).rejects.toEqual(
        expect.objectContaining<Partial<AdminMfaApiError>>({
            code: 'password_confirmation_required',
            status: 423,
        }),
    );

    fetchMock.mockResolvedValueOnce(
        jsonResponse(
            { message: 'Invalid code.', errors: { code: ['Invalid code.'] } },
            422,
        ),
    );

    await expect(
        confirmAdminMfa('/user/confirmed-two-factor-authentication', '000000'),
    ).rejects.toMatchObject({
        code: 'validation',
        fieldErrors: { code: 'Invalid code.' },
        status: 422,
    });
});

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        headers: { 'Content-Type': 'application/json' },
        status,
    });
}
