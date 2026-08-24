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
    sampleAdminCategoriesPageProps,
    sampleAdminCategoryFilterOptions,
    sampleAdminCategoryRows,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminCategoriesPage from '@/pages/admin/categories/index';
import type { AdminCategoriesPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/categories/index',
    url: '/admin/categories',
    props: {} as AdminCategoriesPageProps,
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
    useHttp: () => ({
        data: {},
        setData: vi.fn(),
        submit: vi.fn(),
        processing: false,
        errors: {},
    }),
}));

function defaultProps(): AdminCategoriesPageProps {
    return {
        ...sampleAdminCategoriesPageProps,
        categories: sampleAdminCategoryRows,
        filterOptions: sampleAdminCategoryFilterOptions,
    };
}

describe('AdminCategoriesPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/categories';
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the page title, back link, and category rows in the table', () => {
        render(<AdminCategoriesPage {...pageState.props} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Categories' }),
        ).toBeInTheDocument();

        const tableRegion = screen.getByRole('region', {
            name: 'Categories list',
        });

        expect(within(tableRegion).getByText('FC Coins')).toBeVisible();
        expect(within(tableRegion).getByText('SBC Solutions')).toBeVisible();
        expect(within(tableRegion).getByText('Draft Boost')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Products' })).toHaveAttribute(
            'href',
            '/admin/products',
        );
    });

    it('renders distinguishable storefront status badges and product counts', () => {
        render(<AdminCategoriesPage {...pageState.props} />);

        const tableRegion = screen.getByRole('region', {
            name: 'Categories list',
        });

        const copy = englishAdminUi.categories;
        const rowFor = (name: string) =>
            within(tableRegion).getByRole('row', { name: new RegExp(name) });

        // Each fixture row is in a different state, and the three states must
        // stay distinguishable — an admin who cannot tell "hidden by me" from
        // "hidden by the sync" will unhide in the admin and wonder why the
        // category is still gone from the store.
        expect(
            within(rowFor('FC Coins')).getByText(copy.stateVisible),
        ).toBeVisible();
        expect(
            within(rowFor('SBC Solutions')).getByText(copy.stateAdminHidden),
        ).toBeVisible();
        expect(
            within(rowFor('Draft Boost')).getByText(copy.stateAutomationHidden),
        ).toBeVisible();

        // Counts are asserted inside their own row; the same number appears in
        // several rows and in the mobile card list.
        // Both counts matter: total products, and how many of them are actually
        // on the storefront. The row for the automation-hidden category must
        // still report its products.
        const coins = sampleAdminCategoryRows[0];
        expect(
            within(rowFor('FC Coins')).getAllByText(String(coins.productsCount))
                .length,
        ).toBeGreaterThan(0);
        expect(
            within(rowFor('FC Coins')).getAllByText(
                String(coins.visibleProductsCount),
            ).length,
        ).toBeGreaterThan(0);
    });

    it('submits search filter when form is submitted', () => {
        render(<AdminCategoriesPage {...pageState.props} />);

        const searchInput = screen.getByLabelText('Search categories');
        fireEvent.change(searchInput, { target: { value: 'Coins' } });

        const searchButton = screen.getByRole('button', { name: 'Search' });
        fireEvent.click(searchButton);

        expect(inertia.get).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ search: 'Coins' }),
            expect.any(Object),
        );
    });

    it('clears search when clear button is clicked', () => {
        pageState.props.filters.search = 'Coins';
        render(<AdminCategoriesPage {...pageState.props} />);

        const clearButton = screen.getByRole('button', {
            name: 'Clear search',
        });
        fireEvent.click(clearButton);

        expect(inertia.get).toHaveBeenCalledWith(
            expect.any(String),
            expect.not.objectContaining({ search: 'Coins' }),
            expect.any(Object),
        );
    });

    it('triggers sort change when clicking sortable header', () => {
        render(<AdminCategoriesPage {...pageState.props} />);

        const sortButton = screen.getByRole('button', {
            name: /Sort by Name/i,
        });

        // First click sorts ascending. 'asc' is the default for this list, and
        // the query builder omits a filter that equals its default to keep the
        // URL clean — so the absence of `direction` here is the contract.
        fireEvent.click(sortButton);
        expect(inertia.get).toHaveBeenLastCalledWith(
            expect.any(String),
            expect.not.objectContaining({ direction: expect.anything() }),
            expect.any(Object),
        );
        expect(inertia.get).toHaveBeenLastCalledWith(
            expect.any(String),
            expect.objectContaining({ sort: 'name' }),
            expect.any(Object),
        );
    });

    it('renders empty state when no categories match filters', () => {
        pageState.props.categories = [];
        pageState.props.filters.search = 'nonexistent';
        pageState.props.pagination.total = 0;

        render(<AdminCategoriesPage {...pageState.props} />);

        expect(
            screen.getAllByText('No categories match your filter criteria.')
                .length,
        ).toBeGreaterThan(0);
    });

    it('opens the visibility dialog when clicking Hide from store button', () => {
        render(<AdminCategoriesPage {...pageState.props} />);

        const hideButtons = screen.getAllByRole('button', {
            name: 'Hide from store',
        });
        expect(hideButtons.length).toBeGreaterThan(0);

        fireEvent.click(hideButtons[0]);

        expect(
            screen.getByRole('heading', {
                name: 'Hide category from storefront?',
            }),
        ).toBeInTheDocument();
    });

    it('opens the visibility dialog when clicking Restore to store button', () => {
        render(<AdminCategoriesPage {...pageState.props} />);

        const restoreButtons = screen.getAllByRole('button', {
            name: 'Restore to store',
        });
        expect(restoreButtons.length).toBeGreaterThan(0);

        fireEvent.click(restoreButtons[0]);

        expect(
            screen.getByRole('heading', {
                name: 'Restore category to storefront?',
            }),
        ).toBeInTheDocument();
    });

    it('hides action buttons when user lacks catalog.manage permission', () => {
        pageState.props.permissions = ['catalog.view'];
        render(<AdminCategoriesPage {...pageState.props} />);

        expect(
            screen.queryByRole('button', { name: 'Hide from store' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Restore to store' }),
        ).toBeNull();
    });
});
