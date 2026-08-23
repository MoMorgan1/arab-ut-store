import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminOverviewPage from '@/pages/admin/overview';
import type { AdminOverviewPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    finish: undefined as (() => void) | undefined,
    props: {} as AdminOverviewPageProps,
    url: '/en/admin?range=7',
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        'aria-current': ariaCurrent,
        children,
        className,
        'data-loading': dataLoading,
        href,
        onClick,
        onFinish,
        onStart,
    }: React.ComponentProps<'a'> & {
        'data-loading'?: string;
        onFinish?: () => void;
        onStart?: () => void;
        preserveScroll?: boolean;
    }) => (
        <a
            aria-current={ariaCurrent}
            className={className}
            data-loading={dataLoading}
            href={typeof href === 'string' ? href : ''}
            onClick={(event) => {
                event.preventDefault();
                onClick?.(event);
                onStart?.();
                inertia.finish = onFinish;
            }}
        >
            {children}
        </a>
    ),
    usePage: () => ({ props: inertia.props, url: inertia.url }),
}));

function pageProps(
    overrides: Partial<AdminOverviewPageProps> = {},
): AdminOverviewPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/en/admin' },
            { key: 'orders', label: 'Orders', url: '/en/admin/orders' },
            {
                key: 'settings',
                label: 'Settings',
                url: '/en/admin/settings',
            },
        ],
        permissions: ['dashboard.view', 'audit.view', 'orders.view'],
        overview: {
            rangeDays: 7,
            orders: { received: 12, inProgress: 3, waitingForCustomer: 4 },
            payments: { pending: 5, failed: 2 },
            refunds: { failed: 1 },
            capturedRevenue: { amountMinor: '123456', currency: 'SAR' },
            previousCapturedRevenue: { amountMinor: '100000', currency: 'SAR' },
            totalOrders: { current: 23, previous: 15 },
            newCustomers: { current: 8, previous: 5 },
            attentionCount: 7,
            revenueTrend: [
                { date: '2026-08-14', amountMinor: '0', currency: 'SAR' },
                { date: '2026-08-15', amountMinor: '0', currency: 'SAR' },
                { date: '2026-08-16', amountMinor: '0', currency: 'SAR' },
                { date: '2026-08-17', amountMinor: '0', currency: 'SAR' },
                { date: '2026-08-18', amountMinor: '0', currency: 'SAR' },
                { date: '2026-08-19', amountMinor: '0', currency: 'SAR' },
                { date: '2026-08-20', amountMinor: '123456', currency: 'SAR' },
                { date: '2026-08-21', amountMinor: '0', currency: 'SAR' },
            ],
            recentOrders: [
                {
                    id: '01K5ADM1N00000000000000001',
                    number: 'AUT-RECEIVED-1001-WITH-A-LONG-IDENTIFIER',
                    status: 'received',
                    placedAt: '2026-08-20T10:00:00.000000Z',
                    total: { amountMinor: '123456', currency: 'SAR' },
                },
            ],
            oldestUnresolvedOrder: {
                id: '01K5ADM1N00000000000000001',
                number: 'AUT-RECEIVED-1001-WITH-A-LONG-IDENTIFIER',
                status: 'received',
                placedAt: '2026-08-20T10:00:00.000000Z',
            },
        },
        rangeOptions: [
            {
                days: 1,
                label: 'Today',
                url: '/en/admin?range=1',
                active: false,
            },
            {
                days: 7,
                label: 'Last 7 days',
                url: '/en/admin?range=7',
                active: true,
            },
            {
                days: 30,
                label: 'Last 30 days',
                url: '/en/admin?range=30',
                active: false,
            },
        ],
        logoutUrl: '/logout',
        ...overrides,
    };
}

