import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    englishAdminUi,
    sampleAdminOrderDetail,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminOrderDetailPage from '@/pages/admin/orders/show';
import type { AdminOrderDetailPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/orders/show',
    url: '/admin/orders/01K5ADM1N00000000000000001',
    props: {} as AdminOrderDetailPageProps,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

function defaultProps(): AdminOrderDetailPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/admin' },
            { key: 'orders', label: 'Orders', url: '/admin/orders' },
            {
                key: 'security',
                label: 'MFA Security',
                url: '/admin/security/mfa',
            },
        ],
        permissions: [
            'dashboard.view',
            'orders.view',
            'orders.update',
            'orders.cancel',
            'audit.view',
        ],
        order: { ...sampleAdminOrderDetail },
        allowedTransitions: [
            'in_progress',
            'waiting_for_customer',
            'completed',
            'cancelled',
        ],
        transitionUrl: '/admin/orders/01K5ADM1N00000000000000001/transitions',
        logoutUrl: '/logout',
    };
}

describe('AdminOrderDetailPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/orders/01K5ADM1N00000000000000001';
        global.fetch = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders customer info, order items, money breakdown, and status history', () => {
        render(<AdminOrderDetailPage />);

        expect(
            screen.getByRole('heading', { level: 1, name: /AUT-1001/i }),
        ).toBeVisible();

        // Customer information
        expect(screen.getByText('Saud Al-Otaibi')).toBeVisible();
        expect(screen.getByText('saud@example.test')).toBeVisible();
        expect(screen.getByText('+966500000001')).toBeVisible();

        // Order items
        expect(screen.getByText('Coins Service')).toBeVisible();

        // Money breakdown
        expect(screen.getByText('Payment & breakdown')).toBeVisible();

        // History
        expect(screen.getByText('Status history')).toBeVisible();
        expect(screen.getByText('Admin audit activity')).toBeVisible();
    });

    it('opens confirmation modal when clicking a transition button', () => {
        render(<AdminOrderDetailPage />);

        const inProgressButton = screen.getByRole('button', {
            name: /Change status to In progress/i,
        });
        fireEvent.click(inProgressButton);

        expect(
            screen.getByRole('heading', { name: 'Confirm status change' }),
        ).toBeVisible();
        expect(
            screen.getByText(
                /Are you sure you want to change order AUT-1001 to In progress\?/i,
            ),
        ).toBeVisible();
    });

    it('dispatches transition POST request and updates state on 200 success without optimistic update', async () => {
        const updatedOrder = {
            ...sampleAdminOrderDetail,
            status: 'in_progress',
        };

        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ order: updatedOrder, status: 'in_progress' }),
        });

        render(<AdminOrderDetailPage />);

        const inProgressButton = screen.getByRole('button', {
            name: /Change status to In progress/i,
        });
        fireEvent.click(inProgressButton);

        const confirmButton = screen.getByRole('button', {
            name: 'Confirm transition',
        });
        fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                '/admin/orders/01K5ADM1N00000000000000001/transitions',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({
                        expected_status: 'received',
                        target_status: 'in_progress',
                    }),
                }),
            );
        });

        await waitFor(() => {
            expect(
                screen.getAllByText('Order status updated successfully.')
                    .length,
            ).toBeGreaterThanOrEqual(1);
        });
    });

    it('handles 409 conflict and renders fresh canonical status without optimistic changes', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            ok: false,
            status: 409,
            json: async () => ({
                order: '01K5ADM1N00000000000000001',
                status: 'completed',
            }),
        });

        render(<AdminOrderDetailPage />);

        const inProgressButton = screen.getByRole('button', {
            name: /Change status to In progress/i,
        });
        fireEvent.click(inProgressButton);

        const confirmButton = screen.getByRole('button', {
            name: 'Confirm transition',
        });
        fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(
                screen.getByText(
                    /This order was modified by another action. Current status is Completed. Please review before proceeding./i,
                ),
            ).toBeVisible();
        });

        expect(inertia.reload).toHaveBeenCalledWith({ only: ['order'] });
    });
});
