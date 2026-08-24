import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    englishAdminUi,
    sampleAdminServicePricingData,
    sampleAdminServicePricingUrls,
    sampleAdminTeamData,
    sampleAdminTeamUrls,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminSettingsPage from '@/pages/admin/settings';
import type { AdminSettingsPageProps } from '@/types/admin';

const api = vi.hoisted(() => ({
    confirmAdminMfa: vi.fn(),
    enableAdminMfa: vi.fn(),
    forgetAdminMfaTrustedDevices: vi.fn(),
    loadAdminMfaQrCode: vi.fn(),
    loadAdminMfaRecoveryCodes: vi.fn(),
    regenerateAdminMfaRecoveryCodes: vi.fn(),
}));

const inertia = vi.hoisted(() => ({
    flushAll: vi.fn(),
    on: vi.fn(() => vi.fn()),
    post: vi.fn(),
    reload: vi.fn(),
}));

const http = vi.hoisted(() => ({
    data: {} as Record<string, unknown>,
    processing: false,
    setData: vi.fn(),
    submit: vi.fn(),
}));

vi.mock('@/lib/admin-mfa-api', async () => ({
    ...(await vi.importActual<Record<string, unknown>>('@/lib/admin-mfa-api')),
    ...api,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        flushAll: inertia.flushAll,
        on: inertia.on,
        post: inertia.post,
        reload: inertia.reload,
    },
    useHttp: () => http,
}));

const mfaRoutes = {
    confirm: '/user/confirmed-two-factor-authentication',
    disable: '/user/two-factor-authentication',
    forgetTrustedDevices: '/admin/api/security/trusted-devices',
    enable: '/user/two-factor-authentication',
    qrCode: '/user/two-factor-qr-code',
    recoveryCodes: '/user/two-factor-recovery-codes',
    regenerateRecoveryCodes: '/user/two-factor-recovery-codes',
};

function createDefaultProps(
    overrides: Partial<AdminSettingsPageProps> = {},
): AdminSettingsPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/admin' },
            { key: 'orders', label: 'Orders', url: '/admin/orders' },
            { key: 'settings', label: 'Settings', url: '/admin/settings' },
        ],
        permissions: [
            'dashboard.view',
            'staff.view',
            'staff.manage',
            'settings.view',
            'settings.manage',
        ],
        mfa: {
            confirmed: true,
            enabled: true,
            passwordConfigured: true,
            routes: mfaRoutes,
            trustedDeviceCount: 2,
            trustedDeviceDays: 30,
        },
        team: sampleAdminTeamData,
        teamUrls: sampleAdminTeamUrls,
        servicePricing: sampleAdminServicePricingData,
        servicePricingUrls: sampleAdminServicePricingUrls,
        logoutUrl: '/logout',
        ...overrides,
    };
}

