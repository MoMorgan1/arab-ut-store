import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
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
                        backupCodeCount: 3,
                        hasPassword: true,
                    },
                    credentialsUrl:
                        '/en/cart/items/01K00000000000000000000000/credentials',
                    id: '01K00000000000000000000000',
                    product: {
                        imageUrl: '/images/store/coins/ut-coin-80.webp' as
                            string | null,
                        name: 'FC 27 Coins',
                        serviceType: 'coins',
                    },
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
                credentials_ready: 'Stored securely',
                credentials_missing: 'EA details need to be entered again.',
                backup_codes: ':count backup codes stored',
                ea_email: 'EA email',
                ea_password: 'EA password',
                edit_credentials: 'Edit EA details',
                save_credentials: 'Save EA details',
                cancel_edit: 'Cancel',
                credentials_saved: 'EA details saved',
                credentials_load_error: 'EA details could not be loaded.',
                credentials_save_error: 'EA details could not be saved.',
                backup_code: 'Backup code :number',
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
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    mockPage.props.cart.items[0].configuration = validConfiguration;
    mockPage.props.cart.items[0].product = {
        imageUrl: '/images/store/coins/ut-coin-80.webp',
        name: 'FC 27 Coins',
        serviceType: 'coins',
    };
    mockPage.props.direction = 'ltr';
    mockPage.props.locale = 'en';
    vi.unstubAllGlobals();
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
    expect(screen.getByText('3 backup codes stored')).toBeVisible();
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

it('loads owner-only credentials after render and edits exactly three codes without browser persistence', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({
                    data: {
                        backupCodes: ['10000001', '10000002', '10000003'],
                        eaEmail: 'owner@example.test',
                        eaPassword: 'opaque EA password',
                    },
                }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        )
        .mockResolvedValueOnce(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
    const localStorageSpy = vi.spyOn(Storage.prototype, 'setItem');

    render(<StoreCart />);

    expect(document.body.textContent).not.toContain('owner@example.test');
    expect(await screen.findByText('owner@example.test')).toBeVisible();
    expect(screen.getByText('opaque EA password')).toBeVisible();
    expect(screen.getByText('10000003')).toBeVisible();
    expect(fetchMock).toHaveBeenNthCalledWith(
        1,
        '/en/cart/items/01K00000000000000000000000/credentials',
        expect.objectContaining({
            cache: 'no-store',
            credentials: 'same-origin',
        }),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Edit EA details' }));
    fireEvent.change(screen.getByLabelText('EA email'), {
        target: { value: 'edited@example.test' },
    });
    fireEvent.change(screen.getByLabelText('Backup code 3'), {
        target: { value: '20000003' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save EA details' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(JSON.parse(String(fetchMock.mock.calls[1]?.[1]?.body))).toEqual({
        backup_codes: ['10000001', '10000002', '20000003'],
        ea_email: 'edited@example.test',
        ea_password: 'opaque EA password',
    });
    expect(screen.getByText('EA details saved')).toBeVisible();
    expect(localStorageSpy).not.toHaveBeenCalled();
});

it('does not invent cart facts when a safe projected field is absent', () => {
    mockPage.props.cart.items[0].configuration = {};

    render(<StoreCart />);

    expect(screen.queryByText('PS / Xbox')).not.toBeInTheDocument();
    expect(screen.queryByText('Not required for PC')).not.toBeInTheDocument();
    expect(screen.queryByText('0 Coins')).not.toBeInTheDocument();
    expect(screen.getByText('FC 27 Coins')).toBeVisible();
    expect(screen.queryByText('Coins quantity')).not.toBeInTheDocument();
    expect(screen.queryByText('500,000 Coins')).not.toBeInTheDocument();
    expect(screen.getAllByText('—')).toHaveLength(2);
});

it('does not invent a Coins presentation for another safe service type', () => {
    mockPage.props.cart.items[0].configuration = {
        ...validConfiguration,
        service_type: 'sbc',
    } as StoreCartConfiguration;
    mockPage.props.cart.items[0].product = {
        imageUrl: null,
        name: 'Safe SBC service',
        serviceType: 'sbc',
    };

    render(<StoreCart />);

    expect(screen.queryByText('FC 27 Coins')).not.toBeInTheDocument();
    expect(screen.getByText('Safe SBC service')).toBeVisible();
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
