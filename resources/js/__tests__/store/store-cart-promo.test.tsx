import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import StoreCart from '@/pages/store/cart';
import type { StoreCartItem, StoreCartPageProps } from '@/types/store-shell';

const mockPage = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/cart',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { reload: vi.fn() },
    usePage: () => mockPage,
}));

const promotedItem: StoreCartItem = {
    configuration: {
        market: 'console',
        platform: 'playstation',
        price_version: 3,
        quoted_at: '2026-08-10T12:00:00+00:00',
        service_type: 'objectives',
    },
    credentials: null,
    credentialsUrl: null,
    deleteUrl: '/en/cart/items/01K00000000000000000000000',
    id: '01K00000000000000000000000',
    product: {
        imageUrl: null,
        name: 'Promoted objectives pack',
        serviceType: 'objectives',
    },
    promotion: {
        badge: 'خصم 20%',
        discountHalalah: 2_500,
    },
    previousTotalHalalah: null,
    priceChanged: false,
    quantity: 1,
    requiresCredentials: false,
    totalHalalah: 12_500,
    unavailableReason: null,
    unitPriceHalalah: 12_500,
};

function cartProps(items: StoreCartItem[]): StoreCartPageProps['cart'] {
    return {
        canCheckout: true,
        count: items.length,
        currency: 'SAR',
        items,
        coupon: null,
        useWallet: false,
    };
}

beforeEach(() => {
    const base = baseProps();

    mockPage.props = { ...base, cart: cartProps([{ ...promotedItem }]) };
});

afterEach(() => {
    cleanup();
});

it('shows the promotion badge with the struck-through line total on cart rows', () => {
    render(<StoreCart />);

    const badge = screen.getByText('خصم 20%');

    expect(badge).toBeVisible();
    expect(badge).toHaveClass('store-promo-badge');
    expect(screen.getByText(/125\.00/).closest('del')).toHaveClass(
        'store-price-compare',
    );
    expect(screen.getAllByText(/SAR 100\.00/).length).toBeGreaterThan(0);
});

it('keeps the checkout summary payable amount on the promoted totals', () => {
    render(<StoreCart />);

    // Line total 125.00 − promotion 25.00 = 100.00 payable.
    const summary = screen.getByRole('complementary', {
        name: 'Continue to secure payment',
    });

    expect(summary.textContent).toContain('Order total');
    expect(summary.textContent).toContain('100.00');
    expect(summary.textContent).not.toContain('125.00');
});

it('renders plain rows without promotion data', () => {
    const plain: StoreCartItem = {
        ...promotedItem,
        id: '01K00000000000000000000009',
        promotion: null,
    };

    mockPage.props = {
        ...baseProps(),
        cart: cartProps([plain]),
    };

    render(<StoreCart />);

    expect(screen.queryByText('خصم 20%')).not.toBeInTheDocument();
    expect(screen.queryAllByRole('deletion')).toHaveLength(0);
});

function baseProps() {
    return {
        auth: { user: null as { id: number; name: string } | null },
        cartCount: 1,
        cartPage: {
            checkout: {
                canCheckout: true,
                checkoutUrl: '/en/checkout/paylink',
                couponApplyUrl: '/en/cart/coupon',
                couponRemoveUrl: '/en/cart/coupon',
                loginUrl: '/en/login',
                phoneCodeUrl: '/en/checkout/phone/code',
                phoneVerified: true,
                phoneVerifyUrl: '/en/checkout/phone/verify',
            },
            translations: translations(),
        },
        direction: 'ltr',
        displayCurrencies: ['SAR'],
        displayCurrency: 'SAR',
        locale: 'en' as const,
        storeShell: storeShell(),
        ui: uiTranslations(),
    };
}

function translations(): Record<string, string> {
    return {
        title: 'Your cart',
        eyebrow: 'Arab UT',
        empty_title: 'Your cart is empty',
        empty_description: 'Explore our services.',
        browse_coins: 'Browse Coins services',
        items_heading: 'Your services',
        summary_title: 'Order summary',
        checkout_progress: 'Checkout progress',
        step_cart: 'Cart',
        step_payment: 'Secure payment',
        service: 'Service',
        coins_service: 'FC 27 Coins',
        platform: 'Platform',
        platform_playstation: 'PS / Xbox',
        platform_playstation_manual: 'PS (manual)',
        platform_pc: 'PC',
        launcher: 'Launcher',
        launcher_ea_app: 'EA app',
        launcher_steam: 'Steam',
        delivery: 'Delivery',
        delivery_normal: 'Normal',
        delivery_fast: 'Fast',
        delivery_pc: 'Not required for PC',
        quantity: 'Coins quantity',
        completions: 'Completions',
        rank: 'Target rank',
        rank_value: 'Rank :rank',
        urgent: 'Urgent',
        urgent_yes: 'Yes',
        urgent_no: 'No',
        matches_played: 'Matches played',
        from_division: 'From division',
        to_division: 'To division',
        division_elite: 'Elite',
        coins_unit: 'Coins',
        total: 'Total',
        credentials: 'EA details',
        credentials_ready: 'Stored securely',
        credentials_missing: 'EA details need to be entered again.',
        account_details_ready: 'Account details ready',
        squad_image_ready: 'Squad image ready',
        fulfillment_missing: 'Fulfillment details are missing.',
        backup_codes: ':count backup codes stored',
        backup_code: 'Backup code :number',
        ea_email: 'EA email',
        ea_password: 'EA password',
        current_balance: 'Current Coins balance',
        credentials_show: 'Show EA details',
        credentials_hide: 'Hide EA details',
        edit_credentials: 'Edit EA details',
        save_credentials: 'Save EA details',
        cancel_edit: 'Cancel',
        credentials_saved: 'EA details saved',
        credentials_load_error: 'EA details could not be loaded.',
        credentials_save_error: 'EA details could not be saved.',
        remove_item: 'Remove product',
        remove_confirm: 'Confirm removal',
        remove_cancel: 'Keep product',
        remove_error: 'The product could not be removed.',
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
        phone_delivery_error: 'The WhatsApp code could not be sent right now.',
        order_total: 'Order total',
        coupon_label: 'Discount code',
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
    };
}

function storeShell() {
    return {
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
    };
}

function uiTranslations() {
    return {
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
    };
}
