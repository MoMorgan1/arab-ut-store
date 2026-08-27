import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type * as CheckoutPhoneApi from '@/lib/checkout-phone-api';
import type * as PaylinkCheckoutApi from '@/lib/paylink-checkout-api';
import StoreCart from '@/pages/store/cart';
import type {
    StoreCartConfiguration,
    StoreCartItem,
    StoreCartPageProps,
} from '@/types/store-shell';

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
            canCheckout: false,
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
            coupon: null,
            useWallet: false,
        } as StoreCartPageProps['cart'],
        cartPage: {
            checkout: {
                checkoutUrl: '/en/checkout/paylink',
                couponApplyUrl: '/en/cart/coupon',
                couponRemoveUrl: '/en/cart/coupon',
                walletToggleUrl: '/en/cart/wallet',
                walletBalanceHalalah: 0,
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
                remove_hint: 'Press and hold until the bar fills.',
                removed_item: 'Removed :name',
                undo: 'Undo',
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
                checkout_pricing_updating:
                    'Prices are updating right now. Try again in a moment.',
                checkout_too_many_requests:
                    'Too many attempts. Try again in a minute.',
                prices_updated: 'Prices updated',
                prices_updated_note: 'Coins prices change hourly.',
                price_was: 'Was',
                unavailable: 'Unavailable',
                unavailable_note: 'Remove it to continue to checkout.',
                confirm_total_title: 'Your total changed',
                confirm_total_note: 'Check the new amount before you pay.',
                confirm_total_previous: 'Total shown before',
                confirm_total_new: 'Total now',
                confirm_coupon_removed:
                    'The coupon was removed because the total fell below its minimum.',
                confirm_pay: 'Confirm and pay',
                confirm_cancel: 'Back to cart',
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
                coupon_label: 'Discount code',
                coupon_prompt: 'Have a discount code?',
                coupon_placeholder: 'Enter coupon code',
                coupon_apply: 'Apply',
                coupon_applying: 'Applying…',
                coupon_remove: 'Remove',
                coupon_removing: 'Removing…',
                coupon_applied: 'Coupon applied',
                coupon_discount: 'Discount',
                coupon_invalid: 'This coupon code is not valid.',
                coupon_expired: 'This coupon has expired or is not yet active.',
                coupon_limit: 'This coupon has reached its usage limit.',
                coupon_minimum:
                    'Your order must be at least :amount to use this coupon.',
                coupon_error: 'The coupon could not be applied. Try again.',
                wallet_toggle: 'Use wallet balance (:balance)',
                wallet_deduction: 'Wallet balance',
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

const manualFutCartItem = {
    ...defaultCartItem,
    configuration: {
        market: 'pc',
        matches_played: 4,
        pc_launcher: 'steam',
        platform: 'pc',
        schedule_version: 1,
        service_type: 'fut_champions',
        target_rank: 3,
        urgent: true,
    },
    credentials: null,
    credentialsUrl: null,
    fulfillment: { credentialsReady: true, squadImagePresent: true },
    product: {
        imageUrl: '/images/store/navigation/logo-champions-80.webp',
        name: 'FUT Champions service',
        serviceType: 'fut_champions',
    },
    requiresCredentials: false,
    totalHalalah: 21_000,
    unitPriceHalalah: 21_000,
} as StoreCartItem;

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        reload: vi.fn(),
    },
    usePage: () => mockPage,
}));

afterEach(cleanup);
beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    mockPage.props.auth.user = null;
    mockPage.props.cart.canCheckout = false;
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

