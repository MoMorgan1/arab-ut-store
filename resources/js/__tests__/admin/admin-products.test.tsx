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
    sampleAdminPagination,
    sampleAdminProductFilterOptions,
    sampleAdminProductRows,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminProductsPage from '@/pages/admin/products/index';
import type { AdminProductsPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    flushAll: vi.fn(),
    post: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/products/index',
    url: '/admin/products',
    props: {} as AdminProductsPageProps,
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

function defaultProps(): AdminProductsPageProps {
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
        products: sampleAdminProductRows,
        pagination: sampleAdminPagination,
        filters: {
            search: null,
            service_type: null,
            authority: null,
            source: null,
            visibility: null,
            archived: null,
            sort: 'created_at',
            direction: 'desc',
            per_page: 15,
            page: 1,
        },
        filterOptions: sampleAdminProductFilterOptions,
        logoutUrl: '/logout',
    };
}

describe('AdminProductsPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/products';
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the page title and product table rows', () => {
        render(<AdminProductsPage />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Products' }),
        ).toBeInTheDocument();
        // The list renders a desktop table and a mobile card stack, so each row
        // name appears twice. Scope to the table the way the customers and
        // orders list tests do.
        const productsTable = screen.getByRole('region', {
            name: 'Products list',
        });

        expect(
            within(productsTable).getByText('FC 26 Coins PS5'),
        ).toBeVisible();
        expect(
            within(productsTable).getByText('FC 26 SBC Service'),
        ).toBeVisible();
    });

    it('renders authority and visibility badges correctly', () => {
        render(<AdminProductsPage />);

        // Manual and Automation badges
        expect(screen.getAllByText('Manual').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Automation').length).toBeGreaterThan(0);

        // Visible and Hidden badges
        expect(screen.getAllByText('Visible').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Hidden').length).toBeGreaterThan(0);
    });

    it('submits search filter when form is submitted', () => {
        render(<AdminProductsPage />);

        const searchInput = screen.getByLabelText('Search products');
        fireEvent.change(searchInput, { target: { value: 'COINS-PS5' } });

        const searchButton = screen.getByRole('button', { name: 'Search' });
        fireEvent.click(searchButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.objectContaining({ search: 'COINS-PS5' }),
            expect.any(Object),
        );
    });

    it('clears search when clear button is clicked', () => {
        pageState.props.filters.search = 'existing';
        render(<AdminProductsPage />);

        const clearButton = screen.getByRole('button', {
            name: 'Clear search',
        });
        fireEvent.click(clearButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.not.objectContaining({ search: 'existing' }),
            expect.any(Object),
        );
    });

    it('triggers sort change when clicking sortable header', () => {
        render(<AdminProductsPage />);

        const sortButton = screen.getByRole('button', {
            name: /Sort by Product/i,
        });
        fireEvent.click(sortButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.objectContaining({ sort: 'name', direction: 'asc' }),
            expect.any(Object),
        );
    });

    it('renders empty state when no products match filters', () => {
        pageState.props.products = [];
        pageState.props.filters.search = 'nonexistent';
        pageState.props.pagination.total = 0;

        render(<AdminProductsPage />);

        expect(
            screen.getAllByText('No products match your filter criteria.')
                .length,
        ).toBeGreaterThan(0);
    });
});
