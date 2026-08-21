import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
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

const arabicUi = {
    ...englishAdminUi,
    brand: 'عرب التيميت',
    overview: {
        capturedRevenue: 'الإيراد المحصل',
        description: 'ملخص واضح للطلبات والمدفوعات التي تحتاج إلى متابعة.',
        failedPayments: 'مدفوعات فاشلة',
        failedRefunds: 'استردادات فاشلة',
        headTitle: 'نظرة عامة على العمليات',
        inProgressOrders: 'طلبات قيد التنفيذ',
        noAudit: 'لا توجد نشاطات إدارية حديثة.',
        noUnresolved: 'لا توجد طلبات مفتوحة.',
        oldestUnresolved: 'أقدم طلب مفتوح',
        pendingPayments: 'مدفوعات معلقة',
        range7: 'آخر 7 أيام',
        range30: 'آخر 30 يومًا',
        receivedOrders: 'طلبات مستلمة',
        recentAudit: 'آخر نشاطات الإدارة',
        title: 'لوحة العمليات',
        waitingForCustomer: 'بانتظار العميل',
    },
    statuses: {
        received: 'مستلم',
        in_progress: 'قيد التنفيذ',
        waiting_for_customer: 'بانتظار العميل',
    },
};

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
            oldestUnresolvedOrder: {
                id: '01J-LONG-PRIVATE-ID',
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

    it('renders work, failed money, and revenue in deliberate reading order with literal values', () => {
        const { container } = render(<AdminOverviewPage />);
        const text = container.textContent ?? '';

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Operations dashboard',
        );
        expect(screen.getByText('12')).toBeVisible();
        expect(screen.getByText('3')).toBeVisible();
        expect(screen.getByText('4')).toBeVisible();
        expect(screen.getByText('5')).toBeVisible();
        expect(screen.getByText('2')).toBeVisible();
        expect(screen.getByText('1')).toBeVisible();
        expect(screen.getByText(/SAR\s+1,234\.56/)).toBeVisible();

        expect(text.indexOf('Received orders')).toBeLessThan(
            text.indexOf('Failed payments'),
        );
        expect(text.indexOf('Failed payments')).toBeLessThan(
            text.indexOf('Captured revenue'),
        );
    });

    it('renders Arabic natively with locale-aware values and dates', () => {
        inertia.props = pageProps({
            locale: 'ar',
            direction: 'rtl',
            adminUi: arabicUi,
            rangeOptions: [
                {
                    days: 7,
                    label: 'آخر 7 أيام',
                    url: '/admin?range=7',
                    active: true,
                },
                {
                    days: 30,
                    label: 'آخر 30 يومًا',
                    url: '/admin?range=30',
                    active: false,
                },
            ],
        });

        const { container } = render(<AdminOverviewPage />);

        expect(container.firstElementChild).toHaveAttribute('dir', 'rtl');
        expect(screen.getByText('طلبات مستلمة')).toBeVisible();
        expect(
            screen.getByText(new Intl.NumberFormat('ar').format(12)),
        ).toBeVisible();
        expect(
            screen.getByText(
                new Intl.DateTimeFormat('ar', {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                    timeZone: 'UTC',
                }).format(new Date('2026-08-20T10:00:00.000000Z')),
            ),
        ).toBeVisible();
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

    it('shows the oldest unresolved identity and status without inventing a detail link', () => {
        render(<AdminOverviewPage />);

        expect(
            screen.getByText('AUT-RECEIVED-1001-WITH-A-LONG-IDENTIFIER'),
        ).toBeVisible();
        expect(screen.getByText('Received')).toBeVisible();
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

    it('renders clear empty queue states without charts or credential content', () => {
        inertia.props = pageProps({
            overview: {
                ...pageProps().overview,
                oldestUnresolvedOrder: null,
                recentAuditEvents: [],
            },
        });

        const { container } = render(<AdminOverviewPage />);

        expect(
            screen.getByText('There are no unresolved orders.'),
        ).toBeVisible();
        expect(
            screen.getByText('There is no recent Admin activity.'),
        ).toBeVisible();
        expect(container.querySelector('canvas, svg[role="img"]')).toBeNull();
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
