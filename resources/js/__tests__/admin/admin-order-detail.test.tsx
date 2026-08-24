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
import type {
    AdminOrderDetail,
    AdminOrderDetailPageProps,
} from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
}));

const http = vi.hoisted(() => ({
    data: { expected_status: '', target_status: '' },
    processing: false,
    setData: vi.fn(),
    submit: vi.fn(),
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
    useHttp: () => http,
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
                key: 'settings',
                label: 'Settings',
                url: '/admin/settings',
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
        refund: {
            eligible: false,
            amountMinor: '0',
            currency: 'SAR',
        },
        refundUrl: '/admin/api/orders/01K5ADM1N00000000000000001/refund',
        logoutUrl: '/logout',
    };
}

describe('AdminOrderDetailPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
        pageState.url = '/admin/orders/01K5ADM1N00000000000000001';
        http.data = { expected_status: '', target_status: '' };
        http.processing = false;
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

    it('opens confirmation modal when applying cancelled status and directly transitions for other statuses', () => {
        render(<AdminOrderDetailPage />);

        const statusSelect = screen.getByLabelText('Next status');
        const applyButton = screen.getByRole('button', {
            name: 'Apply status',
        });
        expect(applyButton).toBeDisabled();

        fireEvent.change(statusSelect, { target: { value: 'cancelled' } });
        expect(applyButton).not.toBeDisabled();
        fireEvent.click(applyButton);

        expect(
            screen.getByRole('heading', { name: 'Confirm status change' }),
        ).toBeVisible();
        expect(
            screen.getByText(
                /Are you sure you want to cancel order AUT-1001\?/i,
            ),
        ).toBeVisible();
    });

    it('submits the transition through inertia http and updates state on success without optimistic update', async () => {
        const updatedOrder = {
            ...sampleAdminOrderDetail,
            status: 'in_progress',
        };

        http.submit.mockImplementationOnce(
            (
                _method: string,
                _url: string,
                options: {
                    onSuccess?: (response: unknown) => void;
                },
            ) => {
                options.onSuccess?.({
                    order: updatedOrder,
                    status: 'in_progress',
                });

                return Promise.resolve(updatedOrder);
            },
        );

        render(<AdminOrderDetailPage />);

        const statusSelect = screen.getByLabelText('Next status');
        fireEvent.change(statusSelect, { target: { value: 'in_progress' } });

        const applyButton = screen.getByRole('button', {
            name: 'Apply status',
        });
        fireEvent.click(applyButton);

        await waitFor(() => {
            expect(http.setData).toHaveBeenCalledWith({
                expected_status: 'received',
                target_status: 'in_progress',
            });
            expect(http.submit).toHaveBeenCalledWith(
                'post',
                '/admin/orders/01K5ADM1N00000000000000001/transitions',
                expect.objectContaining({
                    headers: { Accept: 'application/json' },
                }),
            );
        });

        await waitFor(() => {
            // Scoped to the alert title: the Alert renders the same message as
            // both its title and its description, so an unscoped text query
            // matches twice for one on-screen message.
            expect(
                screen.getByText('Order status updated successfully.', {
                    selector: '[data-slot="alert-title"]',
                }),
            ).toBeVisible();
        });
    });

    it('handles 409 conflict and renders fresh canonical status without optimistic changes', async () => {
        http.submit.mockImplementationOnce(
            (
                _method: string,
                _url: string,
                options: {
                    onHttpException?: (response: {
                        data: string;
                        status: number;
                    }) => boolean | void;
                },
            ) => {
                options.onHttpException?.({
                    data: JSON.stringify({
                        order: '01K5ADM1N00000000000000001',
                        status: 'completed',
                    }),
                    status: 409,
                });

                return Promise.resolve(null);
            },
        );

        render(<AdminOrderDetailPage />);

        const statusSelect = screen.getByLabelText('Next status');
        fireEvent.change(statusSelect, { target: { value: 'in_progress' } });

        const applyButton = screen.getByRole('button', {
            name: 'Apply status',
        });
        fireEvent.click(applyButton);

        await waitFor(() => {
            expect(
                screen.getByText(
                    /This order was modified by another action. Current status is Completed. Please review before proceeding./i,
                ),
            ).toBeVisible();
        });

        expect(inertia.reload).toHaveBeenCalledWith({ only: ['order'] });
    });

    it('re-syncs local order state when incoming props.order updates, reflecting refunded badge and refund history', () => {
        const { rerender } = render(<AdminOrderDetailPage />);

        expect(
            screen.getByRole('heading', { level: 1, name: /AUT-1001/i }),
        ).toBeVisible();
        expect(screen.getAllByText('Received')[0]).toBeVisible();
        expect(screen.queryByText('Refunds')).not.toBeInTheDocument();

        const updatedOrder: AdminOrderDetail = {
            ...sampleAdminOrderDetail,
            status: 'refunded',
            refunds: [
                {
                    id: '01K5REF00000000000000001',
                    status: 'completed',
                    method: 'paylink',
                    amount: { amountMinor: '15000', currency: 'SAR' },
                    reason: 'Customer requested refund',
                    completedAt: '2026-08-20T10:15:00Z',
                    createdAt: '2026-08-20T10:10:00Z',
                },
            ],
        };

        pageState.props = {
            ...pageState.props,
            order: updatedOrder,
        };

        rerender(<AdminOrderDetailPage />);

        expect(screen.getAllByText('Refunded')[0]).toBeVisible();
        expect(screen.getByText('Refunds')).toBeVisible();
        expect(screen.getAllByText('Completed')[0]).toBeVisible();
    });

    describe('Admin credential auto-display', () => {
        const itemWithSecret = {
            ...sampleAdminOrderDetail.items[0],
            hasSecret: true,
            maskedSummary: {
                account: 'p***r@example.com',
                backupCodesCount: 2,
            },
        };

        const secretProps = (): AdminOrderDetailPageProps => ({
            ...defaultProps(),
            order: {
                ...sampleAdminOrderDetail,
                items: [itemWithSecret],
            },
            revealUrlTemplate:
                '/admin/api/orders/01K5ADM1N00000000000000001/items/__ITEM_ID__/reveal',
        });

        it('automatically submits on mount, renders loading state then values after submit resolves, and supports copying without a reveal button', async () => {
            const clipboardSpy = vi.fn().mockResolvedValue(undefined);
            Object.assign(navigator, {
                clipboard: { writeText: clipboardSpy },
            });

            pageState.props = secretProps();

            const decryptedData = {
                ea_email: 'player@example.com',
                ea_password: 'SecretPassword123!',
                ea_backup_codes: ['11111111', '22222222'],
            };

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onSuccess?: (response: unknown) => void;
                    },
                ) => {
                    options.onSuccess?.({
                        data: decryptedData,
                    });

                    return Promise.resolve({ data: decryptedData });
                },
            );

            render(<AdminOrderDetailPage />);

            // No "Reveal credentials" button exists
            expect(
                screen.queryByRole('button', { name: /Reveal credentials/i }),
            ).not.toBeInTheDocument();

            // Heading is visible
            expect(screen.getByText('Credentials')).toBeVisible();

            await waitFor(() => {
                expect(http.submit).toHaveBeenCalledWith(
                    'post',
                    '/admin/api/orders/01K5ADM1N00000000000000001/items/01K5ITEM00000000000000001/reveal',
                    expect.objectContaining({
                        headers: { Accept: 'application/json' },
                    }),
                );
            });

            // Decrypted credentials card rendered
            await waitFor(() => {
                expect(screen.getByText('Decrypted credentials')).toBeVisible();
                expect(screen.getByText('player@example.com')).toBeVisible();
                expect(screen.getByText('SecretPassword123!')).toBeVisible();
                expect(screen.getByText('11111111 · 22222222')).toBeVisible();
            });

            // Test Copy button for single value
            const copyEmailButton = screen.getByRole('button', {
                name: /Copy ea_email/i,
            });
            fireEvent.click(copyEmailButton);
            expect(clipboardSpy).toHaveBeenCalledWith('player@example.com');

            // Test Copy button for array element
            const copyCodeBtn = screen.getByRole('button', {
                name: /Copy ea_backup_codes/i,
            });
            fireEvent.click(copyCodeBtn);
            expect(clipboardSpy).toHaveBeenCalledWith('11111111\n22222222');
        });

        it('handles 410 purged secret and displays purged notice', async () => {
            pageState.props = secretProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onHttpException?: (response: {
                            data: string;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    options.onHttpException?.({
                        data: JSON.stringify({ error: 'secret_purged' }),
                        status: 410,
                    });

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'Credentials for this item have been purged and are no longer available.',
                    ),
                ).toBeVisible();
            });
        });

        it('handles 403 forbidden and displays forbidden error message without retry button', async () => {
            pageState.props = secretProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onHttpException?: (response: {
                            data: string;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    options.onHttpException?.({
                        data: JSON.stringify({ message: 'Forbidden.' }),
                        status: 403,
                    });

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'You do not have permission to view credentials.',
                    ),
                ).toBeVisible();
            });

            expect(
                screen.queryByRole('button', { name: /Retry/i }),
            ).not.toBeInTheDocument();
        });

        it('handles network error, displays network error message with Retry button, and retry re-submits', async () => {
            pageState.props = secretProps();

            const decryptedData = {
                ea_email: 'retried@example.com',
            };

            let callCount = 0;
            http.submit.mockImplementation(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onNetworkError?: () => boolean | void;
                        onSuccess?: (response: unknown) => void;
                    },
                ) => {
                    callCount++;

                    if (callCount === 1) {
                        options.onNetworkError?.();
                    } else {
                        options.onSuccess?.({ data: decryptedData });
                    }

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'Network error. Please check your connection and try again.',
                    ),
                ).toBeVisible();
            });

            const retryButton = screen.getByRole('button', { name: 'Retry' });
            expect(retryButton).toBeVisible();

            fireEvent.click(retryButton);

            await waitFor(() => {
                expect(http.submit).toHaveBeenCalledTimes(2);
                expect(screen.getByText('Decrypted credentials')).toBeVisible();
                expect(screen.getByText('retried@example.com')).toBeVisible();
            });
        });

        it('clears decrypted payload on unmount and touches no browser storage', async () => {
            const localStorageSpy = vi.spyOn(Storage.prototype, 'setItem');
            const sessionStorageSpy = vi.spyOn(
                sessionStorage.__proto__,
                'setItem',
            );

            pageState.props = secretProps();

            const decryptedData = {
                ea_email: 'player@example.com',
            };

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onSuccess?: (response: unknown) => void;
                    },
                ) => {
                    options.onSuccess?.({
                        data: decryptedData,
                    });

                    return Promise.resolve({ data: decryptedData });
                },
            );

            const { unmount } = render(<AdminOrderDetailPage />);

            await waitFor(() => {
                expect(screen.getByText('player@example.com')).toBeVisible();
            });

            unmount();

            expect(localStorageSpy).not.toHaveBeenCalled();
            expect(sessionStorageSpy).not.toHaveBeenCalled();
        });
    });

    describe('Admin Paylink refund (Task 10)', () => {
        const refundProps = (
            overrides?: Partial<AdminOrderDetailPageProps>,
        ): AdminOrderDetailPageProps => ({
            ...defaultProps(),
            permissions: [...defaultProps().permissions, 'orders.refund'],
            refund: {
                eligible: true,
                amountMinor: '15000',
                currency: 'SAR',
            },
            refundUrl: '/admin/api/orders/01K5ADM1N00000000000000001/refund',
            ...overrides,
        });

        it('renders refund control when actor has orders.refund permission and refund is eligible', () => {
            pageState.props = refundProps();
            render(<AdminOrderDetailPage />);

            const refundButton = screen.getByRole('button', {
                name: /Refund order/i,
            });
            expect(refundButton).toBeVisible();

            fireEvent.click(refundButton);

            expect(
                screen.getByRole('heading', { name: 'Issue Paylink refund' }),
            ).toBeVisible();
            expect(
                screen.getByText(
                    /Refunds the full captured payment back to the customer via Paylink/i,
                ),
            ).toBeVisible();
            expect(screen.getByText('Refund amount')).toBeVisible();
            expect(screen.getByLabelText('Refund amount')).toHaveTextContent(
                /SAR\s*150\.00/,
            );
            expect(screen.getByLabelText('Staff reason')).toBeVisible();
            expect(
                screen.getByRole('button', { name: /Refund SAR\s*150\.00/i }),
            ).toBeVisible();
        });

        it('renders status transition and refund controls cleanly when actor has permission', () => {
            pageState.props = refundProps();
            render(<AdminOrderDetailPage />);

            expect(screen.getByLabelText('Next status')).toBeVisible();
            expect(
                screen.getByRole('button', { name: /Refund order/i }),
            ).toBeVisible();
        });

        it('hides refund control when actor lacks orders.refund permission', () => {
            pageState.props = refundProps({
                permissions: ['dashboard.view', 'orders.view', 'orders.update'],
            });
            render(<AdminOrderDetailPage />);

            expect(
                screen.queryByRole('button', { name: /Refund order/i }),
            ).not.toBeInTheDocument();
        });

        it('hides refund control when refund is ineligible', () => {
            pageState.props = refundProps({
                refund: {
                    eligible: false,
                    amountMinor: '15000',
                    currency: 'SAR',
                },
            });
            render(<AdminOrderDetailPage />);

            expect(
                screen.queryByRole('button', { name: /Refund order/i }),
            ).not.toBeInTheDocument();
        });

        it('renders existing refund history independently in payment section', () => {
            pageState.props = refundProps({
                refund: {
                    eligible: false,
                    amountMinor: '15000',
                    currency: 'SAR',
                },
                order: {
                    ...sampleAdminOrderDetail,
                    refunds: [
                        {
                            id: '01K5REF00000000000000001',
                            status: 'failed',
                            method: 'paylink',
                            amount: { amountMinor: '15000', currency: 'SAR' },
                            reason: 'Provider mismatch',
                            completedAt: null,
                            createdAt: '2026-08-20T10:15:00Z',
                        },
                    ],
                },
            });
            render(<AdminOrderDetailPage />);

            expect(screen.getByText('Refunds')).toBeVisible();
            expect(screen.getByText('Failed')).toBeVisible();
        });

        it('validates staff reason length and requires non-empty reason', () => {
            pageState.props = refundProps();
            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            const submitBtn = screen.getByRole('button', {
                name: /Refund SAR\s*150\.00/i,
            });
            expect(submitBtn).toBeDisabled();

            const reasonInput = screen.getByLabelText('Staff reason');
            fireEvent.change(reasonInput, { target: { value: '   ' } });
            expect(submitBtn).toBeDisabled();

            fireEvent.change(reasonInput, {
                target: {
                    value: 'Customer requested refund after support ticket #123.',
                },
            });
            expect(submitBtn).not.toBeDisabled();
        });

        it('submits exact payload on confirm and completes refund', async () => {
            pageState.props = refundProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onSuccess?: (response: unknown) => void;
                    },
                ) => {
                    options.onSuccess?.({
                        data: {
                            refundId: '01K5REF00000000000000002',
                            status: 'completed',
                            amountHalalah: 15000,
                        },
                    });

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            const reasonInput = screen.getByLabelText('Staff reason');
            fireEvent.change(reasonInput, {
                target: { value: 'Customer cancellation.' },
            });

            const submitBtn = screen.getByRole('button', {
                name: /Refund SAR\s*150\.00/i,
            });
            fireEvent.click(submitBtn);

            await waitFor(() => {
                expect(http.setData).toHaveBeenCalledWith({
                    amountHalalah: 15000,
                    reason: 'Customer cancellation.',
                });
                expect(http.submit).toHaveBeenCalledWith(
                    'post',
                    '/admin/api/orders/01K5ADM1N00000000000000001/refund',
                    expect.objectContaining({
                        headers: { Accept: 'application/json' },
                    }),
                );
            });

            await waitFor(() => {
                expect(screen.getByText('Refund completed')).toBeVisible();
                expect(
                    screen.getByText('Refund processed successfully.'),
                ).toBeVisible();
            });

            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['order', 'refund', 'allowedTransitions'],
            });
        });

        it('maps a 422 validation response to the full refund requirement message', async () => {
            pageState.props = refundProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onError?: (errors: Record<string, string>) => void;
                    },
                ) => {
                    options.onError?.({});

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund SAR\s*150\.00/i }),
            );

            await waitFor(() => {
                expect(screen.getByText('Refund error')).toBeVisible();
                expect(
                    screen.getByText(
                        'Paylink supports a full original-payment refund only.',
                    ),
                ).toBeVisible();
            });
        });

        it('maps 409 response to order cannot be refunded automatically message', async () => {
            pageState.props = refundProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onHttpException?: (response: {
                            data: string;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    options.onHttpException?.({
                        data: JSON.stringify({
                            error: {
                                code: 'refund_unavailable',
                                message: 'Unavailable',
                            },
                        }),
                        status: 409,
                    });

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund SAR\s*150\.00/i }),
            );

            await waitFor(() => {
                expect(screen.getByText('Refund error')).toBeVisible();
                expect(
                    screen.getByText(
                        'This order cannot be refunded automatically.',
                    ),
                ).toBeVisible();
            });
        });

        it('maps 503 response to provider unavailable message', async () => {
            pageState.props = refundProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onHttpException?: (response: {
                            data: string;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    options.onHttpException?.({
                        data: JSON.stringify({
                            error: {
                                code: 'refund_provider_unavailable',
                                message: 'Unavailable',
                            },
                        }),
                        status: 503,
                    });

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund SAR\s*150\.00/i }),
            );

            await waitFor(() => {
                expect(screen.getByText('Refund error')).toBeVisible();
                expect(
                    screen.getByText(
                        'The refund provider is currently unavailable. Please try again later.',
                    ),
                ).toBeVisible();
            });
        });

        it('maps 429 response with Retry-After header to rate limit message with seconds', async () => {
            pageState.props = refundProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onHttpException?: (response: {
                            data: string;
                            headers: Record<string, string>;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    options.onHttpException?.({
                        data: JSON.stringify({ message: 'Too many attempts.' }),
                        headers: { 'retry-after': '60' },
                        status: 429,
                    });

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund SAR\s*150\.00/i }),
            );

            await waitFor(() => {
                expect(screen.getByText('Refund error')).toBeVisible();
                expect(
                    screen.getByText(
                        'Too many refund requests. Please wait 60 seconds before trying again.',
                    ),
                ).toBeVisible();
            });
        });

        it('handles network failure and displays network error message', async () => {
            pageState.props = refundProps();

            http.submit.mockImplementationOnce(
                (
                    _method: string,
                    _url: string,
                    options: {
                        onNetworkError?: () => boolean | void;
                    },
                ) => {
                    options.onNetworkError?.();

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund SAR\s*150\.00/i }),
            );

            await waitFor(() => {
                expect(screen.getByText('Refund error')).toBeVisible();
                expect(
                    screen.getByText(
                        'Network error. Please check your connection and try again.',
                    ),
                ).toBeVisible();
            });
        });
    });
});
