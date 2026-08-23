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

    it('renders inline controls (search, filters, columns) on mobile while select dropdowns are desktop only', () => {
        render(<AdminProductsPage />);

        expect(
            screen.getByRole('searchbox', { name: 'Search products' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Search' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Filters/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Toggle columns' }),
        ).toBeInTheDocument();
    });

    it('shows the applied-filter count badge on the Filters button', () => {
        pageState.props.filters = {
            ...pageState.props.filters,
            authority: 'manual',
            service_type: 'coins',
            visibility: 'visible',
        };

        render(<AdminProductsPage />);

        const filtersBtn = screen.getByRole('button', { name: /Filters/i });
        expect(within(filtersBtn).getByText('3')).toBeVisible();
    });

    it('opens the sheet, changes a filter, and pressing Apply issues exactly one visit with expected query', () => {
        render(<AdminProductsPage />);

        const filtersBtn = screen.getByRole('button', { name: /Filters/i });
        fireEvent.click(filtersBtn);

        const sheet = screen.getByRole('dialog', { name: 'Filters' });
        expect(sheet).toBeVisible();

        const authoritySelect = within(sheet).getByRole('combobox', {
            name: 'Filter by authority',
        });
        fireEvent.click(authoritySelect);
        fireEvent.click(screen.getByRole('option', { name: 'Automation' }));

        const applyBtn = within(sheet).getByRole('button', { name: 'Apply' });
        fireEvent.click(applyBtn);

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.objectContaining({ authority: 'automation' }),
            expect.any(Object),
        );
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('pressing Apply in the sheet with no changes does not issue an Inertia visit', () => {
        render(<AdminProductsPage />);

        const filtersBtn = screen.getByRole('button', { name: /Filters/i });
        fireEvent.click(filtersBtn);

        const sheet = screen.getByRole('dialog', { name: 'Filters' });
        expect(sheet).toBeVisible();

        const applyBtn = within(sheet).getByRole('button', { name: 'Apply' });
        fireEvent.click(applyBtn);

        expect(inertia.get).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('clicking Clear all in the sheet resets to the unfiltered query', () => {
        pageState.props.filters = {
            ...pageState.props.filters,
            authority: 'manual',
            service_type: 'coins',
            visibility: 'visible',
        };

        render(<AdminProductsPage />);

        const filtersBtn = screen.getByRole('button', { name: /Filters/i });
        fireEvent.click(filtersBtn);

        const sheet = screen.getByRole('dialog', { name: 'Filters' });
        const clearAllBtn = within(sheet).getByRole('button', {
            name: 'Clear all',
        });
        fireEvent.click(clearAllBtn);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.not.objectContaining({
                authority: 'manual',
                service_type: 'coins',
                visibility: 'visible',
            }),
            expect.any(Object),
        );
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('renders active filter chips and dismissing a chip removes exactly that one filter and keeps the others', () => {
        pageState.props.filters = {
            ...pageState.props.filters,
            authority: 'manual',
            search: 'COINS-PS5',
            service_type: 'coins',
            visibility: 'visible',
        };

        render(<AdminProductsPage />);

        expect(screen.getByText('Active filters:')).toBeVisible();
        expect(screen.getByText('Search: "COINS-PS5"')).toBeVisible();
        expect(screen.getByText('Service: Coins')).toBeVisible();
        expect(screen.getByText('Authority: Manual')).toBeVisible();
        expect(screen.getByText('Visibility: Visible')).toBeVisible();

        const clearServiceBtn = screen.getByRole('button', {
            name: 'Clear service filter',
        });
        fireEvent.click(clearServiceBtn);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.not.objectContaining({ service_type: 'coins' }),
            expect.any(Object),
        );
        expect(inertia.get.mock.calls[0][1]).toEqual(
            expect.objectContaining({
                authority: 'manual',
                search: 'COINS-PS5',
                visibility: 'visible',
            }),
        );
    });

    it('clicking Clear all from the active chips row resets all filters', () => {
        pageState.props.filters = {
            ...pageState.props.filters,
            search: 'COINS-PS5',
            service_type: 'coins',
        };

        render(<AdminProductsPage />);

        const clearAllBtn = screen.getByRole('button', { name: 'Clear all' });
        fireEvent.click(clearAllBtn);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.not.objectContaining({
                search: 'COINS-PS5',
                service_type: 'coins',
            }),
            expect.any(Object),
        );
    });

    it('submits desktop inline filter selects immediately', () => {
        render(<AdminProductsPage />);

        const serviceSelect = screen.getByRole('combobox', {
            name: 'Filter by service',
        });
        fireEvent.click(serviceSelect);
        fireEvent.click(screen.getByRole('option', { name: 'Coins' }));

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/products',
            expect.objectContaining({ service_type: 'coins' }),
            expect.any(Object),
        );
    });
});