describe('Admin operational overview', () => {
    beforeEach(() => {
        inertia.props = pageProps();
        inertia.url = '/en/admin?range=7';
        inertia.finish = undefined;
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the compact attention strip and links to the filtered orders list', () => {
        render(<AdminOverviewPage />);

        const attentionStrip = screen.getByRole('complementary', {
            name: 'Needs attention',
        });
        expect(attentionStrip).toBeVisible();

        const strip = within(attentionStrip);
        expect(strip.getByText('7')).toBeVisible();
        expect(
            strip.getByText('AUT-RECEIVED-1001-WITH-A-LONG-IDENTIFIER'),
        ).toBeVisible();
        expect(strip.getByText('Received')).toBeVisible();

        const filterLink = strip.getByRole('link', {
            name: /view unresolved orders/i,
        });
        expect(filterLink).toBeVisible();
        expect(filterLink).toHaveAttribute(
            'href',
            '/en/admin/orders?status=received',
        );
    });

    it('renders the 2x2 KPI grid with four values and deltas in deliberate reading order', () => {
        const { container } = render(<AdminOverviewPage />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Operations dashboard',
        );

        const kpiStrip = container.querySelector('.admin-kpi-strip');
        expect(kpiStrip).not.toBeNull();
        const kpi = within(kpiStrip as HTMLElement);

        // 1. Captured revenue
        expect(kpi.getByText('Captured revenue')).toBeVisible();
        expect(kpi.getByText(/SAR\s+1,234\.56/)).toBeVisible();
        expect(kpi.getByText('+23.5% vs. previous period')).toBeVisible();

        // 2. Total orders
        expect(kpi.getByText('Total placed orders')).toBeVisible();
        expect(kpi.getByText('23')).toBeVisible();
        expect(kpi.getByText('+53.3% vs. previous period')).toBeVisible();

        // 3. Orders in flight (received + inProgress + waitingForCustomer = 12 + 3 + 4 = 19)
        expect(kpi.getByText('Orders in flight')).toBeVisible();
        expect(kpi.getByText('12 Received orders')).toBeVisible();

        // 4. New customers
        expect(kpi.getByText('New customers')).toBeVisible();
        expect(kpi.getByText('8')).toBeVisible();
        expect(kpi.getByText('+60.0% vs. previous period')).toBeVisible();

        // Exactly 4 KPI cells
        const ddElements = container.querySelectorAll('.admin-kpi-strip dd');
        expect(ddElements).toHaveLength(4);
    });

    it('preserves signed 64-bit revenue precision when formatting minor units', () => {
        inertia.props = pageProps({
            overview: {
                ...pageProps().overview,
                capturedRevenue: {
                    amountMinor: '9223372036854775807',
                    currency: 'SAR',
                },
            },
        });

        render(<AdminOverviewPage />);

        expect(
            screen.getByText(/SAR\s+92,233,720,368,547,758\.07/),
        ).toBeVisible();
    });

    it('calculates exact finite percentages without Infinity or NaN on 64-bit scale values', () => {
        inertia.props = pageProps({
            overview: {
                ...pageProps().overview,
                capturedRevenue: {
                    amountMinor: '9223372036854775807',
                    currency: 'SAR',
                },
                previousCapturedRevenue: {
                    amountMinor: '4611686018427387903',
                    currency: 'SAR',
                },
            },
        });

        const { container } = render(<AdminOverviewPage />);
        const kpi = within(
            container.querySelector('.admin-kpi-strip') as HTMLElement,
        );

        expect(kpi.getByText('+100.0% vs. previous period')).toBeVisible();
        expect(container.textContent).not.toContain('Infinity');
        expect(container.textContent).not.toContain('NaN');
    });

    it('shows honest comparison wording for new period and unchanged metrics without duplicated phrases', () => {
        inertia.props = pageProps({
            overview: {
                ...pageProps().overview,
                capturedRevenue: {
                    amountMinor: '5000',
                    currency: 'SAR',
                },
                previousCapturedRevenue: {
                    amountMinor: '0',
                    currency: 'SAR',
                },
                totalOrders: { current: 0, previous: 0 },
            },
        });

        const { container, rerender } = render(<AdminOverviewPage />);
        const kpi = within(
            container.querySelector('.admin-kpi-strip') as HTMLElement,
        );

        expect(kpi.getAllByText('New this period')).toHaveLength(1);
        expect(kpi.getByText('No change')).toBeVisible();

        inertia.props = pageProps({
            overview: {
                ...pageProps().overview,
                capturedRevenue: {
                    amountMinor: '0',
                    currency: 'SAR',
                },
                previousCapturedRevenue: {
                    amountMinor: '0',
                    currency: 'SAR',
                },
            },
        });
        rerender(<AdminOverviewPage />);

        const updatedKpi = within(
            container.querySelector('.admin-kpi-strip') as HTMLElement,
        );
        expect(
            updatedKpi.getAllByText('No change').length,
        ).toBeGreaterThanOrEqual(1);
    });

    it('renders revenue bar chart and recent orders linking through to /admin/orders', () => {
        render(<AdminOverviewPage />);

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Captured revenue trend',
            }),
        ).toBeVisible();

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Recent placed orders',
            }),
        ).toBeVisible();

        const viewAllLinks = screen.getAllByRole('link', {
            name: /view all orders/i,
        });
        expect(viewAllLinks.length).toBeGreaterThanOrEqual(2);

        for (const link of viewAllLinks) {
            expect(link).toHaveAttribute('href', '/en/admin/orders');
        }
    });

    it('confirms the audit feed and status distribution donut are completely removed', () => {
        const { container } = render(<AdminOverviewPage />);

        expect(screen.queryByText('Recent Admin activity')).toBeNull();
        expect(screen.queryByText('Order status distribution')).toBeNull();
        expect(
            container.querySelector('[aria-label="Order status distribution"]'),
        ).toBeNull();
    });

    it('renders purposeful empty chart and attention states when series are all-zero without credential content', () => {
        inertia.props = pageProps({
            overview: {
                ...pageProps().overview,
                attentionCount: 0,
                revenueTrend: [
                    { date: '2026-08-20', amountMinor: '0', currency: 'SAR' },
                    { date: '2026-08-21', amountMinor: '0', currency: 'SAR' },
                ],
                recentOrders: [],
                oldestUnresolvedOrder: null,
            },
        });

        const { container } = render(<AdminOverviewPage />);

        expect(
            screen.getByText('No captured revenue in this period.'),
        ).toBeVisible();
        expect(
            screen.getByText('There are no recent orders in this period.'),
        ).toBeVisible();
        expect(
            screen.getByText('There are no unresolved orders.'),
        ).toBeVisible();
        expect(container).not.toHaveTextContent(
            /credential|password|provider/i,
        );
    });

    it('exposes exact Today, 7, and 30 day links with current and loading feedback', () => {
        render(<AdminOverviewPage />);

        const today = screen.getByRole('link', { name: 'Today' });
        const current7 = screen.getByRole('link', { name: 'Last 7 days' });
        const next30 = screen.getByRole('link', { name: 'Last 30 days' });

        expect(today).toHaveAttribute('href', '/en/admin?range=1');
        expect(today).not.toHaveAttribute('aria-current');
        expect(current7).toHaveAttribute('href', '/en/admin?range=7');
        expect(current7).toHaveAttribute('aria-current', 'page');
        expect(next30).toHaveAttribute('href', '/en/admin?range=30');

        fireEvent.click(today);

        expect(
            screen.getByRole('navigation', { name: 'Date range' }),
        ).toHaveAttribute('aria-busy', 'true');
        expect(today).toHaveAttribute('data-loading', 'true');

        act(() => inertia.finish?.());

        expect(
            screen.getByRole('navigation', { name: 'Date range' }),
        ).toHaveAttribute('aria-busy', 'false');
    });
});
