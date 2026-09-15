import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import StoreTrackOrder from '@/pages/store/track-order';
import type { OrderItemTracking } from '@/types/account';

/**
 * The page is a read-only capability view: it shows the state of the work and
 * nothing else. Every assertion here is a field that must never slip back in -
 * except the action URLs, which are now part of the contract: the card's
 * buttons post to them.
 */

// The card's forty-odd copy strings are not what is under test; each one
// answers with its own key name, so the test never carries a translation
// table it would then have to maintain.
const trackingStrings = new Proxy({}, { get: (_target, key) => String(key) });

function tracking(
    overrides: Partial<OrderItemTracking> = {},
): OrderItemTracking {
    return {
        kind: 'coins',
        phase: 'coins',
        presentation: 'transferring',
        headline: 'Transferring',
        subline: 'Moving coins to your :console account',
        holdReason: null,
        holdMessage: null,
        holdTone: null,
        completedAt: null,
        actions: [],
        supported: true,
        observedAt: new Date().toISOString(),
        accountCoins: { amount: null, state: 'unknown' },
        progress: {
            coinsDelivered: 400_000,
            coinsOrdered: 1_000_000,
            squadsDone: null,
            squadsTotal: null,
            solvesDone: null,
            solvesTotal: null,
        },
        challenges: null,
        coverage: null,
        workStarted: true,
        credentialsPending: false,
        ...overrides,
    };
}

function pageProps() {
    return {
        cartCount: 0,
        direction: 'ltr' as const,
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR', 'USD'],
        locale: 'en' as const,
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
            currency_selector: 'Choose display currency',
            language: 'العربية',
            language_label: 'Language',
            currency: 'Currency',
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
                payment_methods: 'Payment methods',
                copyright: 'Copyright © :year Arab UT.',
                ea_disclaimer: 'Independent from EA Sports.',
            },
            cart_added: {
                title: 'Added',
                in_cart: 'In cart',
                checkout: 'Checkout',
                cart: 'Cart',
                dismiss: 'Dismiss',
                duplicate_title: 'Already in cart',
                duplicate_hint: 'Already there',
                open_cart: 'Open cart',
            },
        },
        order: {
            number: 'UT-12345678',
            status: 'in_progress',
            statusNote: null,
            placedAt: '2026-09-13T10:00:00+00:00',
            refreshable: true,
            items: [
                {
                    name: 'Coins Service',
                    platform: 'playstation' as const,
                    imageUrl: '/images/store/coins/ut-coin-80.webp',
                    status: 'in_progress',
                    quantity: 1,
                    actionUrls: {
                        editCredentials:
                            '/en/orders/track/token123/items/item1/actions/edit-credentials',
                        resume: '/en/orders/track/token123/items/item1/actions/resume',
                        retryChallenge:
                            '/en/orders/track/token123/items/item1/actions/retry-challenge',
                    },
                    tracking: tracking(),
                },
            ],
        },
        accountUi: {
            track_order: { title: 'Track order' },
            statuses: {
                pending_payment: 'Awaiting payment',
                received: 'Payment received',
                in_progress: 'In progress',
                waiting_for_customer: 'Paused',
                completed: 'Completed',
                cancelled: 'Cancelled',
                refunded: 'Refunded',
            },
            orders: { tracking: trackingStrings },
        },
    };
}

type MockPageProps = ReturnType<typeof pageProps>;

const mockPage = vi.hoisted(() => ({
    props: {} as MockPageProps,
    url: '/en/orders/track/ABC1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJK',
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockPage,
}));

beforeEach(() => {
    mockPage.props = pageProps();
    document.head.innerHTML =
        '<meta name="csrf-token" content="csrf-token-value">';
});

afterEach(() => {
    vi.unstubAllGlobals();
    cleanup();
});

it('renders an item tracking card', () => {
    const { container } = render(<StoreTrackOrder />);

    expect(screen.getByText('UT-12345678')).toBeInTheDocument();
    expect(container.querySelector('.track-card')).not.toBeNull();
});

it('renders no action buttons when the action list is empty', () => {
    const { container } = render(<StoreTrackOrder />);

    expect(container.querySelector('.track-btn')).toBeNull();
    expect(container.querySelector('.track-challenge__actions')).toBeNull();
    expect(container.querySelector('.track-action-box__actions')).toBeNull();
});

it('renders no money in the order view', () => {
    // The store shell's currency selector legitimately shows currency codes, so
    // the money check is scoped to the order view rather than the whole page.
    const { container } = render(<StoreTrackOrder />);

    expect(container.querySelector('.account-invoice__totals')).toBeNull();
    expect(container.querySelector('.account-invoice__item-total')).toBeNull();
    expect(container.querySelector('.account-invoice__grand')).toBeNull();
    expect(container.querySelector('.account-invoice__method')).toBeNull();

    const orderView = container.querySelector('.account-invoice');

    expect(orderView?.textContent).not.toMatch(/SAR|ر\.س|USD|\$/);
});

it('renders action buttons for an item with actions', () => {
    mockPage.props.order.items[0].tracking!.actions = ['resume'];

    const { container } = render(<StoreTrackOrder />);

    expect(container.querySelector('.track-btn')).not.toBeNull();
});

it('posts a pressed action to the URL from actionUrls', async () => {
    mockPage.props.order.items[0].tracking!.actions = ['resume'];

    const fetchMock = vi.fn(() =>
        Promise.resolve(
            new Response(
                JSON.stringify({ tracking: tracking(), status: 'accepted' }),
                {
                    headers: { 'Content-Type': 'application/json' },
                    status: 200,
                },
            ),
        ),
    );
    vi.stubGlobal('fetch', fetchMock);

    render(<StoreTrackOrder />);

    fireEvent.click(screen.getByRole('button', { name: 'resume' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    await act(async () => {});

    expect(fetchMock).toHaveBeenCalledWith(
        '/en/orders/track/token123/items/item1/actions/resume',
        expect.objectContaining({ method: 'POST' }),
    );
});
