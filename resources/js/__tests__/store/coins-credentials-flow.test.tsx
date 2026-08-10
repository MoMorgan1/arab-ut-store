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
const visitMock = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { visit: visitMock },
    usePage: () => mockPage,
}));

const store = {
    seo_title: 'FC 27 Coins',
    hero: {
        badge: 'FC 27 services',
        title: 'FC 27 Coins',
        accent: 'At the best prices',
        subtitle: 'Fast and secure delivery.',
        cta: 'Choose your Coins',
        proof_label: 'Store proof',
        stats: [],
    },
    coins_section: {
        tag: 'FC 27 Coins',
        title: 'Order Coins',
        intro: 'Choose your order.',
    },
    availability: { title: 'Unavailable', body: 'Try again later.' },
    progress: {
        platform: 'Platform',
        delivery: 'Delivery',
        amount: 'Amount',
        credentials: 'EA details',
        summary: 'Summary',
    },
    platform: {
        title: 'Choose your platform',
        help: 'Choose PS / Xbox or PC.',
        options: { playstation: 'PS / Xbox', pc: 'PC' },
        descriptions: { playstation: 'PlayStation and Xbox', pc: 'Computer' },
    },
    delivery: {
        title: 'Choose delivery',
        help: 'Choose delivery speed.',
        eta: ':minutes minutes per million',
        badges: { normal: 'Lower cost', fast: 'Recommended' },
        maximum: 'Up to :maximum',
        options: { normal: 'Normal', fast: 'Fast' },
    },
    amount_copy: {
        title: 'Choose the amount',
        help: 'Enter the amount you want.',
        label: 'Coins amount',
        preset_label: 'Quick amounts',
        slider_label: 'Choose the Coins amount',
        minimum_label: 'Minimum',
        maximum_label: 'Maximum',
        clamped: 'Amount adjusted.',
        normal_delivery_suggestion: 'Fast supports more Coins.',
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
        backup_help: 'Enter five different 8-digit codes.',
        required_email: 'Enter a valid EA email.',
        required_password: 'Enter your EA password.',
        required_code: 'Enter an 8-digit backup code.',
        duplicate_code: 'Each backup code must be different.',
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
        validation_error: 'Review the highlighted EA details.',
        conflict_error: 'Start a new cart submission.',
        unavailable_error: 'Pricing is unavailable right now.',
        generic_error: 'Could not add Coins to the cart.',
    },
    actions: { continue: 'Continue', back: 'Back' },
    quote: {
        title: 'Your live quote',
        loading: 'Checking the current price',
        refreshing: 'Refreshing price…',
        total: 'Total',
        unavailable: 'Pricing is unavailable right now.',
        validation_error: 'Check the selection.',
    },
    units: { coins: 'Coins', million: 'million' },
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
    const length = (maximum - 50_000) / 10_000 + 1;

    return Array.from({ length }, (_, index) => 600 + index * 100);
}

function quoteSchedules() {
    const shared = {
        displayCurrency: 'SAR',
        increment: 10_000,
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
            market: 'pc',
            maximum: 2_000_000,
            platform: 'pc',
            totalsHalalah: scheduleTotals(2_000_000),
            variantId: '01K00000000000000000000001',
        },
        'playstation:fast': {
            ...shared,
            delivery: 'fast',
            displayTotalsMinor: scheduleTotals(20_000_000),
            market: 'console',
            maximum: 20_000_000,
            platform: 'playstation',
            totalsHalalah: scheduleTotals(20_000_000),
            variantId: '01K00000000000000000000002',
        },
        'playstation:normal': {
            ...shared,
            delivery: 'normal',
            displayTotalsMinor: scheduleTotals(2_000_000),
            market: 'console',
            maximum: 2_000_000,
            platform: 'playstation',
            totalsHalalah: scheduleTotals(2_000_000),
            variantId: '01K00000000000000000000003',
        },
    };
}

