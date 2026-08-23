import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountWallet from '@/pages/account/wallet';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/my-account/wallet',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''}>{children}</a>
    ),
    usePage: () => page,
}));

vi.mock('@/layouts/my-account-layout', () => ({
    default: ({ children }: React.PropsWithChildren) => <main>{children}</main>,
}));

beforeEach(() => {
    page.props = walletProps();
});

afterEach(cleanup);

it('renders exact balance, lifetime cashback, typed text semantics, order context, and bounded pagination', () => {
    render(<AccountWallet />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'Wallet' }),
    ).toBeVisible();
    expect(screen.getByText(/90,071,992,547,409\.91/)).toBeVisible();
    expect(screen.getByText('Cashback earned')).toBeVisible();
    expect(screen.getByText(/^SAR\s50\.00$/)).toBeVisible();

    const ledger = screen.getByRole('region', { name: 'Wallet activity' });
    expect(within(ledger).getByText('Refund')).toBeVisible();
    expect(within(ledger).getByText(/^\+SAR\s12\.50$/)).toBeVisible();
    expect(within(ledger).getByText('Debit')).toBeVisible();
    expect(within(ledger).getByText(/^−SAR\s5\.00$/)).toBeVisible();
    expect(within(ledger).getByText('Cashback')).toBeVisible();
    expect(within(ledger).getByText(/^\+SAR\s25\.00$/)).toBeVisible();
    expect(within(ledger).getByText('Cashback reversal')).toBeVisible();
    expect(within(ledger).getByText(/^−SAR\s10\.00$/)).toBeVisible();
    expect(within(ledger).getByText('Adjustment')).toBeVisible();
    expect(within(ledger).getByText(/^SAR\s1\.00$/)).toBeVisible();
    expect(
        within(ledger).getByRole('link', { name: 'Order UT-00000071' }),
    ).toHaveAttribute(
        'href',
        '/en/my-account/orders/01K00000000000000000000000',
    );
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '/en/my-account/wallet?page=2',
    );
});

it('renders balance as 0.00 and empty state when customer has no wallet account yet', () => {
    page.props = {
        ...walletProps(),
        wallet: {
            exists: false,
            balance: null,
            lifetimeCashback: { amountMinor: '0', currency: 'SAR' },
            entries: [],
            pagination: pagination(0),
        },
    };
    const { rerender } = render(<AccountWallet />);

    expect(screen.getAllByText(/^SAR\s0\.00$/)).toHaveLength(2);
    expect(screen.getByText('No wallet activity yet')).toBeVisible();
    expect(
        screen.getByText(
            'No activity yet — earn cashback with your first completed order.',
        ),
    ).toBeVisible();

    page.props = {
        ...walletProps(),
        wallet: {
            exists: true,
            balance: { amountMinor: '0', currency: 'SAR' },
            lifetimeCashback: { amountMinor: '0', currency: 'SAR' },
            entries: [],
            pagination: pagination(0),
        },
    };
    rerender(<AccountWallet />);

    expect(screen.getAllByText(/^SAR\s0\.00$/)).toHaveLength(2);
    expect(screen.getByText('No wallet activity yet')).toBeVisible();
});

function walletProps() {
    return {
        locale: 'en',
        accountUi: {
            eyebrow: 'Arab UT account',
            wallet: {
                title: 'Wallet',
                description: 'Your balance and verified wallet activity.',
                available_balance: 'Available balance',
                unavailable_balance: 'Wallet is not active yet',
                lifetime_cashback: 'Cashback earned',
                loyalty_title: 'Loyalty programme',
                ledger_title: 'Wallet activity',
                empty_title: 'No wallet activity yet',
                empty_description:
                    'No activity yet — earn cashback with your first completed order.',
                credit: 'Credit',
                debit: 'Debit',
                refund: 'Refund',
                adjustment: 'Adjustment',
                cashback: 'Cashback',
                cashback_reversal: 'Cashback reversal',
                balance_after: 'Balance after entry',
                related_order: 'Order :number',
                previous: 'Previous',
                next: 'Next',
                pagination: 'Wallet activity pages',
                page_status: 'Page :current of :total',
            },
            overview: {
                loyalty_remaining: ':amount remaining to reach :tier.',
                loyalty_complete: 'Highest tier reached.',
            },
        },
        wallet: {
            exists: true,
            balance: {
                amountMinor: '9007199254740991',
                currency: 'SAR',
            },
            lifetimeCashback: {
                amountMinor: '5000',
                currency: 'SAR',
            },
            entries: [
                entry(5, 'cashback', 'credit', '2500'),
                entry(4, 'cashback_reversal', 'debit', '1000'),
                entry(3, 'refund', 'credit', '1250', {
                    number: 'UT-00000071',
                    url: '/en/my-account/orders/01K00000000000000000000000',
                }),
                entry(2, 'debit', 'debit', '500'),
                entry(1, 'adjustment', 'neutral', '100'),
            ],
            pagination: {
                ...pagination(11),
                lastPage: 2,
                nextUrl: '/en/my-account/wallet?page=2',
            },
        },
        loyalty: null,
    };
}

function entry(
    sequence: number,
    type: string,
    effect: string,
    amountMinor: string,
    order: { number: string; url: string } | null = null,
) {
    return {
        id: `entry-${sequence}`,
        sequence,
        type,
        effect,
        amount: { amountMinor, currency: 'SAR' },
        balanceAfter: { amountMinor: '10000', currency: 'SAR' },
        createdAt: '2026-08-15T10:00:00+00:00',
        order,
    };
}

function pagination(total: number) {
    return {
        currentPage: 1,
        lastPage: 1,
        perPage: 10,
        total,
        nextUrl: null,
        previousUrl: null,
    };
}
