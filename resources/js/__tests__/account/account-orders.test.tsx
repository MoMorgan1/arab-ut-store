import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountOrderCard from '@/components/account/account-order-card';
import AccountLiveOrder from '@/pages/account/live-order';
import AccountOrders from '@/pages/account/orders';
import type {
    AccountLiveOrderPageProps,
    AccountOrder,
    AccountTranslations,
} from '@/types/account';

const inertia = vi.hoisted(() => ({
    flushAll: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));
const paymentNavigation = vi.hoisted(() => ({
    hosted: vi.fn(),
    order: vi.fn(),
}));
const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/my-account/orders?status=open',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        'aria-current': ariaCurrent,
        children,
        className,
        href,
    }: React.ComponentProps<'a'> & { preserveScroll?: boolean }) => (
        <a
            aria-current={ariaCurrent}
            className={className}
            href={typeof href === 'string' ? href : ''}
        >
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => page,
}));

vi.mock('@/lib/paylink-checkout-api', async (importOriginal) => ({
    ...(await importOriginal()),
    navigateToHostedPayment: paymentNavigation.hosted,
    navigateToOrder: paymentNavigation.order,
}));

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    page.props = shellProps();
    page.url = '/en/my-account/orders?status=open';
    vi.clearAllMocks();
    vi.unstubAllGlobals();
});

afterEach(cleanup);

it('renders canonical filters, safe order cards, and bounded pagination', () => {
    page.props = {
        ...shellProps(),
        filters: { status: 'open' },
        orders: [order('01ORDER1', 'UT-00000001', 'in_progress')],
        pagination: {
            currentPage: 1,
            lastPage: 2,
            perPage: 10,
            total: 11,
            nextUrl: '/en/my-account/orders?status=open&page=2',
            previousUrl: null,
        },
    };

    render(<AccountOrders />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'Orders' }),
    ).toBeVisible();
    const filters = screen.getByRole('navigation', { name: 'Filter orders' });

    expect(
        within(filters).getByRole('link', { name: 'In progress' }),
    ).toHaveAttribute('aria-current', 'page');
    expect(screen.getByTitle('UT-00000001')).toBeVisible();
    expect(screen.getByText('FC 27 Coins service')).toBeVisible();
    expect(screen.getByRole('link', { name: /Next/ })).toHaveAttribute(
        'href',
        '/en/my-account/orders?status=open&page=2',
    );
    expect(
        screen.queryByText(/password|configuration/i),
    ).not.toBeInTheDocument();
});

it('refreshes only current safe order data and keeps credentials opaque', () => {
    page.url = '/en/my-account/orders/01ORDER1';
    page.props = {
        ...shellProps(),
        order: liveOrder(null),
    };

    render(<AccountLiveOrder />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'UT-00000001' }),
    ).toBeVisible();
    expect(
        screen.getByText('Fulfilment details stored securely'),
    ).toBeVisible();
    expect(
        screen.queryByText(/password|credential value/i),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));

    expect(inertia.reload).toHaveBeenCalledWith(
        expect.objectContaining({ only: ['order'] }),
    );
});

it('reveals manual-service credentials and squad image only after the owner asks', async () => {
    const orderData = liveOrder(null);
    orderData.items[0] = {
        ...orderData.items[0],
        name: 'FUT Champions service',
        manualFulfillment: {
            credentialsUrl:
                '/en/my-account/orders/01ORDER1/items/01ITEM1/credentials',
            squadImageUrl:
                '/en/my-account/orders/01ORDER1/items/01ITEM1/squad-image',
            platform: 'playstation',
            targetRank: 3,
            urgent: true,
            matchesPlayed: 4,
        },
    };
    page.props = { ...shellProps(), order: orderData };
    const fetchMock = vi.fn().mockResolvedValue(
        new Response(
            JSON.stringify({
                data: {
                    platform: 'playstation',
                    playstationEmail: 'owner@example.test',
                    playstationPassword: 'private password',
                    eaBackupCodes: ['12345678', '23456789', '34567890'],
                    playstationBackupCodes: ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
                },
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
    );
    vi.stubGlobal('fetch', fetchMock);
    const localStorageSpy = vi.spyOn(Storage.prototype, 'setItem');

    render(<AccountLiveOrder />);

    expect(document.body.textContent).not.toContain('owner@example.test');
    expect(fetchMock).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Show account details' }),
    );

    expect(await screen.findByText('owner@example.test')).toBeVisible();
    expect(screen.getByText('private password')).toBeVisible();
    expect(screen.getByText(/12345678/)).toBeVisible();
    expect(screen.getByText(/A1B2C3/)).toBeVisible();
    expect(
        screen.getByRole('img', { name: 'Submitted squad' }),
    ).toHaveAttribute(
        'src',
        '/en/my-account/orders/01ORDER1/items/01ITEM1/squad-image',
    );
    expect(fetchMock).toHaveBeenCalledWith(
        '/en/my-account/orders/01ORDER1/items/01ITEM1/credentials',
        expect.objectContaining({
            cache: 'no-store',
            credentials: 'same-origin',
        }),
    );
    expect(localStorageSpy).not.toHaveBeenCalled();

    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(window.navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
    });

    expect(screen.getAllByText('12345678')).toHaveLength(1);
    expect(screen.getByText('A1B2C3')).toBeVisible();
    expect(screen.getByText('D4E5F6')).toBeVisible();
    expect(screen.getByText('Z9Y8X7')).toBeVisible();

    const emailRow = screen
        .getByText('owner@example.test')
        .closest('.account-order-fulfillment__value-row');
    expect(emailRow).not.toBeNull();
    fireEvent.click(
        within(emailRow as HTMLElement).getByRole('button', {
            name: 'PlayStation email — Copy',
        }),
    );

    await waitFor(() =>
        expect(writeText).toHaveBeenCalledWith('owner@example.test'),
    );
    expect(screen.getAllByText('Copied').length).toBeGreaterThan(0);
    expect(writeText).not.toHaveBeenCalledWith(expect.stringContaining('A1B2C3,D4E5F6'));

    fireEvent.click(
        screen.getByRole('button', { name: 'Hide account details' }),
    );
    expect(screen.queryByText('owner@example.test')).not.toBeInTheDocument();
});

it('offers a browse services CTA from the orders empty state', () => {
    page.props = {
        ...shellProps(),
        filters: { status: 'all' },
        orders: [],
        pagination: {
            currentPage: 1,
            lastPage: 1,
            perPage: 10,
            total: 0,
            nextUrl: null,
            previousUrl: null,
        },
    };

    render(<AccountOrders />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'No orders yet' }),
    ).toBeVisible();
    expect(screen.getByRole('link', { name: 'Browse services' })).toHaveAttribute(
        'href',
        '/en#coins',
    );
});