function pageProps(authenticated = true) {
    return {
        amount: {
            increment: 10_000,
            minimum: 50_000,
            presets: [50_000, 100_000, 500_000, 1_000_000],
        },
        auth: {
            user: authenticated
                ? { id: 7, name: 'Player', email: 'store@example.com' }
                : null,
        },
        coinsCart: {
            addUrl: '/en/cart/items/coins',
            initialSelection: null,
            resumeUrl: '/en/cart/items/coins/resume',
        },
        direction: 'ltr',
        displayCurrencies: ['SAR'],
        displayCurrency: 'SAR',
        locale: 'en',
        platforms,
        quoteSchedules: quoteSchedules(),
        quoteUrl: '/en/coins/quote',
        status: 'available',
        store,
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
            whatsappUrl: 'https://wa.me/966500000000',
            email: 'support@example.com',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'Arab UT',
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
                exchange_rate_attribution: '',
            },
        },
    };
}

function quoteResponse() {
    return new Response(
        JSON.stringify({
            data: {
                delivery: null,
                displayTotal: { amountMinor: 600, currency: 'SAR' },
                market: 'pc',
                platform: 'pc',
                pricedAt: '2026-08-10T12:00:00Z',
                productId: '01K00000000000000000000000',
                quantity: 50_000,
                total: { amountHalalah: 600, currency: 'SAR' },
                variantId: '01K00000000000000000000001',
            },
        }),
        { headers: { 'Content-Type': 'application/json' }, status: 200 },
    );
}