it('renders an immutable FUT summary with fulfillment readiness and no credential edit action', () => {
    Object.assign(mockPage.props.cartPage.translations, {
        account_details_ready: 'Account details stored securely',
        division_elite: 'Elite',
        from_division: 'From division',
        launcher: 'Launcher',
        launcher_ea_app: 'EA app',
        launcher_steam: 'Steam',
        matches_played: 'Matches already played',
        rank: 'Target rank',
        rank_value: 'Rank :rank',
        squad_image_ready: 'Squad image attached',
        to_division: 'To division',
        urgent: 'Urgent service',
        urgent_yes: 'Yes — 24–36 hours',
    });
    mockPage.props.cart.items = [
        manualFutCartItem,
    ] as typeof mockPage.props.cart.items;

    render(<StoreCart />);

    expect(screen.getByText('FUT Champions service')).toBeVisible();
    expect(screen.getByText('Steam')).toBeVisible();
    expect(screen.getByText('Rank 3')).toBeVisible();
    expect(screen.getByText('Yes — 24–36 hours')).toBeVisible();
    expect(screen.getByText('4')).toBeVisible();
    expect(screen.getByText('Account details stored securely')).toBeVisible();
    expect(screen.getByText('Squad image attached')).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Edit EA details' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Show EA details' }),
    ).not.toBeInTheDocument();
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
    mockPage.props.cart.canCheckout = true;
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
    mockPage.props.cart.canCheckout = true;
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
    fireEvent.paste(screen.getByLabelText('6-digit verification code 1/6'), {
        clipboardData: { getData: () => '123456' },
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

it('removes a product only after a sustained hold, and lets it be undone', async () => {
    const cartCountEvents: number[] = [];
    window.addEventListener('arabut:cart-count', (event) => {
        cartCountEvents.push((event as CustomEvent<number>).detail);
    });
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ data: { cartCount: 0 } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    vi.stubGlobal('fetch', fetchMock);

    // Drive the hold deterministically: hold the animation frame instead of
    // running it, so the clock can be moved past the hold duration by hand.
    let clock = 0;
    let frame: FrameRequestCallback | null = null;
    vi.spyOn(performance, 'now').mockImplementation(() => clock);
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
        frame = cb;

        return 1;
    });
    vi.stubGlobal('cancelAnimationFrame', () => {
        frame = null;
    });

    render(<StoreCart />);
    const remove = screen.getByRole('button', { name: 'Remove product' });

    // A tap that is not held deletes nothing.
    fireEvent.pointerUp(remove);
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByText('FC 27 Coins')).toBeVisible();

    fireEvent.pointerDown(remove);
    expect(frame).not.toBeNull();

    clock = 10_000;
    act(() => {
        frame?.(10_000);
    });

    await waitFor(() =>
        expect(screen.queryByText('FC 27 Coins')).not.toBeInTheDocument(),
    );
    // Gone from the page, but nothing has been sent yet - the window is open.
    expect(fetchMock).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Undo' }));

    expect(screen.getByText('FC 27 Coins')).toBeVisible();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(cartCountEvents).toEqual([]);
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

describe('cart coupon field', () => {
    beforeEach(() => {
        vi.mocked(router.reload).mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('reveals the discount code field only when asked, with apply disabled until input', () => {
        render(<StoreCart />);

        // Folded away by default: most orders carry no coupon.
        expect(
            screen.queryByLabelText('Discount code'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Have a discount code?' }),
        );

        const codeInput = screen.getByLabelText('Discount code');
        expect(codeInput).toBeVisible();

        const applyButton = screen.getByRole('button', { name: 'Apply' });
        expect(applyButton).toBeDisabled();

        fireEvent.change(codeInput, { target: { value: 'save20' } });
        expect(applyButton).toBeEnabled();
    });

    it('applies a coupon and reloads the cart props', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        code: 'SAVE20',
                        discountType: 'percent',
                        discountHalalah: 2500,
                    },
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<StoreCart />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Have a discount code?' }),
        );

        fireEvent.change(screen.getByLabelText('Discount code'), {
            target: { value: ' save20 ' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalledWith({ only: ['cart'] });
        });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/en/cart/coupon');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ code: 'SAVE20' });
        expect(init.headers['X-CSRF-TOKEN']).toBe('test-token');
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('surfaces the localized coupon rejection message from the server', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        error: {
                            code: 'coupon_minimum',
                            message: 'SAR 50.00',
                        },
                    }),
                    { status: 422 },
                ),
            ),
        );

        render(<StoreCart />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Have a discount code?' }),
        );

        fireEvent.change(screen.getByLabelText('Discount code'), {
            target: { value: 'BIGSPEND' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        await waitFor(() => {
            expect(document.querySelector('[role="alert"]')).not.toBeNull();
        });

        console.log(
            'ALERTS:',
            Array.from(document.querySelectorAll('[role="alert"]')).map(
                (el) => el.textContent,
            ),
        );
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('shows the applied coupon, its discount line, and the reduced total', () => {
        mockPage.props.cart.coupon = {
            code: 'SAVE20',
            discountType: 'percent',
            discountHalalah: 2500,
        };

        render(<StoreCart />);

        expect(screen.getByText(/Coupon applied/)).toBeVisible();
        expect(screen.getByText('SAVE20')).toBeVisible();
        expect(screen.getByText('Discount')).toBeVisible();
        expect(screen.getByText('-SAR 25.00')).toBeVisible();
        // 125.00 subtotal minus the 25.00 discount.
        expect(screen.getByText('SAR 100.00')).toBeVisible();
        expect(
            screen.queryByLabelText('Discount code'),
        ).not.toBeInTheDocument();
    });

    it('removes the applied coupon and reloads the cart props', async () => {
        mockPage.props.cart.coupon = {
            code: 'SAVE20',
            discountType: 'percent',
            discountHalalah: 2500,
        };
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: { removed: true } }), {
                status: 200,
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<StoreCart />);

        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalledWith({ only: ['cart'] });
        });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/en/cart/coupon');
        expect(init.method).toBe('DELETE');
    });
});

