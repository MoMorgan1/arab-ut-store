import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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

    return {
        data,
        processing: false,
        setData: vi.fn((next: any) => {
            data = next;
        }),
        submit: vi.fn(),
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
                key: 'settings',
                label: 'Settings',
                url: '/admin/settings',
            },
        ],
        permissions: [
            'dashboard.view',
            'orders.view',
            'customers.view',
            'catalog.view',
            'catalog.manage',
        ],
        product: sampleAdminProductDetail,
        updateUrl: '/admin/api/products/01K5PROD00000000000000001',
        confirmPasswordUrl: '/admin/confirm-password',
        logoutUrl: '/logout',
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

        // The name also reaches the document title, so assert the heading.
        expect(
            screen.getByRole('heading', { level: 1, name: 'FC 26 Coins PS5' }),
        ).toBeVisible();
        expect(screen.getByText('Manual')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Edit product' }),
        ).toBeInTheDocument();

        // Variants
        expect(screen.getByText('COINS-PS5-100K')).toBeInTheDocument();
        expect(screen.getByText('COINS-PS5-500K')).toBeInTheDocument();
    });

    it('renders automation product as read-only with automation history panel', () => {
        pageState.props.product = sampleAdminAutomationProductDetail;
        pageState.props.updateUrl =
            '/admin/api/products/01K5PROD00000000000000002';
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

        // No edit button
        expect(
            screen.queryByRole('button', { name: 'Edit product' }),
        ).not.toBeInTheDocument();

        // Automation snapshot panel
        expect(
            screen.getByText('Automation snapshot history'),
        ).toBeInTheDocument();
        expect(screen.getByText('RUN-2026-08-21-001')).toBeInTheDocument();
    });

    it('opens edit dialog when clicking edit button and submits form', async () => {
        render(<AdminProductDetailPage />);

        const editButton = screen.getByRole('button', { name: 'Edit product' });
        fireEvent.click(editButton);

        expect(screen.getByText('Edit manual product')).toBeInTheDocument();

        const nameEnInput = screen.getByLabelText(/English name/i);
        fireEvent.change(nameEnInput, {
            target: { value: 'FC 26 Coins PS5 (Updated)' },
        });

        const saveButton = screen.getByRole('button', { name: 'Save changes' });
        expect(saveButton).not.toBeDisabled();

        fireEvent.click(saveButton);

        expect(mockHttp.setData).toHaveBeenCalledWith(
            expect.objectContaining({
                name_en: 'FC 26 Coins PS5 (Updated)',
                expected: expect.objectContaining({
                    name_en: 'FC 26 Coins PS5',
                }),
            }),
        );
        expect(mockHttp.submit).toHaveBeenCalledWith(
            'post',
            '/admin/api/products/01K5PROD00000000000000001',
            expect.any(Object),
        );
    });
});
