import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import { AdminMfaApiError } from '@/lib/admin-mfa-api';
import AdminMfaPage from '@/pages/admin/security/mfa';
import type { AdminMfaPageProps } from '@/types/admin';

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

const adminUi: AdminMfaPageProps['adminUi'] = {
    brand: englishAdminUi.brand,
    common: {
        cancel: englishAdminUi.common.cancel,
        retry: englishAdminUi.common.retry,
    },
    mfa: englishAdminUi.mfa,
};

const routes = {
    confirm: '/user/confirmed-two-factor-authentication',
    disable: '/user/two-factor-authentication',
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

it('renders the English start state with explicit accessible actions', () => {
    renderPage({ enabled: false, confirmed: false });

    expect(screen.getByRole('main')).toHaveAttribute('dir', 'ltr');
    expect(
        screen.getByRole('heading', {
            name: adminUi.mfa.title,
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

    fireEvent.click(screen.getByRole('button', { name: adminUi.mfa.enable }));

    expect(
        await screen.findByRole('img', { name: adminUi.mfa.qrAlt }),
    ).toHaveAttribute('src', expect.stringContaining('data:image/svg+xml'));
    expect(document.body.textContent).not.toContain(
        'otpauth://must-not-render',
    );

    const code = screen.getByLabelText(adminUi.mfa.confirmCode);
    fireEvent.change(code, { target: { value: '123456' } });
    fireEvent.click(screen.getByRole('button', { name: adminUi.mfa.confirm }));

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
            <AdminMfaPage
                adminUi={adminUi}
                direction="ltr"
                locale="en"
                mfa={{
                    confirmed: false,
                    enabled: false,
                    passwordConfigured: true,
                    routes,
                }}
            />
        </StrictMode>,
    );

    fireEvent.click(screen.getByRole('button', { name: adminUi.mfa.enable }));

    expect(
        await screen.findByRole('img', { name: adminUi.mfa.qrAlt }),
    ).toBeVisible();
    expect(screen.getByLabelText(adminUi.mfa.confirmCode)).not.toBeDisabled();
    expect(screen.queryByText(adminUi.mfa.enabling)).not.toBeInTheDocument();
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
    fireEvent.click(screen.getByRole('button', { name: adminUi.mfa.confirm }));

    expect(await screen.findByText(adminUi.mfa.invalidCode)).toHaveAttribute(
        'id',
        'admin-mfa-code-error',
    );
    expect(code).toHaveAttribute('aria-invalid', 'true');
    expect(code).toHaveAttribute('aria-describedby', 'admin-mfa-code-error');
});

it('requires explicit regeneration confirmation before replacing codes', async () => {
    api.loadAdminMfaRecoveryCodes
        .mockResolvedValueOnce(['recovery-one'])
        .mockResolvedValueOnce(['new-recovery-code']);
    api.regenerateAdminMfaRecoveryCodes.mockResolvedValue(undefined);
    renderPage({ enabled: true, confirmed: true });

    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.showRecoveryCodes,
        }),
    );
    expect(await screen.findByText('recovery-one')).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.regenerateRecoveryCodes,
        }),
    );

    expect(api.regenerateAdminMfaRecoveryCodes).not.toHaveBeenCalled();
    expect(
        screen.getByRole('heading', {
            name: adminUi.mfa.regenerateTitle,
        }),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.confirmRegenerate,
        }),
    );

    expect(await screen.findByText('new-recovery-code')).toBeVisible();
    expect(api.regenerateAdminMfaRecoveryCodes).toHaveBeenCalledWith(
        routes.regenerateRecoveryCodes,
    );
});

it('forgets invalidated codes after regeneration and retries only the failed code fetch', async () => {
    api.loadAdminMfaRecoveryCodes
        .mockResolvedValueOnce(['old-invalidated-code'])
        .mockRejectedValueOnce(new Error('recovery fetch failed'))
        .mockResolvedValueOnce(['new-recovery-code']);
    api.regenerateAdminMfaRecoveryCodes.mockResolvedValue(undefined);
    renderPage({ enabled: true, confirmed: true });

    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.showRecoveryCodes,
        }),
    );
    expect(await screen.findByText('old-invalidated-code')).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.regenerateRecoveryCodes,
        }),
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.confirmRegenerate,
        }),
    );

    expect(await screen.findByRole('alert')).toHaveTextContent(
        adminUi.mfa.failed,
    );
    expect(screen.queryByText('old-invalidated-code')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('heading', {
            name: adminUi.mfa.regenerateTitle,
        }),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: adminUi.common.retry }));

    expect(await screen.findByText('new-recovery-code')).toBeVisible();
    expect(api.regenerateAdminMfaRecoveryCodes).toHaveBeenCalledTimes(1);
    expect(api.loadAdminMfaRecoveryCodes).toHaveBeenCalledTimes(3);
});

