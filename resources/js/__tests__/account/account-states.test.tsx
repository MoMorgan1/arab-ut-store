import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountSectionError from '@/components/account/account-section-error';
import AccountProfile from '@/pages/account/profile';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/my-account/profile?order=01TEST',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => page,
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        dontRemember: vi.fn(),
        errors: {},
        patch: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        processing: false,
        recentlySuccessful: false,
        reset: vi.fn(),
        setData: vi.fn(),
    }),
}));

vi.mock('@/layouts/my-account-layout', () => ({
    default: ({ children }: React.PropsWithChildren) => (
        <main data-testid="account-layout">{children}</main>
    ),
}));

beforeEach(() => {
    page.props = profileProps();
});

afterEach(cleanup);

it('renders safe contact destinations and keeps order context outside their URLs', () => {
    render(<AccountProfile />);

    expect(
        screen.getByRole('heading', { level: 3, name: 'Support' }),
    ).toBeVisible();
    expect(screen.getByText('UT-00000091')).toBeVisible();
    expect(screen.getByRole('link', { name: 'Open WhatsApp' })).toHaveAttribute(
        'href',
        'https://wa.me/966537998099',
    );
    expect(screen.getByRole('link', { name: 'Send an email' })).toHaveAttribute(
        'href',
        'mailto:support@example.test',
    );
    expect(
        screen
            .getByRole('link', { name: 'Open WhatsApp' })
            .getAttribute('href'),
    ).not.toContain('UT-00000091');
});

it('keeps the account shell visible when support is unavailable', () => {
    page.props = profileProps({
        available: false,
        emailUrl: null,
        orderNumber: null,
        whatsappUrl: null,
    });
    render(<AccountProfile />);

    expect(screen.getByTestId('account-layout')).toBeVisible();
    expect(
        screen.getByRole('heading', { name: 'Support unavailable' }),
    ).toBeVisible();
    expect(
        screen.queryByRole('link', { name: 'Open WhatsApp' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Send an email' }),
    ).not.toBeInTheDocument();
});

it('offers an accessible retry action for reusable section failures', () => {
    const retry = vi.fn();
    render(
        <AccountSectionError
            actionLabel="Try again"
            description="The section could not load."
            onRetry={retry}
            title="Could not load"
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(retry).toHaveBeenCalledOnce();
});

function profileProps(
    support: {
        available: boolean;
        emailUrl: string | null;
        orderNumber: string | null;
        whatsappUrl: string | null;
    } = {
        available: true,
        emailUrl: 'mailto:support@example.test',
        orderNumber: 'UT-00000091',
        whatsappUrl: 'https://wa.me/966537998099',
    },
) {
    return {
        locale: 'en',
        accountUi: {
            eyebrow: 'Arab UT account',
            navigation: {
                label: 'Account sections',
                overview: 'Overview',
                orders: 'Orders',
                wallet: 'Wallet',
                profile: 'Profile',
                security: 'Security',
                support: 'Support',
                logout: 'Log out',
            },
            profile: {
                title: 'Profile',
                description: 'Update your verified account details securely.',
                personal_title: 'Personal details',
                contact_title: 'Verified contact details',
                sections: {
                    label: 'Profile sections',
                    personal: 'Personal',
                    contact: 'Contact & verification',
                    security: 'Security',
                    support: 'Support',
                },
                first_name: 'First name',
                last_name: 'Last name',
                email: 'Email address',
                phone: 'WhatsApp number',
                preferred_locale: 'Preferred language',
                display_currency: 'Display currency',
                save: 'Save changes',
                saved: 'Your details have been saved.',
                edit_email: 'Edit email',
                edit_phone: 'Edit number',
                cancel_edit: 'Cancel',
                new_email: 'New email address',
                request_email: 'Send verification link',
                new_phone: 'New WhatsApp number',
                send_phone_code: 'Send WhatsApp code',
                phone_code: '6-digit verification code',
                confirm_phone: 'Confirm new number',
                sensitive_hint:
                    'A verification link or WhatsApp code will confirm the change.',
                pending_email: 'New email awaiting verification',
                pending_phone: 'New number awaiting verification',
                email_link_invalid: 'Invalid link.',
                phone_code_invalid: 'Invalid code.',
            },
            verification: {
                verified: 'Verified',
                unverified: 'Not verified',
                pending: 'Verification pending',
                send_code: 'Send code',
                verify: 'Verify',
                code: 'Verification code',
            },
            security: {
                title: 'Security',
                description: 'Manage your password.',
                current_password: 'Current password',
                new_password: 'New password',
                confirm_password: 'Confirm new password',
                change_password: 'Change password',
                set_password: 'Set a password',
                password_changed: 'Password updated.',
                social_login_notice: 'Set a password for your account.',
                change_title: 'Change your password',
                setup_title: 'Create an account password',
                change_description: 'Use your current password.',
                setup_description: 'Create a secure password.',
                recovery_title: 'Account recovery',
                recovery_email: 'Use your verified email.',
                recovery_whatsapp: 'Use WhatsApp recovery.',
                recovery_action: 'View recovery options',
            },
            support: {
                title: 'Support',
                description: 'We are here to help.',
                whatsapp_title: 'Chat on WhatsApp',
                whatsapp_description: 'Our support team is available.',
                whatsapp_action: 'Open WhatsApp',
                email_title: 'Email us',
                email_description: 'Send the team a message.',
                email_action: 'Send an email',
                order_context: 'Regarding order',
                unavailable_title: 'Support unavailable',
                unavailable_description: 'Contact options are not configured.',
            },
            actions: { retry: 'Try again' },
        },
        profile: {
            firstName: 'Mohamed',
            lastName: 'Player',
            email: {
                value: 'owner@example.test',
                verified: true,
                pending: null,
            },
            phone: {
                value: '+201001234567',
                verified: true,
                pending: null,
            },
            preferredLocale: 'en',
            displayCurrency: 'SAR',
        },
        security: {
            passwordMode: 'change',
            passwordRules: 'minlength:8',
            recoveryMode: 'email',
            recoveryUrl: '/en/forgot-password',
        },
        securityActions: {
            changePasswordUrl: '/en/my-account/security/password',
            setupPasswordUrl: '/en/my-account/security/password',
        },
        profileActions: {
            updateUrl: '/en/my-account/profile',
            emailRequestUrl: '/en/my-account/profile/email',
            phoneRequestUrl: '/en/my-account/profile/phone',
            phoneConfirmUrl: '/en/my-account/profile/phone/confirm',
        },
        support,
        displayCurrencies: ['SAR', 'AED'],
        logoutUrl: '/logout',
    };
}
