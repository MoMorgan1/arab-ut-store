import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { sliderStops } from '@/lib/coins-quantity';

import StoreHome from '@/pages/store/home';

/**
 * The amount field renders a grouped, locale-formatted number, so the separator
 * is whatever `Intl` chose for the active locale — a comma, an Arabic thousands
 * mark, or a non-breaking space. Compare the digits and let the formatting be.
 */
function expectAmountDigits(input: HTMLElement, digits: string) {
    const value = (input as HTMLInputElement).value ?? '';
    expect(value.replace(/\D/g, '')).toBe(digits);
}

const mockPage = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

const store = {
    seo_title: 'FC 27 Coins',
    hero: {
        badge: 'Everything you need for FC 27, all in one place.',
        title: 'FIFA 27 Coins',
        accent: 'At the best prices',
        subtitle:
            'Fast, secure FIFA 27 Coins delivery to your account — backed by our full guarantee.',
        cta: 'Choose your Coins',
        services_cta: 'Explore other services',
        proof_label: 'Store proof',
        stats: [],
    },
    coins_section: {
        tag: 'FC 27 Coins',
        title: 'Buy FIFA 27 Coins',
        intro: 'Choose your platform, delivery type, and amount, then complete your order in minutes.',
    },
    availability: {
        title: 'Pricing is unavailable',
        body: 'The order desk will reopen when current pricing is ready.',
    },
    progress: {
        platform: 'Platform',
        delivery: 'Delivery',
        amount: 'Amount',
        credentials: 'EA details',
        summary: 'Summary',
    },
    platform: {
        title: 'Choose your platform',
        options: {
            playstation: 'PS / Xbox',
            pc: 'PC',
        },
        descriptions: {
            playstation: 'PlayStation and Xbox',
            pc: 'PC',
        },
    },
    delivery: {
        title: 'Choose delivery',
        help: 'Select the route for this console order.',
        eta: ':minutes minutes per million',
        badges: {
            normal: 'Lower cost',
            fast: 'Recommended',
        },
        maximum: 'Up to :maximum',
        options: {
            normal: 'Normal',
            fast: 'Fast',
        },
    },
    amount_copy: {
        title: 'Choose the amount',
        help: 'Enter the amount you want.',
        label: 'Amount',
        preset_label: 'Quick amounts',
        slider_label: 'Choose the Coins amount',
        minimum_label: 'Minimum',
        maximum_label: 'Maximum',
        clamped: 'Amount adjusted to this delivery limit.',
        normal_delivery_suggestion:
            'Fast delivery supports more than 2M Coins.',
        switch_to_fast: 'Switch to Fast',
    },
    credentials: {
        title: 'EA account details',
        trust: 'Encrypted and removed automatically.',
        email: 'EA email',
        password: 'EA password',
        show_password: 'Show password',
        hide_password: 'Hide password',
        backup_codes: 'EA backup codes',
        backup_code: 'Backup code :number',
        backup_help: 'Enter three different codes.',
        current_balance: 'Current Coins balance',
        current_balance_help: 'Required for Fast console delivery.',
        companion_market_open: 'Transfer Market is open in EA Companion',
        market_guide: 'How to check the Transfer Market',
        market_open_label: 'Open Transfer Market example',
        market_closed_label: 'Closed Transfer Market example',
        market_modal: {
            close: 'Close',
            badge: 'How do I check the market?',
            title: 'Check your Transfer Market status',
            subtitle: 'Follow these steps before completing your order.',
            steps: [],
            open_badge: 'Open',
            open_description: 'Open market example.',
            closed_badge: 'Locked',
            closed_description: 'Locked market example.',
            note: 'Play for several days if the market is locked.',
        },
        policy_agree_prefix: 'I confirm my details and agree to the ',
        policy_agree_join: ' and the ',
        policy_agree_suffix: '.',
        terms_link: 'Terms',
        warranty_link: 'Warranty',
        required_email: 'Enter a valid EA email.',
        required_password: 'Enter your EA password.',
        required_code: 'Enter an 8-digit backup code.',
        duplicate_code: 'Each code must be different.',
        required_balance: 'Enter your current Coins balance.',
        required_companion: 'Confirm that the Transfer Market is open.',
        required_policy: 'Accept the policies to continue.',
        clear: 'Cancel and clear details',
    },
    summary: {
        title: 'Review and add',
        service: 'Service',
        service_value: 'FC 27 Coins',
        platform: 'Platform',
        delivery: 'Delivery',
        delivery_pc: 'Not required for PC',
        quantity: 'Quantity',
        total: 'Authoritative total',
        credentials_ready: 'EA details ready securely',
        add: 'Add to cart',
        adding: 'Adding to cart…',
        retry: 'Try adding again',
        transport_error: 'Connection interrupted. Try again.',
        validation_error: 'Review the EA details.',
        conflict_error: 'Start a new submission.',
        unavailable_error: 'Pricing unavailable.',
        generic_error: 'Could not add Coins.',
    },
    actions: {
        continue: 'Continue',
        back: 'Back',
    },
    quote: {
        title: 'Your live quote',
        loading: 'Checking the current price',
        refreshing: 'Refreshing price…',
        total: 'Total',
        unavailable: 'Pricing is unavailable right now.',
        validation_error: 'Check the selected amount and try again.',
    },
    units: {
        coins: 'Coins',
        million: 'million',
    },
    accessibility: {
        steps: 'Step :current of :total',
        selection: 'Selected: :value',
        live: ':message',
    },
};

