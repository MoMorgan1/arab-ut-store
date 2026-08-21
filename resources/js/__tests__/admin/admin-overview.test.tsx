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
            {
                key: 'security',
                label: 'MFA Security',
                url: '/en/admin/security/mfa',
            },
        ],
        permissions: ['dashboard.view', 'audit.view'],
        overview: {
            rangeDays: 7,
            orders: { received: 12, inProgress: 3, waitingForCustomer: 4 },
            payments: { pending: 5, failed: 2 },
            refunds: { failed: 1 },
            capturedRevenue: { amountMinor: '123456', currency: 'SAR' },
            previousCapturedRevenue: { amountMinor: '100000', currency: 'SAR' },
            totalOrders: { current: 19, previous: 15 },
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
            orderStatusDistribution: [
                { status: 'pending_payment', count: 0 },
                { status: 'received', count: 12 },
                { status: 'in_progress', count: 3 },
                { status: 'waiting_for_customer', count: 4 },
                { status: 'completed', count: 0 },
                { status: 'cancelled', count: 0 },
                { status: 'refunded', count: 0 },
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
            recentAuditEvents: [
                {
                    id: 'audit-1',
                    action: 'order.status_updated',
                    createdAt: '2026-08-20T12:30:00.000000Z',
                },
            ],
        },
        rangeOptions: [
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

    it('renders dominant gross captured revenue and supporting metrics in deliberate reading order with literal values', () => {
        const { container } = render(<AdminOverviewPage />);
        const text = container.textContent ?? '';

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Operations dashboard',
        );

        const kpiStrip = container.querySelector('.admin-kpi-strip');
        expect(kpiStrip).not.toBeNull();
        const kpi = within(kpiStrip as HTMLElement);

        expect(kpi.getByText(/SAR\s+1,234\.56/)).toBeVisible();
        expect(kpi.getByText('+23.5% vs. previous period')).toBeVisible();
        expect(kpi.getByText('Total placed orders')).toBeVisible();
        expect(kpi.getByText('19')).toBeVisible();
        expect(kpi.getByText('New customers')).toBeVisible();
        expect(kpi.getByText('8')).toBeVisible();
        expect(kpi.getByText('Needs attention')).toBeVisible();
        expect(kpi.getByText('7')).toBeVisible();

        const rail = within(
            screen.getByRole('complementary', { name: 'Operational focus' }),
        );
        expect(rail.getByText('12')).toBeVisible();
        expect(rail.getByText('3')).toBeVisible();
        expect(rail.getByText('4')).toBeVisible();
        expect(rail.getByText('5')).toBeVisible();
        expect(rail.getByText('2')).toBeVisible();
        expect(rail.getByText('1')).toBeVisible();

        expect(text.indexOf('Captured revenue')).toBeLessThan(
            text.indexOf('Total placed orders'),
        );
        expect(text.indexOf('Total placed orders')).toBeLessThan(
            text.indexOf('Captured revenue trend'),
        );
        expect(text.indexOf('Captured revenue trend')).toBeLessThan(
            text.indexOf('Recent placed orders'),
        );
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

    it('shows recent orders as plain records without pretending to be links', () => {
        render(<AdminOverviewPage />);

        expect(
            screen.getAllByText('AUT-RECEIVED-1001-WITH-A-LONG-IDENTIFIER')
                .length,
        ).toBeGreaterThanOrEqual(1);
        expect(screen.getAllByText('Received').length).toBeGreaterThanOrEqual(
            1,
        );
        expect(
            screen.queryByRole('link', {
                name: /AUT-RECEIVED-1001-WITH-A-LONG-IDENTIFIER/,
            }),
        ).not.toBeInTheDocument();
    });

    it('shows global audit only when the server supplies Admin events', () => {
        const { rerender } = render(<AdminOverviewPage />);

        expect(screen.getByText('Recent Admin activity')).toBeVisible();
        expect(screen.getByText('order.status_updated')).toBeVisible();

        inertia.props = pageProps({
            adminIdentity: { name: 'Order Operator', role: 'staff' },
            permissions: ['dashboard.view'],
            overview: {
                ...pageProps().overview,
                recentAuditEvents: null,
            },
        });
        rerender(<AdminOverviewPage />);

        expect(screen.queryByText('Recent Admin activity')).toBeNull();
        expect(screen.queryByText('order.status_updated')).toBeNull();
    });

    it('renders purposeful empty chart and queue states when series are all-zero without credential content', () => {
        inertia.props = pageProps({
            overview: {
                ...pageProps().overview,
                revenueTrend: [
                    { date: '2026-08-20', amountMinor: '0', currency: 'SAR' },
                    { date: '2026-08-21', amountMinor: '0', currency: 'SAR' },
                ],
                orderStatusDistribution: [
                    { status: 'pending_payment', count: 0 },
                    { status: 'received', count: 0 },
                    { status: 'in_progress', count: 0 },
                    { status: 'waiting_for_customer', count: 0 },
                    { status: 'completed', count: 0 },
                    { status: 'cancelled', count: 0 },
                    { status: 'refunded', count: 0 },
                ],
                recentOrders: [],
                oldestUnresolvedOrder: null,
                recentAuditEvents: [],
            },
        });

        const { container } = render(<AdminOverviewPage />);

        expect(
            screen.getByText('No captured revenue in this period.'),
        ).toBeVisible();
        expect(
            screen.getByText('No orders placed in this period.'),
        ).toBeVisible();
        expect(
            screen.getByText('There are no recent orders in this period.'),
        ).toBeVisible();
        expect(
            screen.getByText('There are no unresolved orders.'),
        ).toBeVisible();
        expect(
            screen.getByText('There is no recent Admin activity.'),
        ).toBeVisible();
        expect(container).not.toHaveTextContent(
            /credential|password|provider/i,
        );
    });

    it('exposes exact 7 and 30 day links with current and loading feedback', () => {
        render(<AdminOverviewPage />);
        const current = screen.getByRole('link', { name: 'Last 7 days' });
        const next = screen.getByRole('link', { name: 'Last 30 days' });

        expect(current).toHaveAttribute('href', '/en/admin?range=7');
        expect(current).toHaveAttribute('aria-current', 'page');
        expect(next).toHaveAttribute('href', '/en/admin?range=30');

        fireEvent.click(next);

        expect(
            screen.getByRole('navigation', { name: 'Date range' }),
        ).toHaveAttribute('aria-busy', 'true');
        expect(next).toHaveAttribute('data-loading', 'true');

        act(() => inertia.finish?.());

        expect(
            screen.getByRole('navigation', { name: 'Date range' }),
        ).toHaveAttribute('aria-busy', 'false');
    });
});
