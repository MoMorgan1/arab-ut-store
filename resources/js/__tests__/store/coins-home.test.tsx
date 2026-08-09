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
        options: {
            normal: 'Normal',
            fast: 'Fast',
        },
    },
    amount_copy: {
        title: 'Choose the amount',
        help: 'Enter an amount in the allowed increments.',
        label: 'Amount',
        preset_label: 'Quick amounts',
        slider_label: 'Choose the Coins amount',
        minimum_label: 'Minimum',
        maximum_label: 'Maximum',
        clamped: 'Amount adjusted to this delivery limit.',
    },
    actions: {
        continue: 'Continue',
        back: 'Back',
        restart: 'Start again',
    },
    quote: {
        title: 'Your live quote',
        loading: 'Checking the current price',
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
        checkoutCurrency: 'SAR',
        direction: 'ltr',
        displayCurrencies: ['SAR', 'USD'],
        displayCurrency: 'SAR',
        locale: 'en',
        platforms,
        product: {
            imageUrl: '/images/store/coins/ut-coin-80.webp',
            name: 'FC 27 Coins',
            publicId: '01K00000000000000000000000',
        },
        quoteUrl: '/en/coins/quote',
        status: 'available',
        store,
        ui: {
            brand: 'Arab UT',
            checkout_notice:
                'All final prices and checkout are in Saudi Riyal (SAR).',
            currency_selector: 'Choose display currency',
            home_title: 'Home',
            language: 'العربية',
            skip_to_content: 'Skip to content',
            store_tools: 'Store tools',
        },
    };
}

function quoteResponse(
    amountHalalah: number,
    platform: 'playstation' | 'pc' = 'pc',
    quantity = 50_000,
    delivery: 'normal' | 'fast' | null = platform === 'pc' ? null : 'fast',
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

    it('omits unsupported credential and commerce controls', () => {
        render(<StoreHome />);

        expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', {
                name: /cart|checkout|buy|order/i,
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /cart|checkout|buy|order/i,
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
        const fiveMillion = screen.getByRole('button', { name: '5M' });
        const orderedControls = [
            amountInput,
            ...quickChips,
            range,
            ...sliderLabels,
            ...adjustments,
            quotePanel,
        ];

        expect(quickChips).toHaveLength(5);
        expect(sliderLabels).toHaveLength(2);
        expect(adjustments).toHaveLength(8);
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

        expect(amountInput).toHaveValue('500000');
        expect(screen.getByRole('button', { name: '+1M' })).toBeVisible();
        expect(screen.getByRole('button', { name: '-1M' })).toBeVisible();
    });

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
        expect(amountInput).toHaveValue('500000');
        expect(range).toHaveValue('500000');

        fireEvent.change(range, { target: { value: '1000000' } });
        expect(amountInput).toHaveValue('1000000');

        fireEvent.focus(amountInput);
        fireEvent.change(amountInput, { target: { value: '750,000 coins' } });
        expect(amountInput).toHaveValue('750000');
        fireEvent.blur(amountInput);
        expect(range).toHaveValue('750000');

        fireEvent.click(screen.getByRole('button', { name: '+1M' }));
        expect(amountInput).toHaveValue('1750000');
        expect(range).toHaveValue('1750000');
        fireEvent.click(screen.getByRole('button', { name: '-1M' }));
        expect(amountInput).toHaveValue('750000');
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
        expect(amountInput).toHaveValue('55000');
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
        expect(amountInput).toHaveValue('50000');

        fireEvent.click(screen.getByRole('button', { name: '1M' }));
        fireEvent.click(screen.getByRole('button', { name: '+1M' }));
        expect(amountInput).toHaveValue('2000000');
        fireEvent.click(screen.getByRole('button', { name: '+1M' }));
        expect(amountInput).toHaveValue('2000000');
    });

    it('debounces a range drag to one quote for its final quantity', async () => {
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

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const request = new URL(
            String(fetchMock.mock.calls[0][0]),
            'https://arab-ut.test',
        );
        expect(request.searchParams.get('quantity')).toBe('1000000');
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

            expect(fetchMock).toHaveBeenCalledTimes(1);
            const request = new URL(
                String(fetchMock.mock.calls[0][0]),
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
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fireEvent.click(increment);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(screen.getByText((text) => text.includes('6.00'))).toBeVisible();
        expect(fetchMock).toHaveBeenCalledTimes(1);
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

    it('focuses the amount heading when PC skips delivery and the platform heading after restart', async () => {
        render(<StoreHome />);
        selectPlatform('PC');

        expect(screen.getByText(store.amount_copy.title)).toHaveFocus();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        fireEvent.click(
            screen.getByRole('button', { name: store.actions.restart }),
        );

        expect(screen.getByText(store.platform.title)).toHaveFocus();
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
        expect(screen.getByLabelText('Step 2 of 2')).toBeVisible();
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

        expect(screen.getByLabelText('Step 3 of 3')).toBeVisible();
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
            product: null,
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