describe('Cart wallet balance at checkout', () => {
    beforeEach(() => {
        mockPage.props.auth.user = { id: 1, name: 'Mohamed' };
        mockPage.props.cartPage.checkout.walletBalanceHalalah = 5000;
        mockPage.props.cart.useWallet = false;
        mockPage.props.cart.coupon = null;
    });

    it('does not display the wallet toggle for guest users', () => {
        mockPage.props.auth.user = null;
        mockPage.props.cartPage.checkout.walletBalanceHalalah = 5000;

        render(<StoreCart />);

        expect(
            screen.queryByRole('checkbox', { name: /wallet/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Use wallet balance/),
        ).not.toBeInTheDocument();
    });

    it('does not display the wallet toggle when user balance is zero', () => {
        mockPage.props.cartPage.checkout.walletBalanceHalalah = 0;

        render(<StoreCart />);

        expect(
            screen.queryByRole('checkbox', { name: /wallet/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Use wallet balance/),
        ).not.toBeInTheDocument();
    });

    it('displays the wallet toggle with formatted balance for logged in users with balance', () => {
        render(<StoreCart />);

        expect(
            screen.getByText('Use wallet balance (SAR 50.00)'),
        ).toBeVisible();
        const checkbox = screen.getByRole('checkbox');
        expect(checkbox).not.toBeChecked();
    });

    it('toggles wallet usage on, sends POST to endpoint, and reloads cart props', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: { use_wallet: true } }), {
                status: 200,
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<StoreCart />);

        const checkbox = screen.getByRole('checkbox');
        fireEvent.click(checkbox);

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalledWith({ only: ['cart'] });
        });

        expect(fetchMock).toHaveBeenCalledWith(
            '/en/cart/wallet',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ use: true }),
            }),
        );
    });

    it('shows wallet deduction line and reduced total when wallet is enabled', () => {
        mockPage.props.cart.useWallet = true;
        // Total is 125.00 SAR (12,500 halalah), wallet balance is 50.00 SAR (5,000 halalah)
        render(<StoreCart />);

        const checkbox = screen.getByRole('checkbox');
        expect(checkbox).toBeChecked();
        expect(screen.getByText('Wallet balance')).toBeVisible();
        expect(screen.getByText('-SAR 50.00')).toBeVisible();
        // 125.00 minus 50.00 = 75.00
        expect(screen.getByText('SAR 75.00')).toBeVisible();
    });

    it('displays breakdown correctly with both coupon discount and wallet deduction', () => {
        mockPage.props.cart.useWallet = true;
        mockPage.props.cart.coupon = {
            code: 'PROMO20',
            discountType: 'fixed',
            discountHalalah: 2500, // 25.00 SAR discount
        };
        // Total 125.00, coupon 25.00, wallet 50.00 -> payable 50.00
        render(<StoreCart />);

        expect(screen.getByText('Discount')).toBeVisible();
        expect(screen.getByText('-SAR 25.00')).toBeVisible();
        expect(screen.getByText('Wallet balance')).toBeVisible();
        expect(screen.getByText('-SAR 50.00')).toBeVisible();
        expect(screen.getByText('SAR 50.00')).toBeVisible();
    });

    it('displays zero payable total when order is fully covered by wallet', () => {
        mockPage.props.cart.useWallet = true;
        mockPage.props.cartPage.checkout.walletBalanceHalalah = 20000; // 200.00 SAR balance covers 125.00 SAR total

        render(<StoreCart />);

        expect(screen.getByText('Wallet balance')).toBeVisible();
        expect(screen.getByText('-SAR 125.00')).toBeVisible();
        expect(screen.getByText('SAR 0.00')).toBeVisible();
    });
});

