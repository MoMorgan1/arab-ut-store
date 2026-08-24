import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminMorePage from '@/pages/admin/more';
import type { AdminMoreGroup, AdminMorePageProps } from '@/types/admin';

const pageState = vi.hoisted(() => ({
    component: 'admin/more',
    url: '/en/admin/more',
    props: {} as AdminMorePageProps,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

const sampleGroups: AdminMoreGroup[] = [
    {
        key: 'catalog',
        label: 'Catalog',
        tiles: [
            {
                key: 'categories',
                label: 'Categories',
                description: 'Organize catalog categories and taxonomies.',
                url: '/en/admin/categories',
            },
        ],
    },
    {
        key: 'marketing',
        label: 'Marketing',
        tiles: [
            {
                key: 'coupons',
                label: 'Coupons',
                description: 'Create and configure discount codes.',
                url: '/en/admin/marketing/coupons',
            },
            {
                key: 'promotions',
                label: 'Promotions',
                description: 'Manage automated price promotions.',
                url: '/en/admin/marketing/promotions',
            },
            {
                key: 'loyalty',
                label: 'Loyalty',
                description: 'Tune loyalty tiers and multipliers.',
                url: '/en/admin/marketing/loyalty',
            },
        ],
    },
    {
        key: 'system',
        label: 'Support & System',
        tiles: [
            {
                key: 'conversations',
                label: 'Conversations',
                description: 'Review customer support conversations.',
                url: '/en/admin/conversations',
            },
            {
                key: 'settings',
                label: 'Settings',
                description: 'Manage team access and security.',
                url: '/en/admin/settings',
            },
        ],
    },
];

describe('AdminMorePage', () => {
    beforeEach(() => {
        pageState.props = {
            locale: 'en',
            direction: 'ltr',
            adminUi: englishAdminUi,
            adminIdentity: { name: 'Operations Owner', role: 'admin' },
            adminNavigation: [
                { key: 'overview', label: 'Overview', url: '/en/admin' },
                { key: 'more', label: 'More', url: '/en/admin/more' },
            ],
            permissions: [
                'dashboard.view',
                'catalog.view',
                'marketing.view',
                'loyalty.view',
                'chat.view',
                'settings.view',
            ],
            groups: sampleGroups,
            logoutUrl: '/logout',
        };
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders all section headings and all six navigation tiles with descriptions', () => {
        render(<AdminMorePage />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'More' }),
        ).toBeVisible();

        // Section headings
        expect(
            screen.getByRole('heading', { level: 2, name: 'Catalog' }),
        ).toBeVisible();
        expect(
            screen.getByRole('heading', { level: 2, name: 'Marketing' }),
        ).toBeVisible();
        expect(
            screen.getByRole('heading', { level: 2, name: 'Support & System' }),
        ).toBeVisible();

        // 6 tiles as links
        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(6);

        expect(
            screen.getByRole('link', { name: /Categories/i }),
        ).toHaveAttribute('href', '/en/admin/categories');
        expect(screen.getByRole('link', { name: /Coupons/i })).toHaveAttribute(
            'href',
            '/en/admin/marketing/coupons',
        );
        expect(
            screen.getByRole('link', { name: /Promotions/i }),
        ).toHaveAttribute('href', '/en/admin/marketing/promotions');
        expect(screen.getByRole('link', { name: /Loyalty/i })).toHaveAttribute(
            'href',
            '/en/admin/marketing/loyalty',
        );
        expect(
            screen.getByRole('link', { name: /Conversations/i }),
        ).toHaveAttribute('href', '/en/admin/conversations');
        expect(screen.getByRole('link', { name: /Settings/i })).toHaveAttribute(
            'href',
            '/en/admin/settings',
        );

        // Descriptions are present
        expect(
            screen.getByText('Organize catalog categories and taxonomies.'),
        ).toBeVisible();
        expect(
            screen.getByText('Create and configure discount codes.'),
        ).toBeVisible();
    });

    it('renders empty state when actor has no accessible tiles', () => {
        pageState.props = {
            ...pageState.props,
            groups: [],
        };

        render(<AdminMorePage />);

        expect(
            screen.getByText(
                'No additional sections are available for your current permissions.',
            ),
        ).toBeVisible();
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('applies RTL layout direction correctly', () => {
        pageState.props = {
            ...pageState.props,
            direction: 'rtl',
            locale: 'ar',
        };

        render(<AdminMorePage />);

        const article = screen.getByRole('article');
        expect(article).toHaveAttribute('dir', 'rtl');
    });
});