it('resumes the existing Paylink payment from the canonical detail', async () => {
    const orderId = '01K00000000000000000000000';

    page.url = `/en/my-account/orders/${orderId}`;
    page.props = {
        ...shellProps(),
        order: {
            ...liveOrder(`/en/orders/${orderId}/payments/paylink`),
            id: orderId,
            status: 'pending_payment',
        },
    };
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        orderUrl: `/en/orders/${orderId}`,
                        paymentUrl:
                            'https://payment.paylink.sa/pay/info/1710000000099',
                        status: 'pending',
                    },
                }),
                { status: 200 },
            ),
        ),
    );

    render(<AccountLiveOrder />);
    fireEvent.click(screen.getByRole('button', { name: 'Complete payment' }));

    await waitFor(() =>
        expect(paymentNavigation.hosted).toHaveBeenCalledWith(
            'https://payment.paylink.sa/pay/info/1710000000099',
        ),
    );
    expect(paymentNavigation.order).not.toHaveBeenCalled();
});

it('renders distinct classes for prominent and standard order cards without class name concatenation errors', () => {
    const sampleOrder = order(
        '01PROMINENT',
        'UT-00000099',
        'waiting_for_customer',
    ) as unknown as AccountOrder;
    const shell = shellProps();

    const { container, rerender } = render(
        <AccountOrderCard
            locale="en"
            order={sampleOrder}
            prominent
            translations={shell.accountUi as unknown as AccountTranslations}
        />,
    );

    const prominentCard = container.querySelector('.account-order-card');
    expect(prominentCard).not.toBeNull();
    expect(prominentCard).toHaveClass('account-order-card');
    expect(prominentCard).toHaveClass('account-order-card--prominent');

    rerender(
        <AccountOrderCard
            locale="en"
            order={sampleOrder}
            prominent={false}
            translations={shell.accountUi as unknown as AccountTranslations}
        />,
    );

    expect(prominentCard).toHaveClass('account-order-card');
    expect(prominentCard).not.toHaveClass('account-order-card--prominent');
});

function order(id: string, number: string, status: string): AccountOrder {
    return {
        id,
        source: 'live',
        number,
        status: status as AccountOrder['status'],
        placedAt: '2026-08-15T10:00:00+00:00',
        summary: 'FC 27 Coins service',
        itemCount: 1,
        total: { amountMinor: '12999', currency: 'SAR' },
        detailUrl: `/en/my-account/orders/${id}`,
    };
}

function liveOrder(
    paymentStartUrl: string | null,
): AccountLiveOrderPageProps['order'] {
    return {
        id: '01ORDER1',
        number: 'UT-00000001',
        status: 'waiting_for_customer',
        placedAt: '2026-08-15T10:00:00+00:00',
        total: { amountMinor: '12999', currency: 'SAR' },
        refreshable: true,
        paymentStartUrl,
        items: [
            {
                id: '01ITEM1',
                name: 'FC 27 Coins service',
                status: 'waiting_for_customer',
                quantity: 1,
                total: { amountMinor: '12999', currency: 'SAR' },
                credentialsPresent: true,
                manualFulfillment: null,
            },
        ],
    };
}

