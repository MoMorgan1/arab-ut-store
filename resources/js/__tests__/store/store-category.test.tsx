import {
    cleanup,
    fireEvent,
    render,
    screen,
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
    fireEvent.click(screen.getByRole('button', { name: /^Icons/ }));
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
            name: 'Complete Squad Building Challenges',
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
        screen.getByRole('list', { name: 'Platform prices' }).closest('li'),
    ).toHaveClass('store-catalog-card', 'store-catalog-card--sbc');
    expect(
        document.querySelector(
            '.store-catalog-card--sbc .store-catalog-card__image img',
        ),
    ).toHaveAttribute('height', '288');
    expect(
        screen.getByRole('heading', { name: 'Browse by type', level: 2 }),
    ).toBeVisible();
    expect(screen.getByText('Includes coins and Submitting')).toBeVisible();
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
    expect(
        screen.getByText(
            'We fund and complete the SBC without taking players.',
        ),
    ).toBeVisible();
});

it('keeps the SBC inclusion ribbon in a reserved row above the artwork', () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc');
    const media = card?.querySelector('.store-catalog-card__media');
    const ribbon = screen.getByText('Includes coins and Submitting');
    const artwork = card?.querySelector('.store-catalog-card__image');

    expect(media).not.toBeNull();
    expect(media?.children[0]).toBe(ribbon);
    expect(media?.lastElementChild).toBe(artwork);
});

it('applies a visible lift while an SBC card is held on touch', () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc');

    expect(card).not.toHaveStyle({
        transform: 'translateY(-0.35rem) scale(0.99)',
    });

    fireEvent.pointerDown(card as Element, { pointerType: 'touch' });
    expect(card).toHaveClass('is-pressed');
    expect(card).toHaveStyle({
        transform: 'translateY(-0.35rem) scale(0.99)',
    });
    expect(card?.querySelector('.store-catalog-card__image img')).toHaveStyle({
        transform: 'translateY(-0.7rem) scale(1.095) rotate(0.8deg)',
    });

    fireEvent.pointerCancel(card as Element, { pointerType: 'touch' });
    expect(card).not.toHaveClass('is-pressed');
    expect(card).not.toHaveStyle({
        transform: 'translateY(-0.35rem) scale(0.99)',
    });
    expect(
        card?.querySelector('.store-catalog-card__image img'),
    ).not.toHaveStyle({
        transform: 'translateY(-0.7rem) scale(1.095) rotate(0.8deg)',
    });
});

it('renders a decorative glow layer behind SBC artwork', () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc');
    const media = card?.querySelector('.store-catalog-card__media');
    const glow = media?.querySelector('.store-catalog-card__artwork-glow');

    expect(glow).toHaveAttribute('aria-hidden', 'true');
    expect(glow?.nextElementSibling).toHaveClass('store-catalog-card__image');
});

it('keeps SBC listing cards compact without removing descriptions from other catalogs', () => {
    render(<StoreCategory />);

    expect(screen.queryByText('Complete the Icon challenge.')).toBeNull();

    cleanup();
    const props = categoryProps();
    page.props = categoryProps({
        catalog: { ...props.catalog, service: 'objectives' },
        catalogPageUrl: '/en/objectives',
        servicePage: {
            ...props.servicePage,
            page_title: undefined,
            title: 'Objectives',
        },
    });

    render(<StoreCategory />);

    expect(screen.getByText('Complete the Icon challenge.')).toBeVisible();
});

it('shows truthful category counts and disables categories with no products', () => {
    render(<StoreCategory />);

    expect(screen.getByRole('button', { name: 'All: 1' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Icons: 1' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Players: 0' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Upgrades: 0' })).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Foundations: 0' }),
    ).toBeDisabled();
});

it('keeps the current search, filter, and sort while navigating every result page', () => {
    page.props = categoryProps({
        catalog: {
            ...categoryProps().catalog,
            query: {
                filter: 'icons',
                q: 'icon',
                sort: 'price_asc',
                page: 2,
            },
            pagination: { page: 2, perPage: 12, total: 30, lastPage: 3 },
        },
    });

    render(<StoreCategory />);

    const pagination = screen.getByRole('navigation', {
        name: 'Catalog pages',
    });
    expect(within(pagination).getByText('Page 2 of 3')).toBeVisible();
    expect(
        within(pagination).getByRole('button', { name: 'Previous' }),
    ).toBeEnabled();
    expect(
        within(pagination).getByRole('button', { name: 'Next' }),
    ).toBeEnabled();

    fireEvent.click(within(pagination).getByRole('button', { name: 'Next' }));

    expect(mocks.get).toHaveBeenLastCalledWith(
        '/en/sbc',
        { filter: 'icons', page: 3, q: 'icon', sort: 'price_asc' },
        expect.objectContaining({ preserveScroll: true, replace: true }),
    );
});

it('requires an explicit SBC platform before linking to its credential page', () => {
    render(<StoreCategory />);

    const prices = screen.getByRole('list', { name: 'Platform prices' });
    const platformButtons = within(prices).getAllByRole('button');

    platformButtons.forEach((button) =>
        expect(button).toHaveAttribute('aria-pressed', 'false'),
    );
    expect(screen.queryByRole('link', { name: 'Add to cart' })).toBeNull();

    fireEvent.click(platformButtons[1]);

    const add = screen.getByRole('link', { name: 'Add to cart' });
    expect(add).toHaveAttribute(
        'href',
        '/en/sbc/icon-service?variant=01K00000000000000000000003',
    );
    expect(mocks.submit).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('button', { name: /details|contact/i }),
    ).toBeNull();
});

it('suppresses cart actions and announces when the selected platform price is unavailable', () => {
    const props = categoryProps();
    const product = props.catalog.products[0];

    page.props = categoryProps({
        catalog: {
            ...props.catalog,
            products: [
                {
                    ...product,
                    price: null,
                    variants: product.variants.map((variant) => ({
                        ...variant,
                        price: null,
                    })),
                },
            ],
        },
    });

    render(<StoreCategory />);

    fireEvent.click(
        within(
            screen.getByRole('list', { name: 'Platform prices' }),
        ).getAllByRole('button')[0],
    );

    expect(
        screen.getByRole('status', {
            name: 'Price temporarily unavailable',
        }),
    ).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Add to cart' })).toBeNull();
});

function categoryProps(overrides: Record<string, unknown> = {}) {
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
            filterCounts: {
                all: 1,
                players: 0,
                icons: 1,
                upgrades: 0,
                foundations: 0,
            },
        },
        catalogCartUrl: '/en/cart/items/catalog',
        catalogPageUrl: '/en/sbc',
        catalogPage: catalogTranslations(),
        servicePage: {
            eyebrow: 'FC 27 services',
            title: 'SBC Services',
            page_title: 'Complete Squad Building Challenges',
            intro: 'Choose your service.',
            card_description: 'SBC service.',
        },
        ...shellProps(),
        ...overrides,
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
        included: 'Includes coins and Submitting',
        browse_by_type: 'Browse by type',
        assurances: 'Store assurances',
        assurance_no_players: 'your club is safe',
        assurance_no_players_detail:
            'We fund and complete the SBC without taking players.',
        assurance_fast: 'Fast delivery',
        assurance_fast_detail: 'Fast delivery of your challenge rewards.',
        assurance_support: '24/7 support',
        assurance_support_detail: 'Our team is available whenever you need it.',
        assurance_secure: 'Secure service',
        assurance_secure_detail: 'Your account details stay protected.',
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
        },
    };
}
