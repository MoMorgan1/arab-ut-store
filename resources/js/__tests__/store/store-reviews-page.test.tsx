import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import StoreReviews from '@/pages/store/reviews';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/reviews',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => page,
}));

afterEach(cleanup);

it('renders the complete paginated safe review list', () => {
    page.props = props();
    render(<StoreReviews />);

    expect(
        screen.getByRole('heading', { name: 'All customer reviews' }),
    ).toHaveClass('store-reviews-page__title');
    expect(screen.getByText(/A real four-star review/)).toBeInTheDocument();
    expect(screen.getByText('Cairo')).toBeInTheDocument();
    expect(
        screen.getByRole('navigation', { name: 'Reviews pages' }),
    ).toHaveTextContent('Page 1 of 2');
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '?page=2',
    );
    expect(screen.queryByText(/email|phone/i)).toBeNull();
});

it('renders the filter chips as links that toggle the query string', () => {
    page.props = {
        ...props(),
        filters: {
            rating: '5',
            sort: 'highest',
            verified: true,
            withComment: false,
        },
    };
    render(<StoreReviews />);

    const filters = screen.getByRole('navigation', { name: 'Filter reviews' });

    expect(
        within(filters).getByRole('link', { name: '5 stars' }),
    ).toHaveAttribute('aria-current', 'true');
    // Clicking the active rating chip clears it and keeps the rest.
    expect(
        within(filters).getByRole('link', { name: '5 stars' }),
    ).toHaveAttribute('href', '?verified=1&sort=highest');
    expect(
        within(filters).getByRole('link', { name: '4 stars' }),
    ).toHaveAttribute('href', '?rating=4&verified=1&sort=highest');
    expect(within(filters).getByRole('link', { name: 'All' })).toHaveAttribute(
        'href',
        '?sort=highest',
    );
    expect(
        within(filters).getByRole('link', { name: 'Newest first' }),
    ).toHaveAttribute('href', '?rating=5&verified=1');
    expect(
        screen.getByRole('link', { name: 'Rate your order' }),
    ).toHaveAttribute('href', '/en/my-account/orders');
});

function props() {
    return {
        cartCount: 0,
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR'],
        locale: 'en',
        reviews: {
            average: 4,
            count: 1,
            items: [
                {
                    id: 'one',
                    reviewerName: 'Customer',
                    reviewerLocation: 'Cairo',
                    rating: 4,
                    body: 'A real four-star review',
                    verified: false,
                    publishedAt: '2026-08-10T12:00:00+00:00',
                },
            ],
            pagination: { page: 1, lastPage: 2, perPage: 12, total: 13 },
        },
        reviewsPage: {
            eyebrow: 'Reviews',
            title: 'All customer reviews',
            empty: 'No reviews',
            view_all: 'View all',
            verified: 'Verified order',
            rating_label: ':rating out of 5',
            summary: ':average from :count reviews',
            anonymous_customer: 'Customer',
            pages: 'Reviews pages',
            previous: 'Previous',
            next: 'Next',
            intro: 'Verified reviews from real orders.',
            of_count: 'from :count reviews',
            verified_count: ':count verified orders',
            distribution_label: 'Star distribution',
            filters_label: 'Filter reviews',
            filter_all: 'All',
            filter_five: '5 stars',
            filter_four: '4 stars',
            filter_verified: 'Verified orders',
            filter_with_comment: 'With a comment',
            sort_label: 'Sort',
            sort_newest: 'Newest first',
            sort_highest: 'Highest rated',
            page_of: 'Page :page of :last',
            rate_your_order: 'Rate your order',
        },
        filters: {
            rating: null,
            sort: 'newest',
            verified: false,
            withComment: false,
        },
        rateUrl: '/en/my-account/orders',
        storeShell: {
            homeUrl: '/en',
            coinsUrl: '/en#coins',
            cartUrl: '/en/cart',
            sbcUrl: '/en/sbc',
            futChampionsUrl: '/en/fut-champions',
            accountUrl: '/en/login',
            privacyUrl: '/en/privacy',
            returnsUrl: '/en/returns',
            warrantyUrl: '/en/warranty',
            eaBackupCodesUrl: '/en/ea-backup-codes',
            termsUrl: '/en/terms',
            whatsappUrl: '#',
            email: '',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'Arab UT',
            language: 'Arabic',
            currency_selector: 'Currency',
            home_title: 'Home',
            skip_to_content: 'Skip',
            store_tools: 'Tools',
            header: {
                primary_navigation: 'Primary',
                preferences: 'Preferences',
                home: 'Home',
                coins: 'Coins',
                sbc: 'SBC',
                fut_champions: 'FUT',
                most_requested: 'Most',
                whatsapp: 'WhatsApp',
                cart: 'Cart',
                account: 'Account',
            },
            preferences: { exchange_rate_attribution: 'Rates' },
            footer: {
                description: '',
                important_links: '',
                privacy: '',
                returns: '',
                warranty: '',
                ea_backup_codes: '',
                terms: '',
                customer_service: '',
                whatsapp: '',
                payment_methods: '',
                copyright: '',
                ea_disclaimer: '',
            },
        },
    };
}
