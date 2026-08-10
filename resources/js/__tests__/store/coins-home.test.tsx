import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import StoreHome from '@/pages/store/home';

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
            'Fast, secure FIFA 27 Coins delivery to your account — backed by our guarantee or a refund.',
        cta: 'Choose your Coins',
        proof_label: 'Store proof',
        stats: [
            { value: '+8,877', label: 'Customers served' },
            { value: '+29,161', label: 'Completed orders' },
            { value: '30B+', label: 'Coins delivered' },
            { value: '99.9%', label: 'Security rate' },
        ],
    },
    coins_section: {
        tag: 'FC 27 Coins',
        title: 'Choose your Coins package',
        intro: 'A direct quote for your selection.',
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
        help: 'Choose the combined console market or PC.',
        options: {
            playstation: 'PS / Xbox',
            pc: 'PC',
        },
        descriptions: {
            playstation: 'PlayStation and Xbox',
            pc: 'Computer',
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
        backup_help: 'Enter five different codes.',
        required_email: 'Enter a valid EA email.',
        required_password: 'Enter your EA password.',
        required_code: 'Enter an 8-digit backup code.',
        duplicate_code: 'Each code must be different.',
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
        iconUrls: [
            '/images/store/platforms/ps-logo-white-80.webp',
            '/images/store/platforms/xbox-logo-white-80.webp',
        ],
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
        iconUrls: ['/images/store/platforms/pc-logo.svg'],
        maximum: 2_000_000,
        deliveries: [],
    },
] as const;

function availableProps() {
    return {
        amount: {
            increment: 10_000,
            minimum: 50_000,
            presets: [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
        },
        auth: { user: { id: 1, name: 'Player', email: 'player@example.com' } },
        cartCount: 0,
        coinsCart: {
            addUrl: '/en/cart/items/coins',
            initialSelection: null,
            resumeUrl: '/en/cart/items/coins/resume',
        },
        checkoutCurrency: 'SAR',
        direction: 'ltr',
        displayCurrencies: ['SAR', 'USD'],
        displayCurrency: 'SAR',
        locale: 'en',
        platforms,
        quoteUrl: '/en/coins/quote',
        status: 'available',
        store,
        storeShell: {
            homeUrl: '/en',
            coinsUrl: '/en#coins',
            cartUrl: '/en/cart',
            sbcUrl: '/en/sbc',
            futChampionsUrl: '/en/fut-champions',
            accountUrl: '/en/my-account',
            privacyUrl: '/en/privacy',
            returnsUrl: '/en/returns',
            warrantyUrl: '/en/warranty',
            eaBackupCodesUrl: '/en/ea-backup-codes',
            termsUrl: '/en/terms',
            whatsappUrl: 'https://wa.me/966537998099',
            email: 'support@example.com',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'Arab UT',
            checkout_notice:
                'All final prices and checkout are in Saudi Riyal (SAR).',
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
            footer: {
                description: 'Trusted FC 27 services.',
                important_links: 'Important links',
                privacy: 'Privacy Policy',
                returns: 'Returns Policy',
                warranty: 'Warranty and Compensation',
                ea_backup_codes: 'EA Backup Codes',
                terms: 'Terms of Service',
                customer_service: 'Customer service',
                whatsapp: 'WhatsApp support',
                payment_methods: 'Accepted payment methods',
                copyright: 'Copyright © :year Arab UT.',
                ea_disclaimer: 'Independent from EA Sports.',
                exchange_rate_attribution: 'Rates By Exchange Rate API',
            },
        },
    };
}

function quoteResponse(
    amountHalalah: number,
    platform: 'playstation' | 'pc' = 'pc',
    quantity = 50_000,
    delivery: 'normal' | 'fast' | null = platform === 'pc' ? null : 'fast',
    displayAmountMinor = amountHalalah,
    displayCurrency = 'SAR',
): Response {
    return new Response(
        JSON.stringify({
            data: {
                delivery,
                market: platform === 'pc' ? 'pc' : 'console',
                platform,
                pricedAt: '2026-08-09T12:00:00Z',
                productId: '01K00000000000000000000000',
                quantity,
                displayTotal: {
                    amountMinor: displayAmountMinor,
                    currency: displayCurrency,
                },
                total: {
                    amountHalalah,
                    currency: 'SAR',
                },
                variantId: '01K00000000000000000000001',
            },
        }),
        {
            headers: { 'Content-Type': 'application/json' },
            status: 200,
        },
    );
}

function quoteResponseForRequest(
    input: RequestInfo | URL,
    amountHalalah = 600,
): Response {
    const url = new URL(String(input), 'https://arab-ut.test');
    const platform = url.searchParams.get('platform') as 'playstation' | 'pc';
    const delivery = url.searchParams.get('delivery') as
        'normal' | 'fast' | null;

    return quoteResponse(
        amountHalalah,
        platform,
        Number(url.searchParams.get('quantity')),
        delivery,
    );
}

function selectPlatform(label: string) {
    fireEvent.click(screen.getByRole('radio', { name: label }));
    fireEvent.click(
        screen.getByRole('button', { name: store.actions.continue }),
    );
}

function selectConsoleDelivery(label: 'Normal' | 'Fast') {
    selectPlatform('PS / Xbox');
    fireEvent.click(screen.getByRole('radio', { name: label }));
    fireEvent.click(
        screen.getByRole('button', { name: store.actions.continue }),
    );
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((resolver) => {
        resolve = resolver;
    });

    return { promise, resolve };
}

beforeEach(() => {
    mockPage.props = availableProps();
    mockPage.url = '/en';
    vi.useFakeTimers();
    vi.stubGlobal(
        'fetch',
        vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        ),
    );
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.title = '';
});

