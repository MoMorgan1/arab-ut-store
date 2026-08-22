import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
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

        const inProgressButton = screen.getByRole('button', {
            name: /Change status to In progress/i,
        });
        fireEvent.click(inProgressButton);

        const confirmButton = screen.getByRole('button', {
            name: 'Confirm transition',
        });
        fireEvent.click(confirmButton);

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
            expect(
                screen.getAllByText('Order status updated successfully.')
                    .length,
            ).toBeGreaterThanOrEqual(1);
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

    describe('Admin credential reveal (Task 9)', () => {
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

        it('renders masked summary chips and Reveal credentials button for items with secrets', () => {
            pageState.props = secretProps();
            render(<AdminOrderDetailPage />);

            expect(screen.getByText('Stored credentials:')).toBeVisible();
            expect(screen.getByText('account:')).toBeVisible();
            expect(screen.getByText('p***r@example.com')).toBeVisible();
            expect(screen.getByText('backupCodesCount:')).toBeVisible();
            expect(screen.getByText('2')).toBeVisible();

            expect(
                screen.getByRole('button', { name: /Reveal credentials/i }),
            ).toBeVisible();
        });

        it('opens inline purpose selector and case reference input on clicking reveal', () => {
            pageState.props = secretProps();
            render(<AdminOrderDetailPage />);

            const revealButton = screen.getByRole('button', {
                name: /Reveal credentials/i,
            });
            fireEvent.click(revealButton);

            expect(screen.getByText('Access purpose')).toBeVisible();
            expect(screen.getByText('Order fulfillment')).toBeVisible();
            expect(screen.getByText('Customer support inquiry')).toBeVisible();
            expect(
                screen.getByText('Order review or verification'),
            ).toBeVisible();
            expect(screen.getByText('Incident investigation')).toBeVisible();

            expect(
                screen.getByLabelText(/Case reference \(optional\)/i),
            ).toBeVisible();
            expect(
                screen.getByRole('button', { name: /Confirm reveal/i }),
            ).toBeVisible();
            expect(
                within(screen.getByTestId('reveal-panel')).getByRole('button', {
                    name: 'Cancel',
                }),
            ).toBeVisible();
        });

        it('successfully reveals credentials on 200, supports show/hide and copy, forgets on close, and never writes to storage', async () => {
            const localStorageSpy = vi.spyOn(Storage.prototype, 'setItem');
            const sessionStorageSpy = vi.spyOn(
                sessionStorage.__proto__,
                'setItem',
            );
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

            fireEvent.click(
                screen.getByRole('button', { name: /Reveal credentials/i }),
            );

            const caseInput = screen.getByLabelText(
                /Case reference \(optional\)/i,
            );
            fireEvent.change(caseInput, { target: { value: 'CR-1001' } });

            const confirmRevealBtn = screen.getByRole('button', {
                name: /Confirm reveal/i,
            });
            fireEvent.click(confirmRevealBtn);

            await waitFor(() => {
                expect(http.setData).toHaveBeenCalledWith({
                    case_reference: 'CR-1001',
                    purpose: 'fulfillment',
                });
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
                expect(screen.getByText('••••••••')).toBeVisible();
                expect(screen.getByText('11111111')).toBeVisible();
                expect(screen.getByText('22222222')).toBeVisible();
            });

            // Test Show/Hide password toggle
            const showPasswordButton = screen.getByRole('button', {
                name: /Show credentials/i,
            });
            fireEvent.click(showPasswordButton);
            expect(screen.getByText('SecretPassword123!')).toBeVisible();

            // Test Copy button
            const copyEmailButton = screen.getByRole('button', {
                name: /Copy ea_email/i,
            });
            fireEvent.click(copyEmailButton);
            expect(clipboardSpy).toHaveBeenCalledWith('player@example.com');

            // Close credentials forgets state
            const closeBtn = screen.getByRole('button', {
                name: /Close credentials/i,
            });
            fireEvent.click(closeBtn);

            expect(
                screen.queryByText('Decrypted credentials'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('player@example.com'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: /Reveal credentials/i }),
            ).toBeVisible();

            // Verify no browser storage was touched
            expect(localStorageSpy).not.toHaveBeenCalled();
            expect(sessionStorageSpy).not.toHaveBeenCalled();
        });

        it('handles 410 purged secret and disables further attempts', async () => {
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

            fireEvent.click(
                screen.getByRole('button', { name: /Reveal credentials/i }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: /Confirm reveal/i }),
            );

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'Credentials for this item have been purged and are no longer available.',
                    ),
                ).toBeVisible();
            });

            expect(
                screen.queryByRole('button', { name: /Reveal credentials/i }),
            ).not.toBeInTheDocument();
        });

        it('handles 423 password confirmation response, prompts for password, and replays reveal automatically on confirmation', async () => {
            pageState.props = secretProps();

            const decryptedData = {
                ea_email: 'replayed@example.com',
            };

            let callCount = 0;
            http.submit.mockImplementation(
                (
                    _method: string,
                    url: string,
                    options: {
                        onSuccess?: (response: unknown) => void;
                        onHttpException?: (response: {
                            data: string;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    callCount++;

                    if (callCount === 1) {
                        // First call to reveal returns 423
                        options.onHttpException?.({
                            data: JSON.stringify({
                                message: 'Password confirmation required.',
                            }),
                            status: 423,
                        });
                    } else if (
                        callCount === 2 &&
                        url.includes('confirm-password')
                    ) {
                        // Second call to password confirm succeeds
                        options.onSuccess?.({});
                    } else if (callCount === 3 && url.includes('reveal')) {
                        // Replayed reveal succeeds
                        options.onSuccess?.({ data: decryptedData });
                    }

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.click(
                screen.getByRole('button', { name: /Reveal credentials/i }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: /Confirm reveal/i }),
            );

            // Password modal opens
            await waitFor(() => {
                expect(
                    screen.getByRole('heading', {
                        name: 'Confirm your password',
                    }),
                ).toBeVisible();
                expect(
                    screen.getByText(
                        /For security, please enter your password to confirm access to order credentials\./i,
                    ),
                ).toBeVisible();
            });

            // Enter password and submit
            const passwordInput = screen.getByPlaceholderText(
                'Enter your current password',
            );
            fireEvent.change(passwordInput, {
                target: { value: 'MySecretPassword123' },
            });

            const confirmPwButton = screen.getByRole('button', {
                name: 'Confirm password',
            });
            fireEvent.click(confirmPwButton);

            await waitFor(() => {
                expect(screen.getByText('Decrypted credentials')).toBeVisible();
                expect(screen.getByText('replayed@example.com')).toBeVisible();
            });
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

            expect(
                screen.getByRole('heading', { name: 'Issue Paylink refund' }),
            ).toBeVisible();
            expect(
                screen.getByText(
                    'Refund the captured payment back to the customer via Paylink.',
                ),
            ).toBeVisible();
            expect(screen.getByText('Refund amount')).toBeVisible();
            expect(screen.getByLabelText('Refund amount')).toHaveTextContent(
                /SAR\s*150\.00/,
            );
            expect(screen.getByLabelText('Staff reason')).toBeVisible();
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
                screen.queryByRole('heading', { name: 'Issue Paylink refund' }),
            ).not.toBeInTheDocument();
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
                screen.queryByRole('heading', { name: 'Issue Paylink refund' }),
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

            const submitBtn = screen.getByRole('button', {
                name: /Refund order/i,
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

        it('opens second confirmation modal naming exact order number and consequence, and submits exact payload on confirm', async () => {
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

            const reasonInput = screen.getByLabelText('Staff reason');
            fireEvent.change(reasonInput, {
                target: { value: 'Customer cancellation.' },
            });

            const initialSubmitBtn = screen.getByRole('button', {
                name: /Refund order/i,
            });
            fireEvent.click(initialSubmitBtn);

            await waitFor(() => {
                expect(
                    screen.getByRole('heading', {
                        name: 'Confirm Paylink refund',
                    }),
                ).toBeVisible();
                expect(
                    screen.getByText(
                        /Are you sure you want to refund SAR\s*150\.00 for order AUT-1001\? This will return the payment to the customer and mark the order as refunded\./i,
                    ),
                ).toBeVisible();
                expect(
                    screen.getByRole('button', { name: 'Issue full refund' }),
                ).toBeVisible();
            });

            const confirmBtn = screen.getByRole('button', {
                name: 'Issue full refund',
            });
            fireEvent.click(confirmBtn);

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

        it('keeps the password prompt open after invalid credentials, then replays the exact refund payload after confirmation', async () => {
            pageState.props = refundProps();

            let callCount = 0;
            http.submit.mockImplementation(
                (
                    _method: string,
                    url: string,
                    options: {
                        onError?: (errors: Record<string, string>) => void;
                        onSuccess?: (response: unknown) => void;
                        onHttpException?: (response: {
                            data: string;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    callCount++;

                    if (callCount === 1) {
                        options.onHttpException?.({
                            data: JSON.stringify({
                                message: 'Password confirmation required.',
                            }),
                            status: 423,
                        });
                    } else if (
                        callCount === 2 &&
                        url.includes('confirm-password')
                    ) {
                        options.onError?.({
                            password: 'The provided password was incorrect.',
                        });
                    } else if (
                        callCount === 3 &&
                        url.includes('confirm-password')
                    ) {
                        options.onSuccess?.({});
                    } else if (callCount === 4 && url.includes('refund')) {
                        options.onSuccess?.({
                            data: {
                                refundId: '01K5REF00000000000000003',
                                status: 'completed',
                                amountHalalah: 15000,
                            },
                        });
                    }

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Valid reason.' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            await waitFor(() => {
                expect(
                    screen.getByRole('button', { name: 'Issue full refund' }),
                ).toBeVisible();
            });
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue full refund' }),
            );

            await waitFor(() => {
                expect(
                    screen.getByRole('heading', {
                        name: 'Confirm your password',
                    }),
                ).toBeVisible();
                expect(
                    screen.getByText(
                        /For security, please enter your password to confirm this refund\./i,
                    ),
                ).toBeVisible();
            });

            fireEvent.change(
                screen.getByPlaceholderText('Enter your current password'),
                {
                    target: { value: 'AdminPassword!12' },
                },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Confirm password' }),
            );

            await waitFor(() => {
                expect(
                    screen.getByText('The provided password was incorrect.'),
                ).toBeVisible();
            });

            fireEvent.change(
                screen.getByPlaceholderText('Enter your current password'),
                {
                    target: { value: 'CorrectAdminPassword!12' },
                },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Confirm password' }),
            );

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

        it('reopens the password confirmation modal if replayed refund itself returns 423 and submits exact original payload both times', async () => {
            pageState.props = refundProps();

            let callCount = 0;
            const submittedPayloads: unknown[] = [];

            http.setData.mockImplementation((data: unknown) => {
                submittedPayloads.push(data);
            });

            http.submit.mockImplementation(
                (
                    _method: string,
                    url: string,
                    options: {
                        onSuccess?: (response: unknown) => void;
                        onHttpException?: (response: {
                            data: string;
                            status: number;
                        }) => boolean | void;
                    },
                ) => {
                    callCount++;

                    if (callCount === 1) {
                        options.onHttpException?.({
                            data: JSON.stringify({
                                message: 'Password confirmation required.',
                            }),
                            status: 423,
                        });
                    } else if (
                        callCount === 2 &&
                        url.includes('confirm-password')
                    ) {
                        options.onSuccess?.({});
                    } else if (callCount === 3 && url.includes('refund')) {
                        options.onHttpException?.({
                            data: JSON.stringify({
                                message:
                                    'Password confirmation required again.',
                            }),
                            status: 423,
                        });
                    } else if (
                        callCount === 4 &&
                        url.includes('confirm-password')
                    ) {
                        options.onSuccess?.({});
                    } else if (callCount === 5 && url.includes('refund')) {
                        options.onSuccess?.({
                            data: {
                                refundId: '01K5REF00000000000000004',
                                status: 'completed',
                                amountHalalah: 15000,
                            },
                        });
                    }

                    return Promise.resolve(null);
                },
            );

            render(<AdminOrderDetailPage />);

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Persistent reason snapshot.' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );

            await waitFor(() => {
                expect(
                    screen.getByRole('button', { name: 'Issue full refund' }),
                ).toBeVisible();
            });
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue full refund' }),
            );

            await waitFor(() => {
                expect(
                    screen.getByRole('heading', {
                        name: 'Confirm your password',
                    }),
                ).toBeVisible();
            });

            fireEvent.change(
                screen.getByPlaceholderText('Enter your current password'),
                {
                    target: { value: 'FirstPasswordAttempt' },
                },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Confirm password' }),
            );

            await waitFor(() => {
                expect(
                    screen.getByRole('heading', {
                        name: 'Confirm your password',
                    }),
                ).toBeVisible();
            });

            fireEvent.change(
                screen.getByPlaceholderText('Enter your current password'),
                {
                    target: { value: 'SecondPasswordAttempt' },
                },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Confirm password' }),
            );

            await waitFor(() => {
                expect(screen.getByText('Refund completed')).toBeVisible();
                expect(
                    screen.getByText('Refund processed successfully.'),
                ).toBeVisible();
            });

            const refundPayloads = submittedPayloads.filter(
                (p): p is { amountHalalah: number; reason: string } =>
                    Boolean(
                        p &&
                        typeof p === 'object' &&
                        'reason' in p &&
                        p.reason === 'Persistent reason snapshot.',
                    ),
            );
            expect(refundPayloads).toEqual([
                {
                    amountHalalah: 15000,
                    reason: 'Persistent reason snapshot.',
                },
                {
                    amountHalalah: 15000,
                    reason: 'Persistent reason snapshot.',
                },
                {
                    amountHalalah: 15000,
                    reason: 'Persistent reason snapshot.',
                },
            ]);
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

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue full refund' }),
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

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue full refund' }),
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

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue full refund' }),
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

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue full refund' }),
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

            fireEvent.change(screen.getByLabelText('Staff reason'), {
                target: { value: 'Reason' },
            });
            fireEvent.click(
                screen.getByRole('button', { name: /Refund order/i }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue full refund' }),
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
