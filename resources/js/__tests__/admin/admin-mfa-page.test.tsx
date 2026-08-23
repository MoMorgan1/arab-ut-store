import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminSecuritySection from '@/components/admin/settings/admin-security-section';
import { AdminMfaApiError } from '@/lib/admin-mfa-api';
import type { AdminMfaState } from '@/types/admin';

const api = vi.hoisted(() => ({
    confirmAdminMfa: vi.fn(),
    enableAdminMfa: vi.fn(),
    loadAdminMfaQrCode: vi.fn(),
    loadAdminMfaRecoveryCodes: vi.fn(),
    regenerateAdminMfaRecoveryCodes: vi.fn(),
}));
const inertia = vi.hoisted(() => ({
    before: undefined as (() => void) | undefined,
    on: vi.fn(),
}));

vi.mock('@/lib/admin-mfa-api', async () => ({
    ...(await vi.importActual<Record<string, unknown>>('@/lib/admin-mfa-api')),
    ...api,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        on: inertia.on,
    },
}));

const adminUi = englishAdminUi;

const routes = {
    confirm: '/user/confirmed-two-factor-authentication',
    disable: '/user/two-factor-authentication',
    forgetTrustedDevices: '/admin/api/security/trusted-devices',
    enable: '/user/two-factor-authentication',
    qrCode: '/user/two-factor-qr-code',
    recoveryCodes: '/user/two-factor-recovery-codes',
    regenerateRecoveryCodes: '/user/two-factor-recovery-codes',
};

beforeEach(() => {
    Object.values(api).forEach((mock) => mock.mockReset());
    inertia.before = undefined;
    inertia.on.mockReset();
    inertia.on.mockImplementation((event: string, callback: () => void) => {
        if (event === 'before') {
            inertia.before = callback;
        }

        return vi.fn();
    });
});

afterEach(cleanup);

