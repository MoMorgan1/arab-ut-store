import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import StoreCategory from '@/pages/store/category';

const appCss = readFileSync(
    resolve(process.cwd(), 'resources/css/app.css'),
    'utf8',
);

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    submit: vi.fn(),
    visit: vi.fn(),
}));

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/objectives',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { get: mocks.get, visit: mocks.visit },
    usePage: () => page,
}));
vi.mock('@/lib/catalog-cart-api', () => ({ submitCatalogCart: mocks.submit }));

afterEach(() => {
    cleanup();
});

beforeEach(() => {
    mocks.get.mockReset();
    mocks.submit.mockReset();
    mocks.visit.mockReset();
    page.props = categoryProps();
});

it('shows the promotion badge struck-through base price and discounted price on catalog cards', () => {
    render(<StoreCategory />);

    const badge = screen.getByText('20% off');

    expect(badge).toBeVisible();
    expect(badge).toHaveClass('store-promo-badge');
    expect(screen.getByText('SAR 80.00')).toBeVisible();
    expect(screen.getByText(/100\.00/).closest('del')).toHaveClass(
        'store-price-compare',
    );
});

it('keeps the promoted styling hooks in the shared stylesheet', () => {
    expect(appCss).toContain('.store-promo-badge');
    expect(appCss).toContain('.store-price-compare');
});

it('renders without a badge or compare-at price when no promotion applies', () => {
    const props = categoryProps();

    page.props = {
        ...props,
        catalog: {
            ...props.catalog,
            products: [
                {
                    ...(props.catalog as CatalogShape).products[0],
                    compareAtPrice: null,
                    promotionBadge: null,
                    variants: (
                        props.catalog as CatalogShape
                    ).products[0].variants.map((variant) => ({
                        ...variant,
                        compareAtPrice: null,
                        promotionBadge: null,
                    })),
                },
            ],
        },
    };

    render(<StoreCategory />);

    expect(screen.queryByText('20% off')).not.toBeInTheDocument();
    expect(screen.queryByText('SAR 100.00')).not.toBeInTheDocument();
    expect(screen.getByText('SAR 80.00')).toBeVisible();
});

type CatalogVariantShape = {
    id: string;
    name: string;
    platform: string;
    price: { amountMinor: number; currency: string };
    compareAtPrice: null | { amountMinor: number; currency: string };
    promotionBadge: null | string;
};

type CatalogProductShape = {
    id: string;
    slug: string;
    url: string;
    name: string;
    description: string;
    image: null;
    price: { amountMinor: number; currency: string };
    compareAtPrice: null | { amountMinor: number; currency: string };
    promotionBadge: null | string;
    platforms: string[];
    variants: CatalogVariantShape[];
};

type CatalogShape = {
    service: string;
    products: CatalogProductShape[];
};

function catalogProduct(): CatalogProductShape {
    return {
        id: '01K00000000000000000000000',
        slug: 'promoted-service',
        url: '/en/objectives/promoted-service',
        name: 'Promoted Service',
        description: 'A discounted service.',
        image: null,
        price: { amountMinor: 8_000, currency: 'SAR' },
        compareAtPrice: { amountMinor: 10_000, currency: 'SAR' },
        promotionBadge: '20% off',
        platforms: ['playstation'],
        variants: [
            {
                id: '01K00000000000000000000002',
                name: 'PlayStation',
                platform: 'playstation',
                price: { amountMinor: 8_000, currency: 'SAR' },
                compareAtPrice: { amountMinor: 10_000, currency: 'SAR' },
                promotionBadge: '20% off',
            },
        ],
    };
}

function categoryProps() {
    return {
        catalog: {
            service: 'objectives',
            products: [catalogProduct()],
            query: { filter: 'all', q: '', sort: 'recommended', page: 1 },
            pagination: { page: 1, perPage: 12, total: 1, lastPage: 1 },
            filterCounts: {
                all: 1,
                players: 0,
                icons: 0,
                upgrades: 0,
                foundations: 0,
            },
        },
        catalogCartUrl: '/en/cart/items/catalog',
        catalogPageUrl: '/en/objectives',
        catalogPage: {
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
            unavailable_price: 'Price temporarily unavailable',
            empty: 'No services',
            previous: 'Previous',
            next: 'Next',
            pagination: 'Catalog pages',
            page_status: 'Page :current of :total',
            add_to_cart: 'Add to cart',
            added: 'Added to cart',
            adding: 'Adding…',
            add_error: 'Could not add this item.',
            platform: 'Platform',
            platform_prices: 'Platform prices',
            included: 'Coins + completion',
            browse_by_type: 'Browse by type',
            assurances: 'Store assurances',
            assurance_no_players: 'your club is safe',
            assurance_no_players_detail: 'detail',
            assurance_fast: 'Fast delivery',
            assurance_fast_detail: 'detail',
            assurance_support: '24/7 support',
            assurance_support_detail: 'detail',
            assurance_secure: 'Secure',
            assurance_secure_detail: 'detail',
        },
        servicePage: {
            eyebrow: 'FC 27 services',
            title: 'Objectives Services',
            intro: 'Choose your service.',
            card_description: 'Objectives service.',
        },
        cartCount: 0,
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR'],
        locale: 'en' as const,
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
