import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import StoreCart from '@/pages/store/cart';
import type { StoreCartConfiguration } from '@/types/store-shell';

const mockPage = vi.hoisted(() => ({
    props: {
        auth: { user: null },
        cart: {
            count: 1,
            currency: 'SAR',
            items: [
                {
                    configuration: {
                        coins_quantity: 500_000,
                        delivery: 'fast',
                        market: 'console',
                        platform: 'playstation',
                        price_version: 3,
                        quoted_at: '2026-08-10T12:00:00+00:00',
                        service_type: 'coins',
                    } as StoreCartConfiguration,
                    credentials: {
                        backupCodeCount: 5,
                        hasPassword: true,
                        retainedUntil: '2026-08-11T12:00:00+00:00',
                    },
                    id: '01K00000000000000000000000',
                    quantity: 1,
                    requiresCredentials: false,
                    totalHalalah: 12_500,
                    unitPriceHalalah: 12_500,
                },
            ],
        },
        cartPage: {
            backUrl: '/en#coins',
            translations: {
                title: 'Your cart',
                eyebrow: 'Arab UT',
                empty: 'Your cart is empty.',
                back: 'Back to Coins',
                service: 'Service',
                coins_service: 'FC 27 Coins',
                platform: 'Platform',
                platform_playstation: 'PS / Xbox',
                platform_pc: 'PC',
                delivery: 'Delivery',
                delivery_normal: 'Normal',
                delivery_fast: 'Fast',
                delivery_pc: 'Not required for PC',
                quantity: 'Coins quantity',
                coins_unit: 'Coins',
                total: 'Authoritative total',
                credentials: 'EA details',
                credentials_ready: 'Stored securely until :expiry',
                credentials_missing: 'EA details need to be entered again.',
                backup_codes: ':count backup codes stored',
            },
        },
        direction: 'ltr',
        displayCurrencies: ['SAR'],
        displayCurrency: 'SAR',
        locale: 'en',
        storeShell: {
            homeUrl: '/en',
            coinsUrl: '/en#coins',
            cartUrl: '/en/cart',
            sbcUrl: '/en/sbc',
            futChampionsUrl: '/en/fut-champions',
            accountUrl: '/dashboard',
            privacyUrl: '/en/privacy',
            returnsUrl: '/en/returns',
            warrantyUrl: '/en/warranty',
            eaBackupCodesUrl: '/en/ea-backup-codes',
            termsUrl: '/en/terms',
            whatsappUrl: 'https://wa.me/1',
            email: 'support@example.com',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'Arab UT',
            currency_selector: 'Currency',
            home_title: 'Home',
            language: 'العربية',
            skip_to_content: 'Skip',
            store_tools: 'Tools',
            header: {
                primary_navigation: 'Primary',
                preferences: 'Preferences',
                home: 'Home',
                coins: 'Coins',
                sbc: 'SBC',
                fut_champions: 'FUT Champions',
                most_requested: 'Most requested',
                whatsapp: 'WhatsApp',
                cart: 'Cart',
                account: 'Account',
            },
            preferences: {
                exchange_rate_attribution: 'Rates By Exchange Rate API',
            },
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
    },
    url: '/en/cart',
}));

const validConfiguration: StoreCartConfiguration = {
    coins_quantity: 500_000,
    delivery: 'fast' as const,
    market: 'console' as const,
    platform: 'playstation' as const,
    price_version: 3,
    quoted_at: '2026-08-10T12:00:00+00:00',
    service_type: 'coins' as const,
};

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

afterEach(cleanup);
beforeEach(() => {
    mockPage.props.cart.items[0].configuration = validConfiguration;
    mockPage.props.direction = 'ltr';
    mockPage.props.locale = 'en';
});

it('renders only the authoritative read-only Coins cart summary', () => {
    render(<StoreCart />);

    expect(screen.getByRole('heading', { name: 'Your cart' })).toBeVisible();
    expect(screen.getByText('FC 27 Coins')).toBeVisible();
    expect(screen.getByText('PS / Xbox')).toBeVisible();
    expect(screen.getByText('Fast')).toBeVisible();
    expect(screen.getByText('500,000 Coins')).toBeVisible();
    expect(screen.getByText(/125\.00/)).toBeVisible();
    expect(document.body.textContent).not.toContain('EA email:');
    expect(screen.getByText('5 backup codes stored')).toBeVisible();
    expect(screen.getByRole('link', { name: 'Back to Coins' })).toHaveAttribute(
        'href',
        '/en#coins',
    );
    expect(
        screen.queryByRole('button', { name: /checkout|pay|remove/i }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /checkout|pay/i })).toBeNull();
    expect(document.body.textContent).not.toMatch(
        /10000001|opaque EA password/,
    );
});

it('does not invent cart facts when a safe projected field is absent', () => {
    mockPage.props.cart.items[0].configuration = {};

    render(<StoreCart />);

    expect(screen.queryByText('PS / Xbox')).not.toBeInTheDocument();
    expect(screen.queryByText('Not required for PC')).not.toBeInTheDocument();
    expect(screen.queryByText('0 Coins')).not.toBeInTheDocument();
    expect(screen.queryByText('FC 27 Coins')).not.toBeInTheDocument();
    expect(screen.queryByText('Coins quantity')).not.toBeInTheDocument();
    expect(screen.queryByText('500,000 Coins')).not.toBeInTheDocument();
    expect(
        document.querySelector(
            '.store-cart-line__title img[src="/images/store/coins/ut-coin-80.webp"]',
        ),
    ).not.toBeInTheDocument();
    expect(screen.getAllByText('—')).toHaveLength(3);
});

it('does not invent a Coins presentation for another safe service type', () => {
    mockPage.props.cart.items[0].configuration = {
        ...validConfiguration,
        service_type: 'sbc',
    } as StoreCartConfiguration;

    render(<StoreCart />);

    expect(screen.queryByText('FC 27 Coins')).not.toBeInTheDocument();
    expect(screen.queryByText('Coins quantity')).not.toBeInTheDocument();
    expect(screen.queryByText('500,000 Coins')).not.toBeInTheDocument();
    expect(
        document.querySelector(
            '.store-cart-line__title img[src="/images/store/coins/ut-coin-80.webp"]',
        ),
    ).not.toBeInTheDocument();
});

it('keeps the Arabic credential state RTL without an email identity isolate', () => {
    mockPage.props.direction = 'rtl';
    mockPage.props.locale = 'ar';

    render(<StoreCart />);

    const credentialState = document.querySelector('.store-cart-credentials');

    expect(credentialState?.closest('[dir]')).toHaveAttribute('dir', 'rtl');
    expect(credentialState?.querySelector('[dir="ltr"]')).toBeNull();
});
