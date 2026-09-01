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
import AdminCouponDetailPage from '@/pages/admin/marketing/coupons/show';
import type { AdminCouponDetailPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/marketing/coupons/show',
    url: '/admin/marketing/coupons/01KCOUPON0000000000000001',
    props: {} as AdminCouponDetailPageProps,
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

function defaultDetailProps(): AdminCouponDetailPageProps {
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
        coupon: {
            id: '01KCOUPON0000000000000001',
            code: 'SUMMER20',
            createdAt: '2026-08-20T10:00:00Z',
            descriptionAr: 'خصم الصيف',
            descriptionEn: 'Summer 20% discount',
            discountType: 'percent',
            isActive: true,
            status: 'active',
            maximumDiscountHalalah: 10_000,
            minimumOrderHalalah: 5_000,
            perUserLimit: 2,
            startsAt: '2026-08-01T00:00:00Z',
            endsAt: '2026-08-31T00:00:00Z',
            usageLimit: 100,
            usedCount: 25,
            value: 20,
            scope: 'order',
            serviceType: null,
            firstOrderOnly: true,
            excludesPromotedItems: true,
            targets: [],
            categoryIds: [],
            productIds: [],
        },
        kpis: {
            usedCount: 25,
            usageLimit: 100,
            uniqueCustomers: 20,
            revenueAttributed: { amountMinor: '500000', currency: 'SAR' },
            totalDiscountGiven: { amountMinor: '100000', currency: 'SAR' },
            totalRedemptions: 27,
            releasedRedemptionsCount: 2,
        },
        rules: [
            {
                key: 'discount',
                label: 'Discount',
                value: '20% (Cap: 100.00 SAR)',
            },
            {
                key: 'minimum_order',
                label: 'Minimum order',
                value: '50.00 SAR',
                description: 'Checked against eligible items only.',
            },
            {
                key: 'eligibility',
                label: 'Eligibility',
                value: 'First order only • Excludes promoted items',
            },
            {
                key: 'usage_limit',
                label: 'Usage limit',
                value: '25 / 100 (2 released by cancellation)',
            },
        ],
        chart: [
            {
                date: '2026-08-21',
                redemptions: 10,
                revenueHalalah: 200000,
                discountHalalah: 40000,
            },
            {
                date: '2026-08-22',
                redemptions: 15,
                revenueHalalah: 300000,
                discountHalalah: 60000,
            },
        ],
        recentRedemptions: [
            {
                id: '1',
                orderId: '01KORDER0000000000000001',
                orderNumber: 'ORD-1001',
                orderStatus: 'completed',
                isPaid: true,
                paidAt: '2026-08-22T12:00:00Z',
                orderTotal: { amountMinor: '20000', currency: 'SAR' },
                discount: { amountMinor: '4000', currency: 'SAR' },
                customer: {
                    id: '01KUSER0000000000000001',
                    name: 'Ahmed Al-Harbi',
                    email: 'ahmed@example.com',
                },
                redeemedAt: '2026-08-22T12:00:00Z',
            },
        ],
        categories: [{ id: 1, publicId: '01CAT1', name: 'FC Coins' }],
        products: [{ id: 1, publicId: '01PROD1', name: '100K Coins' }],
        serviceTypes: [{ value: 'coins', label: 'Coins Delivery' }],
        updateUrl: '/admin/api/marketing/coupons/01KCOUPON0000000000000001',
        statusUrl:
            '/admin/api/marketing/coupons/01KCOUPON0000000000000001/status',
        duplicateUrl:
            '/admin/api/marketing/coupons/01KCOUPON0000000000000001/duplicate',
        listUrl: '/admin/marketing/coupons',
        logoutUrl: '/logout',
    };
}

describe('AdminCouponDetailPage', () => {
    beforeEach(() => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="test-token">';
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            value: 'XSRF-TOKEN=test-xsrf',
            writable: true,
        });
        pageState.props = defaultDetailProps();
        pageState.url = '/admin/marketing/coupons/01KCOUPON0000000000000001';
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('leaves the sidebar, main landmark and tab bar to the shared admin shell', () => {
        const { container } = render(<AdminCouponDetailPage />);

        // AdminLayout already renders <aside>, <main> and the mobile tab bar
        // around every admin/* page. Rendering them again here stacked a
        // second sidebar inside the content column on desktop.
        expect(container.querySelector('aside')).toBeNull();
        expect(container.querySelector('main')).toBeNull();
        expect(container.querySelector('.admin-document-layout')).toBeNull();
    });

    it('renders the coupon detail with KPIs, released notice, rules, and redemptions', () => {
        render(<AdminCouponDetailPage />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'SUMMER20' }),
        ).toBeVisible();
        expect(screen.getByText('Summer 20% discount')).toBeVisible();
        expect(screen.getByText('25 / 100')).toBeVisible();
        expect(screen.getByText('20')).toBeVisible(); // unique customers
        // Intl renders SAR before the amount and separates them with a
        // non-breaking space, so match on the digits and tolerate the spacing
        // rather than hard-coding it - JS \\s already matches U+00A0.
        expect(
            screen.getByText((text) => /SAR\s*5,000\.00/.test(text)),
        ).toBeVisible(); // revenue attributed
        expect(
            screen.getByText((text) => /SAR\s*1,000\.00/.test(text)),
        ).toBeVisible(); // total discount given
        expect(
            screen.getByText(/released by order cancellation/i),
        ).toBeVisible();

        // Rules
        expect(screen.getByText('20% (Cap: 100.00 SAR)')).toBeVisible();
        expect(
            screen.getByText('First order only • Excludes promoted items'),
        ).toBeVisible();

        // Recent Redemptions Table
        expect(screen.getByText('ORD-1001')).toBeVisible();
        expect(screen.getByText('Ahmed Al-Harbi')).toBeVisible();
        expect(
            screen.getByText((text) => /SAR\s*200\.00/.test(text)),
        ).toBeVisible();
    });

    it('handles duplicate action from header with custom code', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        code: 'SUMMER20-SPECIAL',
                        id: '01KCOUPONNEW',
                        isActive: false,
                    },
                }),
                { status: 201 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminCouponDetailPage />);

        fireEvent.click(screen.getByRole('button', { name: 'Duplicate' }));

        const dialog = await screen.findByRole('dialog', {
            name: englishAdminUi.coupons.duplicateTitle,
        });

        fireEvent.change(
            within(dialog).getByLabelText(
                englishAdminUi.coupons.duplicateCodeLabel,
            ),
            {
                target: { value: 'summer20-special' },
            },
        );

        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Duplicate coupon' }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/coupons/01KCOUPON0000000000000001/duplicate',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({ code: 'SUMMER20-SPECIAL' }),
                }),
            );
        });

        expect(inertia.visit).toHaveBeenCalledWith(
            '/admin/marketing/coupons/01KCOUPONNEW',
        );
    });

    it('handles pause/resume toggle from header', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        code: 'SUMMER20',
                        id: '01KCOUPON0000000000000001',
                        isActive: false,
                    },
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminCouponDetailPage />);

        fireEvent.click(screen.getByRole('button', { name: 'Pause' }));

        const dialog = await screen.findByRole('dialog', {
            name: englishAdminUi.coupons.deactivateTitle,
        });

        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Confirm' }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/coupons/01KCOUPON0000000000000001/status',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({ is_active: false }),
                }),
            );
        });
    });
});
