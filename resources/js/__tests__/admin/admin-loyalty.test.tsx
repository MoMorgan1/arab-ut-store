import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    englishAdminUi,
    sampleAdminLoyaltyKpis,
    sampleAdminLoyaltyTiers,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminLoyaltyPage from '@/pages/admin/marketing/loyalty';
import type { AdminLoyaltyPageProps } from '@/types/admin';

class TestResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

vi.stubGlobal('ResizeObserver', TestResizeObserver);

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
}));

const http = vi.hoisted(() => ({
    data: {
        cashback_basis_points: 0,
        is_active: true,
        minimum_lifetime_spend_halalah: 0,
        name_ar: '',
        name_en: '',
    },
    processing: false,
    setData: vi.fn(),
    submit: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/marketing/loyalty',
    props: {} as AdminLoyaltyPageProps,
    url: '/admin/marketing/loyalty',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    useHttp: () => http,
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

function defaultProps(): AdminLoyaltyPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/admin' },
            { key: 'orders', label: 'Orders', url: '/admin/orders' },
            { key: 'customers', label: 'Customers', url: '/admin/customers' },
            { key: 'products', label: 'Products', url: '/admin/products' },
            {
                key: 'marketingLoyalty',
                label: 'Loyalty',
                url: '/admin/marketing/loyalty',
            },
            { key: 'settings', label: 'Settings', url: '/admin/settings' },
        ],
        permissions: [
            'dashboard.view',
            'orders.view',
            'customers.view',
            'catalog.view',
            'loyalty.view',
            'loyalty.manage',
            'audit.view',
            'settings.view',
        ],
        tiers: [...sampleAdminLoyaltyTiers],
        kpis: { ...sampleAdminLoyaltyKpis },
        updateTierUrlTemplate: '/admin/api/marketing/loyalty/tiers/__ID__',
        confirmPasswordUrl: '/user/confirm-password',
        logoutUrl: '/logout',
    };
}

describe('AdminLoyaltyPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/marketing/loyalty';
        http.data = {
            cashback_basis_points: 0,
            is_active: true,
            minimum_lifetime_spend_halalah: 0,
            name_ar: '',
            name_en: '',
        };
        http.processing = false;
        http.setData = vi.fn();
        http.submit = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders heading, description, KPIs, and all 4 loyalty tiers', () => {
        render(<AdminLoyaltyPage {...defaultProps()} />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Loyalty tiers',
            }),
        ).toBeVisible();

        // KPIs
        expect(screen.getByText('Cashback credited (30d)')).toBeVisible();
        expect(screen.getByText('Total customers')).toBeVisible();
        expect(document.body.textContent?.replace(/\s/g, '')).toMatch(
            /1,?850\.00/,
        );

        // Table Rows
        expect(screen.getByText('Bronze')).toBeVisible();
        expect(screen.getByText('Silver')).toBeVisible();
        expect(screen.getByText('Gold')).toBeVisible();
        expect(screen.getByText('Platinum')).toBeVisible();
        const tableText = document.body.textContent?.replace(/\s/g, '') ?? '';

        expect(tableText).toMatch(/500\.00/);
        expect(tableText).toMatch(/1,?500\.00/);
        expect(tableText).toMatch(/5,?000\.00/);
    });

    it('opens edit dialog when edit tier button is clicked', () => {
        render(<AdminLoyaltyPage {...defaultProps()} />);

        const editSilverBtn = screen.getByRole('button', {
            name: 'Edit tier Silver',
        });
        fireEvent.click(editSilverBtn);

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Edit tier Silver',
            }),
        ).toBeVisible();

        expect(screen.getByLabelText(/English name/i)).toHaveValue('Silver');
        expect(screen.getByLabelText(/Lifetime spend threshold/i)).toHaveValue(
            500,
        );
    });

    it('submits tier update and updates local state on success', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onSuccess?: (response: {
                        data: (typeof sampleAdminLoyaltyTiers)[1];
                    }) => void;
                },
            ) => {
                options.onSuccess?.({
                    data: {
                        ...sampleAdminLoyaltyTiers[1],
                        nameEn: 'Silver Pro',
                        cashbackPercent: '4.0%',
                        cashbackBasisPoints: 400,
                    },
                });
            },
        );

        render(<AdminLoyaltyPage {...defaultProps()} />);

        const editSilverBtn = screen.getByRole('button', {
            name: 'Edit tier Silver',
        });
        fireEvent.click(editSilverBtn);

        const nameEnInput = screen.getByLabelText(/English name/i);
        fireEvent.change(nameEnInput, { target: { value: 'Silver Pro' } });

        const cashbackInput = screen.getByLabelText(/Cashback rate/i);
        fireEvent.change(cashbackInput, { target: { value: '4' } });

        const saveBtn = screen.getByRole('button', { name: 'Save changes' });
        fireEvent.click(saveBtn);

        expect(http.submit).toHaveBeenCalledWith(
            'put',
            '/admin/api/marketing/loyalty/tiers/01K5LOY00000000000000002',
            expect.any(Object),
        );

        await waitFor(() => {
            expect(
                screen.getByText('Loyalty tier updated successfully.'),
            ).toBeVisible();
        });
    });

    it('hides edit buttons if actor lacks loyalty.manage permission', () => {
        const readonlyProps = defaultProps();
        readonlyProps.permissions = ['loyalty.view'];

        render(<AdminLoyaltyPage {...readonlyProps} />);

        expect(
            screen.queryByRole('button', { name: /Edit tier/i }),
        ).not.toBeInTheDocument();
    });
});
