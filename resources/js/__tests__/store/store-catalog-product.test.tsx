import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import StoreCatalogProduct from '@/pages/store/catalog-product';

const mocks = vi.hoisted(() => ({ submit: vi.fn(), visit: vi.fn() }));
const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/fut-champions',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { visit: mocks.visit },
    usePage: () => page,
}));
vi.mock('@/lib/catalog-cart-api', () => ({ submitCatalogCart: mocks.submit }));

afterEach(cleanup);

beforeEach(() => {
    mocks.submit.mockReset();
    mocks.visit.mockReset();
    mocks.submit.mockResolvedValue({ cartUrl: '/en/cart' });
});

it('renders product hierarchy and adds the selected variant without checkout controls', async () => {
    page.props = productProps();
    render(<StoreCatalogProduct />);

    expect(
        screen.getByRole('heading', { name: 'FUT Champions Package' }),
    ).toHaveClass('store-catalog-product__title');
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Choose an option' }),
        {
            target: { value: '01K00000000000000000000004' },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));

    await waitFor(() => expect(mocks.submit).toHaveBeenCalled());
    expect(mocks.submit.mock.calls[0][0].variantId).toBe(
        '01K00000000000000000000004',
    );
    expect(mocks.visit).toHaveBeenCalledWith('/en/cart');
    expect(screen.queryByRole('button', { name: /checkout|pay/i })).toBeNull();
});

function productProps() {
    const shell = {
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

    return {
        ...shell,
        backUrl: '/en',
        catalogCartUrl: '/en/cart/items/catalog',
        catalog: {
            service: 'fut_champions',
            suggestions: [],
            product: {
                id: '01K00000000000000000000003',
                slug: 'fut',
                url: null,
                name: 'FUT Champions Package',
                description: 'Competitive package.',
                image: null,
                price: { amountMinor: 10000, currency: 'SAR' },
                platforms: ['playstation', 'pc'],
                variants: [
                    {
                        id: '01K00000000000000000000003',
                        name: 'Console',
                        platform: 'playstation',
                        price: { amountMinor: 10000, currency: 'SAR' },
                    },
                    {
                        id: '01K00000000000000000000004',
                        name: 'PC',
                        platform: 'pc',
                        price: { amountMinor: 12000, currency: 'SAR' },
                    },
                ],
            },
        },
        productPage: {
            choose_option: 'Choose an option',
            platform: 'Platform',
            price: 'Price',
            add_to_cart: 'Add to cart',
            adding: 'Adding…',
            back: 'Back to services',
            unavailable_price: 'Unavailable',
            add_error: 'Could not add this item.',
        },
    };
}
