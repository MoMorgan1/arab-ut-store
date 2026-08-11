import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import StoreCategory from '@/pages/store/category';

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    submit: vi.fn(),
    visit: vi.fn(),
}));

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/sbc',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { get: mocks.get, visit: mocks.visit },
    usePage: () => page,
}));
vi.mock('@/lib/catalog-cart-api', () => ({ submitCatalogCart: mocks.submit }));

afterEach(cleanup);

beforeEach(() => {
    mocks.get.mockReset();
    mocks.submit.mockReset();
    mocks.visit.mockReset();
    mocks.submit.mockResolvedValue({
        cartCount: 1,
        cartItemId: '01K00000000000000000000001',
        cartUrl: '/en/cart',
    });
    page.props = categoryProps();
});

it('submits locale-preserving search filter and sort parameters', () => {
    render(<StoreCategory />);

    fireEvent.change(screen.getByRole('searchbox'), {
        target: { value: 'icon' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Icons' }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Sort' }), {
        target: { value: 'price_asc' },
    });

    expect(mocks.get).toHaveBeenLastCalledWith(
        '/en/sbc',
        { filter: 'icons', q: 'icon', sort: 'price_asc' },
        expect.objectContaining({ preserveScroll: true, replace: true }),
    );
});

it('renders the refined SBC hierarchy and trust strip', () => {
    render(<StoreCategory />);

    expect(screen.getByRole('search')).toHaveClass(
        'store-catalog-toolbar--compact',
    );
    expect(screen.getByRole('searchbox').closest('label')).toHaveClass(
        'store-catalog-toolbar__search',
    );
    expect(screen.getByRole('group', { name: 'Filter' })).toHaveClass(
        'store-catalog-toolbar__filters',
    );
    expect(
        screen.getByRole('combobox', { name: 'Sort' }).closest('label'),
    ).toHaveClass('store-catalog-toolbar__sort');
    expect(screen.getAllByRole('main')).toHaveLength(1);
    expect(
        screen.getByRole('heading', {
            name: 'Complete Squad Building Challenges SBC',
            level: 1,
        }),
    ).toBeVisible();
    expect(
        document.querySelector('.store-catalog-hero__shield img'),
    ).toHaveAttribute('src', '/images/store/navigation/logo-sbc-96.webp');
    expect(document.querySelector('.store-catalog-page')).toHaveClass(
        'store-catalog-page--sbc',
    );
    expect(
        screen.getByRole('button', { name: 'Add to cart' }).closest('li'),
    ).toHaveClass('store-catalog-card', 'store-catalog-card--sbc');
    expect(
        screen.getByRole('heading', { name: 'Browse by type', level: 2 }),
    ).toBeVisible();
    expect(
        screen.getByText('Coins funding and completion included'),
    ).toBeVisible();
    expect(
        within(
            screen.getByRole('list', { name: 'Platform prices' }),
        ).getAllByRole('listitem'),
    ).toHaveLength(2);
    expect(
        within(
            screen.getByRole('list', { name: 'Store assurances' }),
        ).getAllByRole('listitem'),
    ).toHaveLength(4);
});

it('adds the selected authoritative variant, updates the cart count, and stays on the listing', async () => {
    render(<StoreCategory />);

    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));

    await waitFor(() => expect(mocks.submit).toHaveBeenCalled());
    expect(mocks.submit.mock.calls[0][0]).toMatchObject({
        cartUrl: '/en/cart/items/catalog',
        variantId: '01K00000000000000000000002',
    });
    expect(mocks.visit).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Added to cart' })).toBeVisible();
    expect(
        within(screen.getByRole('link', { name: 'Cart' })).getByText('1'),
    ).toBeVisible();
    expect(
        screen.queryByRole('button', { name: /details|contact/i }),
    ).toBeNull();
});

function categoryProps() {
    return {
        catalog: {
            service: 'sbc',
            products: [
                {
                    id: '01K00000000000000000000000',
                    slug: 'icon-service',
                    url: '/en/sbc/icon-service',
                    name: 'Icon Service',
                    description: 'Complete the Icon challenge.',
                    image: null,
                    price: { amountMinor: 12500, currency: 'SAR' },
                    platforms: ['playstation'],
                    variants: [
                        {
                            id: '01K00000000000000000000002',
                            name: 'PlayStation',
                            platform: 'playstation',
                            price: { amountMinor: 12500, currency: 'SAR' },
                        },
                        {
                            id: '01K00000000000000000000003',
                            name: 'PC',
                            platform: 'pc',
                            price: { amountMinor: 9900, currency: 'SAR' },
                        },
                    ],
                },
            ],
            query: { filter: 'all', q: '', sort: 'recommended', page: 1 },
            pagination: { page: 1, perPage: 12, total: 1, lastPage: 1 },
        },
        catalogCartUrl: '/en/cart/items/catalog',
        catalogPageUrl: '/en/sbc',
        catalogPage: catalogTranslations(),
        servicePage: {
            eyebrow: 'FC 27 services',
            title: 'SBC Services',
            page_title: 'Complete Squad Building Challenges SBC',
            intro: 'Choose your service.',
            card_description: 'SBC service.',
        },
        ...shellProps(),
    };
}

function catalogTranslations() {
    return {
        search: 'Search services',
        filter: 'Filter',
        sort: 'Sort',
        all: 'All',
        players: 'Players',
        icons: 'Icons',
        upgrades: 'Upgrades',
        foundations: 'Foundations',
        recommended: 'Recommended',
        newest: 'Newest',
        price_asc: 'Price: low to high',
        price_desc: 'Price: high to low',
        from: 'From',
        unavailable_price: 'Unavailable',
        empty: 'No services',
        previous: 'Previous',
        next: 'Next',
        add_to_cart: 'Add to cart',
        added: 'Added to cart',
        adding: 'Adding…',
        add_error: 'Could not add this item.',
        platform: 'Platform',
        platform_prices: 'Platform prices',
        included: 'Coins funding and completion included',
        browse_by_type: 'Browse by type',
        assurances: 'Store assurances',
        assurance_no_players: 'No player withdrawal',
        assurance_fast: 'Fast delivery',
        assurance_support: '24/7 support',
        assurance_secure: 'Secure service',
    };
}

function shellProps() {
    return {
        cartCount: 0,
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR'],
        locale: 'en',
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
            simple_pages: {
                eyebrow: '',
                back_home: '',
                privacy: { title: '', body: '' },
                returns: { title: '', body: '' },
                warranty: { title: '', body: '' },
                ea_backup_codes: { title: '', body: '' },
                terms: { title: '', body: '' },
            },
        },
    };
}
