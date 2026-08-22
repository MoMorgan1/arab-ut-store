import {
    act,
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
    sampleAdminFilterOptions,
    sampleAdminOrderRows,
    sampleAdminPagination,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminOrdersPage from '@/pages/admin/orders/index';
import type { AdminOrdersPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    flushAll: vi.fn(),
    post: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/orders/index',
    url: '/admin/orders',
    props: {} as AdminOrdersPageProps,
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

function defaultProps(): AdminOrdersPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/admin' },
            { key: 'orders', label: 'Orders', url: '/admin/orders' },
            {
                key: 'security',
                label: 'MFA Security',
                url: '/admin/security/mfa',
            },
        ],
        permissions: ['dashboard.view', 'orders.view'],
        orders: sampleAdminOrderRows,
        pagination: sampleAdminPagination,
        filters: {
            search: null,
            status: null,
            service: null,
            platform: null,
            payment_status: null,
            date_from: null,
            date_to: null,
            sort: 'placed_at',
            direction: 'desc',
            per_page: 15,
            page: 1,
        },
        filterOptions: sampleAdminFilterOptions,
        logoutUrl: '/logout',
    };
}

describe('AdminOrdersPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/orders';
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the orders list with server-projected rows and no fake detail links', () => {
        render(<AdminOrdersPage />);

        const ordersTable = screen.getByRole('region', { name: 'Orders list' });

        expect(
            screen.getByRole('heading', { level: 1, name: 'Orders' }),
        ).toBeVisible();
        expect(within(ordersTable).getByText('AUT-1001')).toBeVisible();
        expect(within(ordersTable).getByText('Saud Al-Otaibi')).toBeVisible();
        expect(
            within(ordersTable).getByText('saud@example.test'),
        ).toBeVisible();
        expect(within(ordersTable).getByText('AUT-1002')).toBeVisible();
        expect(within(ordersTable).getByText('Fahad Al-Harbi')).toBeVisible();
        expect(within(ordersTable).getByText('AUT-1003')).toBeVisible();
        expect(within(ordersTable).getByText('Tariq Al-Ghamdi')).toBeVisible();

        // Verify detail link for order row exists and points to the detail route
        const detailLink = within(ordersTable).getByRole('link', {
            name: 'AUT-1001',
        });
        expect(detailLink).toHaveAttribute(
            'href',
            '/admin/orders/01K5ADM1N00000000000000001',
        );
    });

    it('submits search query and resets page to 1 via router.get', () => {
        pageState.props.filters = { ...pageState.props.filters, page: 3 };
        render(<AdminOrdersPage />);

        const searchInput = screen.getByRole('searchbox', {
            name: 'Search orders',
        });
        fireEvent.change(searchInput, { target: { value: 'AUT-1001' } });

        const searchButton = screen.getByRole('button', { name: 'Search' });
        fireEvent.click(searchButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.objectContaining({ search: 'AUT-1001' }),
            expect.objectContaining({
                preserveState: true,
                replace: true,
                preserveScroll: true,
            }),
        );
        expect(inertia.get.mock.calls[0][1]).not.toHaveProperty('page');
    });

    it('sorts by order number when clicking sort header', () => {
        render(<AdminOrdersPage />);

        expect(
            screen.getByRole('columnheader', { name: /Placed at/i }),
        ).toHaveAttribute('aria-sort', 'descending');

        const sortButton = screen.getByRole('button', { name: /Order/i });
        fireEvent.click(sortButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.objectContaining({
                sort: 'order_number',
                direction: 'asc',
            }),
            expect.objectContaining({
                preserveState: true,
                replace: true,
                preserveScroll: true,
            }),
        );
    });

    it('handles pagination navigation with router.get', () => {
        render(<AdminOrdersPage />);

        const nextButton = screen.getByRole('button', { name: 'Next' });
        fireEvent.click(nextButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.objectContaining({
                page: 2,
            }),
            expect.objectContaining({
                preserveState: true,
                replace: true,
                preserveScroll: true,
            }),
        );
    });

    it('preserves the localized Admin route alias during table navigation', () => {
        pageState.url = '/en/admin/orders?status=received';
        render(<AdminOrdersPage />);

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect(inertia.get).toHaveBeenCalledWith(
            '/en/admin/orders',
            expect.objectContaining({ page: 2 }),
            expect.any(Object),
        );
    });

    it('handles per-page changes and resets page to 1', () => {
        pageState.props.filters.page = 3;
        render(<AdminOrdersPage />);

        const perPageTrigger = screen.getByRole('combobox', {
            name: 'Per page',
        });
        fireEvent.click(perPageTrigger);
        fireEvent.click(screen.getByRole('option', { name: '25' }));

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.objectContaining({ per_page: 25 }),
            expect.any(Object),
        );
        expect(inertia.get.mock.calls[0][1]).not.toHaveProperty('page');
    });

    it.each([
        {
            field: 'Filter by status',
            option: 'Received',
            expected: { status: 'received' },
        },
        {
            field: 'Filter by service',
            option: 'Coins',
            expected: { service: 'coins' },
        },
        {
            field: 'Filter by platform',
            option: 'Xbox',
            expected: { platform: 'xbox' },
        },
        {
            field: 'Filter by payment status',
            option: 'Paid',
            expected: { payment_status: 'paid' },
        },
    ])(
        'submits $field through router.get with the selected value',
        ({ field, option, expected }) => {
            render(<AdminOrdersPage />);

            fireEvent.click(screen.getByRole('combobox', { name: field }));
            fireEvent.click(screen.getByRole('option', { name: option }));

            expect(inertia.get).toHaveBeenCalledWith(
                '/admin/orders',
                expect.objectContaining(expected),
                expect.any(Object),
            );
            expect(inertia.get.mock.calls[0][1]).not.toHaveProperty('page');
        },
    );

    it('submits date filters as inclusive UTC bounds', () => {
        render(<AdminOrdersPage />);

        fireEvent.change(screen.getByLabelText('Date from'), {
            target: { value: '2026-08-01' },
        });

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.objectContaining({ date_from: '2026-08-01' }),
            expect.any(Object),
        );
    });

    it('clears a conflicting date_to when date_from moves past it', () => {
        pageState.props.filters = {
            ...pageState.props.filters,
            date_from: '2026-08-01',
            date_to: '2026-08-10',
        };
        render(<AdminOrdersPage />);

        fireEvent.change(screen.getByLabelText('Date from'), {
            target: { value: '2026-08-20' },
        });

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.objectContaining({ date_from: '2026-08-20' }),
            expect.any(Object),
        );
        expect(inertia.get.mock.calls[0][1]).not.toHaveProperty('date_to');
    });

    it('keeps column visibility local to the table', () => {
        render(<AdminOrdersPage />);

        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'Toggle columns' }),
            { button: 0, ctrlKey: false },
        );
        fireEvent.click(
            screen.getByRole('menuitemcheckbox', { name: 'Customer' }),
        );

        const ordersTable = screen.getByRole('region', { name: 'Orders list' });
        expect(
            within(ordersTable).queryByRole('columnheader', {
                name: 'Customer',
            }),
        ).toBeNull();
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('renders empty state when no orders match filter criteria and offers reset', () => {
        pageState.props.orders = [];
        pageState.props.pagination = {
            currentPage: 1,
            lastPage: 1,
            perPage: 15,
            total: 0,
            from: null,
            to: null,
        };
        pageState.props.filters.status = 'completed';

        render(<AdminOrdersPage />);

        expect(
            screen.getAllByText('No orders match your filter criteria.').length,
        ).toBeGreaterThanOrEqual(1);

        const resetButtons = screen.getAllByRole('button', {
            name: 'Reset filters',
        });
        expect(resetButtons.length).toBeGreaterThanOrEqual(1);

        fireEvent.click(resetButtons[0]);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.not.objectContaining({ status: 'completed' }),
            expect.objectContaining({
                preserveState: true,
                replace: true,
                preserveScroll: true,
            }),
        );
    });

    it('renders base empty state when database has no orders', () => {
        pageState.props.orders = [];
        pageState.props.pagination = {
            currentPage: 1,
            lastPage: 1,
            perPage: 15,
            total: 0,
            from: null,
            to: null,
        };

        render(<AdminOrdersPage />);

        expect(
            screen.getAllByText('No orders found.').length,
        ).toBeGreaterThanOrEqual(1);
    });

    it('manages row selection on current page and resets when orders change', () => {
        const { rerender } = render(<AdminOrdersPage />);

        const selectRow = screen.getAllByRole('checkbox', {
            name: `Select row ${sampleAdminOrderRows[0].orderNumber}`,
        })[0];
        fireEvent.click(selectRow);
        expect(screen.getByText('1 of 3 row(s) selected')).toBeVisible();
        fireEvent.click(selectRow);

        const selectAll = screen.getByRole('checkbox', {
            name: 'Select all rows on this page',
        });
        fireEvent.click(selectAll);

        expect(screen.getByText('3 of 3 row(s) selected')).toBeVisible();

        pageState.props.filters.page = 2;
        pageState.props.orders = [sampleAdminOrderRows[0]];
        rerender(<AdminOrdersPage />);

        expect(screen.queryByText('3 of 3 row(s) selected')).toBeNull();
        expect(
            screen.getByRole('checkbox', {
                name: 'Select all rows on this page',
            }),
        ).not.toBeChecked();
    });

    it('shows loading feedback and recovers from a network failure', () => {
        render(<AdminOrdersPage />);

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        const options = inertia.get.mock.calls[0][2];

        act(() => options.onStart());
        expect(screen.getByText('Loading orders…')).toBeVisible();

        act(() => options.onNetworkError(new Error('offline')));
        expect(screen.getByText('Orders unavailable')).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(inertia.get).toHaveBeenCalledTimes(2);
        expect(inertia.get.mock.calls[1][1]).toEqual(
            inertia.get.mock.calls[0][1],
        );
    });

    it('renders active filter chips with individual remove buttons and a Clear all button', () => {
        pageState.props.filters = {
            ...pageState.props.filters,
            date_from: '2026-08-01',
            platform: 'playstation',
            search: 'AUT-1001',
            status: 'received',
        };

        render(<AdminOrdersPage />);

        expect(screen.getByText('Active filters:')).toBeVisible();
        expect(screen.getByText('Search: "AUT-1001"')).toBeVisible();
        expect(screen.getByText('Status: Received')).toBeVisible();
        expect(screen.getByText('Platform: PlayStation')).toBeVisible();
        expect(screen.getByText('From: 2026-08-01')).toBeVisible();

        // Clear status filter individually
        const clearStatusBtn = screen.getByRole('button', {
            name: 'Clear status filter',
        });
        fireEvent.click(clearStatusBtn);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.not.objectContaining({ status: 'received' }),
            expect.any(Object),
        );

        // Click Clear all
        const clearAllBtn = screen.getByRole('button', {
            name: 'Clear all',
        });
        fireEvent.click(clearAllBtn);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.not.objectContaining({
                date_from: '2026-08-01',
                platform: 'playstation',
                search: 'AUT-1001',
                status: 'received',
            }),
            expect.any(Object),
        );
    });

    it('does not render active filter chips when no filters are active', () => {
        render(<AdminOrdersPage />);

        expect(screen.queryByText('Active filters:')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Clear all' })).toBeNull();
    });

    it('opens mobile filter sheet, configures filters, and applies them', () => {
        render(<AdminOrdersPage />);

        // Mobile Filters button is visible
        const filtersBtn = screen.getByRole('button', { name: /Filters/i });
        expect(filtersBtn).toBeInTheDocument();

        fireEvent.click(filtersBtn);

        // Sheet opens
        expect(screen.getByRole('dialog')).toBeVisible();

        const sheet = screen.getByRole('dialog');
        const applyBtn = within(sheet).getByRole('button', { name: 'Apply' });
        expect(applyBtn).toBeInTheDocument();

        // Select a status inside the sheet
        const statusSelect = within(sheet).getByRole('combobox', {
            name: 'Filter by status',
        });
        fireEvent.click(statusSelect);
        fireEvent.click(screen.getByRole('option', { name: 'Received' }));

        fireEvent.click(applyBtn);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/orders',
            expect.objectContaining({ status: 'received' }),
            expect.any(Object),
        );
    });

    it('renders 3-line mobile cards with checkbox, details, service chips, and date', () => {
        render(<AdminOrdersPage />);

        // Find mobile cards container
        const mobileContainer = screen.getByLabelText('Orders list mobile');
        expect(mobileContainer).toBeInTheDocument();

        // Check first order row content
        const firstOrder = sampleAdminOrderRows[0];
        expect(
            within(mobileContainer).getByRole('link', {
                name: firstOrder.orderNumber,
            }),
        ).toHaveAttribute('href', `/admin/orders/${firstOrder.id}`);

        expect(
            within(mobileContainer).getByRole('checkbox', {
                name: `Select row ${firstOrder.orderNumber}`,
            }),
        ).toBeInTheDocument();

        expect(
            within(mobileContainer).getByText(firstOrder.customer.name),
        ).toBeVisible();
        expect(within(mobileContainer).getByText('Received')).toBeVisible();
    });

    it('renders mobile compact pagination count and hides first/last buttons', () => {
        render(<AdminOrdersPage />);

        expect(screen.getByText('1–15')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'First page' })).toHaveClass(
            'hidden md:inline-flex',
        );
        expect(screen.getByRole('button', { name: 'Last page' })).toHaveClass(
            'hidden md:inline-flex',
        );
    });
});
