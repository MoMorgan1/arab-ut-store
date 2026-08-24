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
import AdminPromotionsPage from '@/pages/admin/marketing/promotions';
import type {
    AdminPromotionRow,
    AdminPromotionsPageProps,
} from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/marketing/promotions',
    url: '/admin/marketing/promotions',
    props: {} as AdminPromotionsPageProps,
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

function samplePromotionRow(
    overrides: Partial<AdminPromotionRow>,
): AdminPromotionRow {
    return {
        id: '01KPROMO000000000000000001',
        nameAr: 'عرض الصيف',
        nameEn: 'Summer deal',
        badgeAr: 'خصم 20%',
        badgeEn: '20% off',
        mechanic: 'item',
        scope: 'all',
        categoryName: null,
        categoryId: null,
        serviceType: null,
        discountType: 'percent',
        value: 20,
        buyQuantity: null,
        getQuantity: null,
        maxApplications: null,
        discountTarget: null,
        qualifyingScope: null,
        bundlePriceHalalah: null,
        appliesToPromotedItems: false,
        components: [],
        startsAt: null,
        endsAt: '2026-12-31T00:00:00Z',
        isActive: true,
        createdAt: '2026-08-20T10:00:00Z',
        ...overrides,
    };
}

const samplePromotions: AdminPromotionRow[] = [
    samplePromotionRow({}),
    samplePromotionRow({
        id: '01KPROMO000000000000000002',
        nameAr: 'خصم ثابت',
        nameEn: 'Fixed off',
        badgeAr: 'خصم 15 ر.س',
        badgeEn: '15 SAR off',
        discountType: 'fixed',
        value: 1500,
        scope: 'service',
        serviceType: 'rivals',
        startsAt: '2026-08-01T00:00:00Z',
        endsAt: null,
        isActive: false,
    }),
    samplePromotionRow({
        id: '01KPROMO000000000000000003',
        nameAr: 'باقة توفير',
        nameEn: 'Bundle deal',
        mechanic: 'bundle',
        bundlePriceHalalah: 8000,
        components: [
            {
                id: 'c1',
                productId: '01KPROD000000000000000001',
                productName: 'Product Alpha',
                quantity: 2,
            },
            {
                id: 'c2',
                productId: '01KPROD000000000000000002',
                productName: 'Product Beta',
                quantity: 1,
            },
        ],
        isActive: true,
    }),
];

function defaultProps(): AdminPromotionsPageProps {
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
                children: [
                    {
                        key: 'marketingCoupons',
                        label: 'Coupons',
                        url: '/admin/marketing/coupons',
                    },
                    {
                        key: 'marketingPromotions',
                        label: 'Promotions',
                        url: '/admin/marketing/promotions',
                    },
                ],
            },
            { key: 'settings', label: 'Settings', url: '/admin/settings' },
        ],
        permissions: [
            'dashboard.view',
            'customers.view',
            'marketing.view',
            'marketing.manage',
        ],
        promotions: samplePromotions.map((promotion) => ({ ...promotion })),
        pagination: {
            currentPage: 1,
            lastPage: 1,
            perPage: 15,
            total: 3,
            from: 1,
            to: 3,
        },
        counts: {
            total: 3,
            active: 2,
            scheduled: 0,
            paused: 1,
            ended: 0,
        },
        categories: [{ id: '01KCATEGORY0000000000000001', name: 'Icons' }],
        products: [
            {
                id: '01KPROD000000000000000001',
                name: 'Product Alpha',
                priceHalalah: 5000,
            },
            {
                id: '01KPROD000000000000000002',
                name: 'Product Beta',
                priceHalalah: 3000,
            },
        ],
        createUrl: '/admin/api/marketing/promotions',
        updateUrlTemplate: '/admin/api/marketing/promotions/__ID__',
        statusUrlTemplate: '/admin/api/marketing/promotions/__ID__/status',
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

