import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

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
    url: '/en/sbc',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { get: mocks.get, visit: mocks.visit },
    usePage: () => page,
}));
vi.mock('@/lib/catalog-cart-api', () => ({ submitCatalogCart: mocks.submit }));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

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

it('keeps SBC discovery focused on filter and sort without a search field', () => {
    render(<StoreCategory />);

    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /^Icons/ }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Sort' }), {
        target: { value: 'price_asc' },
    });

    expect(mocks.get).toHaveBeenLastCalledWith(
        '/en/sbc',
        { filter: 'icons', q: '', sort: 'price_asc' },
        expect.objectContaining({ preserveScroll: true, replace: true }),
    );
});

it('renders the refined SBC hierarchy and trust strip', () => {
    render(<StoreCategory />);

    expect(screen.getByRole('search')).toHaveClass(
        'store-catalog-toolbar--compact',
        'store-catalog-toolbar--premium',
    );
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Filter' })).toHaveClass(
        'store-catalog-toolbar__filters',
        'store-catalog-toolbar__filters--single-row',
    );
    expect(
        screen.getByRole('combobox', { name: 'Sort' }).closest('label'),
    ).toHaveClass('store-catalog-toolbar__sort');
    expect(
        screen.getByRole('combobox', { name: 'Sort' }).parentElement,
    ).toHaveClass('store-catalog-toolbar__sort-control');
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
    expect(
        screen.getByRole('heading', { name: 'Browse by type', level: 2 })
            .parentElement,
    ).toHaveClass('store-catalog-toolbar__heading-row');
    expect(
        document.querySelector('.store-catalog-toolbar__search-row'),
    ).toBeNull();
    expect(
        screen.getByRole('group', { name: 'Filter' }).parentElement,
    ).toHaveClass('store-catalog-toolbar__filter-shell');
    expect(screen.getByText('Coins + completion')).toBeVisible();
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

it('keeps the mobile SBC filter rail inside its shell while scrolling', () => {
    const filterRule =
        appCss.match(
            /\.store-catalog-toolbar--premium\s+\.store-catalog-toolbar__filters--single-row\s*\{[^}]*\}/s,
        )?.[0] ?? '';

    expect(filterRule).toContain('min-inline-size: 0;');
    expect(filterRule).toContain('max-inline-size: 100%;');
});

it('keeps the included-service label below the artwork', () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc');
    const media = card?.querySelector('.store-catalog-card__media');
    const ribbon = screen.getByText('Coins + completion');
    const body = card?.querySelector('.store-catalog-card__body');

    expect(media).not.toContainElement(ribbon);
    expect(ribbon).toHaveClass('store-catalog-card__included');
    expect(body).toContainElement(ribbon);
});

it('keeps touch feedback active while the finger scrolls the page', () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc');

    fireEvent.touchStart(card as Element, {
        touches: [{ clientX: 24, clientY: 40, identifier: 7 }],
    });
    expect(card).toHaveClass('is-pressed');

    fireEvent.touchMove(card as Element, {
        touches: [{ clientX: 27, clientY: 46, identifier: 7 }],
    });
    expect(card).toHaveClass('is-pressed');

    fireEvent.touchMove(card as Element, {
        touches: [{ clientX: 26, clientY: 58, identifier: 7 }],
    });
    expect(card).toHaveClass('is-pressed');
});

it('clears touch feedback when the browser cancels the touch', () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc');

    fireEvent.touchStart(card as Element, {
        touches: [{ clientX: 24, clientY: 40, identifier: 9 }],
    });
    fireEvent.touchCancel(card as Element);

    expect(card).not.toHaveClass('is-pressed');
});

it('clears touch feedback as soon as a stationary touch ends', () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc');

    fireEvent.touchStart(card as Element, {
        touches: [{ clientX: 24, clientY: 40, identifier: 8 }],
    });
    fireEvent.touchEnd(card as Element);

    expect(card).not.toHaveClass('is-pressed');
});

it('keeps the shine clipping layer separate from the protruding artwork', () => {
    render(<StoreCategory />);

    const cardLink = screen.getByRole('link', { name: /Icon Service/i });
    const shineClip = cardLink.querySelector('.store-catalog-card__shine-clip');
    const artwork = cardLink.querySelector('.store-catalog-card__media');

    expect(shineClip).not.toBeNull();
    expect(shineClip).not.toContainElement(artwork as HTMLElement);
});

it('separates the console logos from the non-wrapping platform label', () => {
    render(<StoreCategory />);

    const consolePlatform = screen.getByText('PlayStation / Xbox');

    expect(consolePlatform).toHaveClass('store-catalog-card__platform-name');
    expect(consolePlatform.previousElementSibling).toHaveClass(
        'store-catalog-card__platform-logos',
    );
});

it('tilts SBC artwork toward a fine pointer and resets on exit', async () => {
    render(<StoreCategory />);

    const card = screen
        .getByRole('list', { name: 'Platform prices' })
        .closest('.store-catalog-card--sbc') as HTMLElement;

    vi.spyOn(card, 'getBoundingClientRect').mockReturnValue({
        bottom: 400,
        height: 200,
        left: 100,
        right: 300,
        toJSON: () => ({}),
        top: 200,
        width: 200,
        x: 100,
        y: 200,
    });

    fireEvent.pointerMove(card, {
        clientX: 290,
        clientY: 210,
        pointerType: 'mouse',
    });

    await waitFor(() => {
        expect(card.style.getPropertyValue('--sbc-tilt-x')).toBe('5.4deg');
        expect(card.style.getPropertyValue('--sbc-tilt-y')).toBe('5.4deg');
    });

    fireEvent.pointerLeave(card, { pointerType: 'mouse' });

    expect(card.style.getPropertyValue('--sbc-tilt-x')).toBe('0deg');
    expect(card.style.getPropertyValue('--sbc-tilt-y')).toBe('0deg');
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

it('returns to the first product after changing result pages', () => {
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
        expect.objectContaining({
            onSuccess: expect.any(Function),
            preserveScroll: false,
            replace: true,
        }),
    );

    const productList = document.querySelector('.store-catalog-grid');
    const scrollIntoView = vi.fn();
    Object.defineProperty(productList, 'scrollIntoView', {
        configurable: true,
        value: scrollIntoView,
    });

    const visitOptions = mocks.get.mock.lastCall?.[2] as {
        onSuccess?: () => void;
    };
    visitOptions.onSuccess?.();

    expect(scrollIntoView).toHaveBeenCalledWith({
        behavior: 'auto',
        block: 'start',
    });
});

it('makes the complete SBC card a product link with informational prices', () => {
    render(<StoreCategory />);

    const prices = screen.getByRole('list', { name: 'Platform prices' });
    const cardLink = screen.getByRole('link', { name: /Icon Service/i });

    expect(within(prices).queryAllByRole('button')).toHaveLength(0);
    expect(within(prices).getByText(/125\.00/)).toBeVisible();
    expect(within(prices).getByText(/99\.00/)).toBeVisible();
    expect(cardLink).toHaveAttribute('href', '/en/sbc/icon-service');
    expect(cardLink).toHaveClass('store-catalog-card__target');
    expect(mocks.submit).not.toHaveBeenCalled();
});

it('keeps unavailable SBC platform prices informational and linkable', () => {
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

    expect(
        within(
            screen.getByRole('list', { name: 'Platform prices' }),
        ).getAllByText('Price temporarily unavailable'),
    ).toHaveLength(2);
    expect(screen.getByRole('link', { name: /Icon Service/i })).toHaveAttribute(
        'href',
        '/en/sbc/icon-service',
    );
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
        included: 'Coins + completion',
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