it('keeps the Arabic credential state RTL without an email identity isolate', () => {
    mockPage.props.direction = 'rtl';
    mockPage.props.locale = 'ar';

    render(<StoreCart />);

    const credentialState = document.querySelector('.store-cart-credentials');

    expect(credentialState?.closest('[dir]')).toHaveAttribute('dir', 'rtl');
    expect(credentialState?.querySelector('[dir="ltr"]')).toBeNull();
});

describe('cart repricing states', () => {
    it('shows the live price with what it was, and a notice above the lines', () => {
        mockPage.props.cart.items[0].priceChanged = true;
        mockPage.props.cart.items[0].previousTotalHalalah = 11_000;

        render(<StoreCart />);

        expect(screen.getByText('Prices updated')).toBeVisible();
        expect(screen.getByText('Was')).toBeVisible();
        expect(screen.getByText('SAR 110.00')).toBeVisible();
    });

    it('marks an unavailable item in place and keeps checkout disabled', () => {
        mockPage.props.auth.user = { id: 1, name: 'Buyer' };
        mockPage.props.cartPage.checkout.phoneVerified = true;
        mockPage.props.cart.items[0].unavailableReason = 'variant_inactive';
        mockPage.props.cart.canCheckout = false;

        render(<StoreCart />);

        expect(screen.getByText('Unavailable')).toBeVisible();
        // Once on the line, once under the dead checkout button.
        expect(
            screen.getAllByText('Remove it to continue to checkout.'),
        ).toHaveLength(2);
        expect(
            screen.getByRole('button', { name: 'Continue to secure payment' }),
        ).toBeDisabled();
    });

    it('asks for confirmation when the server reprices, and pays the new total', async () => {
        mockPage.props.auth.user = { id: 1, name: 'Buyer' };
        mockPage.props.cart.canCheckout = true;
        mockPage.props.cartPage.checkout.phoneVerified = true;

        const posts: RequestInit[] = [];
        const fetchMock = vi.fn(
            (input: RequestInfo | URL, init?: RequestInit) => {
                if (String(input).endsWith('/credentials')) {
                    return Promise.resolve(new Response('{}', { status: 404 }));
                }

                posts.push(init as RequestInit);

                if (posts.length === 1) {
                    return Promise.resolve(
                        new Response(
                            JSON.stringify({
                                error: { code: 'cart_repriced' },
                                repricing: {
                                    couponRemoved: true,
                                    orderTotalHalalah: 13_000,
                                    payableHalalah: 13_000,
                                    previousOrderTotalHalalah: 12_500,
                                    previousPayableHalalah: 12_500,
                                },
                            }),
                            { status: 422 },
                        ),
                    );
                }

                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            data: {
                                orderUrl:
                                    '/en/orders/01K00000000000000000000000',
                                paymentUrl: null,
                                status: 'paid',
                            },
                        }),
                        { status: 201 },
                    ),
                );
            },
        );
        vi.stubGlobal('fetch', fetchMock);

        render(<StoreCart />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue to secure payment' }),
        );

        await waitFor(() =>
            expect(screen.getByText('Your total changed')).toBeVisible(),
        );
        expect(screen.getByText('SAR 130.00')).toBeVisible();
        expect(
            screen.getByText(
                'The coupon was removed because the total fell below its minimum.',
            ),
        ).toBeVisible();

        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm and pay' }),
        );

        await waitFor(() => expect(posts).toHaveLength(2));

        // The confirmed figures go out, not the stale ones the page still shows.
        const headers = posts[1].headers as Record<string, string>;

        expect(headers['X-Expected-Order-Total-Halalah']).toBe('13000');
        expect(headers['X-Expected-Total-Halalah']).toBe('13000');
    });
});
