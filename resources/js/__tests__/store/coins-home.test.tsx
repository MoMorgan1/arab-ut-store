import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
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
        badge: 'FC 27 services for players worldwide',
        title: 'Arab UT',
        accent: 'Ultimate Team Coins',
        subtitle: 'Choose a platform, delivery route, and amount.',
        cta: 'Check price',
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
            presets: [50_000, 100_000, 500_000, 1_000_000],
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
    it('renders the exact wordmark and no forbidden commerce or proof UI', () => {
        render(<StoreHome />);

        expect(screen.getAllByText('Arab UT')).toHaveLength(2);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Arab UT Ultimate Team Coins',
        );
        expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /cart|checkout/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /cart|checkout|buy|order/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/review|customers|orders/i),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(
                'All final prices and checkout are in Saudi Riyal (SAR).',
            ),
        ).not.toBeInTheDocument();
    });

    it.each([
        {
            cta: 'Check price',
            direction: 'ltr' as const,
            locale: 'en' as const,
        },
        {
            cta: 'شوف السعر',
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

    it('keeps an active amount preset as a safe no-op during debounce and after success', async () => {
        const fetchMock = vi.fn((input: RequestInfo | URL) =>
            Promise.resolve(quoteResponseForRequest(input)),
        );

        vi.stubGlobal('fetch', fetchMock);
        render(<StoreHome />);
        selectPlatform('PC');

        const activePreset = screen.getByRole('button', {
            name: '50,000',
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
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
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
        expect(
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
        ).toHaveAttribute('min', '50000');
        expect(
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
        ).toHaveAttribute('max', '20000000');
        expect(
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
        ).toHaveAttribute('step', '10000');
    });

    it('clamps an excessive fast amount when delivery changes to normal and announces it', () => {
        render(<StoreHome />);
        selectPlatform('PS / Xbox');
        fireEvent.click(screen.getByRole('radio', { name: 'Fast' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );

        fireEvent.change(
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
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
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
        ).toHaveValue(2_000_000);
    });

    it('clamps and announces a 20M fast-console amount when switching to PC', () => {
        render(<StoreHome />);
        selectPlatform('PS / Xbox');
        fireEvent.click(screen.getByRole('radio', { name: 'Fast' }));
        fireEvent.click(
            screen.getByRole('button', { name: store.actions.continue }),
        );
        fireEvent.change(
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
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
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
        ).toHaveValue(2_000_000);
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
            screen.getByRole('spinbutton', { name: store.amount_copy.label }),
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