describe('AdminSecuritySection', () => {
    it('renders the English start state with explicit accessible actions', () => {
        renderPage({ enabled: false, confirmed: false });

        expect(
            screen.getByRole('heading', {
                name: adminUi.settings.securitySection,
            }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: adminUi.mfa.enable }),
        ).toHaveClass('min-h-11');
        expect(screen.queryByText('recovery-one')).not.toBeInTheDocument();
    });

    it('enables MFA, shows the QR confirmation state, and confirms a six-digit code', async () => {
        api.enableAdminMfa.mockResolvedValue(undefined);
        api.loadAdminMfaQrCode.mockResolvedValue({
            svg: '<svg viewBox="0 0 1 1"></svg>',
            url: 'otpauth://must-not-render',
        });
        api.confirmAdminMfa.mockResolvedValue(undefined);
        renderPage({ enabled: false, confirmed: false });

        fireEvent.click(
            screen.getByRole('button', { name: adminUi.mfa.enable }),
        );

        expect(
            await screen.findByRole('img', { name: adminUi.mfa.qrAlt }),
        ).toHaveAttribute('src', expect.stringContaining('data:image/svg+xml'));
        expect(document.body.textContent).not.toContain(
            'otpauth://must-not-render',
        );

        const code = screen.getByLabelText(adminUi.mfa.confirmCode);
        fireEvent.change(code, { target: { value: '123456' } });
        fireEvent.click(
            screen.getByRole('button', { name: adminUi.mfa.confirm }),
        );

        await waitFor(() =>
            expect(api.confirmAdminMfa).toHaveBeenCalledWith(
                routes.confirm,
                '123456',
            ),
        );
        expect(
            await screen.findByRole('heading', {
                name: adminUi.mfa.configured,
            }),
        ).toBeVisible();
    });

    it('completes async enrollment after StrictMode replays effect setup and cleanup', async () => {
        api.enableAdminMfa.mockResolvedValue(undefined);
        api.loadAdminMfaQrCode.mockResolvedValue({
            svg: '<svg viewBox="0 0 1 1"></svg>',
            url: 'otpauth://strict-mode',
        });

        render(
            <StrictMode>
                <AdminSecuritySection
                    adminUi={adminUi}
                    direction="ltr"
                    locale="en"
                    mfa={{
                        confirmed: false,
                        enabled: false,
                        passwordConfigured: true,
                        routes,
                        trustedDeviceCount: 0,
                        trustedDeviceDays: 30,
                    }}
                />
            </StrictMode>,
        );

        fireEvent.click(
            screen.getByRole('button', { name: adminUi.mfa.enable }),
        );

        expect(
            await screen.findByRole('img', { name: adminUi.mfa.qrAlt }),
        ).toBeVisible();
        expect(
            screen.getByLabelText(adminUi.mfa.confirmCode),
        ).not.toBeDisabled();
        expect(
            screen.queryByText(adminUi.mfa.enabling),
        ).not.toBeInTheDocument();
    });

    it('reveals recovery codes only on request and clears them on route change', async () => {
        api.loadAdminMfaRecoveryCodes.mockResolvedValue([
            'recovery-one',
            'recovery-two',
        ]);
        renderPage({ enabled: true, confirmed: true });

        expect(screen.queryByText('recovery-one')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: adminUi.mfa.showRecoveryCodes,
            }),
        );

        expect(await screen.findByText('recovery-one')).toBeVisible();
        expect(inertia.before).toBeTypeOf('function');
        inertia.before?.();

        await waitFor(() =>
            expect(screen.queryByText('recovery-one')).not.toBeInTheDocument(),
        );
    });

    it('places an invalid authenticator-code error beside the code field', async () => {
        api.loadAdminMfaQrCode.mockResolvedValue({
            svg: '<svg></svg>',
            url: 'otpauth://safe',
        });
        api.confirmAdminMfa.mockRejectedValue(
            new AdminMfaApiError('validation', 'Invalid code.', 422, {
                code: 'Invalid code.',
            }),
        );
        renderPage({ enabled: true, confirmed: false });

        const code = await screen.findByLabelText(adminUi.mfa.confirmCode);
        fireEvent.change(code, { target: { value: '000000' } });
        fireEvent.click(
            screen.getByRole('button', { name: adminUi.mfa.confirm }),
        );

        expect(
            await screen.findByText(adminUi.mfa.invalidCode),
        ).toHaveAttribute('id', 'admin-mfa-code-error');
        expect(code).toHaveAttribute('aria-invalid', 'true');
        expect(code).toHaveAttribute(
            'aria-describedby',
            'admin-mfa-code-error',
        );
    });

    it('renders regeneration confirmation and rotates recovery codes', async () => {
        api.loadAdminMfaRecoveryCodes
            .mockResolvedValueOnce(['old-code-1'])
            .mockResolvedValueOnce(['new-code-1', 'new-code-2']);
        api.regenerateAdminMfaRecoveryCodes.mockResolvedValue(undefined);

        renderPage({ enabled: true, confirmed: true });

        fireEvent.click(
            screen.getByRole('button', {
                name: adminUi.mfa.showRecoveryCodes,
            }),
        );
        expect(await screen.findByText('old-code-1')).toBeVisible();

        fireEvent.click(
            screen.getByRole('button', {
                name: adminUi.mfa.regenerateRecoveryCodes,
            }),
        );
        expect(
            screen.getByText(adminUi.mfa.regenerateTitle),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: adminUi.mfa.confirmRegenerate,
            }),
        );

        await waitFor(() => {
            expect(api.regenerateAdminMfaRecoveryCodes).toHaveBeenCalledWith(
                routes.regenerateRecoveryCodes,
            );
            expect(screen.getByText('new-code-1')).toBeVisible();
            expect(screen.getByText('new-code-2')).toBeVisible();
        });
    });

    it('explains throttling without offering an immediate retry loop', async () => {
        api.enableAdminMfa.mockRejectedValue(
            new AdminMfaApiError('rate_limited', 'Too many requests.', 429),
        );
        renderPage({ enabled: false, confirmed: false });

        fireEvent.click(
            screen.getByRole('button', { name: adminUi.mfa.enable }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            adminUi.mfa.rateLimited,
        );
        expect(
            screen.queryByRole('button', { name: adminUi.common.retry }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: adminUi.mfa.retryAfterWait }),
        ).toHaveAttribute('href', '/en/admin/settings');
        expect(
            screen.queryByRole('button', { name: adminUi.mfa.enable }),
        ).not.toBeInTheDocument();
    });

    it('provides English password-setup guidance without attempting MFA requests', () => {
        renderPage({
            passwordConfigured: false,
            enabled: false,
            confirmed: false,
        });

        expect(
            screen.getByRole('heading', {
                name: adminUi.mfa.setupPassword,
            }),
        ).toBeVisible();
        expect(
            screen.getByRole('link', {
                name: adminUi.mfa.openAccountSecurity,
            }),
        ).toHaveAttribute('href', '/en/my-account/security');
        expect(api.enableAdminMfa).not.toHaveBeenCalled();
    });
});

function renderPage(
    state: Partial<AdminMfaState> &
        Pick<AdminMfaState, 'enabled' | 'confirmed'>,
) {
    return render(
        <AdminSecuritySection
            adminUi={adminUi}
            direction="ltr"
            locale="en"
            mfa={{
                passwordConfigured: true,
                routes,
                trustedDeviceCount: 0,
                trustedDeviceDays: 30,
                ...state,
            }}
        />,
    );
}
