import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import type * as CheckoutPhoneApi from '@/lib/checkout-phone-api';
import type * as PaylinkCheckoutApi from '@/lib/paylink-checkout-api';
import StoreCart from '@/pages/store/cart';
import type { StoreCartConfiguration } from '@/types/store-shell';

const navigateToHostedPayment = vi.hoisted(() => vi.fn());
const navigateToOrder = vi.hoisted(() => vi.fn());
const reloadAfterPhoneVerification = vi.hoisted(() => vi.fn());

vi.mock('@/lib/checkout-phone-api', async (importOriginal) => ({
    ...(await importOriginal<typeof CheckoutPhoneApi>()),
    reloadAfterPhoneVerification,
}));

vi.mock('@/lib/paylink-checkout-api', async (importOriginal) => ({
    ...(await importOriginal<typeof PaylinkCheckoutApi>()),
    navigateToHostedPayment,
    navigateToOrder,
}));

const mockPage = vi.hoisted(() => ({
    props: {
        auth: { user: null as { id: number; name: string } | null },
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
                    deleteUrl: '/en/cart/items/01K00000000000000000000000',
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
            checkout: {
                canCheckout: false,
                checkoutUrl: '/en/checkout/paylink',
                loginUrl: '/en/login',
                phoneCodeUrl: '/en/checkout/phone/code',
                phoneVerified: false,
                phoneVerifyUrl: '/en/checkout/phone/verify',
            },
            translations: {
                title: 'Your cart',
                eyebrow: 'Arab UT',
                empty: 'Your cart is empty.',
                empty_title: 'Your cart is empty',
                empty_description:
                    'Explore our services and complete your order in a few clear steps.',
                browse_coins: 'Browse Coins services',
                back: 'Back to Coins',
                items_heading: 'Your services',
                summary_title: 'Order summary',
                checkout_progress: 'Checkout progress',
                step_cart: 'Cart',
                step_payment: 'Secure payment',
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
                completions: 'Completions',
                coins_unit: 'Coins',
                total: 'Authoritative total',
                credentials: 'EA details',
                credentials_ready: 'Stored securely',
                credentials_missing: 'EA details need to be entered again.',
                backup_codes: ':count backup codes stored',
                ea_email: 'EA email',
                ea_password: 'EA password',
                current_balance: 'Current Coins balance',
                companion_market_open: 'Transfer Market is open',
                policy_accepted: 'Policies accepted',
                edit_credentials: 'Edit EA details',
                save_credentials: 'Save EA details',
                cancel_edit: 'Cancel',
                credentials_saved: 'EA details saved',
                credentials_load_error: 'EA details could not be loaded.',
                credentials_save_error: 'EA details could not be saved.',
                credentials_show: 'Show EA details',
                credentials_hide: 'Hide EA details',
                remove_item: 'Remove product',
                remove_confirm: 'Confirm removal',
                remove_cancel: 'Keep product',
                remove_error: 'The product could not be removed.',
                backup_code: 'Backup code :number',
                checkout: 'Continue to secure payment',
                checkout_loading: 'Opening Paylink…',
                checkout_login: 'Sign in to continue',
                checkout_phone: 'Verify your WhatsApp number to continue.',
                checkout_error: 'Payment could not be opened.',
                checkout_cart_changed: 'Prices changed. Refresh and try again.',
                phone_country: 'Country code',
                phone_number: 'WhatsApp number',
                phone_code: '6-digit verification code',
                phone_send: 'Send WhatsApp code',
                phone_sending: 'Sending code…',
                phone_verify: 'Verify number',
                phone_verifying: 'Verifying…',
                phone_sent: 'We sent a 6-digit code to your WhatsApp.',
                phone_invalid: 'Check the number or code and try again.',
                phone_unavailable: 'This number is already in use.',
                phone_delivery_error:
                    'The WhatsApp code could not be sent right now.',
                order_total: 'Order total',
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
            accountUrl: '/my-account',
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
            cart_added: {
                title: 'Added to your cart',
                message: ':item is ready in your cart.',
                buy_now: 'Buy now',
                continue_shopping: 'Continue shopping',
            },
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
const defaultCartItem = mockPage.props.cart.items[0];

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

afterEach(cleanup);
beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    mockPage.props.auth.user = null;
    mockPage.props.cartPage.checkout.canCheckout = false;
    mockPage.props.cartPage.checkout.phoneVerified = false;
    mockPage.props.cart.count = 1;
    mockPage.props.cart.items = [defaultCartItem];
    mockPage.props.cart.items[0].configuration = validConfiguration;
    mockPage.props.cart.items[0].product = {
        imageUrl: '/images/store/coins/ut-coin-80.webp',
        name: 'FC 27 Coins',
        serviceType: 'coins',
    };
    mockPage.props.direction = 'ltr';
    mockPage.props.locale = 'en';
    navigateToHostedPayment.mockReset();
    navigateToOrder.mockReset();
    reloadAfterPhoneVerification.mockReset();
    vi.unstubAllGlobals();
});

it('renders only the authoritative read-only Coins cart summary', () => {
    render(<StoreCart />);

    expect(screen.getByRole('heading', { name: 'Your cart' })).toBeVisible();
    expect(screen.getByText('FC 27 Coins')).toBeVisible();
    expect(screen.getByText('PS / Xbox')).toBeVisible();
    expect(screen.getByText('Fast')).toBeVisible();
    expect(screen.getByText('500,000 Coins')).toBeVisible();
    expect(screen.getAllByText(/125\.00/)).toHaveLength(2);
    expect(document.body.textContent).not.toContain('EA email:');
    expect(screen.getByText('3 backup codes stored')).toBeVisible();
    expect(
        screen.queryByRole('link', { name: 'Back to Coins' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Sign in to continue' }),
    ).toHaveAttribute('href', '/en/login');
    expect(document.body.textContent).not.toMatch(
        /10000001|opaque EA password/,
    );
    expect(
        screen.getByRole('navigation', { name: 'Checkout progress' }),
    ).toBeVisible();
    expect(screen.getByText('Order summary')).toBeVisible();
    expect(screen.getByText('Your services')).toBeVisible();
});

it('offers the Coins route from a purposeful empty state without a back link', () => {
    mockPage.props.cart.items = [];
    mockPage.props.cart.count = 0;

    render(<StoreCart />);

    expect(
        screen.getByRole('heading', { name: 'Your cart is empty' }),
    ).toBeVisible();
    expect(
        screen.queryByRole('link', { name: 'Back to Coins' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Browse Coins services' }),
    ).toHaveAttribute('href', '/en#coins');
    expect(
        screen.getByText(
            'Explore our services and complete your order in a few clear steps.',
        ),
    ).toBeVisible();
});

it('locks checkout while Paylink opens and navigates only to the validated hosted URL', async () => {
    mockPage.props.auth.user = { id: 1, name: 'Buyer' };
    mockPage.props.cartPage.checkout.canCheckout = true;
    mockPage.props.cartPage.checkout.phoneVerified = true;
    const fetchMock = vi.fn().mockImplementation(() =>
        Promise.resolve(
            new Response(
                JSON.stringify({
                    data: {
                        orderUrl: '/en/orders/01K00000000000000000000000',
                        paymentUrl:
                            'https://payment.paylink.sa/pay/info/1710000000099',
                        status: 'pending',
                    },
                }),
                {
                    status: 201,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        ),
    );
    vi.stubGlobal('fetch', fetchMock);
    render(<StoreCart />);
    const checkout = screen.getByRole('button', {
        name: 'Continue to secure payment',
    });
    fireEvent.click(checkout);
    fireEvent.click(checkout);

    expect(
        screen.getByRole('button', { name: 'Opening Paylink…' }),
    ).toBeDisabled();
    await waitFor(() =>
        expect(
            fetchMock.mock.calls.filter(
                ([, init]) =>
                    (init as RequestInit | undefined)?.method === 'POST',
            ),
        ).toHaveLength(1),
    );
    await waitFor(() =>
        expect(navigateToHostedPayment).toHaveBeenCalledWith(
            'https://payment.paylink.sa/pay/info/1710000000099',
        ),
    );
});

it('opens the existing order when an idempotent checkout retry is already paid', async () => {
    mockPage.props.auth.user = { id: 1, name: 'Buyer' };
    mockPage.props.cartPage.checkout.canCheckout = true;
    mockPage.props.cartPage.checkout.phoneVerified = true;
    vi.stubGlobal(
        'fetch',
        vi.fn((input: RequestInfo | URL) => {
            if (String(input).endsWith('/credentials')) {
                return Promise.resolve(new Response('{}', { status: 404 }));
            }

            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        data: {
                            orderUrl: '/en/orders/01K00000000000000000000000',
                            paymentUrl: null,
                            status: 'paid',
                        },
                    }),
                    { status: 200 },
                ),
            );
        }),
    );

    render(<StoreCart />);
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Continue to secure payment',
        }),
    );

    await waitFor(() =>
        expect(navigateToOrder).toHaveBeenCalledWith(
            '/en/orders/01K00000000000000000000000',
        ),
    );
    expect(navigateToHostedPayment).not.toHaveBeenCalled();
});

it('verifies an authenticated checkout phone through Whapi before enabling payment', async () => {
    mockPage.props.auth.user = { id: 1, name: 'Buyer' };
    const fetchMock = vi.fn((input: RequestInfo | URL) => {
        const url = String(input);

        if (url.endsWith('/credentials')) {
            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        data: {
                            backupCodes: ['10000001', '10000002', '10000003'],
                            eaEmail: 'owner@example.test',
                            eaPassword: 'opaque EA password',
                        },
                    }),
                    { status: 200 },
                ),
            );
        }

        if (url.endsWith('/code')) {
            return Promise.resolve(
                new Response(JSON.stringify({ data: { sent: true } }), {
                    status: 200,
                }),
            );
        }

        return Promise.resolve(
            new Response(JSON.stringify({ data: { verified: true } }), {
                status: 200,
            }),
        );
    });
    vi.stubGlobal('fetch', fetchMock);

    render(<StoreCart />);
    expect(screen.queryByText('WhatsApp verification')).not.toBeInTheDocument();
    expect(
        screen.queryByText('Secure payment powered by Paylink'),
    ).not.toBeInTheDocument();
    expect(screen.getByText('Cart')).toBeVisible();
    expect(screen.getByText('Secure payment')).toBeVisible();
    const phoneNumber = screen.getByLabelText('WhatsApp number');
    const phoneField = phoneNumber.closest('.auth-phone-field');

    expect(phoneField).not.toBeNull();
    expect(phoneField).toContainElement(screen.getByLabelText('Country code'));
    expect(screen.getByLabelText('Country code')).toHaveClass(
        'auth-phone-field__country',
    );
    fireEvent.change(screen.getByLabelText('Country code'), {
        target: { value: '+966' },
    });
    fireEvent.change(phoneNumber, {
        target: { value: '501234567' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send WhatsApp code' }));

    expect(await screen.findByText(/sent a 6-digit code/i)).toBeVisible();
    fireEvent.change(screen.getByLabelText('6-digit verification code'), {
        target: { value: '123456' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Verify number' }));

    await waitFor(() =>
        expect(reloadAfterPhoneVerification).toHaveBeenCalledTimes(1),
    );
    expect(fetchMock).toHaveBeenCalledWith(
        new URL('/en/checkout/phone/code', window.location.origin),
        expect.objectContaining({
            body: JSON.stringify({ phone: '+966501234567' }),
        }),
    );
});

it('explains a temporary WhatsApp delivery failure without blaming the phone number', async () => {
    mockPage.props.auth.user = { id: 1, name: 'Buyer' };
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    error: { code: 'whatsapp_unavailable' },
                }),
                { status: 503 },
            ),
        ),
    );

    render(<StoreCart />);
    fireEvent.change(screen.getByLabelText('Country code'), {
        target: { value: '+20' },
    });
    fireEvent.change(screen.getByLabelText('WhatsApp number'), {
        target: { value: '1001234567' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send WhatsApp code' }));

    expect(
        await screen.findByText(
            'The WhatsApp code could not be sent right now.',
        ),
    ).toBeVisible();
    expect(
        screen.queryByText('Check the number or code and try again.'),
    ).not.toBeInTheDocument();
});

it('loads owner-only credentials only after disclosure and edits exactly three codes without browser persistence', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({
                    data: {
                        backupCodes: ['10000001', '10000002', '10000003'],
                        eaEmail: 'owner@example.test',
                        eaPassword: 'opaque EA password',
                        currentBalance: 500000,
                        companionMarketOpen: true,
                        policyAccepted: true,
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
    expect(fetchMock).not.toHaveBeenCalled();
    const disclosure = screen.getByRole('button', {
        name: 'Show EA details',
    });
    expect(disclosure).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(disclosure);
    expect(disclosure).toHaveAttribute('aria-expanded', 'true');
    expect(await screen.findByText('owner@example.test')).toBeVisible();
    expect(screen.getByText('opaque EA password')).toBeVisible();
    expect(screen.getByText('10000003')).toBeVisible();
    expect(screen.getByText('500,000')).toBeVisible();
    expect(
        screen.queryByText('Transfer Market is open'),
    ).not.toBeInTheDocument();
    expect(screen.queryByText('Policies accepted')).not.toBeInTheDocument();
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
        companion_market_open: true,
        current_balance: 500000,
        ea_email: 'edited@example.test',
        ea_password: 'opaque EA password',
        policy_accepted: true,
    });
    expect(screen.getByText('EA details saved')).toBeVisible();
    expect(localStorageSpy).not.toHaveBeenCalled();
});

it('removes a cart product only after inline confirmation and updates the cart count', async () => {
    const cartCountEvents: number[] = [];
    window.addEventListener(
        'arabut:cart-count',
        (event) => {
            cartCountEvents.push((event as CustomEvent<number>).detail);
        },
        { once: true },
    );
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ data: { cartCount: 0 } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    vi.stubGlobal('fetch', fetchMock);

    render(<StoreCart />);

    fireEvent.click(screen.getByRole('button', { name: 'Remove product' }));
    expect(screen.getByText('FC 27 Coins')).toBeVisible();
    expect(fetchMock).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm removal' }));

    await waitFor(() =>
        expect(screen.queryByText('FC 27 Coins')).not.toBeInTheDocument(),
    );
    expect(fetchMock).toHaveBeenCalledWith(
        '/en/cart/items/01K00000000000000000000000',
        expect.objectContaining({ method: 'DELETE' }),
    );
    expect(cartCountEvents).toEqual([0]);
    expect(
        screen.getByRole('heading', { name: 'Your cart is empty' }),
    ).toBeVisible();
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

it('omits delivery from an SBC cart item while keeping its platform', () => {
    mockPage.props.cart.items[0].configuration = {
        market: 'console',
        platform: 'playstation',
        price_version: 3,
        quoted_at: '2026-08-10T12:00:00+00:00',
        service_type: 'sbc',
    } as StoreCartConfiguration;
    mockPage.props.cart.items[0].product = {
        imageUrl: null,
        name: 'Safe SBC service',
        serviceType: 'sbc',
    };

    render(<StoreCart />);

    expect(screen.getByText('PS / Xbox')).toBeVisible();
    expect(screen.queryByText('Delivery')).not.toBeInTheDocument();
    expect(screen.queryByText('Normal')).not.toBeInTheDocument();
    expect(screen.queryByText('Fast')).not.toBeInTheDocument();
    expect(screen.queryByText('Not required for PC')).not.toBeInTheDocument();
});

it('shows the selected completion count for an SBC cart item', () => {
    mockPage.props.cart.items[0].configuration = {
        ...validConfiguration,
        completion_count: 10,
        service_type: 'sbc',
    } as StoreCartConfiguration;
    mockPage.props.cart.items[0].product = {
        imageUrl: null,
        name: 'Repeatable SBC',
        serviceType: 'sbc',
    };

    render(<StoreCart />);

    expect(screen.getByText('Completions')).toBeVisible();
    expect(screen.getByText('10')).toBeVisible();
});

it('does not show an SBC completion count for a Coins cart item', () => {
    mockPage.props.cart.items[0].configuration = {
        ...validConfiguration,
        completion_count: 10,
    } as StoreCartConfiguration;
    mockPage.props.cart.items[0].product = {
        imageUrl: '/images/store/coins/ut-coin-80.webp',
        name: 'FC 27 Coins',
        serviceType: 'coins',
    };

    render(<StoreCart />);

    expect(screen.queryByText('Completions')).not.toBeInTheDocument();
});

it('keeps the Arabic credential state RTL without an email identity isolate', () => {
    mockPage.props.direction = 'rtl';
    mockPage.props.locale = 'ar';

    render(<StoreCart />);

    const credentialState = document.querySelector('.store-cart-credentials');

    expect(credentialState?.closest('[dir]')).toHaveAttribute('dir', 'rtl');
    expect(credentialState?.querySelector('[dir="ltr"]')).toBeNull();
});
