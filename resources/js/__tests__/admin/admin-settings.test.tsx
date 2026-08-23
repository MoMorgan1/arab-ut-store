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
    sampleAdminTeamData,
    sampleAdminTeamUrls,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminSettingsPage from '@/pages/admin/settings';
import type { AdminSettingsPageProps } from '@/types/admin';

const api = vi.hoisted(() => ({
    confirmAdminMfa: vi.fn(),
    enableAdminMfa: vi.fn(),
    loadAdminMfaQrCode: vi.fn(),
    loadAdminMfaRecoveryCodes: vi.fn(),
    regenerateAdminMfaRecoveryCodes: vi.fn(),
}));

const inertia = vi.hoisted(() => ({
    on: vi.fn(() => vi.fn()),
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
        on: inertia.on,
        reload: inertia.reload,
    },
    useHttp: () => http,
}));

const mfaRoutes = {
    confirm: '/user/confirmed-two-factor-authentication',
    disable: '/user/two-factor-authentication',
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
        permissions: ['dashboard.view', 'staff.view', 'staff.manage'],
        mfa: {
            confirmed: true,
            enabled: true,
            passwordConfigured: true,
            routes: mfaRoutes,
        },
        team: sampleAdminTeamData,
        teamUrls: sampleAdminTeamUrls,
        confirmPasswordUrl: '/confirm-password',
        logoutUrl: '/logout',
        ...overrides,
    };
}

describe('AdminSettingsPage', () => {
    beforeEach(() => {
        Object.values(api).forEach((mock) => mock.mockReset());
        inertia.reload.mockReset();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    it('renders the settings header and anchor navigation links for security and team', () => {
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
    });

    it('hides team section and anchor link when team is null (e.g. for Staff)', () => {
        render(
            <AdminSettingsPage
                {...createDefaultProps({ team: null, teamUrls: null })}
            />,
        );

        expect(screen.getByRole('link', { name: 'Security' })).toBeVisible();
        expect(screen.queryByRole('link', { name: 'Team' })).toBeNull();
        expect(
            screen.queryByRole('heading', { level: 2, name: 'Team' }),
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
});
