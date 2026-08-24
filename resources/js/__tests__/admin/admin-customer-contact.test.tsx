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
import AdminCustomerContactDialog from '@/components/admin/customers/admin-customer-contact-dialog';
import AdminCustomerDetailPage from '@/pages/admin/customers/show';
import type { AdminCustomerDetailPageProps } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
}));

const http = vi.hoisted(() => ({
    data: {
        email: '',
        expected: {
            email: '',
            first_name: '',
            last_name: '',
            phone: null as string | null,
        },
        first_name: '',
        last_name: '',
        phone: null as string | null,
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
            { key: 'settings', label: 'Settings', url: '/admin/settings' },
        ],
        permissions: [
            'dashboard.view',
            'orders.view',
            'customers.view',
            'customers.update_status',
            'customers.update_contact',
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

describe('Admin Customer Contact Management', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/customers/01K5CUST00000000000000001';
        http.data = {
            email: sampleAdminCustomerDetail.email,
            expected: {
                email: sampleAdminCustomerDetail.email,
                first_name: sampleAdminCustomerDetail.firstName,
                last_name: sampleAdminCustomerDetail.lastName,
                phone: sampleAdminCustomerDetail.phone,
            },
            first_name: sampleAdminCustomerDetail.firstName,
            last_name: sampleAdminCustomerDetail.lastName,
            phone: sampleAdminCustomerDetail.phone,
        };
        http.processing = false;
        http.setData = vi.fn();
        http.submit = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders Edit details button when customers.update_contact permission is present', () => {
        render(<AdminCustomerDetailPage />);

        expect(
            screen.getByRole('button', { name: /Edit details/i }),
        ).toBeVisible();
    });

    it('does not render Edit details button when permission is missing', () => {
        pageState.props.permissions = [
            'dashboard.view',
            'orders.view',
            'customers.view',
        ];

        render(<AdminCustomerDetailPage />);

        expect(
            screen.queryByRole('button', { name: /Edit details/i }),
        ).toBeNull();
    });

    it('opens contact dialog prefilled with customer details and disables save while unchanged', () => {
        render(<AdminCustomerDetailPage />);

        const editBtn = screen.getByRole('button', { name: /Edit details/i });
        fireEvent.click(editBtn);

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Edit customer details',
            }),
        ).toBeVisible();

        const firstNameInput = screen.getByLabelText(/First name/i);
        const lastNameInput = screen.getByLabelText(/Last name/i);
        const emailInput = screen.getByLabelText(/Email address/i);
        const phoneInput = screen.getByLabelText(/Phone number/i);

        expect(firstNameInput).toHaveValue('Saud');
        expect(lastNameInput).toHaveValue('Al-Otaibi');
        expect(emailInput).toHaveValue('saud@example.test');
        expect(phoneInput).toHaveValue('+966500000001');

        const saveBtn = screen.getByRole('button', { name: 'Save changes' });
        expect(saveBtn).toBeDisabled();
    });

    it('submits updated contact details and reloads customer prop on success', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onSuccess?: (response: {
                        data: {
                            email: string;
                            firstName: string;
                            lastName: string;
                            phone: string | null;
                            updatedAt: string;
                        };
                    }) => void;
                },
            ) => {
                options.onSuccess?.({
                    data: {
                        email: 'saud.new@example.test',
                        firstName: 'SaudModified',
                        lastName: 'Al-OtaibiModified',
                        phone: '+966509998877',
                        updatedAt: '2026-08-23T12:00:00Z',
                    },
                });
            },
        );

        render(<AdminCustomerDetailPage />);

        fireEvent.click(screen.getByRole('button', { name: /Edit details/i }));

        const firstNameInput = screen.getByLabelText(/First name/i);
        const lastNameInput = screen.getByLabelText(/Last name/i);
        const emailInput = screen.getByLabelText(/Email address/i);
        const phoneInput = screen.getByLabelText(/Phone number/i);

        fireEvent.change(firstNameInput, { target: { value: 'SaudModified' } });
        fireEvent.change(lastNameInput, {
            target: { value: 'Al-OtaibiModified' },
        });
        fireEvent.change(emailInput, {
            target: { value: 'saud.new@example.test' },
        });
        fireEvent.change(phoneInput, { target: { value: '+966509998877' } });

        const saveBtn = screen.getByRole('button', { name: 'Save changes' });
        expect(saveBtn).not.toBeDisabled();
        fireEvent.click(saveBtn);

        expect(http.setData).toHaveBeenCalledWith({
            email: 'saud.new@example.test',
            expected: {
                email: sampleAdminCustomerDetail.email,
                first_name: sampleAdminCustomerDetail.firstName,
                last_name: sampleAdminCustomerDetail.lastName,
                phone: sampleAdminCustomerDetail.phone,
            },
            first_name: 'SaudModified',
            last_name: 'Al-OtaibiModified',
            phone: '+966509998877',
        });

        expect(http.submit).toHaveBeenCalledWith(
            'post',
            '/api/customers/01K5CUST00000000000000001/contact',
            expect.any(Object),
        );

        await waitFor(() => {
            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['customer'],
            });
        });
    });

    it('allows clearing phone number and submitting null', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onSuccess?: (response: {
                        data: {
                            email: string;
                            firstName: string;
                            lastName: string;
                            phone: string | null;
                            updatedAt: string;
                        };
                    }) => void;
                },
            ) => {
                options.onSuccess?.({
                    data: {
                        email: sampleAdminCustomerDetail.email,
                        firstName: sampleAdminCustomerDetail.firstName,
                        lastName: sampleAdminCustomerDetail.lastName,
                        phone: null,
                        updatedAt: '2026-08-23T12:00:00Z',
                    },
                });
            },
        );

        render(<AdminCustomerDetailPage />);

        fireEvent.click(screen.getByRole('button', { name: /Edit details/i }));

        const phoneInput = screen.getByLabelText(/Phone number/i);
        fireEvent.change(phoneInput, { target: { value: '' } });

        const saveBtn = screen.getByRole('button', { name: 'Save changes' });
        expect(saveBtn).not.toBeDisabled();
        fireEvent.click(saveBtn);

        expect(http.setData).toHaveBeenCalledWith({
            email: 'saud@example.test',
            expected: {
                email: sampleAdminCustomerDetail.email,
                first_name: sampleAdminCustomerDetail.firstName,
                last_name: sampleAdminCustomerDetail.lastName,
                phone: sampleAdminCustomerDetail.phone,
            },
            first_name: 'Saud',
            last_name: 'Al-Otaibi',
            phone: null,
        });
    });

    it('displays inline 422 field errors', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onError?: (errors: Record<string, string>) => void;
                },
            ) => {
                options.onError?.({
                    email: 'The email has already been taken.',
                    phone: 'The phone field format is invalid.',
                });
            },
        );

        render(
            <AdminCustomerContactDialog
                adminUi={englishAdminUi}
                contactUrl="/api/customers/01K5CUST00000000000000001/contact"
                customer={sampleAdminCustomerDetail}
                onConflict={vi.fn()}
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                open={true}
            />,
        );

        const emailInput = screen.getByLabelText(/Email address/i);
        fireEvent.change(emailInput, {
            target: { value: 'taken@example.test' },
        });

        const saveBtn = screen.getByRole('button', { name: 'Save changes' });
        fireEvent.click(saveBtn);

        await waitFor(() => {
            expect(
                screen.getByText('The email has already been taken.'),
            ).toBeVisible();
        });
    });

    it('handles 409 conflict by displaying conflict message and reloading customer', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onHttpException?: (response: {
                        status: number;
                        data: { customer: string; updatedAt: string };
                    }) => boolean;
                },
            ) => {
                options.onHttpException?.({
                    status: 409,
                    data: {
                        customer: '01K5CUST00000000000000001',
                        updatedAt: '2026-08-23T15:00:00Z',
                    },
                });
            },
        );

        render(<AdminCustomerDetailPage />);

        fireEvent.click(screen.getByRole('button', { name: /Edit details/i }));

        const firstNameInput = screen.getByLabelText(/First name/i);
        fireEvent.change(firstNameInput, {
            target: { value: 'DifferentName' },
        });

        const saveBtn = screen.getByRole('button', { name: 'Save changes' });
        fireEvent.click(saveBtn);

        await waitFor(() => {
            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['customer'],
            });
            expect(
                screen.getByText(
                    englishAdminUi.customerDetail.contactConflictError,
                ),
            ).toBeVisible();
        });
    });

    it('surfaces a generic failure message when the route errors', async () => {
        http.submit.mockImplementation(
            async (
                _method: string,
                _url: string,
                options: {
                    onHttpException?: (response: {
                        status: number;
                        data: unknown;
                    }) => boolean;
                },
            ) => {
                options.onHttpException?.({ status: 500, data: {} });
            },
        );

        render(<AdminCustomerDetailPage />);

        fireEvent.click(screen.getByRole('button', { name: /Edit details/i }));
        fireEvent.change(screen.getByLabelText(/First name/i), {
            target: { value: 'DifferentName' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(
                screen.getByText(
                    englishAdminUi.customerDetail.updateContactFailed,
                ),
            ).toBeVisible();
        });
    });
});