function shellProps() {
    return {
        accountIdentity: { name: 'Player', greeting: 'Welcome, Player' },
        accountNavigation: [
            { key: 'overview', label: 'Overview', url: '/en/my-account' },
            { key: 'orders', label: 'Orders', url: '/en/my-account/orders' },
        ],
        accountUi: {
            page_title: 'My Account',
            eyebrow: 'Arab UT account',
            greeting: 'Welcome, :name',
            introduction: 'Track your account in one place.',
            navigation: {
                label: 'My Account sections',
                overview: 'Overview',
                orders: 'Orders',
                wallet: 'Wallet',
                profile: 'Profile',
                security: 'Security',
                support: 'Support',
                logout: 'Log out',
            },
            overview: {
                title: 'Overview',
                description: 'Summary',
                orders_metric: 'Orders',
                open_orders_metric: 'Open orders',
                completed_orders_metric: 'Completed orders',
                wallet_metric: 'Wallet',
                active_order: 'Active order',
                recent_orders: 'Recent orders',
                loyalty: 'Loyalty',
                empty_title: 'No orders',
                empty_description: 'Start shopping.',
                browse_services: 'Browse services',
                loyalty_remaining: ':amount remaining to reach :tier.',
                loyalty_complete: 'Highest tier reached.',
            },
            orders: {
                title: 'Orders',
                description: 'Track current and previous orders.',
                all: 'All orders',
                open: 'In progress',
                completed: 'Completed',
                empty_title: 'No orders yet',
                empty_description: 'Orders will appear here.',
                number: 'Order number',
                placed_at: 'Placed on',
                total: 'Total',
                status: 'Status',
                source_live: 'Current order',
                source_archive: 'Previous order',
                filters_label: 'Filter orders',
                previous: 'Previous',
                next: 'Next',
                pagination: 'Order pages',
                page_status: 'Page :current of :total',
                items_title: 'Service details',
                item_quantity: 'Quantity: :count',
                credentials_ready: 'Fulfilment details stored securely',
                manual_details: 'Manual service details',
                platform: 'Platform',
                platform_playstation: 'PlayStation',
                platform_pc: 'PC',
                launcher: 'Launcher',
                rank: 'Target rank',
                rank_value: 'Rank :rank',
                urgent: 'Delivery',
                urgent_yes: 'Urgent — 24–36 hours',
                urgent_no: 'Standard',
                matches_played: 'Matches already played',
                from_division: 'Current division',
                to_division: 'Target division',
                elite: 'Elite',
                show_credentials: 'Show account details',
                hide_credentials: 'Hide account details',
                credentials_loading: 'Loading account details…',
                credentials_error: 'Could not load account details.',
                squad_image: 'Submitted squad',
                playstation_email: 'PlayStation email',
                playstation_password: 'PlayStation password',
                ea_email: 'EA email',
                ea_password: 'EA password',
                steam_username: 'Steam username',
                steam_password: 'Steam password',
                ea_codes: 'EA backup codes',
                playstation_codes: 'PlayStation backup codes',
                refresh_status: 'Refresh status',
                refreshing: 'Refreshing…',
                back: 'Back to Orders',
                copy: 'Copy',
                copied: 'Copied',
            },
            statuses: {
                pending_payment: 'Awaiting payment',
                received: 'Payment received',
                in_progress: 'In progress',
                waiting_for_customer: 'Waiting for you',
                completed: 'Completed',
                cancelled: 'Cancelled',
                refunded: 'Refunded',
                failed: 'Needs attention',
            },
            actions: {
                view_order: 'View order',
                view_all: 'View all',
                pay_now: 'Complete payment',
                retry_payment: 'Retry payment',
                provide_details: 'Provide details',
                retry: 'Try again',
                back_to_account: 'Back to My Account',
            },
            accessibility: {
                current_page: 'Current page',
                open_navigation: 'Open navigation',
                close_navigation: 'Close navigation',
                order_status: 'Order status: :status',
            },
            errors: {
                section_title: 'Could not load',
                section_description: 'Try again.',
                save_failed: 'Could not save.',
                unexpected: 'Something unexpected happened. Try again.',
            },
        },
        cartCount: 0,
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR'],
        locale: 'en',
        logoutUrl: '/logout',
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
            whatsappUrl: 'https://wa.me/1',
            email: 'support@example.test',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'Arab UT',
            cart_added: {
                title: 'Added',
                message: 'Added.',
                buy_now: 'Buy now',
                continue_shopping: 'Continue shopping',
            },
            language: 'العربية',
            currency_selector: 'Currency',
            home_title: 'Home',
            skip_to_content: 'Skip to content',
            store_tools: 'Tools',
            header: {
                primary_navigation: 'Primary navigation',
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
            preferences: { exchange_rate_attribution: 'Rates' },
            footer: {
                description: '',
                important_links: 'Important links',
                privacy: 'Privacy',
                returns: 'Returns',
                warranty: 'Warranty',
                ea_backup_codes: 'EA codes',
                terms: 'Terms',
                customer_service: 'Customer service',
                whatsapp: 'WhatsApp',
                payment_methods: 'Payment methods',
                copyright: '',
                ea_disclaimer: '',
            },
        },
    };
}
