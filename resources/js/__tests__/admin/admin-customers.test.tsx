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
    sampleAdminCustomerFilterOptions,
    sampleAdminCustomerRows,
    sampleAdminPagination,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminCustomersPage from '@/pages/admin/customers/index';
import type { AdminCustomersPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    flushAll: vi.fn(),
    post: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/customers/index',
    url: '/admin/customers',
    props: {} as AdminCustomersPageProps,
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

function defaultProps(): AdminCustomersPageProps {
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
                key: 'settings',
                label: 'Settings',
                url: '/admin/settings',
            },
        ],
        permissions: [
            'dashboard.view',
            'orders.view',
            'customers.view',
            'customers.update_status',
        ],
        customers: sampleAdminCustomerRows,
        pagination: sampleAdminPagination,
        filters: {
            search: null,
            status: null,
            date_from: null,
            date_to: null,
            sort: 'created_at',
            direction: 'desc',
            per_page: 15,
            page: 1,
        },
        filterOptions: sampleAdminCustomerFilterOptions,
        logoutUrl: '/logout',
    };
}

describe('AdminCustomersPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/customers';
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the customers list with server-projected rows and detail links', () => {
        render(<AdminCustomersPage />);

        const customersTable = screen.getByRole('region', {
            name: 'Customers list',
        });

        expect(
            screen.getByRole('heading', { level: 1, name: 'Customers' }),
        ).toBeVisible();
        expect(
            within(customersTable).getByText('Saud Al-Otaibi'),
        ).toBeVisible();
        expect(
            within(customersTable).getByText('saud@example.test'),
        ).toBeVisible();
        expect(
            within(customersTable).getByText('Fahad Al-Harbi'),
        ).toBeVisible();
        expect(
            within(customersTable).getByText('fahad@example.test'),
        ).toBeVisible();

        // Verify detail link for customer row
        const detailLink = within(customersTable).getByRole('link', {
            name: 'Saud Al-Otaibi',
        });
        expect(detailLink).toHaveAttribute(
            'href',
            '/admin/customers/01K5CUST00000000000000001',
        );
    });

    it('submits search query and resets page to 1 via router.get', () => {
        pageState.props.filters = { ...pageState.props.filters, page: 3 };
        render(<AdminCustomersPage />);

        const searchInput = screen.getByRole('searchbox', {
            name: 'Search customers',
        });
        fireEvent.change(searchInput, { target: { value: 'Saud' } });

        const searchButton = screen.getByRole('button', { name: 'Search' });
        fireEvent.click(searchButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/customers',
            expect.objectContaining({ search: 'Saud' }),
            expect.objectContaining({
                preserveState: true,
                replace: true,
                preserveScroll: true,
            }),
        );
        expect(inertia.get.mock.calls[0][1]).not.toHaveProperty('page');
    });

    it('sorts by name when clicking Customer sort header', () => {
        render(<AdminCustomersPage />);

        const sortButton = screen.getByRole('button', { name: /Customer/i });
        fireEvent.click(sortButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/customers',
            expect.objectContaining({
                sort: 'name',
                direction: 'asc',
            }),
            expect.objectContaining({
                preserveState: true,
                replace: true,
                preserveScroll: true,
            }),
        );
    });

    it('sorts by total_spent when clicking Total spent sort header', () => {
        render(<AdminCustomersPage />);

        const sortButton = screen.getByRole('button', { name: /Total spent/i });
        fireEvent.click(sortButton);

        expect(inertia.get).toHaveBeenCalled();
        expect(inertia.get.mock.calls[0][0]).toBe('/admin/customers');
        expect(inertia.get.mock.calls[0][1]).toEqual(
            expect.objectContaining({ sort: 'total_spent' }),
        );
    });

    it('handles pagination navigation with router.get', () => {
        render(<AdminCustomersPage />);

        const nextButton = screen.getByRole('button', { name: 'Next' });
        fireEvent.click(nextButton);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/customers',
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
        pageState.url = '/en/admin/customers?status=active';
        render(<AdminCustomersPage />);

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect(inertia.get).toHaveBeenCalledWith(
            '/en/admin/customers',
            expect.objectContaining({ page: 2 }),
            expect.any(Object),
        );
    });

    it('handles per-page changes and resets page to 1', () => {
        pageState.props.filters.page = 3;
        render(<AdminCustomersPage />);

        const perPageTrigger = screen.getByRole('combobox', {
            name: 'Per page',
        });
        fireEvent.click(perPageTrigger);
        fireEvent.click(screen.getByRole('option', { name: '25' }));

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/customers',
            expect.objectContaining({ per_page: 25 }),
            expect.any(Object),
        );
        expect(inertia.get.mock.calls[0][1]).not.toHaveProperty('page');
    });

    it('submits status filter through router.get with the selected value', () => {
        render(<AdminCustomersPage />);

        fireEvent.click(
            screen.getByRole('combobox', { name: 'Filter by status' }),
        );
        fireEvent.click(screen.getByRole('option', { name: 'Active' }));

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/customers',
            expect.objectContaining({ status: 'active' }),
            expect.any(Object),
        );
    });

    it('resets all filters when reset filters button is clicked', () => {
        pageState.props.filters = {
            ...pageState.props.filters,
            search: 'Saud',
            status: 'active',
            date_from: '2026-08-01',
        };
        render(<AdminCustomersPage />);

        const resetBtn = screen.getByRole('button', { name: 'Reset filters' });
        fireEvent.click(resetBtn);

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/customers',
            expect.not.objectContaining({
                search: expect.anything(),
                status: expect.anything(),
                date_from: expect.anything(),
            }),
            expect.any(Object),
        );
    });

    it('allows toggling column visibility in the table', () => {
        render(<AdminCustomersPage />);

        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'Toggle columns' }),
            { button: 0, ctrlKey: false },
        );

        const emailCheckbox = screen.getByRole('menuitemcheckbox', {
            name: 'Email',
        });
        expect(emailCheckbox).toHaveAttribute('aria-checked', 'true');
        fireEvent.click(emailCheckbox);

        // Header should now be hidden
        const customersTable = screen.getByRole('region', {
            name: 'Customers list',
        });
        expect(
            within(customersTable).queryByRole('columnheader', {
                name: 'Email',
            }),
        ).toBeNull();
    });

    it('renders mobile cards on smaller viewports with touch targets', () => {
        render(<AdminCustomersPage />);

        const mobileList = screen.getByRole('list', { name: 'Customers list' });
        expect(within(mobileList).getByText('Saud Al-Otaibi')).toBeVisible();
        expect(within(mobileList).getByText('Fahad Al-Harbi')).toBeVisible();
    });
});
