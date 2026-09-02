import { cleanup, render, screen, within } from '@testing-library/react';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    englishAdminUi,
    sampleAdminCategoriesPageProps,
    sampleAdminPagination,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminReviewsPage from '@/pages/admin/reviews/index';
import type { AdminReviewRow, AdminReviewsPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/reviews/index',
    url: '/admin/reviews',
    props: {} as AdminReviewsPageProps,
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

const rows: AdminReviewRow[] = [
    {
        id: 'rev-visible',
        reviewerName: 'Mohamed',
        reviewerLocation: 'Riyadh',
        rating: 5,
        excerpt: 'Fast and safe delivery.',
        bodyLocale: 'en',
        order: { number: 'UT-00000101', publicId: 'order-101' },
        source: 'customer',
        isVisible: true,
        publishedAt: '2026-09-02T10:00:00Z',
        createdAt: '2026-09-02T10:00:00Z',
    },
    {
        id: 'rev-low',
        reviewerName: 'Fahad',
        reviewerLocation: null,
        rating: 2,
        excerpt: 'Took longer than promised.',
        bodyLocale: 'en',
        order: { number: 'UT-00000102', publicId: 'order-102' },
        source: 'customer',
        isVisible: false,
        publishedAt: null,
        createdAt: '2026-09-01T10:00:00Z',
    },
    {
        id: 'rev-archive-hidden',
        reviewerName: 'Sara',
        reviewerLocation: 'Jeddah',
        rating: 4,
        excerpt: 'Good experience.',
        bodyLocale: 'en',
        order: null,
        source: 'archive',
        isVisible: false,
        publishedAt: '2026-06-03T10:00:00Z',
        createdAt: '2026-06-03T10:00:00Z',
    },
];

function defaultProps(): AdminReviewsPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: sampleAdminCategoriesPageProps.adminIdentity,
        adminNavigation: sampleAdminCategoriesPageProps.adminNavigation,
        permissions: ['marketing.view', 'marketing.manage'],
        reviews: rows,
        pagination: { ...sampleAdminPagination, total: rows.length },
        filters: { status: 'all', rating: 'all', source: 'all' },
        filterOptions: {
            statuses: [
                { value: 'all', label: 'All statuses' },
                { value: 'visible', label: 'Visible' },
                { value: 'hidden', label: 'Hidden' },
            ],
            ratings: [
                { value: 'all', label: 'All ratings' },
                { value: '5', label: '5 stars' },
            ],
            sources: [
                { value: 'all', label: 'All sources' },
                { value: 'customer', label: 'Customer' },
                { value: 'archive', label: 'Salla archive' },
            ],
            perPageOptions: [15, 25, 50, 100],
        },
        orderUrlTemplate: '/admin/orders/__ID__',
        visibilityUrlTemplate: '/admin/api/reviews/__ID__/visibility',
        logoutUrl: '/logout',
    };
}

describe('AdminReviewsPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the title and one row per review with its order link', () => {
        render(<AdminReviewsPage {...pageState.props} />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: englishAdminUi.reviews.title,
            }),
        ).toBeInTheDocument();

        const table = screen.getAllByRole('region', {
            name: englishAdminUi.reviews.tableLabel,
        })[0];

        expect(within(table).getByText('Mohamed')).toBeVisible();
        expect(within(table).getByText('Fahad')).toBeVisible();
        expect(
            within(table).getByRole('link', { name: 'UT-00000101' }),
        ).toHaveAttribute('href', '/admin/orders/order-101');
    });

    it('labels the storefront state and source of each row', () => {
        render(<AdminReviewsPage {...pageState.props} />);

        const table = screen.getAllByRole('region', {
            name: englishAdminUi.reviews.tableLabel,
        })[0];
        const copy = englishAdminUi.reviews;
        const rowFor = (name: string) =>
            within(table).getByRole('row', { name: new RegExp(name) });

        expect(
            within(rowFor('Mohamed')).getByText(copy.stateVisible),
        ).toBeVisible();
        expect(
            within(rowFor('Fahad')).getByText(copy.stateBelowThreshold),
        ).toBeVisible();
        expect(
            within(rowFor('Sara')).getByText(copy.stateHidden),
        ).toBeVisible();
        expect(
            within(rowFor('Sara')).getByText(copy.sourceArchive),
        ).toBeVisible();
    });

    it('offers hide for visible rows, show for hidden four-plus rows, and nothing below four', () => {
        render(<AdminReviewsPage {...pageState.props} />);

        const table = screen.getAllByRole('region', {
            name: englishAdminUi.reviews.tableLabel,
        })[0];
        const copy = englishAdminUi.reviews;
        const rowFor = (name: string) =>
            within(table).getByRole('row', { name: new RegExp(name) });

        expect(
            within(rowFor('Mohamed')).getByRole('button', {
                name: new RegExp(copy.hideFromStore),
            }),
        ).toBeEnabled();
        expect(
            within(rowFor('Sara')).getByRole('button', {
                name: new RegExp(copy.showInStore),
            }),
        ).toBeEnabled();
        expect(
            within(rowFor('Fahad')).queryByRole('button', {
                name: new RegExp(`${copy.hideFromStore}|${copy.showInStore}`),
            }),
        ).toBeNull();
    });

    it('hides the actions from a viewer without marketing.manage', () => {
        pageState.props = {
            ...defaultProps(),
            permissions: ['marketing.view'],
        };

        render(<AdminReviewsPage {...pageState.props} />);

        const copy = englishAdminUi.reviews;

        expect(
            screen.queryByRole('button', {
                name: new RegExp(`${copy.hideFromStore}|${copy.showInStore}`),
            }),
        ).toBeNull();
    });
});