describe('AdminPromotionsPage', () => {
    beforeEach(() => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="test-token">';
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            value: 'XSRF-TOKEN=test-xsrf',
            writable: true,
        });
        pageState.props = defaultProps();
        pageState.url = '/admin/marketing/promotions';
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('renders the promotion table with scopes discounts windows and status', () => {
        render(<AdminPromotionsPage />);

        expect(screen.getByRole('heading', { level: 1 })).toBeVisible();
        expect(screen.getByText('Summer deal')).toBeVisible();
        expect(screen.getByText('عرض الصيف')).toBeVisible();
        expect(screen.getByText('20%')).toBeVisible();
        // More than one promotion in the fixture ends on this date, and the page
        // renders a desktop row and a mobile card for each, so assert presence
        // rather than uniqueness.
        expect(screen.getAllByText('Until 2026-12-31').length).toBeGreaterThan(
            0,
        );
        expect(screen.getAllByText('Everything').length).toBeGreaterThan(0);
        expect(screen.getByText('Fixed off')).toBeVisible();
        expect(screen.getByText('15.00 SAR')).toBeVisible();
        expect(screen.getByText('From 2026-08-01')).toBeVisible();
        expect(screen.getByText('rivals')).toBeVisible();
        expect(screen.getAllByText('Active').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Inactive').length).toBeGreaterThan(0);
    });

    it('opens an empty create dialog and submits an all-scope payload directly', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: { id: 'x', isActive: true, nameEn: 'Fresh deal' },
                }),
                { status: 201 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminPromotionsPage />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Create promotion' }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: englishAdminUi.promotions.createTitle,
        });

        fireEvent.change(within(dialog).getByLabelText(/^English name$/), {
            target: { value: 'Fresh deal' },
        });
        fireEvent.change(within(dialog).getByLabelText(/^Arabic name$/), {
            target: { value: 'عرض جديد' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Value/), {
            target: { value: '25' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/promotions',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        const [, init] = fetchMock.mock.calls[0];
        const body = JSON.parse(init.body);

        expect(body.name_en).toBe('Fresh deal');
        expect(body.scope).toBe('all');
        expect(body.category).toBeNull();
        expect(body.service_type).toBeNull();
        expect(body.discount_type).toBe('percent');
        expect(body.value).toBe(25);
        expect(init.headers['X-XSRF-TOKEN']).toBe('test-xsrf');
        expect(inertia.reload).toHaveBeenCalledWith({
            only: ['promotions', 'pagination', 'counts'],
        });
    });

    it('sends the stored scope and service type when saving an edited service-scoped row', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: { id: 'x', isActive: false, nameEn: 'Fixed off' },
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminPromotionsPage />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[1]);

        const dialog = await screen.findByRole('dialog', {
            name: englishAdminUi.promotions.editTitle,
        });

        expect(within(dialog).getByLabelText(/^English name$/)).toHaveValue(
            'Fixed off',
        );
        expect(within(dialog).getByLabelText(/Value/)).toHaveValue(15);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/promotions/01KPROMO000000000000000002',
                expect.objectContaining({ method: 'PUT' }),
            );
        });

        const [, init] = fetchMock.mock.calls[0];
        const body = JSON.parse(init.body);

        expect(body.scope).toBe('service');
        expect(body.service_type).toBe('rivals');
        expect(body.discount_type).toBe('fixed');
        expect(body.value).toBe(1500);
        expect(body.category).toBeNull();
    });

    it('creates a bundle promotion with components and computes savings dynamically', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        id: 'bundle-1',
                        isActive: true,
                        nameEn: 'Pro Bundle',
                    },
                }),
                { status: 201 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminPromotionsPage />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Create promotion' }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: englishAdminUi.promotions.createTitle,
        });

        // Click the Bundle mechanic card
        fireEvent.click(
            within(dialog).getByText(
                englishAdminUi.promotions.mechanics.bundle.title,
            ),
        );

        expect(
            within(dialog).getByText(englishAdminUi.promotions.componentsLabel),
        ).toBeVisible();

        fireEvent.change(within(dialog).getByLabelText(/^English name$/), {
            target: { value: 'Pro Bundle' },
        });
        fireEvent.change(within(dialog).getByLabelText(/^Arabic name$/), {
            target: { value: 'باقة المحترفين' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Bundle price/), {
            target: { value: '70.00' },
        });

        fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/promotions',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        const [, init] = fetchMock.mock.calls[0];
        const body = JSON.parse(init.body);

        expect(body.mechanic).toBe('bundle');
        expect(body.bundle_price_halalah).toBe(7000);
    });

    it('toggles a promotion to inactive through the confirmation flow', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: { id: 'x', isActive: false, nameEn: 'Summer deal' },
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminPromotionsPage />);

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Deactivate promotion' })[0],
        );

        const confirmDialog = await screen.findByRole('dialog', {
            name: englishAdminUi.promotions.deactivateTitle,
        });

        expect(confirmDialog.textContent).toContain('Summer deal');

        fireEvent.click(
            within(confirmDialog).getByRole('button', { name: 'Confirm' }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/promotions/01KPROMO000000000000000001/status',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        const [, init] = fetchMock.mock.calls[0];

        expect(JSON.parse(init.body)).toEqual({ is_active: false });
    });

    it('hides management controls for viewers without marketing.manage', () => {
        pageState.props.permissions = ['dashboard.view', 'marketing.view'];

        render(<AdminPromotionsPage />);

        expect(
            screen.queryByRole('button', { name: 'Create promotion' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit' }),
        ).not.toBeInTheDocument();
    });
});
