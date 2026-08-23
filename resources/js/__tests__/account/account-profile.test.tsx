import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountProfile from '@/pages/account/profile';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/my-account/profile',
}));
const inertia = vi.hoisted(() => ({
    flushAll: vi.fn(),
    post: vi.fn(),
}));
const excluded = vi.hoisted(() => [] as string[][]);
type FormOptions = {
    onError?: (errors: Record<string, string>) => void;
    onSuccess?: () => void;
};

let phoneRequestShouldFail = true;
const formStore = new Map<string, Record<string, string>>();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: inertia,
    usePage: () => page,
    useForm: (initial: Record<string, string>) => {
        const key = Object.keys(initial).join('|');
        let formData = formStore.get(key) ?? { ...initial };
        formStore.set(key, formData);

        return {
            data: formData,
            dontRemember: (...fields: string[]) => excluded.push(fields),
            errors: {},
            patch: vi.fn(),
            post: vi.fn((url: string, options?: FormOptions) => {
                if (url.includes('/profile/email')) {
                    options?.onError?.({ email: 'Invalid email.' });
                } else if (url.includes('/profile/phone/confirm')) {
                    options?.onSuccess?.();
                } else if (url.includes('/profile/phone')) {
                    if (phoneRequestShouldFail) {
                        options?.onError?.({ phone: 'Invalid phone.' });
                    } else {
                        options?.onSuccess?.();
                    }
                }
            }),
            put: vi.fn(),
            processing: false,
            recentlySuccessful: false,
            reset: vi.fn(() => {
                formData = { ...initial };
                formStore.set(key, formData);
            }),
            setData: vi.fn((key: string, value: string) => {
                formData[key] = value;
            }),
        };
    },
}));

vi.mock('@/layouts/my-account-layout', () => ({
    default: ({ children }: React.PropsWithChildren) => <main>{children}</main>,
}));

beforeEach(() => {
    excluded.length = 0;
    phoneRequestShouldFail = true;
    formStore.clear();
    page.props = profileProps();
});

afterEach(cleanup);

it('renders persistent identity labels, verified states, and safe autocomplete contracts', () => {
    render(<AccountProfile />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'Profile' }),
    ).toBeVisible();
    expect(screen.getByLabelText('First name')).toHaveAttribute(
        'autocomplete',
        'given-name',
    );
    expect(screen.getByLabelText('Last name')).toHaveAttribute(
        'autocomplete',
        'family-name',
    );
    expect(screen.getAllByText('Verified')).toHaveLength(2);
    expect(
        screen.queryByLabelText('New email address'),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit email' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Edit email' }));
    expect(screen.getByLabelText('New email address')).toHaveAttribute(
        'autocomplete',
        'email',
    );
    expect(
        screen
            .getByRole('button', { name: 'Cancel' })
            .closest('.account-profile-contact'),
    ).toHaveClass('is-editing');
    fireEvent.click(screen.getByRole('button', { name: 'Edit number' }));
    expect(screen.getByLabelText('New WhatsApp number')).toHaveAttribute(
        'autocomplete',
        'tel',
    );
    expect(screen.queryByDisplayValue(/\$2y\$/)).not.toBeInTheDocument();
});

it.each([
    ['Edit email', 'Send verification link', 'New email address'],
    ['Edit number', 'Send WhatsApp code', 'New WhatsApp number'],
])(
    'focuses the inline contact field when %s validation fails',
    (editAction, submitAction, fieldLabel) => {
        render(<AccountProfile />);

        fireEvent.click(screen.getByRole('button', { name: editAction }));
        fireEvent.click(screen.getByRole('button', { name: submitAction }));

        expect(screen.getByLabelText(fieldLabel)).toHaveFocus();
    },
);

it('excludes every secret identity field from remembered Inertia state', () => {
    render(<AccountProfile />);

    expect(excluded).toContainEqual(['code']);
});

it('renders the password reset button for verified emails', () => {
    render(<AccountProfile />);

    expect(
        screen.getByRole('button', { name: 'Email me a password link' }),
    ).toBeVisible();
});

it('renders the unverified notice and support link when email is unverified', () => {
    const baseProps = profileProps();
    page.props = {
        ...baseProps,
        profile: {
            ...baseProps.profile,
            email: {
                value: 'unverified@example.test',
                verified: false,
                pending: null,
            },
        },
        security: { emailVerified: false },
        storeShell: {
            whatsappUrl: 'https://wa.me/966537998099',
        },
    };

    render(<AccountProfile />);

    expect(
        screen.queryByRole('button', { name: 'Email me a password link' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByText(
            'Verify your email address first to change your password.',
        ),
    ).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'Contact support' }),
    ).toHaveAttribute('href', 'https://wa.me/966537998099');
});

it('renders masked phone and resend control after successful request and allows changing number', () => {
    phoneRequestShouldFail = false;
    render(<AccountProfile />);

    fireEvent.click(screen.getByRole('button', { name: 'Edit number' }));
    const phoneInput = screen.getByLabelText('New WhatsApp number');
    fireEvent.change(phoneInput, { target: { value: '+201001234567' } });

    fireEvent.click(screen.getByRole('button', { name: 'Send WhatsApp code' }));

    expect(screen.getByText(/We sent the code to \+966•••4567/)).toBeVisible();
    expect(screen.getByText(/Resend code in 60 s/)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Change number' })).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Change number' }));
    expect(screen.getByLabelText('New WhatsApp number')).toBeVisible();
    expect(
        screen.queryByText(/We sent the code to \+966•••4567/),
    ).not.toBeInTheDocument();
});

function profileProps() {
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
                phone_code_sent_to: 'We sent the code to :number',
                phone_resend_in: 'Resend code in :seconds s',
                phone_resend: 'Resend code',
                phone_change_number: 'Change number',
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
                reset_link_description:
                    'For your security we email a password-change link to your verified address instead of showing the form here.',
                reset_link_button: 'Email me a password link',
                reset_link_sent: 'We emailed you a password-change link.',
                reset_link_needs_email:
                    'Verify your email address first to change your password.',
                reset_link_support: 'Contact support',
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
            emailVerified: true,
        },
        securityActions: {
            resetLinkUrl: '/en/my-account/security/password-link',
        },
        profileActions: {
            updateUrl: '/en/my-account/profile',
            emailRequestUrl: '/en/my-account/profile/email',
            phoneRequestUrl: '/en/my-account/profile/phone',
            phoneConfirmUrl: '/en/my-account/profile/phone/confirm',
        },
        displayCurrencies: ['SAR', 'AED'],
        logoutUrl: '/logout',
    };
}