const platforms = [
    {
        value: 'playstation',
        label: 'PlayStation and Xbox',
        iconUrls: ['/ps.webp', '/xbox.webp'],
        maximum: 20_000_000,
        deliveries: [
            {
                value: 'normal',
                label: 'Normal',
                maximum: 2_000_000,
                minutesPerMillion: 150,
            },
            {
                value: 'fast',
                label: 'Fast',
                maximum: 20_000_000,
                minutesPerMillion: 45,
            },
        ],
    },
    {
        value: 'pc',
        label: 'PC',
        iconUrls: ['/pc.svg'],
        maximum: 2_000_000,
        deliveries: [],
    },
] as const;

function scheduleTotals(maximum: number): number[] {
    return scheduleQuantities(maximum).map((_, index) => 600 + index * 100);
}

const SCHEDULE_TIERS = [
    { upTo: 500_000, step: 10_000 },
    { upTo: 2_000_000, step: 50_000 },
    { upTo: 20_000_000, step: 250_000 },
];

function scheduleQuantities(maximum: number): number[] {
    return sliderStops(50_000, SCHEDULE_TIERS, maximum);
}

function quoteSchedules() {
    const shared = {
        displayCurrency: 'SAR',
        minimum: 50_000,
        pricedAt: '2026-08-10T12:00:00Z',
        priceVersion: 1,
        productId: '01K00000000000000000000000',
    };

    return {
        pc: {
            ...shared,
            delivery: null,
            displayTotalsMinor: scheduleTotals(2_000_000),
            market: 'pc' as const,
            maximum: 2_000_000,
            quantities: scheduleQuantities(2_000_000),
            platform: 'pc' as const,
            totalsHalalah: scheduleTotals(2_000_000),
            variantId: '01K00000000000000000000001',
        },
        'playstation:fast': {
            ...shared,
            delivery: 'fast' as const,
            displayTotalsMinor: scheduleTotals(20_000_000),
            market: 'console' as const,
            maximum: 20_000_000,
            quantities: scheduleQuantities(20_000_000),
            platform: 'playstation' as const,
            totalsHalalah: scheduleTotals(20_000_000),
            variantId: '01K00000000000000000000002',
        },
        'playstation:normal': {
            ...shared,
            delivery: 'normal' as const,
            displayTotalsMinor: scheduleTotals(2_000_000),
            market: 'console' as const,
            maximum: 2_000_000,
            quantities: scheduleQuantities(2_000_000),
            platform: 'playstation' as const,
            totalsHalalah: scheduleTotals(2_000_000),
            variantId: '01K00000000000000000000003',
        },
    };
}

