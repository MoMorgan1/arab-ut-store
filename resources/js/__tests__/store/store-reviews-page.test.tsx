import { cleanup, render, screen } from '@testing-library/react';
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
    expect(screen.getByText('A real two-star review')).toBeInTheDocument();
    expect(
        screen.getByRole('navigation', { name: 'Reviews pages' }),
    ).toBeInTheDocument();
    expect(screen.queryByText(/email|phone/i)).toBeNull();
});

function props() {
    return {
        cartCount: 0,
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR'],
        locale: 'en',
        reviews: {
            average: 2,
            count: 1,
            items: [
                {
                    id: 'one',
                    reviewerName: 'Customer',
                    rating: 2,
                    body: 'A real two-star review',
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
        },
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
