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
                key: 'settings',
                label: 'Settings',
                url: '/admin/settings',
            },
        ],
        permissions: [
            'dashboard.view',
            'orders.view',
            'customers.view',
            'customers.update_status',
            'wallet.adjust',
            'audit.view',
        ],
        customer: { ...sampleAdminCustomerDetail },
        statusUrl: '/api/customers/01K5CUST00000000000000001/status',
        contactUrl: '/api/customers/01K5CUST00000000000000001/contact',
        walletAdjustUrl:
            '/api/customers/01K5CUST00000000000000001/wallet/adjust',
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
        expect(screen.getAllByText('AUT-1001')[0]).toBeVisible();
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

        const reactivateBtn = screen.getByRole('button', {
            name: 'Reactivate customer',
        });
        expect(reactivateBtn).toBeVisible();

        fireEvent.click(reactivateBtn);

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Reactivate customer',
            }),
        ).toBeVisible();
    });

    it('renders one-line summary and mobile recent orders cards', () => {
        render(<AdminCustomerDetailPage />);

        // Mobile summary under name
        expect(
            screen.getAllByText(
                (_, element) =>
                    element?.tagName === 'SPAN' &&
                    /^\s*\d+\s+Total orders\s*$/i.test(
                        element.textContent ?? '',
                    ),
            )[0],
        ).toBeInTheDocument();

        // Recent orders link
        const orderLinks = screen.getAllByRole('link', {
            name: /AUT-1001/i,
        });
        expect(orderLinks.length).toBeGreaterThanOrEqual(1);
    });

    it('renders loyalty tier name and lifetime eligible spend in the wallet section', () => {
        render(<AdminCustomerDetailPage />);

        expect(screen.getByText('Current tier')).toBeVisible();
        expect(screen.getByText('Bronze')).toBeVisible();
        expect(screen.getByText('Lifetime eligible spend')).toBeVisible();
        expect(document.body.textContent?.replace(/\s/g, '')).toContain(
            '450.00',
        );
    });

    it('opens wallet adjustment dialog and submits credit adjustment with amount and reason', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onSuccess?: (response: {
                        data: {
                            balance: { amountMinor: string; currency: 'SAR' };
                            entry: (typeof sampleAdminCustomerDetail.recentWalletEntries)[0];
                        };
                    }) => void;
                },
            ) => {
                options.onSuccess?.({
                    data: {
                        balance: { amountMinor: '17000', currency: 'SAR' },
                        entry: {
                            id: '01K5WLT00000000000000002',
                            type: 'adjustment',
                            direction: 'credit',
                            amount: { amountMinor: '5000', currency: 'SAR' },
                            createdAt: '2026-08-20T12:00:00Z',
                            reference: 'admin-adjustment:01k5adj',
                        },
                    },
                });
            },
        );

        render(<AdminCustomerDetailPage />);

        const adjustBtn = screen.getByRole('button', {
            name: 'Adjust balance',
        });
        fireEvent.click(adjustBtn);

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Adjust wallet balance',
            }),
        ).toBeVisible();

        const amountInput = screen.getByLabelText(/Amount \(SAR\)/i);
        fireEvent.change(amountInput, { target: { value: '50.00' } });

        const reasonInput = screen.getByPlaceholderText(
            /Enter internal reason for this wallet adjustment/i,
        );
        fireEvent.change(reasonInput, {
            target: { value: 'Customer service goodwill compensation' },
        });

        const submitBtn = screen.getByRole('button', {
            name: 'Apply adjustment',
        });
        fireEvent.click(submitBtn);

        expect(http.submit).toHaveBeenCalledWith(
            'post',
            '/api/customers/01K5CUST00000000000000001/wallet/adjust',
            expect.any(Object),
        );
        expect(http.setData).toHaveBeenCalledWith({
            amount_halalah: 5000,
            reason: 'Customer service goodwill compensation',
        });

        await waitFor(() => {
            expect(
                screen.getByText('Wallet balance adjusted successfully.'),
            ).toBeVisible();
        });
    });
});