function pageProps() {
    return {
        amount: {
            tiers: [
                { upTo: 500_000, step: 10_000 },
                { upTo: 2_000_000, step: 50_000 },
                { upTo: 20_000_000, step: 250_000 },
            ],
            minimum: 50_000,
            roundingUnit: 5_000,
            presets: [50_000, 100_000, 500_000, 1_000_000],
        },
        auth: {
            user: { id: 7, name: 'Player', email: 'store@example.com' },
        },
        cartCount: 0,
        coinsCart: {
            addUrl: '/en/cart/items/coins',
            initialSelection: null,
        },
        direction: 'ltr' as const,
        displayCurrencies: ['SAR'],
        displayCurrency: 'SAR',
        locale: 'en' as const,
        platforms,
        quoteSchedules: quoteSchedules(),
        quoteUrl: '/en/coins/quote',
        status: 'available' as const,
        store,
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
            whatsappUrl: 'https://wa.me/966500000000',
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
            currency_selector: 'Choose display currency',
            home_title: 'Home',
            language: 'العربية',
            skip_to_content: 'Skip to content',
            store_tools: 'Store tools',
            header: {
                primary_navigation: 'Primary navigation',
                preferences: 'Display preferences',
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
    };
}

beforeEach(() => {
    vi.useFakeTimers();
    mockPage.props = pageProps();
    mockPage.url = '/en';
});

afterEach(() => {
    vi.useRealTimers();
    cleanup();
    window.history.pushState({}, '', '/en');
});

describe('Coins configurator URL prefilling', () => {
    it('prefills PC platform and valid quantity, landing on Amount step with live quote', () => {
        window.history.pushState({}, '', '/en?platform=pc&quantity=500000');
        render(<StoreHome />);

        expect(screen.getByText('Enter the amount you want.')).toBeVisible();
        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        expectAmountDigits(amountInput, '500000');
        expect(
            screen.getByRole('region', { name: store.quote.title }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: store.actions.back }),
        ).toBeVisible();
    });

    it('prefills PlayStation, fast delivery, and quantity, landing on Amount step', () => {
        window.history.pushState(
            {},
            '',
            '/en?platform=playstation&delivery=fast&quantity=1000000',
        );
        render(<StoreHome />);

        expect(screen.getByText('Enter the amount you want.')).toBeVisible();
        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        expectAmountDigits(amountInput, '1000000');

        const backButton = screen.getByRole('button', {
            name: store.actions.back,
        });
        fireEvent.click(backButton);

        expect(screen.getByText(store.delivery.title)).toHaveFocus();
    });

    it('prefills PlayStation without delivery, landing on Delivery step', () => {
        window.history.pushState({}, '', '/en?platform=playstation');
        render(<StoreHome />);

        expect(screen.getByText(store.delivery.title)).toBeVisible();
        expect(screen.getByRole('radio', { name: 'Fast' })).toBeVisible();
        expect(screen.getByRole('radio', { name: 'Normal' })).toBeVisible();
    });

    it('degrades invalid quantity exceeding platform maximum to default minimum', () => {
        window.history.pushState({}, '', '/en?platform=pc&quantity=50000000');
        render(<StoreHome />);

        expect(screen.getByText('Enter the amount you want.')).toBeVisible();
        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        expectAmountDigits(amountInput, '50000');
    });

    it('degrades invalid quantity below minimum or non-step to default minimum', () => {
        window.history.pushState({}, '', '/en?platform=pc&quantity=35000');
        render(<StoreHome />);

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        expectAmountDigits(amountInput, '50000');
    });

    it('never prefills secret credential fields from URL parameters', async () => {
        window.history.pushState(
            {},
            '',
            '/en?platform=pc&quantity=500000&eaEmail=attacker@evil.test&eaPassword=secret&backupCodes[0]=12345678',
        );
        render(<StoreHome />);

        expect(screen.getByText('Enter the amount you want.')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        expect(
            screen.getByRole('heading', { name: store.credentials.title }),
        ).toBeVisible();
        expect(
            screen.getByRole('textbox', { name: store.credentials.email }),
        ).toHaveValue('');
        expect(screen.getByLabelText(store.credentials.password)).toHaveValue(
            '',
        );
        expect(document.body.textContent).not.toContain('attacker@evil.test');
        expect(document.body.textContent).not.toContain('secret');
    });

    it('does not throw on hostile URL input and degrades safely to platform step', () => {
        window.history.pushState(
            {},
            '',
            '/en?platform=%E0%A4%A&quantity=abc&__proto__=polluted',
        );
        render(<StoreHome />);

        expect(
            screen.getByRole('group', { name: store.platform.title }),
        ).toBeVisible();
    });

    it('keeps controls fully editable after prefilling without fighting user edits', () => {
        window.history.pushState({}, '', '/en?platform=pc&quantity=500000');
        render(<StoreHome />);

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        expectAmountDigits(amountInput, '500000');

        fireEvent.click(screen.getByRole('button', { name: '1M' }));
        expectAmountDigits(amountInput, '1000000');
    });
});