describe('Coins homepage', () => {
    it('renders the exact hero copy, proof, and primary Coins link', () => {
        render(<StoreHome />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'FIFA 27 Coins At the best prices',
        );
        const proof = screen.getByRole('group', { name: 'Store proof' });
        expect(within(proof).getByText('+8,877')).toBeVisible();
        expect(within(proof).getByText('+29,161')).toBeVisible();
        expect(within(proof).getByText('30B+')).toBeVisible();
        expect(within(proof).getByText('99.9%')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Choose your Coins' }),
        ).toHaveAttribute('href', '#coins');
    });

    it('renders the available configurator from status while ignoring a legacy product value', () => {
        mockPage.props = { ...availableProps(), product: null };

        render(<StoreHome />);

        expect(
            screen.getByRole('group', { name: store.platform.title }),
        ).toBeVisible();
        expect(
            screen.queryByRole('heading', { name: store.availability.title }),
        ).not.toBeInTheDocument();
    });

    it('omits unsupported credential and commerce controls', () => {
        render(<StoreHome />);
        const main = screen.getByRole('main');

        expect(
            within(main).queryByLabelText(/password/i),
        ).not.toBeInTheDocument();
        expect(
            within(main).queryByRole('link', {
                name: /cart|checkout|buy|order/i,
            }),
        ).not.toBeInTheDocument();
        expect(
            within(main).queryByRole('button', {
                name: /cart|checkout|buy|order/i,
            }),
        ).not.toBeInTheDocument();
    });

    it('keeps selection feedback hidden while exposing every progress label', () => {
        render(<StoreHome />);

        fireEvent.click(screen.getByRole('radio', { name: 'PS / Xbox' }));

        expect(screen.getAllByRole('status')).toHaveLength(1);
        const selectionStatus = screen.getByRole('status');
        expect(selectionStatus).toHaveClass('sr-only');
        expect(selectionStatus).not.toBeVisible();

        const progress = screen.getByRole('list', { name: /Step 1 of 5/ });
        expect(
            within(progress).getByText(store.progress.platform),
        ).toBeVisible();
        expect(
            within(progress).getByText(store.progress.delivery),
        ).toBeVisible();
        expect(within(progress).getByText(store.progress.amount)).toBeVisible();
        expect(
            within(progress).getByText(store.progress.credentials),
        ).toBeVisible();
        expect(
            within(progress).getByText(store.progress.summary),
        ).toBeVisible();
    });

    it.each([
        {
            direction: 'ltr' as const,
            help: 'Enter the amount you want.',
            locale: 'en' as const,
        },
        {
            direction: 'rtl' as const,
            help: 'اكتب الكمية اللي تبيها.',
            locale: 'ar' as const,
        },
    ])(
        'renders the exact $locale amount helper without exposing restart',
        ({ direction, help, locale }) => {
            mockPage.props = {
                ...availableProps(),
                direction,
                locale,
                store: {
                    ...store,
                    amount_copy: { ...store.amount_copy, help },
                },
            };

            render(<StoreHome />);
            selectPlatform('PC');

            expect(screen.getByText(help)).toBeVisible();
            expect(
                screen.getByRole('button', { name: store.actions.back }),
            ).toBeVisible();
            expect(
                screen.queryByRole('button', { name: 'Start again' }),
            ).not.toBeInTheDocument();
        },
    );

    it('shows the WordPress delivery annotations and amount quote footer', async () => {
        render(<StoreHome />);
        selectPlatform('PS / Xbox');

        const normalCard = screen
            .getByRole('radio', { name: 'Normal' })
            .closest('label');
        const fastCard = screen
            .getByRole('radio', { name: 'Fast' })
            .closest('label');

        expect(normalCard).not.toBeNull();
        expect(fastCard).not.toBeNull();
        expect(screen.getAllByRole('radio')).toHaveLength(2);
        expect(within(normalCard!).getByText('Lower cost')).toBeVisible();
        expect(within(normalCard!).getByText(/150/)).toBeVisible();
        expect(within(normalCard!).getByText('Up to 2M')).toBeVisible();
        expect(within(fastCard!).getByText('Recommended')).toBeVisible();
        expect(within(fastCard!).getByText(/45/)).toBeVisible();
        expect(within(fastCard!).getByText('Up to 20M')).toBeVisible();

        fireEvent.click(screen.getByRole('radio', { name: 'Fast' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        expect(screen.getByText('Enter the amount you want.')).toBeVisible();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        const quoteResult = document.querySelector(
            '.coins-quote-panel__result',
        );
        const adjustments = document.querySelector('.coins-adjustments');
        const backButton = screen.getByRole('button', {
            name: store.actions.back,
        });
        expect(quoteResult).not.toBeNull();
        expect(adjustments).not.toBeNull();
        expect(
            backButton.compareDocumentPosition(quoteResult!) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(quoteResult?.matches('a, button')).toBe(false);
        expect(quoteResult?.querySelector('a, button')).toBeNull();
        expect(backButton).toBeVisible();
        expect(document.querySelector('.coins-product-reference')).toBeNull();
        expect(
            screen
                .getByRole('main')
                .querySelector(
                    'img[src="/images/store/coins/ut-coin-80.webp"]',
                ),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Start again' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('region', { name: store.quote.title }),
        ).toBeVisible();
    });

    it('allows backward navigation through completed progress steps only', () => {
        render(<StoreHome />);
        selectConsoleDelivery('Fast');

        const amountProgress = screen.getByRole('list', {
            name: /Step 3 of 5/,
        });
        expect(
            within(amountProgress).getByRole('button', {
                name: store.progress.platform,
            }),
        ).toBeVisible();
        const deliveryStep = within(amountProgress).getByRole('button', {
            name: store.progress.delivery,
        });
        expect(
            within(amountProgress).queryByRole('button', {
                name: store.progress.amount,
            }),
        ).not.toBeInTheDocument();

        fireEvent.click(deliveryStep);

        expect(screen.getByText(store.delivery.title)).toHaveFocus();
        const deliveryProgress = screen.getByRole('list', {
            name: /Step 2 of 5/,
        });
        expect(
            within(deliveryProgress).getByRole('button', {
                name: store.progress.platform,
            }),
        ).toBeVisible();
        expect(
            within(deliveryProgress).queryByRole('button', {
                name: store.progress.delivery,
            }),
        ).not.toBeInTheDocument();
        expect(
            within(deliveryProgress).queryByRole('button', {
                name: store.progress.amount,
            }),
        ).not.toBeInTheDocument();
    });

    it.each([
        {
            cta: 'Choose your Coins',
            direction: 'ltr' as const,
            locale: 'en' as const,
        },
        {
            cta: 'اختر كوينزك',
            direction: 'rtl' as const,
            locale: 'ar' as const,
        },
    ])(
        'renders one functional $locale hero CTA linked to the Coins section',
        ({ cta, direction, locale }) => {
            mockPage.props = {
                ...availableProps(),
                direction,
                locale,
                store: {
                    ...store,
                    hero: { ...store.hero, cta },
                },
            };

            render(<StoreHome />);

            const ctas = screen.getAllByRole('link', { name: cta });

            expect(ctas).toHaveLength(1);
            expect(ctas[0]).toHaveAttribute('href', '#coins');
            expect(document.getElementById('coins')).toBeInTheDocument();
        },
    );

    it('renders one combined PS and Xbox card with the audited artwork plus one PC card', () => {
        render(<StoreHome />);

        const consoleRadio = screen.getByRole('radio', { name: 'PS / Xbox' });
        const consoleCard = consoleRadio.closest('label');
        const consoleArtwork = Array.from(
            consoleCard?.querySelectorAll('img') ?? [],
        ).map((image) => image.getAttribute('src'));

        expect(screen.getAllByRole('radio')).toHaveLength(2);
        expect(
            screen.queryByRole('radio', { name: 'Xbox' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('PlayStation and Xbox')).toBeVisible();
        expect(consoleArtwork).toEqual([
            '/images/store/platforms/ps-logo-white-80.webp',
            '/images/store/platforms/xbox-logo-white-80.webp',
        ]);
        expect(
            screen
                .getByRole('radio', { name: 'PC' })
                .closest('label')
                ?.querySelector('img'),
        ).toHaveAttribute('src', '/images/store/platforms/pc-logo.svg');
    });

    it('renders every WordPress amount control in its exact DOM order', () => {
        render(<StoreHome />);
        selectConsoleDelivery('Fast');

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        const quickAmounts = screen.getByRole('group', {
            name: store.amount_copy.preset_label,
        });
        const quickChips = within(quickAmounts).getAllByRole('button');
        const range = screen.getByRole('slider', {
            name: store.amount_copy.slider_label,
        });
        const sliderLabels = Array.from(
            document.querySelectorAll('.coins-slider-labels > span'),
        );
        const adjustments = Array.from(
            document.querySelectorAll('.coins-adjustment'),
        );
        const quotePanel = screen.getByRole('region', {
            name: store.quote.title,
        });
        const backButton = screen.getByRole('button', {
            name: store.actions.back,
        });
        const fiveMillion = screen.getByRole('button', { name: '5M' });
        const orderedControls = [
            amountInput,
            ...quickChips,
            range,
            ...sliderLabels,
            ...adjustments,
            backButton,
            quotePanel,
        ];

        expect(quickChips).toHaveLength(5);
        expect(quickChips.map((chip) => chip.textContent)).toEqual([
            '50K',
            '100K',
            '500K',
            '1M',
            '5M',
        ]);
        expect(sliderLabels).toHaveLength(2);
        expect(
            sliderLabels.map((label) => label.getAttribute('aria-label')),
        ).toEqual(['Minimum: 50K', 'Maximum: 20M']);
        expect(adjustments).toHaveLength(8);
        expect(adjustments.map((adjustment) => adjustment.textContent)).toEqual(
            ['-1M', '-500K', '-100K', '-50K', '+50K', '+100K', '+500K', '+1M'],
        );

        for (const accessibleName of [
            '50K',
            '100K',
            '500K',
            '1M',
            '5M',
            '-1M',
            '-500K',
            '-100K',
            '-50K',
            '+50K',
            '+100K',
            '+500K',
            '+1M',
            store.actions.back,
        ]) {
            expect(
                screen.getByRole('button', { name: accessibleName }),
            ).toBeVisible();
        }

        orderedControls.slice(0, -1).forEach((control, index) => {
            expect(
                control.compareDocumentPosition(orderedControls[index + 1]) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
            ).toBeTruthy();
        });
        expect(range).toHaveAttribute('min', '50000');
        expect(range).toHaveAttribute('max', '20000000');
        expect(range).toHaveAttribute('step', '10000');
        expect(range).toHaveValue('50000');
        expect(fiveMillion).toBeVisible();

        fireEvent.change(range, { target: { value: '500000' } });

        expect(amountInput).toHaveValue('500,000');
        expect(screen.getByRole('button', { name: '+1M' })).toBeVisible();
        expect(screen.getByRole('button', { name: '-1M' })).toBeVisible();
    });

    it.each([
        { direction: 'rtl' as const, locale: 'ar' as const },
        { direction: 'ltr' as const, locale: 'en' as const },
    ])(
        'renders only Latin numeric digits in the $locale customer surface',
        async ({ direction, locale }) => {
            mockPage.props = {
                ...availableProps(),
                direction,
                locale,
            };

            render(<StoreHome />);
            selectPlatform('PC');

            await act(async () => {
                await vi.advanceTimersByTimeAsync(300);
            });

            const amountInput = screen.getByRole('textbox', {
                name: store.amount_copy.label,
            });
            const customerSurface = document.querySelector('.store-shell');

            expect(amountInput).toHaveValue('50,000');
            expect(customerSurface?.textContent).toContain('6.00');
            expect(customerSurface?.textContent).toContain('SAR');
            expect(
                `${amountInput.getAttribute('value') ?? ''} ${customerSurface?.textContent ?? ''}`,
            ).not.toMatch(/[٠-٩]/);
        },
    );

    it.each([
        { delivery: 'Normal' as const, platform: 'PS / Xbox' },
        { delivery: null, platform: 'PC' },
    ])(
        'caps $platform $delivery amounts at 2M and hides the 5M quick chip',
        ({ delivery, platform }) => {
            render(<StoreHome />);

            if (delivery === null) {
                selectPlatform(platform);
            } else {
                selectConsoleDelivery(delivery);
            }

            expect(
                screen.getByRole('slider', {
                    name: store.amount_copy.slider_label,
                }),
            ).toHaveAttribute('max', '2000000');
            expect(screen.getByText('2M')).toBeVisible();
            expect(
                screen.queryByRole('button', { name: '5M' }),
            ).not.toBeInTheDocument();
        },
    );

    it('keeps quick chips, range, typed input, and adjustments synchronized', () => {
        render(<StoreHome />);
        selectConsoleDelivery('Fast');

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        const range = screen.getByRole('slider', {
            name: store.amount_copy.slider_label,
        });

        fireEvent.click(screen.getByRole('button', { name: '500K' }));
        expect(amountInput).toHaveValue('500,000');
        expect(range).toHaveValue('500000');

        fireEvent.change(range, { target: { value: '1000000' } });
        expect(amountInput).toHaveValue('1,000,000');

        fireEvent.focus(amountInput);
        fireEvent.change(amountInput, { target: { value: '750,000 coins' } });
        expect(amountInput).toHaveValue('750,000');
        fireEvent.blur(amountInput);
        expect(range).toHaveValue('750000');

        fireEvent.click(screen.getByRole('button', { name: '+1M' }));
        expect(amountInput).toHaveValue('1,750,000');
        expect(range).toHaveValue('1750000');
        fireEvent.click(screen.getByRole('button', { name: '-1M' }));
        expect(amountInput).toHaveValue('750,000');
        expect(range).toHaveValue('750000');
    });

    it('snaps a typed 55K amount to 60K on blur', () => {
        render(<StoreHome />);
        selectPlatform('PC');

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });

        fireEvent.focus(amountInput);
        fireEvent.change(amountInput, { target: { value: '55000' } });
        expect(amountInput).toHaveValue('55,000');
        fireEvent.blur(amountInput);

        expect(amountInput).toHaveValue('60,000');
        expect(
            screen.getByRole('slider', {
                name: store.amount_copy.slider_label,
            }),
        ).toHaveValue('60000');
    });

    it('restores the last valid quantity when the typed amount is empty on blur', () => {
        render(<StoreHome />);
        selectPlatform('PC');

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });

        fireEvent.click(screen.getByRole('button', { name: '500K' }));
        fireEvent.focus(amountInput);
        fireEvent.change(amountInput, { target: { value: '' } });
        expect(amountInput).toHaveValue('');
        fireEvent.blur(amountInput);

        expect(amountInput).toHaveValue('500,000');
    });

    it('keeps million adjustments bounded at the selected minimum and maximum', () => {
        render(<StoreHome />);
        selectPlatform('PC');

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });

        fireEvent.click(screen.getByRole('button', { name: '-1M' }));
        expect(amountInput).toHaveValue('50,000');

        fireEvent.click(screen.getByRole('button', { name: '1M' }));
        fireEvent.click(screen.getByRole('button', { name: '+1M' }));
        expect(amountInput).toHaveValue('2,000,000');
        fireEvent.click(screen.getByRole('button', { name: '+1M' }));
        expect(amountInput).toHaveValue('2,000,000');
    });

    it('requests immediately for every valid range change', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        );

        vi.stubGlobal('fetch', fetchMock);
        render(<StoreHome />);
        selectPlatform('PC');

        const range = screen.getByRole('slider', {
            name: store.amount_copy.slider_label,
        });
        fireEvent.change(range, { target: { value: '100000' } });
        fireEvent.change(range, { target: { value: '500000' } });
        fireEvent.change(range, { target: { value: '1000000' } });

        expect(fetchMock).toHaveBeenCalledTimes(4);
        const request = new URL(
            String(fetchMock.mock.calls[3][0]),
            'https://arab-ut.test',
        );
        expect(request.searchParams.get('quantity')).toBe('1000000');
    });

    it('keeps comma-grouped Latin digits while editing from every amount control', () => {
        render(<StoreHome />);
        selectConsoleDelivery('Fast');
        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });

        fireEvent.focus(amountInput);
        fireEvent.change(amountInput, { target: { value: '750000' } });
        expect(amountInput).toHaveValue('750,000');
        fireEvent.change(
            screen.getByRole('slider', {
                name: store.amount_copy.slider_label,
            }),
            { target: { value: '1000000' } },
        );
        expect(amountInput).toHaveValue('1,000,000');
        fireEvent.click(screen.getByRole('button', { name: '500K' }));
        expect(amountInput).toHaveValue('500,000');
    });

    it('preserves the logical caret through middle insertion deletion and subsequent typing', () => {
        render(<StoreHome />);
        selectConsoleDelivery('Fast');
        fireEvent.click(screen.getByRole('button', { name: '100K' }));
        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        }) as HTMLInputElement;

        fireEvent.focus(amountInput);
        amountInput.setSelectionRange(1, 1);
        fireEvent.change(amountInput, {
            target: {
                selectionEnd: 2,
                selectionStart: 2,
                value: '1500,000',
            },
        });

        expect(amountInput).toHaveValue('1,500,000');
        expect(amountInput.selectionStart).toBe(3);
        expect(amountInput.selectionEnd).toBe(3);

        fireEvent.change(amountInput, {
            target: {
                selectionEnd: 2,
                selectionStart: 2,
                value: '1,00,000',
            },
        });

        expect(amountInput).toHaveValue('100,000');
        expect(amountInput.selectionStart).toBe(1);
        expect(amountInput.selectionEnd).toBe(1);

        fireEvent.change(amountInput, {
            target: {
                selectionEnd: 2,
                selectionStart: 2,
                value: '1200,000',
            },
        });

        expect(amountInput).toHaveValue('1,200,000');
        expect(amountInput.selectionStart).toBe(3);
        expect(amountInput.selectionEnd).toBe(3);
    });

    it('renders the page-selected EUR display total instead of authoritative SAR', async () => {
        mockPage.props = { ...availableProps(), displayCurrency: 'EUR' };
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    quoteResponse(600, 'pc', 50_000, null, 137, 'EUR'),
                ),
            ),
        );

        render(<StoreHome />);
        selectPlatform('PC');
        await act(async () => await Promise.resolve());

        expect(
            screen.getByText((text) => text.includes('1.37')),
        ).toHaveTextContent('EUR');
        expect(
            screen.queryByText((text) => text.includes('6.00')),
        ).not.toBeInTheDocument();
    });

    it('offers Fast for normal delivery at 1.5M and returns to delivery with Fast selected', () => {
        render(<StoreHome />);
        selectConsoleDelivery('Normal');
        fireEvent.change(
            screen.getByRole('textbox', { name: store.amount_copy.label }),
            { target: { value: '1500000' } },
        );

        const suggestion = screen.getByRole('complementary', {
            name: store.amount_copy.normal_delivery_suggestion,
        });
        const action = within(suggestion).getByRole('button', {
            name: 'Switch to Fast',
        });

        expect(within(suggestion).getAllByRole('button')).toHaveLength(1);
        fireEvent.click(action);

        expect(screen.getByText(store.delivery.title)).toHaveFocus();
        expect(screen.getByRole('radio', { name: 'Fast' })).toBeChecked();
    });

    it.each(['typing', 'slider', 'chip', 'adjustment'] as const)(
        'keeps the successful price visible while %s refreshes its quote',
        async (interaction) => {
            const refreshedQuote = deferred<Response>();
            let requestCount = 0;

            vi.stubGlobal(
                'fetch',
                vi.fn(() => {
                    requestCount += 1;

                    return requestCount === 1
                        ? Promise.resolve(quoteResponse(600, 'pc', 50_000))
                        : refreshedQuote.promise;
                }),
            );

            render(<StoreHome />);
            selectPlatform('PC');

            await act(async () => {
                await vi.advanceTimersByTimeAsync(300);
            });

            expect(
                screen.getByText((text) => text.includes('6.00')),
            ).toBeVisible();

            if (interaction === 'typing') {
                fireEvent.change(
                    screen.getByRole('textbox', {
                        name: store.amount_copy.label,
                    }),
                    { target: { value: '100000' } },
                );
            } else if (interaction === 'slider') {
                fireEvent.change(
                    screen.getByRole('slider', {
                        name: store.amount_copy.slider_label,
                    }),
                    { target: { value: '100000' } },
                );
            } else if (interaction === 'chip') {
                fireEvent.click(screen.getByRole('button', { name: '100K' }));
            } else {
                fireEvent.click(screen.getByRole('button', { name: '+50K' }));
            }

            expect(
                screen.getByText((text) => text.includes('6.00')),
            ).toBeVisible();
            const refreshingStatus = screen.getByText(store.quote.refreshing);
            const retainedPrice = screen.getByText((text) =>
                text.includes('6.00'),
            );

            expect(refreshingStatus).toBeVisible();
            expect(refreshingStatus.closest('[aria-busy="true"]')).toBeNull();
            expect(retainedPrice.closest('[aria-busy="true"]')).not.toBeNull();

            await act(async () => {
                await vi.advanceTimersByTimeAsync(300);
            });

            expect(
                screen.getByText((text) => text.includes('6.00')),
            ).toBeVisible();
            expect(screen.getByText(store.quote.refreshing)).toBeVisible();

            await act(async () => {
                refreshedQuote.resolve(quoteResponse(700, 'pc', 100_000));
                await Promise.resolve();
            });

            expect(
                screen.getByText((text) => text.includes('7.00')),
            ).toBeVisible();
            expect(
                screen.queryByText(store.quote.refreshing),
            ).not.toBeInTheDocument();
        },
    );

    it('clears the previous price when a refresh fails closed', async () => {
        let requestCount = 0;

        vi.stubGlobal(
            'fetch',
            vi.fn(() => {
                requestCount += 1;

                return Promise.resolve(
                    requestCount === 1
                        ? quoteResponse(600, 'pc', 50_000)
                        : new Response(
                              JSON.stringify({
                                  error: {
                                      code: 'coins_pricing_unavailable',
                                      message: 'Unavailable',
                                  },
                              }),
                              { status: 503 },
                          ),
                );
            }),
        );

        render(<StoreHome />);
        selectPlatform('PC');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        fireEvent.click(screen.getByRole('button', { name: '100K' }));
        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByRole('alert')).toHaveTextContent(
            store.quote.unavailable,
        );
        expect(
            screen.queryByText((text) => text.includes('6.00')),
        ).not.toBeInTheDocument();
    });

    it('clears the previous price when typed input becomes invalid', async () => {
        render(<StoreHome />);
        selectPlatform('PC');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        fireEvent.change(
            screen.getByRole('textbox', {
                name: store.amount_copy.label,
            }),
            { target: { value: '55555' } },
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            store.quote.validation_error,
        );
        expect(
            screen.queryByText((text) => text.includes('6.00')),
        ).not.toBeInTheDocument();
    });

    it('never carries a PC price into a new console selection', async () => {
        const consoleQuote = deferred<Response>();
        let requestCount = 0;

        vi.stubGlobal(
            'fetch',
            vi.fn(() => {
                requestCount += 1;

                return requestCount === 1
                    ? Promise.resolve(quoteResponse(600, 'pc', 50_000))
                    : consoleQuote.promise;
            }),
        );

        render(<StoreHome />);
        selectPlatform('PC');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.back }),
        );
        fireEvent.click(screen.getByRole('radio', { name: 'PS / Xbox' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Fast' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        expect(
            screen.queryByText((text) => text.includes('6.00')),
        ).not.toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText(store.quote.loading)).toBeVisible();
        expect(
            screen.queryByText((text) => text.includes('6.00')),
        ).not.toBeInTheDocument();
    });

    it.each([
        { destination: 'Normal', path: 'delivery' as const },
        { destination: 'PC', path: 'platform' as const },
    ])(
        'clamps fast console to $destination before requesting its quote',
        async ({ destination, path }) => {
            const fetchMock = vi.fn((input: RequestInfo | URL) =>
                Promise.resolve(quoteResponseForRequest(input)),
            );

            vi.stubGlobal('fetch', fetchMock);
            render(<StoreHome />);
            selectConsoleDelivery('Fast');
            fireEvent.click(screen.getByRole('button', { name: '5M' }));

            fireEvent.click(
                screen.getByRole('button', { name: store.actions.back }),
            );

            if (path === 'platform') {
                fireEvent.click(
                    screen.getByRole('button', {
                        name: store.actions.back,
                    }),
                );
            }

            fireEvent.click(screen.getByRole('radio', { name: destination }));
            fireEvent.click(
                screen.getByRole('button', {
                    name: store.actions.continue,
                }),
            );

            await act(async () => {
                await vi.advanceTimersByTimeAsync(300);
            });

            expect(fetchMock).toHaveBeenCalledTimes(3);
            const request = new URL(
                String(fetchMock.mock.calls[2][0]),
                'https://arab-ut.test',
            );
            expect(request.searchParams.get('quantity')).toBe('2000000');
        },
    );

    it('keeps an active amount preset as a safe no-op during debounce and after success', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        );

        vi.stubGlobal('fetch', fetchMock);
        render(<StoreHome />);
        selectPlatform('PC');

        const activePreset = screen.getByRole('button', {
            name: '50K',
            pressed: true,
        });

        fireEvent.click(activePreset);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fireEvent.click(activePreset);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(screen.queryByText(store.quote.loading)).not.toBeInTheDocument();
    });

    it('keeps the quote lifecycle alive when an unchanged amount is blurred', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        );

        vi.stubGlobal('fetch', fetchMock);
        render(<StoreHome />);
        selectPlatform('PC');

        const amountInput = screen.getByRole('textbox', {
            name: store.amount_copy.label,
        });
        fireEvent.focus(amountInput);
        fireEvent.blur(amountInput);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fireEvent.focus(amountInput);
        fireEvent.blur(amountInput);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(screen.queryByText(store.quote.loading)).not.toBeInTheDocument();
    });

    it.each(['50,000', '050000'])(
        'normalizes an equivalent %s paste without restarting the quote lifecycle',
        async (equivalentInput) => {
            const fetchMock = vi.fn((input: RequestInfo | URL) =>
                Promise.resolve(quoteResponseForRequest(input)),
            );

            vi.stubGlobal('fetch', fetchMock);
            render(<StoreHome />);
            selectPlatform('PC');

            const amountInput = screen.getByRole('textbox', {
                name: store.amount_copy.label,
            });
            fireEvent.focus(amountInput);
            fireEvent.change(amountInput, {
                target: { value: equivalentInput },
            });
            expect(amountInput).toHaveValue('50,000');
            fireEvent.blur(amountInput);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(300);
            });

            expect(
                screen.getByText((text) => text.includes('6.00')),
            ).toBeVisible();
            expect(fetchMock).toHaveBeenCalledTimes(1);

            fireEvent.focus(amountInput);
            fireEvent.change(amountInput, {
                target: { value: equivalentInput },
            });
            expect(amountInput).toHaveValue('50,000');
            fireEvent.blur(amountInput);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(300);
            });

            expect(
                screen.getByText((text) => text.includes('6.00')),
            ).toBeVisible();
            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(
                screen.queryByText(store.quote.loading),
            ).not.toBeInTheDocument();
        },
    );

    it('keeps the quote lifecycle alive at the minimum adjustment bound', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        );

        vi.stubGlobal('fetch', fetchMock);
        render(<StoreHome />);
        selectPlatform('PC');

        const decrement = screen.getByRole('button', { name: '-1M' });
        fireEvent.click(decrement);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fireEvent.click(decrement);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(screen.queryByText(store.quote.loading)).not.toBeInTheDocument();
    });

    it('keeps the quote lifecycle alive at the maximum adjustment bound', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        );

        vi.stubGlobal('fetch', fetchMock);
        render(<StoreHome />);
        selectPlatform('PC');

        fireEvent.click(screen.getByRole('button', { name: '1M' }));
        const increment = screen.getByRole('button', { name: '+1M' });
        fireEvent.click(increment);
        fireEvent.click(increment);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(3);

        fireEvent.click(increment);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(screen.queryByText(store.quote.loading)).not.toBeInTheDocument();
    });

    it('moves focus through console steps and back to each step heading', () => {
        render(<StoreHome />);

        fireEvent.click(screen.getByRole('radio', { name: 'PS / Xbox' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        expect(screen.getByText(store.delivery.title)).toHaveFocus();

        fireEvent.click(screen.getByRole('radio', { name: 'Normal' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        expect(screen.getByText(store.amount_copy.title)).toHaveFocus();

        fireEvent.click(
            screen.getByRole('button', { name: store.actions.back }),
        );
        expect(screen.getByText(store.delivery.title)).toHaveFocus();

        fireEvent.click(
            screen.getByRole('button', { name: store.actions.back }),
        );
        expect(screen.getByText(store.platform.title)).toHaveFocus();
    });

    it('focuses the amount heading when PC skips delivery', () => {
        render(<StoreHome />);
        selectPlatform('PC');

        expect(screen.getByText(store.amount_copy.title)).toHaveFocus();
    });

    it('uses the canonical playstation parameter for the combined console card and never sends xbox', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        );

        vi.stubGlobal('fetch', fetchMock);

        render(<StoreHome />);
        selectPlatform('PS / Xbox');
        fireEvent.click(screen.getByRole('radio', { name: 'Normal' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        const urls = fetchMock.mock.calls.map(
            ([input]) => new URL(String(input), 'https://arab-ut.test'),
        );

        expect(urls).toHaveLength(1);
        expect(urls[0].searchParams.get('platform')).toBe('playstation');
        expect(urls[0].toString()).not.toContain('xbox');
    });

    it('removes delivery from the PC flow and requests a quote without a delivery parameter', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn((input: RequestInfo | URL) => {
                const url = new URL(String(input), 'https://arab-ut.test');

                if (url.searchParams.has('delivery')) {
                    return Promise.resolve(
                        new Response(
                            JSON.stringify({
                                message: 'Delivery is prohibited.',
                            }),
                            { status: 422 },
                        ),
                    );
                }

                return Promise.resolve(quoteResponse(600));
            }),
        );

        render(<StoreHome />);
        selectPlatform('PC');

        expect(
            screen.queryByText(store.delivery.title),
        ).not.toBeInTheDocument();
        expect(screen.getByText(store.amount_copy.title)).toBeVisible();
        expect(screen.getByLabelText('Step 2 of 4')).toBeVisible();
        expect(
            screen.getByRole('slider', {
                name: store.amount_copy.slider_label,
            }),
        ).toHaveAttribute('max', '2000000');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
    });

    it('shows console delivery before amount and exposes the approved limits', () => {
        render(<StoreHome />);
        selectPlatform('PS / Xbox');

        expect(screen.getByText(store.delivery.title)).toBeVisible();
        expect(screen.getByRole('radio', { name: 'Normal' })).toBeVisible();
        expect(screen.getByRole('radio', { name: 'Fast' })).toBeVisible();

        fireEvent.click(screen.getByRole('radio', { name: 'Fast' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        expect(screen.getByLabelText('Step 3 of 5')).toBeVisible();
        const range = screen.getByRole('slider', {
            name: store.amount_copy.slider_label,
        });
        expect(range).toHaveAttribute('min', '50000');
        expect(range).toHaveAttribute('max', '20000000');
        expect(range).toHaveAttribute('step', '10000');
    });

    it('clamps an excessive fast amount when delivery changes to normal and announces it', () => {
        render(<StoreHome />);
        selectPlatform('PS / Xbox');
        fireEvent.click(screen.getByRole('radio', { name: 'Fast' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        fireEvent.change(
            screen.getByRole('textbox', { name: store.amount_copy.label }),
            { target: { value: '20000000' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.back }),
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Normal' }));

        expect(screen.getByRole('status')).toHaveTextContent(
            store.amount_copy.clamped,
        );

        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );
        expect(
            screen.getByRole('textbox', { name: store.amount_copy.label }),
        ).toHaveValue('2,000,000');
    });

    it('clamps and announces a 20M fast-console amount when switching to PC', () => {
        render(<StoreHome />);
        selectPlatform('PS / Xbox');
        fireEvent.click(screen.getByRole('radio', { name: 'Fast' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: store.amount_copy.label }),
            { target: { value: '20000000' } },
        );

        fireEvent.click(
            screen.getByRole('button', { name: store.actions.back }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.back }),
        );
        fireEvent.click(screen.getByRole('radio', { name: 'PC' }));

        expect(screen.getByRole('status')).toHaveTextContent(
            store.amount_copy.clamped,
        );

        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        expect(
            screen.getByRole('textbox', { name: store.amount_copy.label }),
        ).toHaveValue('2,000,000');
    });

    it('ignores a stale quote response after aborting the previous request', async () => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        let requestIndex = 0;
        const signals: AbortSignal[] = [];

        vi.stubGlobal(
            'fetch',
            vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
                requestIndex += 1;

                if (init?.signal instanceof AbortSignal) {
                    signals.push(init.signal);
                }

                return requestIndex === 1 ? first.promise : second.promise;
            }),
        );

        render(<StoreHome />);
        selectPlatform('PC');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        fireEvent.change(
            screen.getByRole('textbox', { name: store.amount_copy.label }),
            { target: { value: '100000' } },
        );

        expect(signals[0]).toBeDefined();
        expect(signals[0].aborted).toBe(true);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
            second.resolve(quoteResponse(700, 'pc', 100_000));
            await Promise.resolve();
        });

        expect(screen.getByText((text) => text.includes('7.00'))).toBeVisible();

        await act(async () => {
            first.resolve(quoteResponse(400));
            await Promise.resolve();
        });

        expect(
            screen.queryByText((text) => text.includes('4.00')),
        ).not.toBeInTheDocument();
        expect(screen.getByText((text) => text.includes('7.00'))).toBeVisible();
    });

    it('fails closed when a 200 quote does not match the requested selection', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    quoteResponse(600, 'playstation', 50_000, 'normal'),
                ),
            ),
        );

        render(<StoreHome />);
        selectPlatform('PC');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByRole('alert')).toHaveTextContent(
            store.quote.unavailable,
        );
        expect(
            screen.queryByText((text) => text.includes('6.00')),
        ).not.toBeInTheDocument();
    });

    it('fails closed when homepage pricing is unavailable', () => {
        mockPage.props = {
            ...availableProps(),
            status: 'unavailable',
        };

        render(<StoreHome />);

        expect(
            screen.getByRole('heading', { name: store.availability.title }),
        ).toBeVisible();
        expect(screen.getByText(store.availability.body)).toBeVisible();
        expect(screen.queryAllByRole('radio')).toHaveLength(0);
    });

    it('shows the localized unavailable quote state on a fail-closed response', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    new Response(
                        JSON.stringify({
                            error: {
                                code: 'coins_pricing_unavailable',
                                message: 'Unavailable',
                            },
                        }),
                        { status: 503 },
                    ),
                ),
            ),
        );

        render(<StoreHome />);
        selectPlatform('PC');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByRole('alert')).toHaveTextContent(
            store.quote.unavailable,
        );
        expect(
            screen.queryByText((text) => text.includes('6.00')),
        ).not.toBeInTheDocument();
    });
});
