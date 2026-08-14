import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import type * as PaylinkCheckoutApi from '@/lib/paylink-checkout-api';
import StoreOrder from '@/pages/store/order';
import type { StoreOrderPageProps } from '@/types/store-shell';

const navigateToHostedPayment = vi.hoisted(() => vi.fn());
const navigateToOrder = vi.hoisted(() => vi.fn());
const page = vi.hoisted(() => ({
    props: orderProps(),
    url: '/en/orders/01K00000000000000000000000',
}));

vi.mock('@/lib/paylink-checkout-api', async (importOriginal) => ({
    ...(await importOriginal<typeof PaylinkCheckoutApi>()),
    navigateToHostedPayment,
    navigateToOrder,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => page,
}));

afterEach(cleanup);

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    page.props = orderProps();
    navigateToHostedPayment.mockReset();
    navigateToOrder.mockReset();
    vi.unstubAllGlobals();
});

it('locks a pending order while resuming its existing Paylink payment', async () => {
    let completeRequest: ((response: Response) => void) | undefined;
    const fetchMock = vi.fn().mockReturnValue(
        new Promise<Response>((resolve) => {
            completeRequest = resolve;
        }),
    );
    vi.stubGlobal('fetch', fetchMock);
    render(<StoreOrder />);

    const payButton = screen.getByRole('button', { name: 'Pay with Paylink' });
    fireEvent.click(payButton);
    fireEvent.click(payButton);

    expect(
        screen.getByRole('button', { name: 'Opening Paylink…' }),
    ).toBeDisabled();
    expect(fetchMock).toHaveBeenCalledTimes(1);

    completeRequest?.(
        new Response(
            JSON.stringify({
                data: {
                    orderUrl: '/en/orders/01K00000000000000000000000',
                    paymentUrl:
                        'https://payment.paylink.sa/pay/info/1710000000099',
                    status: 'pending',
                },
            }),
            { status: 200 },
        ),
    );

    await waitFor(() =>
        expect(navigateToHostedPayment).toHaveBeenCalledWith(
            'https://payment.paylink.sa/pay/info/1710000000099',
        ),
    );
    expect(navigateToOrder).not.toHaveBeenCalled();
});

it('shows a retryable error when the existing payment cannot be opened', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
    render(<StoreOrder />);

    fireEvent.click(screen.getByRole('button', { name: 'Pay with Paylink' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Payment could not be opened. Try again.',
    );
});

it('omits payment controls from a received order', () => {
    page.props.order.paymentStartUrl = null;
    page.props.order.status = 'received';
    render(<StoreOrder />);

    expect(
        screen.queryByRole('button', { name: 'Pay with Paylink' }),
    ).not.toBeInTheDocument();
});

function orderProps(): StoreOrderPageProps {
    return {
        cartCount: 0,
        direction: 'ltr' as const,
        displayCurrencies: ['SAR'],
        displayCurrency: 'SAR',
        locale: 'en' as const,
        order: {
            currency: 'SAR' as const,
            id: '01K00000000000000000000000',
            items: [
                {
                    id: '01K00000000000000000000001',
                    name: 'SBC service',
                    status: 'pending_payment' as const,
                    totalHalalah: 1250,
                },
            ],
            number: 'AUT-20260814-0001',
            paymentStartUrl:
                '/en/orders/01K00000000000000000000000/payments/paylink' as
                    string | null,
            status: 'pending_payment' as const,
            totalHalalah: 1250,
        },
        orderPage: {
            back: 'Back to store',
            eyebrow: 'Arab UT order',
            number: 'Order number',
            pay_error: 'Payment could not be opened. Try again.',
            pay_loading: 'Opening Paylink…',
            pay_now: 'Pay with Paylink',
            status: 'Status',
            statuses: {
                cancelled: 'Cancelled',
                completed: 'Completed',
                failed: 'Failed',
                in_progress: 'In progress',
                pending_payment: 'Awaiting payment',
                received: 'Received',
                refunded: 'Refunded',
                waiting_for_customer: 'Waiting for customer',
            },
            title: 'Order',
            total: 'Total',
        },
        storeShell: {
            accountUrl: '/en/login',
            cartUrl: '/en/cart',
            coinsUrl: '/en#coins',
            eaBackupCodesUrl: '/en/ea-backup-codes',
            email: 'support@example.test',
            futChampionsUrl: '/en/fut-champions',
            homeUrl: '/en',
            payments: [],
            privacyUrl: '/en/privacy',
            returnsUrl: '/en/returns',
            sbcUrl: '/en/sbc',
            socials: { instagram: '', x: '' },
            termsUrl: '/en/terms',
            warrantyUrl: '/en/warranty',
            whatsappUrl: 'https://wa.me/1',
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
            footer: {
                copyright: '',
                customer_service: '',
                description: '',
                ea_backup_codes: '',
                ea_disclaimer: '',
                important_links: '',
                payment_methods: '',
                privacy: '',
                returns: '',
                terms: '',
                warranty: '',
                whatsapp: '',
            },
            header: {
                account: 'Account',
                cart: 'Cart',
                coins: 'Coins',
                fut_champions: 'FUT Champions',
                home: 'Home',
                most_requested: 'Most requested',
                preferences: 'Preferences',
                primary_navigation: 'Primary navigation',
                sbc: 'SBC',
                whatsapp: 'WhatsApp',
            },
            home_title: 'Home',
            language: 'Arabic',
            preferences: { exchange_rate_attribution: 'Rates' },
            skip_to_content: 'Skip',
            store_tools: 'Tools',
        },
    };
}
