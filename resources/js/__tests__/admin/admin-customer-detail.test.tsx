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
    sampleAdminCustomerDetail,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminCustomerDetailPage from '@/pages/admin/customers/show';
import type { AdminCustomerDetailPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
}));

const http = vi.hoisted(() => ({
    data: {
        action: '',
        case_reference: null as string | null,
        expected_active: true,
        reason_code: '',
    },
    processing: false,
    setData: vi.fn(),
    submit: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/customers/show',
    url: '/admin/customers/01K5CUST00000000000000001',
    props: {} as AdminCustomerDetailPageProps,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    useHttp: () => http,
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

function defaultProps(): AdminCustomerDetailPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity: { name: 'Operations Owner', role: 'admin' },
        adminNavigation: [
            { key: 'overview', label: 'Overview', url: '/admin' },
            { key: 'orders', label: 'Orders', url: '/admin/orders' },
            { key: 'customers', label: 'Customers', url: '/admin/customers' },
            {
                key: 'security',
                label: 'MFA Security',
                url: '/admin/security/mfa',
            },
        ],
        permissions: [
            'dashboard.view',
            'orders.view',
            'customers.view',
            'customers.update_status',
            'audit.view',
        ],
        customer: { ...sampleAdminCustomerDetail },
        statusUrl: '/api/customers/01K5CUST00000000000000001/status',
        contactUrl: '/api/customers/01K5CUST00000000000000001/contact',
        confirmPasswordUrl: '/user/confirm-password',
        logoutUrl: '/logout',
    };
}

describe('AdminCustomerDetailPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/customers/01K5CUST00000000000000001';
        http.data = {
            action: '',
            case_reference: null,
            expected_active: true,
            reason_code: '',
        };
        http.processing = false;
        http.setData = vi.fn();
        http.submit = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders customer identity, orders summary, recent orders, and wallet summary', () => {
        render(<AdminCustomerDetailPage />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Saud Al-Otaibi',
            }),
        ).toBeVisible();
        expect(screen.getByText('saud@example.test')).toBeVisible();
        expect(screen.getByText('+966500000001')).toBeVisible();
        expect(screen.getByText('AUT-1001')).toBeVisible();
        expect(screen.getByText('PROMO-2026')).toBeVisible();
    });

    it('renders back to customers navigation link', () => {
        render(<AdminCustomerDetailPage />);

        const backLink = screen.getByRole('link', {
            name: /Back to customers/i,
        });
        expect(backLink).toHaveAttribute('href', '/admin/customers');
    });

    it('opens status dialog and submits suspend mutation with reason code and case reference', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onSuccess?: (response: {
                        data: { isActive: boolean; updatedAt: string };
                    }) => void;
                },
            ) => {
                options.onSuccess?.({
                    data: {
                        isActive: false,
                        updatedAt: '2026-08-20T12:00:00Z',
                    },
                });
            },
        );

        render(<AdminCustomerDetailPage />);

        const suspendBtn = screen.getByRole('button', {
            name: 'Suspend customer',
        });
        fireEvent.click(suspendBtn);

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Suspend customer',
            }),
        ).toBeVisible();

        const reasonSelect = screen.getByRole('combobox', {
            name: /Reason code/i,
        });
        fireEvent.change(reasonSelect, {
            target: { value: 'fraud_suspected' },
        });

        const caseRefInput = screen.getByPlaceholderText(
            'e.g. TICKET-1234 or CR-5678',
        );
        fireEvent.change(caseRefInput, { target: { value: 'CR-9999' } });

        const confirmBtn = screen.getByRole('button', {
            name: 'Suspend customer',
        });
        fireEvent.click(confirmBtn);

        expect(http.submit).toHaveBeenCalledWith(
            'post',
            '/api/customers/01K5CUST00000000000000001/status',
            expect.any(Object),
        );
        expect(http.setData).toHaveBeenCalledWith({
            action: 'suspend',
            case_reference: 'CR-9999',
            expected_active: true,
            reason_code: 'fraud_suspected',
        });

        await waitFor(() => {
            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['customer'],
            });
        });
    });

    it('handles 409 conflict and triggers reload', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onHttpException?: (response: {
                        status: number;
                        data: { customer: string; isActive: boolean };
                    }) => boolean;
                },
            ) => {
                options.onHttpException?.({
                    status: 409,
                    data: {
                        customer: '01K5CUST00000000000000001',
                        isActive: false,
                    },
                });
            },
        );

        render(<AdminCustomerDetailPage />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Suspend customer' }),
        );

        const reasonSelect = screen.getByRole('combobox', {
            name: /Reason code/i,
        });
        fireEvent.change(reasonSelect, { target: { value: 'abuse' } });

        fireEvent.click(
            screen.getByRole('button', { name: 'Suspend customer' }),
        );

        await waitFor(() => {
            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['customer'],
            });
        });
    });

    it('displays reactivate button and opens reactivate dialog when customer is suspended', () => {
        pageState.props.customer = {
            ...pageState.props.customer,
            isActive: false,
        };

        render(<AdminCustomerDetailPage />);

        expect(
            screen.getByRole('button', { name: 'Reactivate customer' }),
        ).toBeVisible();

        fireEvent.click(
            screen.getByRole('button', { name: 'Reactivate customer' }),
        );

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Reactivate customer',
            }),
        ).toBeVisible();
    });
});