async function reachAmount() {
    fireEvent.click(screen.getByRole('radio', { name: 'PC' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    await act(async () => {
        await vi.advanceTimersByTimeAsync(300);
    });
}

async function reachCredentials() {
    await reachAmount();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

function fillCredentials() {
    fireEvent.change(screen.getByRole('textbox', { name: 'EA email' }), {
        target: { value: 'player@example.com' },
    });
    fireEvent.change(screen.getByLabelText('EA password'), {
        target: { value: 'opaque EA password' },
    });

    for (let index = 1; index <= 5; index += 1) {
        fireEvent.change(
            screen.getByRole('textbox', { name: `Backup code ${index}` }),
            { target: { value: `1000000${index}` } },
        );
    }
}

beforeEach(() => {
    vi.useFakeTimers();
    visitMock.mockReset();
    mockPage.props = pageProps();
    document.head.innerHTML =
        '<meta name="csrf-token" content="csrf-token-value">';
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve(quoteResponse())),
    );
    vi.stubGlobal('crypto', {
        randomUUID: () => '3dc56ae8-6ed2-4dde-92fd-d170cefa8a3d',
    });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    cleanup();
});

describe('Coins credentials flow', () => {
    it('enables a resumed credentials step from its authoritative schedule', () => {
        mockPage.props = {
            ...pageProps(),
            coinsCart: {
                ...pageProps().coinsCart,
                initialSelection: {
                    delivery: null,
                    platform: 'pc',
                    quantity: 50_000,
                },
            },
        };
        const fetchMock = vi.fn(() => new Promise(() => undefined));
        vi.stubGlobal('fetch', fetchMock);

        render(<StoreHome />);

        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('gates guests at the validated resume URL with safe choices only', async () => {
        mockPage.props = pageProps(false);
        render(<StoreHome />);

        await reachAmount();

        const loginLink = screen.getByRole('link', { name: 'Continue' });
        expect(loginLink).toHaveAttribute(
            'href',
            '/en/cart/items/coins/resume?platform=pc&quantity=50000',
        );
        expect(loginLink.getAttribute('href')).not.toMatch(
            /email|password|backup|credentials/i,
        );
        expect(screen.queryByLabelText('EA password')).not.toBeInTheDocument();
    });

    it('shows four PC progress decisions and five accessible backup-code inputs', async () => {
        render(<StoreHome />);

        await reachCredentials();

        expect(screen.getByLabelText('Step 3 of 4')).toBeVisible();
        expect(screen.queryByText('Delivery')).not.toBeInTheDocument();

        for (let index = 1; index <= 5; index += 1) {
            const code = screen.getByRole('textbox', {
                name: `Backup code ${index}`,
            });
            expect(code).toHaveAttribute('dir', 'ltr');
            expect(code).toHaveAttribute('inputmode', 'numeric');
            expect(code).toHaveAttribute('autocomplete', 'off');
        }
    });

    it('toggles the opaque password and focuses the exact first invalid field', async () => {
        render(<StoreHome />);
        await reachCredentials();

        const password = screen.getByLabelText('EA password');
        expect(password).toHaveAttribute('type', 'password');
        fireEvent.click(screen.getByRole('button', { name: 'Show password' }));
        expect(password).toHaveAttribute('type', 'text');
        expect(
            screen.getByRole('button', { name: 'Hide password' }),
        ).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('textbox', { name: 'EA email' })).toHaveFocus();
        expect(screen.getByText('Enter a valid EA email.')).toHaveAttribute(
            'role',
            'alert',
        );
    });

    it('keeps secrets out of the summary, URL, and browser storage', async () => {
        const localStorageSpy = vi.spyOn(Storage.prototype, 'setItem');
        render(<StoreHome />);
        await reachCredentials();
        fillCredentials();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(
            screen.getByRole('heading', { name: 'Review and add' }),
        ).toBeVisible();
        expect(
            screen.queryByDisplayValue('player@example.com'),
        ).not.toBeInTheDocument();
        expect(document.body.textContent).not.toContain('opaque EA password');
        expect(document.body.textContent).not.toContain('10000001');
        expect(mockPage.url).toBe('/en');
        expect(localStorageSpy).not.toHaveBeenCalled();
    });

    it('prevents double-submit, reuses the key for transport retry, then clears and redirects on 201', async () => {
        const requests: Array<{
            key: string | null;
            method: string | undefined;
        }> = [];
        let cartAttempt = 0;
        const fetchMock = vi.fn(
            (input: RequestInfo | URL, init?: RequestInit) => {
                if (init?.method !== 'POST') {
                    return Promise.resolve(quoteResponse());
                }

                const headers = init.headers as Record<string, string>;
                requests.push({
                    key: headers['Idempotency-Key'],
                    method: init.method,
                });
                cartAttempt += 1;

                if (cartAttempt === 1) {
                    return Promise.reject(new TypeError('offline'));
                }

                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            data: {
                                cartCount: 2,
                                cartItemId: '01K00000000000000000000000',
                                cartUrl: '/en/cart',
                                quote: {
                                    delivery: null,
                                    market: 'pc',
                                    platform: 'pc',
                                    pricedAt: '2026-08-10T12:00:00Z',
                                    quantity: 50_000,
                                    total: {
                                        amountHalalah: 600,
                                        currency: 'SAR',
                                    },
                                },
                            },
                        }),
                        {
                            headers: { 'Content-Type': 'application/json' },
                            status: 201,
                        },
                    ),
                );
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const cartCountEvents: number[] = [];
        window.addEventListener(
            'arabut:cart-count',
            (event) => {
                cartCountEvents.push((event as CustomEvent<number>).detail);
            },
            { once: true },
        );

        render(<StoreHome />);
        await reachCredentials();
        fillCredentials();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        const addButton = screen.getByRole('button', { name: 'Add to cart' });
        fireEvent.click(addButton);
        fireEvent.click(addButton);
        await act(async () => Promise.resolve());

        expect(requests).toHaveLength(1);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Connection interrupted. Try again.',
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Try adding again' }),
        );
        await act(async () => Promise.resolve());

        expect(requests).toHaveLength(2);
        expect(requests[0].key).toBe('3dc56ae8-6ed2-4dde-92fd-d170cefa8a3d');
        expect(requests[1].key).toBe(requests[0].key);
        expect(screen.queryByLabelText('EA password')).not.toBeInTheDocument();
        expect(document.body.textContent).not.toContain('opaque EA password');
        expect(cartCountEvents).toEqual([2]);
        expect(visitMock).toHaveBeenCalledWith('/en/cart');
    });

    it('maps a mixed 422 to credential fields without reflecting backend text', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
                void input;

                if (init?.method !== 'POST') {
                    return Promise.resolve(quoteResponse());
                }

                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            errors: {
                                'credentials.ea_email': [
                                    'Backend email sentinel player@example..com',
                                ],
                                'credentials.ea_password': [
                                    'Backend password sentinel opaque EA password',
                                ],
                                'credentials.backup_codes.2': [
                                    'Backend code sentinel 10000003',
                                ],
                                'credentials.backup_codes.8': [
                                    'Out-of-range backend sentinel',
                                ],
                                request: ['Unknown backend sentinel'],
                            },
                            message: 'Hostile backend message sentinel',
                        }),
                        {
                            headers: { 'Content-Type': 'application/json' },
                            status: 422,
                        },
                    ),
                );
            }),
        );

        render(<StoreHome />);
        await reachCredentials();
        fillCredentials();
        fireEvent.change(screen.getByRole('textbox', { name: 'EA email' }), {
            target: { value: 'player@example..com' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
        await act(async () => Promise.resolve());

        expect(
            screen.getByRole('heading', { name: 'EA account details' }),
        ).toBeVisible();
        expect(screen.getByRole('textbox', { name: 'EA email' })).toHaveFocus();
        expect(screen.getByText('Enter a valid EA email.')).toBeVisible();
        expect(screen.getByText('Enter your EA password.')).toBeVisible();
        expect(screen.getByText('Enter an 8-digit backup code.')).toBeVisible();
        expect(document.body.textContent).not.toMatch(
            /Backend|Hostile|Unknown|Out-of-range|opaque EA password|10000003/,
        );
        expect(mockPage.url).toBe('/en');
        expect(window.localStorage).toHaveLength(0);
        expect(window.sessionStorage).toHaveLength(0);
    });

    it('keeps a non-credential 422 on the summary with a safe localized error', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
                if (init?.method !== 'POST') {
                    return Promise.resolve(quoteResponse());
                }

                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            errors: {
                                idempotency_key: [
                                    'Backend idempotency sentinel',
                                ],
                                platform: ['Backend platform sentinel'],
                                quantity: ['Backend quantity sentinel'],
                                request: ['Backend request sentinel'],
                            },
                            message: 'Hostile backend message sentinel',
                        }),
                        {
                            headers: { 'Content-Type': 'application/json' },
                            status: 422,
                        },
                    ),
                );
            }),
        );

        render(<StoreHome />);
        await reachCredentials();
        fillCredentials();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
        await act(async () => Promise.resolve());

        expect(
            screen.getByRole('heading', { name: 'Review and add' }),
        ).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Review the highlighted EA details.',
        );
        expect(
            screen.queryByRole('heading', { name: 'EA account details' }),
        ).not.toBeInTheDocument();
        expect(document.body.textContent).not.toMatch(
            /Backend|Hostile|opaque EA password|10000001/,
        );
        expect(mockPage.url).toBe('/en');
        expect(window.localStorage).toHaveLength(0);
        expect(window.sessionStorage).toHaveLength(0);
    });

    it('maps a credential-only 422 to the rejected field without reflecting backend text', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
                if (init?.method !== 'POST') {
                    return Promise.resolve(quoteResponse());
                }

                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            errors: {
                                'credentials.ea_email': [
                                    'Backend email sentinel',
                                ],
                            },
                            message: 'Hostile backend message sentinel',
                        }),
                        {
                            headers: { 'Content-Type': 'application/json' },
                            status: 422,
                        },
                    ),
                );
            }),
        );

        render(<StoreHome />);
        await reachCredentials();
        fillCredentials();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
        await act(async () => Promise.resolve());

        expect(
            screen.getByRole('heading', { name: 'EA account details' }),
        ).toBeVisible();
        expect(screen.getByRole('textbox', { name: 'EA email' })).toHaveFocus();
        expect(screen.getByText('Enter a valid EA email.')).toBeVisible();
        expect(document.body.textContent).not.toMatch(
            /Backend|Hostile|opaque EA password|10000001/,
        );
    });

    it('keeps Arabic credential labels RTL while only code values are LTR', async () => {
        mockPage.props = {
            ...pageProps(),
            direction: 'rtl',
            locale: 'ar',
            store: {
                ...store,
                credentials: {
                    ...store.credentials,
                    backup_code: 'الكود الاحتياطي :number',
                    backup_codes: 'أكواد EA الاحتياطية',
                },
            },
        };
        render(<StoreHome />);
        await reachCredentials();

        const firstCodeLabel = document.querySelector<HTMLLabelElement>(
            'label[for="coins-backup-0"]',
        );
        const firstCode =
            document.querySelector<HTMLInputElement>('#coins-backup-0');

        expect(firstCodeLabel?.closest('[dir]')).toHaveAttribute('dir', 'rtl');
        expect(firstCode).toHaveAttribute('dir', 'ltr');
    });

    it('clears credentials on explicit cancel before returning to the form', async () => {
        render(<StoreHome />);
        await reachCredentials();
        fillCredentials();

        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel and clear details' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByRole('textbox', { name: 'EA email' })).toHaveValue(
            '',
        );
        expect(screen.getByLabelText('EA password')).toHaveValue('');
        expect(
            screen.getByRole('textbox', { name: 'Backup code 1' }),
        ).toHaveValue('');
    });
});
