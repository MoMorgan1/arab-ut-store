import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    englishAdminUi,
    sampleAdminAutomationProductDetail,
    sampleAdminProductDetail,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminProductDetailPage from '@/pages/admin/products/show';
import type { AdminProductDetailPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));

const mockHttp = vi.hoisted(() => {
    let data: any = {};

    const submit = vi.fn();
    const setData = vi.fn((next: any) => {
        if (typeof next === 'function') {
            data = next(data);
        } else {
            data = next;
        }
    });

    return {
        get data() {
            return data;
        },
        set data(val: any) {
            data = val;
        },
        processing: false,
        setData,
        submit,
    };
});

const pageState = vi.hoisted(() => ({
    component: 'admin/products/show',
    url: '/admin/products/01K5PROD00000000000000001',
    props: {} as AdminProductDetailPageProps,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    useHttp: () => mockHttp,
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

function defaultProps(): AdminProductDetailPageProps {
    return {
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/admin' },
            { key: 'orders', label: 'Orders', url: '/admin/orders' },
            { key: 'customers', label: 'Customers', url: '/admin/customers' },
            { key: 'products', label: 'Products', url: '/admin/products' },
            {
                key: 'settings',
                label: 'Settings',
                url: '/admin/settings',
            },
        ],
        adminUi: englishAdminUi,
        direction: 'ltr',
        locale: 'en',
        logoutUrl: '/logout',
        permissions: [
            'dashboard.view',
            'orders.view',
            'customers.view',
            'catalog.view',
            'catalog.manage',
        ],
        product: { ...sampleAdminProductDetail },
        updateUrl: '/admin/api/products/01K5PROD00000000000000001',
        visibilityUrl:
            '/admin/api/products/01K5PROD00000000000000001/visibility',
        variantPriceUrlTemplate: '/admin/api/variants/__ID__/price',
    };
}

describe('AdminProductDetailPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/products/01K5PROD00000000000000001';
        mockHttp.processing = false;
        mockHttp.submit.mockReset();
        mockHttp.setData.mockReset();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders manual product details with edit button and variants', () => {
        render(<AdminProductDetailPage />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'FC 26 Coins PS5' }),
        ).toBeVisible();
        expect(screen.getByText('Manual')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Edit product' }),
        ).toBeInTheDocument();

        expect(screen.getByText('COINS-PS5-100K')).toBeInTheDocument();
        expect(screen.getByText('COINS-PS5-500K')).toBeInTheDocument();
    });

    it('renders automation product as read-only with automation history panel', () => {
        pageState.props.product = { ...sampleAdminAutomationProductDetail };
        pageState.props.updateUrl =
            '/admin/api/products/01K5PROD00000000000000002';
        pageState.props.visibilityUrl =
            '/admin/api/products/01K5PROD00000000000000002/visibility';
        pageState.url = '/admin/products/01K5PROD00000000000000002';

        render(<AdminProductDetailPage />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'FC 26 SBC Service',
            }),
        ).toBeVisible();
        expect(screen.getAllByText('Automation').length).toBeGreaterThan(0);
        expect(screen.getByText('Read-only')).toBeInTheDocument();

        // No edit button for automation product
        expect(
            screen.queryByRole('button', { name: 'Edit product' }),
        ).not.toBeInTheDocument();

        // Automation snapshot panel
        expect(
            screen.getByText('Automation snapshot history'),
        ).toBeInTheDocument();
        expect(screen.getByText('RUN-2026-08-21-001')).toBeInTheDocument();
    });

    it('the hide control appears for both manual and automation products', () => {
        // 1. Manual product
        pageState.props.product = { ...sampleAdminProductDetail };
        const { unmount } = render(<AdminProductDetailPage />);

        expect(
            screen.getByRole('button', { name: 'Hide from store' }),
        ).toBeVisible();

        unmount();

        // 2. Automation product
        pageState.props.product = { ...sampleAdminAutomationProductDetail };
        pageState.props.visibilityUrl =
            '/admin/api/products/01K5PROD00000000000000002/visibility';
        render(<AdminProductDetailPage />);

        expect(
            screen.getByRole('button', { name: 'Hide from store' }),
        ).toBeVisible();
    });

    it('it confirms before calling the endpoint, and does not call it if cancelled', () => {
        render(<AdminProductDetailPage />);

        const hideButton = screen.getByRole('button', {
            name: 'Hide from store',
        });
        fireEvent.click(hideButton);

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByRole('heading', {
                level: 2,
                name: 'Hide product from store?',
            }),
        ).toBeVisible();

        // Cancel the dialog
        const cancelButton = within(dialog).getByRole('button', {
            name: 'Cancel',
        });
        fireEvent.click(cancelButton);

        expect(mockHttp.submit).not.toHaveBeenCalled();

        // Open again and confirm
        fireEvent.click(
            screen.getByRole('button', { name: 'Hide from store' }),
        );
        const confirmDialog = screen.getByRole('dialog');
        const confirmButton = within(confirmDialog).getByRole('button', {
            name: 'Hide from store',
        });
        fireEvent.click(confirmButton);

        expect(mockHttp.setData).toHaveBeenCalledWith({
            expected_hidden: false,
            hidden: true,
        });
        expect(mockHttp.submit).toHaveBeenCalledWith(
            'post',
            '/admin/api/products/01K5PROD00000000000000001/visibility',
            expect.any(Object),
        );
    });

    it('a hidden product shows the badge, and an automation hidden product shows both facts', () => {
        // 1. Manual hidden product
        pageState.props.product = {
            ...sampleAdminProductDetail,
            adminHidden: true,
        };
        const { unmount } = render(<AdminProductDetailPage />);

        expect(screen.getByText('Admin hidden')).toBeVisible();
        expect(screen.getByText('Hidden by admin')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Restore to store' }),
        ).toBeVisible();

        unmount();

        // 2. Automation hidden product with isVisible: true
        pageState.props.product = {
            ...sampleAdminAutomationProductDetail,
            adminHidden: true,
            isVisible: true,
        };
        render(<AdminProductDetailPage />);

        // Header shows both Admin hidden badge and Automation: Visible badge
        expect(screen.getByText('Admin hidden')).toBeVisible();
        expect(
            screen.getAllByText('Automation: Visible').length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('Hidden by admin')).toBeVisible();
    });

    it('a 409 shows the conflict message and does not leave the UI claiming success', () => {
        mockHttp.submit.mockImplementation(
            (_method: string, _url: string, options: any) => {
                options.onHttpException?.({
                    data: {
                        current: { adminHidden: true },
                        product: '01K5PROD00000000000000001',
                    },
                    status: 409,
                });
            },
        );

        render(<AdminProductDetailPage />);

        const hideButton = screen.getByRole('button', {
            name: 'Hide from store',
        });
        fireEvent.click(hideButton);

        const dialog = screen.getByRole('dialog');
        const confirmButton = within(dialog).getByRole('button', {
            name: 'Hide from store',
        });
        fireEvent.click(confirmButton);

        expect(
            screen.getByText(
                'The storefront visibility was modified by another operator. Current state has been refreshed.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByText('Product has been hidden from the storefront.'),
        ).not.toBeInTheDocument();
    });

    it('the override dialog pre-fills from the current tiers', () => {
        pageState.props.product = { ...sampleAdminAutomationProductDetail };
        render(<AdminProductDetailPage />);

        // Find the variant row for SBC-POTM-PREMIER
        const skuCell = screen.getByText('SBC-POTM-PREMIER');
        const row = skuCell.closest('tr');
        expect(row).not.toBeNull();

        const overrideBtn = within(row!).getByRole('button', {
            name: 'Override price',
        });
        fireEvent.click(overrideBtn);

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByRole('heading', {
                level: 2,
                name: 'Override variant price',
            }),
        ).toBeVisible();
        expect(
            within(dialog).getByText('SBC Completion Pricing Tiers'),
        ).toBeVisible();

        // Check tier inputs
        const tier1Input = within(dialog).getByLabelText(
            /Total \(SAR\) 5/i,
        ) as HTMLInputElement;
        const tier2Input = within(dialog).getByLabelText(
            /Total \(SAR\) 10/i,
        ) as HTMLInputElement;

        expect(tier1Input.value).toBe('50.00');
        expect(tier2Input.value).toBe('95.00');
    });

    it('saving posts integer halalah and the expected version', () => {
        pageState.props.product = { ...sampleAdminAutomationProductDetail };
        render(<AdminProductDetailPage />);

        const skuCell = screen.getByText('SBC-POTM-PREMIER');
        const row = skuCell.closest('tr');
        const overrideBtn = within(row!).getByRole('button', {
            name: 'Override price',
        });
        fireEvent.click(overrideBtn);

        const dialog = screen.getByRole('dialog');
        const tier1Input = within(dialog).getByLabelText(/Total \(SAR\) 5/i);
        const tier2Input = within(dialog).getByLabelText(/Total \(SAR\) 10/i);

        fireEvent.change(tier1Input, { target: { value: '60.00' } });
        fireEvent.change(tier2Input, { target: { value: '110.00' } });

        const saveButton = within(dialog).getByRole('button', {
            name: 'Save price override',
        });
        fireEvent.click(saveButton);

        expect(mockHttp.setData).toHaveBeenCalledWith({
            completion_pricing: {
                maximum: 10,
                repeatable: true,
                tiers: [
                    { completions: 5, multiplierBps: 10000, totalMinor: 6000 },
                    { completions: 10, multiplierBps: 9500, totalMinor: 11000 },
                ],
                version: 1,
            },
            expected_price_version: 1,
            price_halalah: 6000,
        });
        expect(mockHttp.submit).toHaveBeenCalledWith(
            'post',
            '/admin/api/variants/01K5VAR00000000000000003/price',
            expect.any(Object),
        );
    });

    it("'Revert to automation' posts price_halalah: null", () => {
        pageState.props.product = {
            ...sampleAdminAutomationProductDetail,
            variants: [
                {
                    ...sampleAdminAutomationProductDetail.variants[0],
                    adminPriceHalalah: 6000,
                    hasOverride: true,
                },
                sampleAdminAutomationProductDetail.variants[1],
            ],
        };

        render(<AdminProductDetailPage />);

        const skuCell = screen.getByText('SBC-POTM-PREMIER');
        const row = skuCell.closest('tr');
        expect(row).not.toBeNull();

        expect(within(row!).getByText('Override active')).toBeVisible();

        const revertBtn = within(row!).getByRole('button', {
            name: 'Revert to automation',
        });
        fireEvent.click(revertBtn);

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByRole('heading', {
                level: 2,
                name: 'Revert price to automation?',
            }),
        ).toBeVisible();

        const confirmRevertBtn = within(dialog).getByRole('button', {
            name: 'Revert to automation',
        });
        fireEvent.click(confirmRevertBtn);

        expect(mockHttp.setData).toHaveBeenCalledWith({
            completion_pricing: null,
            expected_price_version: 1,
            price_halalah: null,
        });
        expect(mockHttp.submit).toHaveBeenCalledWith(
            'post',
            '/admin/api/variants/01K5VAR00000000000000003/price',
            expect.any(Object),
        );
    });

    it('a 422 on the tier table renders inline', () => {
        mockHttp.submit.mockImplementation(
            (_method: string, _url: string, options: any) => {
                options.onHttpException?.({
                    data: {
                        errors: {
                            completion_pricing: [
                                'The first tier total must equal the variant price.',
                            ],
                        },
                    },
                    status: 422,
                });
            },
        );

        pageState.props.product = { ...sampleAdminAutomationProductDetail };
        render(<AdminProductDetailPage />);

        const skuCell = screen.getByText('SBC-POTM-PREMIER');
        const row = skuCell.closest('tr');
        const overrideBtn = within(row!).getByRole('button', {
            name: 'Override price',
        });
        fireEvent.click(overrideBtn);

        const dialog = screen.getByRole('dialog');
        const saveButton = within(dialog).getByRole('button', {
            name: 'Save price override',
        });
        fireEvent.click(saveButton);

        expect(
            within(dialog).getByText(
                'The first tier total must equal the variant price.',
            ),
        ).toBeVisible();
    });

    it('for a variant with no declared tiers, the override dialog collapses to a single price field', () => {
        pageState.props.product = { ...sampleAdminAutomationProductDetail };
        render(<AdminProductDetailPage />);

        const skuCell = screen.getByText('SBC-NO-TIERS');
        const row = skuCell.closest('tr');
        const overrideBtn = within(row!).getByRole('button', {
            name: 'Override price',
        });
        fireEvent.click(overrideBtn);

        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByLabelText(/Price \(SAR\)/i)).toBeVisible();
        expect(
            within(dialog).queryByText('SBC Completion Pricing Tiers'),
        ).not.toBeInTheDocument();
    });
});
