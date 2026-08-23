import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminCouponsPage from '@/pages/admin/marketing/coupons';
import type { AdminCouponRow, AdminCouponsPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/marketing/coupons',
    url: '/admin/marketing/coupons',
    props: {} as AdminCouponsPageProps,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

// The shared password dialog has dedicated coverage through the admin order
// detail flows; here we stub the seam so these tests focus on the coupon
// page's own orchestration of pending actions after confirmation.
vi.mock('@/components/admin/admin-password-confirm-dialog', () => ({
    default: ({
        onConfirmed,
        open,
    }: {
        onConfirmed: () => void;
        open: boolean;
    }) =>
        open ? (
            <button onClick={onConfirmed} type="button">
                confirmed-password-overlay
            </button>
        ) : null,
}));

function sampleCouponRow(overrides: Partial<AdminCouponRow>): AdminCouponRow {
    return {
        id: '01KCOUPON0000000000000001',
        code: 'WELCOME10',
        createdAt: '2026-08-20T10:00:00Z',
        discountType: 'percent',
        isActive: true,
        maximumDiscountHalalah: 10_000,
        minimumOrderHalalah: 5_000,
        perUserLimit: null,
        startsAt: null,
        endsAt: '2026-12-31T00:00:00Z',
        usageLimit: null,
        usedCount: 12,
        value: 10,
        ...overrides,
    };
}

const sampleCoupons: AdminCouponRow[] = [
    sampleCouponRow({}),
    sampleCouponRow({
        id: '01KCOUPON0000000000000002',
        code: 'FLAT15SAR',
        discountType: 'fixed',
        value: 1500,
        maximumDiscountHalalah: null,
        minimumOrderHalalah: 0,
        usedCount: 4,
        startsAt: '2026-08-01T00:00:00Z',
        endsAt: null,
        isActive: false,
        usageLimit: 50,
    }),
];

function defaultProps(): AdminCouponsPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/admin' },
            { key: 'orders', label: 'Orders', url: '/admin/orders' },
            { key: 'customers', label: 'Customers', url: '/admin/customers' },
            {
                key: 'marketing',
                label: 'Marketing',
                url: '/admin/marketing/coupons',
            },
            { key: 'settings', label: 'Settings', url: '/admin/settings' },
        ],
        permissions: [
            'dashboard.view',
            'customers.view',
            'marketing.view',
            'marketing.manage',
        ],
        coupons: sampleCoupons.map((coupon) => ({ ...coupon })),
        pagination: {
            currentPage: 1,
            lastPage: 1,
            perPage: 15,
            total: 2,
            from: 1,
            to: 2,
        },
        counts: { total: 2, active: 1 },
        filters: {
            search: null,
            sort: 'created_at',
            direction: 'desc',
            per_page: 15,
            page: 1,
        },
        logoutUrl: '/logout',
    };
}

describe('AdminCouponsPage', () => {
    beforeEach(() => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="test-token">';
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            value: 'XSRF-TOKEN=test-xsrf',
            writable: true,
        });
        pageState.props = defaultProps();
        pageState.url = '/admin/marketing/coupons';
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('renders the coupon table with type badges windows usage and status', () => {
        render(<AdminCouponsPage />);

        expect(screen.getByRole('heading', { level: 1 })).toBeVisible();
        expect(screen.getByText('WELCOME10')).toBeVisible();
        expect(screen.getByText('10%')).toBeVisible();
        expect(screen.getByText('Until 2026-12-31')).toBeVisible();
        expect(screen.getByText('FLAT15SAR')).toBeVisible();
        expect(screen.getByText('15 SAR')).toBeVisible();
        expect(screen.getByText('From 2026-08-01')).toBeVisible();
        expect(screen.getByText('4 / 50')).toBeVisible();
        expect(screen.getByText('Active')).toBeVisible();
        expect(screen.getByText('Inactive')).toBeVisible();
    });

    it('opens an empty create dialog and submits the payload after password confirmation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: { code: 'SAVE20', id: 'x', isActive: true },
                }),
                { status: 201 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminCouponsPage />);

        fireEvent.click(screen.getByRole('button', { name: 'Create coupon' }));

        const dialog = await screen.findByRole('dialog', {
            name: englishAdminUi.coupons.createTitle,
        });

        fireEvent.change(within(dialog).getByLabelText(/^Code$/), {
            target: { value: 'save20' },
        });
        fireEvent.change(within(dialog).getByLabelText(/^Value$/), {
            target: { value: '20' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));

        fireEvent.click(
            await screen.findByRole('button', {
                hidden: true,
                name: 'confirmed-password-overlay',
            }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/coupons',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        const [, init] = fetchMock.mock.calls[0];
        const body = JSON.parse(init.body);

        expect(body.code).toBe('SAVE20');
        expect(body.discount_type).toBe('percent');
        expect(body.value).toBe(20);
        expect(init.headers['X-XSRF-TOKEN']).toBe('test-xsrf');
        expect(inertia.reload).toHaveBeenCalledWith({
            only: ['coupons', 'pagination', 'counts'],
        });
    });

    it('prefills the edit dialog from the selected row and sends a PUT', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: { code: 'WELCOME10', id: 'x', isActive: true },
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminCouponsPage />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0]);

        const dialog = await screen.findByRole('dialog', {
            name: englishAdminUi.coupons.editTitle,
        });

        expect(within(dialog).getByLabelText(/^Code$/)).toHaveValue(
            'WELCOME10',
        );
        expect(within(dialog).getByLabelText(/^Value$/)).toHaveValue(10);
        expect(
            within(dialog).getByLabelText(
                englishAdminUi.coupons.maximumDiscountLabel,
            ),
        ).toHaveValue(10_000);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));

        fireEvent.click(
            await screen.findByRole('button', {
                hidden: true,
                name: 'confirmed-password-overlay',
            }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/coupons/01KCOUPON0000000000000001',
                expect.objectContaining({ method: 'PUT' }),
            );
        });
    });

    it('toggles a coupon to inactive through the confirmation and password flow', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: { code: 'WELCOME10', id: 'x', isActive: false },
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminCouponsPage />);

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Deactivate coupon' })[0],
        );

        const confirmDialog = await screen.findByRole('dialog', {
            name: englishAdminUi.coupons.deactivateTitle,
        });

        expect(confirmDialog.textContent).toContain('WELCOME10');

        fireEvent.click(
            within(confirmDialog).getByRole('button', { name: 'Confirm' }),
        );

        fireEvent.click(
            await screen.findByRole('button', {
                hidden: true,
                name: 'confirmed-password-overlay',
            }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/coupons/01KCOUPON0000000000000001/status',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        const [, init] = fetchMock.mock.calls[0];

        expect(JSON.parse(init.body)).toEqual({ is_active: false });
    });

    it('hides management controls for viewers without marketing.manage', () => {
        pageState.props.permissions = ['dashboard.view', 'marketing.view'];

        render(<AdminCouponsPage />);

        expect(
            screen.queryByRole('button', { name: 'Create coupon' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit' }),
        ).not.toBeInTheDocument();
    });
});