describe('AdminSettingsPage', () => {
    beforeEach(() => {
        Object.values(api).forEach((mock) => mock.mockReset());
        inertia.flushAll.mockReset();
        inertia.post.mockReset();
        inertia.reload.mockReset();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    it('renders the settings header and anchor navigation links for security, team, and service pricing', () => {
        render(<AdminSettingsPage {...createDefaultProps()} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Settings' }),
        ).toBeVisible();
        expect(screen.getByRole('link', { name: 'Security' })).toHaveAttribute(
            'href',
            '#security',
        );
        expect(screen.getByRole('link', { name: 'Team' })).toHaveAttribute(
            'href',
            '#team',
        );
        expect(
            screen.getByRole('link', { name: 'Service pricing' }),
        ).toHaveAttribute('href', '#service-pricing');
    });

    it('hides team and service pricing section and anchor links when props are null (e.g. for Staff)', () => {
        render(
            <AdminSettingsPage
                {...createDefaultProps({
                    servicePricing: null,
                    servicePricingUrls: null,
                    team: null,
                    teamUrls: null,
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'Security' })).toBeVisible();
        expect(screen.queryByRole('link', { name: 'Team' })).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Service pricing' }),
        ).toBeNull();
        expect(
            screen.queryByRole('heading', { level: 2, name: 'Team' }),
        ).toBeNull();
        expect(
            screen.queryByRole('heading', {
                level: 2,
                name: 'Service pricing',
            }),
        ).toBeNull();
        expect(
            screen.getByRole('heading', { level: 2, name: 'Security' }),
        ).toBeVisible();
    });

    it('renders team members with self badge, roles, status, and mfa status', () => {
        render(<AdminSettingsPage {...createDefaultProps()} />);

        expect(
            screen.getByRole('heading', { level: 2, name: 'Team' }),
        ).toBeVisible();
        expect(screen.getAllByText('Operations Owner')[0]).toBeVisible();
        expect(screen.getAllByText('You')[0]).toBeVisible();
        expect(screen.getAllByText('Staff Operator')[0]).toBeVisible();
        expect(screen.getAllByText('Inactive Admin')[0]).toBeVisible();

        expect(
            screen.getByText(
                /New staff members are provisioned via the command line/i,
            ),
        ).toBeVisible();
    });

    it('allows an admin to select a new role and open the confirmation dialog', async () => {
        render(<AdminSettingsPage {...createDefaultProps()} />);

        // The staff member is "Staff Operator"
        const select = within(screen.getByTestId('team-table')).getByLabelText(
            'Role for Staff Operator',
        );
        expect(select).toHaveValue('staff');

        fireEvent.change(select, { target: { value: 'admin' } });
        expect(select).toHaveValue('admin');

        // Apply button should be enabled
        const applyButtons = screen.getAllByRole('button', { name: 'Apply' });
        expect(applyButtons[0]).toBeEnabled();

        fireEvent.click(applyButtons[0]);

        // Dialog should appear
        expect(
            await screen.findByText('Change staff role'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Are you sure you want to change the role of Staff Operator from Staff to Admin\?/i,
            ),
        ).toBeInTheDocument();
    });

    it('allows an admin to open deactivate confirmation dialog for an active staff member', async () => {
        render(<AdminSettingsPage {...createDefaultProps()} />);

        const deactivateButtons = screen.getAllByRole('button', {
            name: /deactivate/i,
        });
        fireEvent.click(deactivateButtons[0]);

        expect(
            await screen.findByText('Deactivate staff member'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Are you sure you want to deactivate Staff Operator\? They will be signed out immediately/i,
            ),
        ).toBeInTheDocument();
    });

    it('submits role change successfully and triggers team prop reload', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    role: 'admin',
                    updatedAt: '2026-08-23T00:00:00Z',
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminSettingsPage {...createDefaultProps()} />);

        const select = within(screen.getByTestId('team-table')).getByLabelText(
            'Role for Staff Operator',
        );
        fireEvent.change(select, { target: { value: 'admin' } });

        const applyButtons = screen.getAllByRole('button', { name: 'Apply' });
        fireEvent.click(applyButtons[0]);

        const confirmButton = await screen.findByRole('button', {
            name: 'Change role',
        });
        fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/team/01K5ADM1N00000000000000002/role',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({
                        expected_role: 'staff',
                        role: 'admin',
                    }),
                }),
            );
            expect(inertia.reload).toHaveBeenCalledWith({ only: ['team'] });
        });
    });

    it('grants admin access to an existing account and reloads the team', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    id: '01K5ADM1N00000000000000003',
                    name: 'New Operator',
                    email: 'new.operator@example.test',
                    role: 'staff',
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminSettingsPage {...createDefaultProps()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Add team member' }),
        );
        fireEvent.change(await screen.findByLabelText('Account email'), {
            target: { value: 'new.operator@example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Grant access' }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/team/grants',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({
                        email: 'new.operator@example.test',
                        role: 'staff',
                    }),
                }),
            );
            expect(inertia.reload).toHaveBeenCalledWith({ only: ['team'] });
        });
    });

    it('explains a refused grant instead of showing a generic error', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({ reason: 'no_such_account' }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminSettingsPage {...createDefaultProps()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Add team member' }),
        );
        fireEvent.change(await screen.findByLabelText('Account email'), {
            target: { value: 'stranger@example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Grant access' }));

        expect(
            await screen.findByText(
                'No account exists for that email address. Ask them to sign up first, then grant access here.',
            ),
        ).toBeVisible();
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('handles 409 conflict during status change by displaying conflict alert and reloading team', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 409,
            json: async () => ({
                member: '01K5ADM1N00000000000000002',
                isActive: false,
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminSettingsPage {...createDefaultProps()} />);

        const deactivateButtons = screen.getAllByRole('button', {
            name: /deactivate/i,
        });
        fireEvent.click(deactivateButtons[0]);

        const confirmButton = await screen.findByRole('button', {
            name: 'Deactivate account',
        });
        fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(
                screen.getByText(
                    'This account was modified by another action. Please review the latest details before trying again.',
                ),
            ).toBeInTheDocument();
            expect(inertia.reload).toHaveBeenCalledWith({ only: ['team'] });
        });
    });

    it('renders security section with recovery codes view and regeneration', async () => {
        api.loadAdminMfaRecoveryCodes.mockResolvedValue([
            'ABCD-1234',
            'EFGH-5678',
        ]);
        api.regenerateAdminMfaRecoveryCodes.mockResolvedValue(undefined);

        render(<AdminSettingsPage {...createDefaultProps()} />);

        const showCodesButton = screen.getByRole('button', {
            name: 'Show recovery codes',
        });
        fireEvent.click(showCodesButton);

        await waitFor(() => {
            expect(api.loadAdminMfaRecoveryCodes).toHaveBeenCalledWith(
                mfaRoutes.recoveryCodes,
            );
            expect(screen.getByText('ABCD-1234')).toBeVisible();
            expect(screen.getByText('EFGH-5678')).toBeVisible();
        });

        const regenButton = screen.getByRole('button', {
            name: 'Create new recovery codes',
        });
        fireEvent.click(regenButton);

        expect(screen.getByText('Replace recovery codes?')).toBeVisible();
        expect(
            screen.getByText(
                'Your current codes will stop working immediately. Be ready to save the new codes.',
            ),
        ).toBeVisible();
    });

    it('renders trusted devices block and allows forgetting trusted browsers with confirmation', async () => {
        api.forgetAdminMfaTrustedDevices.mockResolvedValue({ revoked: 2 });

        render(<AdminSettingsPage {...createDefaultProps()} />);

        expect(
            screen.getByRole('heading', { name: 'Trusted browsers' }),
        ).toBeVisible();
        expect(
            screen.getByText(
                '2 browser(s) currently trusted. Trusted browsers skip the two-factor challenge for up to 30 days.',
            ),
        ).toBeVisible();

        const forgetButton = screen.getByRole('button', {
            name: 'Forget trusted browsers',
        });
        expect(forgetButton).toBeEnabled();

        fireEvent.click(forgetButton);

        expect(
            screen.getByRole('heading', {
                name: 'Forget all trusted browsers?',
            }),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Every browser, including this one, will have to enter a two-factor code again at the next sign-in.',
            ),
        ).toBeVisible();

        const confirmButton = screen.getByRole('button', {
            name: 'Forget all browsers',
        });
        fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(api.forgetAdminMfaTrustedDevices).toHaveBeenCalledWith(
                mfaRoutes.forgetTrustedDevices,
            );
            expect(
                screen.getByText(
                    'No browsers are currently trusted. Every sign-in will require a two-factor code.',
                ),
            ).toBeVisible();
            expect(
                screen.getByRole('button', {
                    name: 'Forget trusted browsers',
                }),
            ).toBeDisabled();
        });
    });

    it('renders the identity and logout card for Admin and allows logging out', () => {
        render(
            <AdminSettingsPage
                {...createDefaultProps({
                    adminIdentity: { name: 'Operations Owner', role: 'admin' },
                    logoutUrl: '/logout',
                })}
            />,
        );

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Operations Owner',
            }),
        ).toBeVisible();
        expect(screen.getAllByText('Admin')[0]).toBeVisible();

        const logoutButton = screen.getByRole('button', { name: 'Log out' });
        expect(logoutButton).toBeVisible();

        fireEvent.click(logoutButton);

        expect(inertia.flushAll).toHaveBeenCalledOnce();
        expect(inertia.post).toHaveBeenCalledWith('/logout');
    });

    it('renders the identity and logout card for Staff even when team and pricing are null', () => {
        render(
            <AdminSettingsPage
                {...createDefaultProps({
                    adminIdentity: { name: 'Staff Operator', role: 'staff' },
                    servicePricing: null,
                    servicePricingUrls: null,
                    team: null,
                    teamUrls: null,
                    logoutUrl: '/logout',
                })}
            />,
        );

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Staff Operator',
            }),
        ).toBeVisible();
        expect(screen.getByText('Staff')).toBeVisible();

        const logoutButton = screen.getByRole('button', { name: 'Log out' });
        expect(logoutButton).toBeVisible();

        fireEvent.click(logoutButton);

        expect(inertia.flushAll).toHaveBeenCalledOnce();
        expect(inertia.post).toHaveBeenCalledWith('/logout');
    });
});