it('reconciles with GET only when a committed regeneration response is lost', async () => {
    let rejectRegeneration!: (reason: unknown) => void;
    const lostRegenerationResponse = new Promise<void>((_resolve, reject) => {
        rejectRegeneration = reject;
    });
    api.loadAdminMfaRecoveryCodes
        .mockResolvedValueOnce(['old-invalidated-code'])
        .mockResolvedValueOnce(['rotated-recovery-code']);
    api.regenerateAdminMfaRecoveryCodes.mockReturnValue(
        lostRegenerationResponse,
    );
    renderPage({ enabled: true, confirmed: true });

    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.showRecoveryCodes,
        }),
    );
    expect(await screen.findByText('old-invalidated-code')).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.regenerateRecoveryCodes,
        }),
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: adminUi.mfa.confirmRegenerate,
        }),
    );

    expect(api.regenerateAdminMfaRecoveryCodes).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('old-invalidated-code')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('heading', {
            name: adminUi.mfa.regenerateTitle,
        }),
    ).not.toBeInTheDocument();

    await act(async () => {
        rejectRegeneration(
            new AdminMfaApiError('network', 'The committed response was lost.'),
        );
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
        adminUi.mfa.failed,
    );
    fireEvent.click(screen.getByRole('button', { name: adminUi.common.retry }));

    expect(await screen.findByText('rotated-recovery-code')).toBeVisible();
    expect(api.regenerateAdminMfaRecoveryCodes).toHaveBeenCalledTimes(1);
    expect(api.loadAdminMfaRecoveryCodes).toHaveBeenCalledTimes(2);
});

it('shows a recoverable failure state and retries the failed operation', async () => {
    api.enableAdminMfa
        .mockRejectedValueOnce(new Error('network'))
        .mockResolvedValueOnce(undefined);
    api.loadAdminMfaQrCode.mockResolvedValue({
        svg: '<svg></svg>',
        url: 'otpauth://safe',
    });
    renderPage({ enabled: false, confirmed: false });

    fireEvent.click(screen.getByRole('button', { name: adminUi.mfa.enable }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        adminUi.mfa.failed,
    );
    fireEvent.click(screen.getByRole('button', { name: adminUi.common.retry }));

    expect(
        await screen.findByRole('img', { name: adminUi.mfa.qrAlt }),
    ).toBeVisible();
    expect(api.enableAdminMfa).toHaveBeenCalledTimes(2);
});

it.each([
    [
        'unauthenticated',
        401,
        adminUi.mfa.sessionExpired,
        adminUi.mfa.signIn,
        '/en/login',
    ],
    [
        'forbidden',
        403,
        adminUi.mfa.accessDenied,
        adminUi.mfa.returnToStore,
        '/en',
    ],
    [
        'password_confirmation_required',
        423,
        adminUi.mfa.passwordConfirmationExpired,
        adminUi.mfa.confirmPasswordAgain,
        '/en/admin/security/mfa',
    ],
] as const)(
    'offers a safe %s recovery destination instead of repeating the failed request',
    async (code, status, message, action, href) => {
        api.enableAdminMfa.mockRejectedValue(
            new AdminMfaApiError(code, message, status),
        );
        renderPage({ enabled: false, confirmed: false });

        fireEvent.click(
            screen.getByRole('button', { name: adminUi.mfa.enable }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(message);
        expect(screen.getByRole('link', { name: action })).toHaveAttribute(
            'href',
            href,
        );
        expect(
            screen.queryByRole('button', { name: adminUi.common.retry }),
        ).not.toBeInTheDocument();
    },
);

it('explains throttling without offering an immediate retry loop', async () => {
    api.enableAdminMfa.mockRejectedValue(
        new AdminMfaApiError('rate_limited', 'Too many requests.', 429),
    );
    renderPage({ enabled: false, confirmed: false });

    fireEvent.click(screen.getByRole('button', { name: adminUi.mfa.enable }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
        adminUi.mfa.rateLimited,
    );
    expect(
        screen.queryByRole('button', { name: adminUi.common.retry }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: adminUi.mfa.retryAfterWait }),
    ).toHaveAttribute('href', '/en/admin/security/mfa');
    expect(
        screen.queryByRole('button', { name: adminUi.mfa.enable }),
    ).not.toBeInTheDocument();
});

it('provides English password-setup guidance without attempting MFA requests', () => {
    renderPage({ passwordConfigured: false, enabled: false, confirmed: false });

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

function renderPage(
    state: Partial<AdminMfaPageProps['mfa']> &
        Pick<AdminMfaPageProps['mfa'], 'enabled' | 'confirmed'>,
) {
    return render(
        <AdminMfaPage
            adminUi={adminUi}
            direction="ltr"
            locale="en"
            mfa={{
                passwordConfigured: true,
                routes,
                ...state,
            }}
        />,
    );
}
