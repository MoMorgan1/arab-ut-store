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
const putSpy = vi.hoisted(() => vi.fn());
const patchSpy = vi.hoisted(() => vi.fn());

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
            patch: patchSpy,
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
                } else if (url.includes('/security/password-link')) {
                    options?.onSuccess?.();
                }
            }),
            put: putSpy,
            processing: false,
            recentlySuccessful: false,
            reset: vi.fn(() => {
                formData = { ...initial };
                formStore.set(key, formData);
            }),
            setData: vi.fn((fieldKey: string, value: string) => {
                formData[fieldKey] = value;
            }),
        };
    },
}));

vi.mock('@/layouts/my-account-layout', () => ({
    default: ({ children }: React.PropsWithChildren) => <main>{children}</main>,
}));

beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn();
    excluded.length = 0;
    putSpy.mockClear();
    patchSpy.mockClear();
    phoneRequestShouldFail = true;
    formStore.clear();
    page.props = profileProps();
});

afterEach(cleanup);

it('renders the three cards with their rows, values, and badges', () => {
    render(<AccountProfile />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'Profile' }),
    ).toBeVisible();
    expect(
        screen.getByRole('heading', { level: 3, name: 'My details' }),
    ).toBeVisible();
    expect(
        screen.getByRole('heading', { level: 3, name: 'Contact' }),
    ).toBeVisible();
    expect(
        screen.getByRole('heading', { level: 3, name: 'Password' }),
    ).toBeVisible();

    expect(screen.getByText('Name')).toBeVisible();
    expect(screen.getByText('Mohamed Player')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Edit' })).toBeVisible();

    expect(screen.getByText('WhatsApp number')).toBeVisible();
    expect(screen.getByText('Email address')).toBeVisible();
    expect(screen.getByText('owner@example.test')).toBeVisible();
    expect(screen.getAllByText('Verified')).toHaveLength(2);

    expect(screen.getByText('Set')).toBeVisible();
});

it('opens the name form in place on edit and closes on cancel_edit', () => {
    render(<AccountProfile />);

    expect(screen.queryByLabelText('First name')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Last name')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

    expect(screen.getByLabelText('First name')).toBeVisible();
    expect(screen.getByLabelText('First name')).toHaveAttribute(
        'autocomplete',
        'given-name',
    );
    expect(screen.getByLabelText('Last name')).toBeVisible();
    expect(screen.getByLabelText('Last name')).toHaveAttribute(
        'autocomplete',
        'family-name',
    );
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByLabelText('First name')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Last name')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit' })).toBeVisible();
});

it('renders contact rows, verified states, and safe autocomplete contracts', () => {
    render(<AccountProfile />);

    expect(
        screen.queryByLabelText('New email address'),
    ).not.toBeInTheDocument();

    const changeButtons = screen.getAllByRole('button', { name: 'Change' });
    // Phone row, Email row, Password row all have "Change" when verified/set
    expect(changeButtons.length).toBeGreaterThanOrEqual(2);

    // Click Change on phone
    fireEvent.click(changeButtons[0]!);
    expect(screen.getByLabelText('New WhatsApp number')).toHaveAttribute(
        'autocomplete',
        'tel',
    );

    // Click Change on email
    fireEvent.click(changeButtons[1]!);
    expect(screen.getByLabelText('New email address')).toHaveAttribute(
        'autocomplete',
        'email',
    );

    expect(screen.queryByDisplayValue(/\$2y\$/)).not.toBeInTheDocument();
});

it('focuses the inline contact field when validation fails', () => {
    render(<AccountProfile />);

    const changeButtons = screen.getAllByRole('button', { name: 'Change' });
    // Phone
    fireEvent.click(changeButtons[0]!);
    fireEvent.click(screen.getByRole('button', { name: 'Send WhatsApp code' }));
    expect(screen.getByLabelText('New WhatsApp number')).toHaveFocus();

    // Email
    fireEvent.click(changeButtons[1]!);
    fireEvent.click(
        screen.getByRole('button', { name: 'Send verification link' }),
    );
    expect(screen.getByLabelText('New email address')).toHaveFocus();
});

it('excludes every secret identity field from remembered Inertia state', () => {
    render(<AccountProfile />);

    expect(excluded).toContainEqual(['code']);
});

it('shows state_set and change for a password account, and state_missing and set_password otherwise', () => {
    const { unmount } = render(<AccountProfile />);

    expect(screen.getByText('Set')).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Set a password' }),
    ).not.toBeInTheDocument();

    unmount();

    const noPasswordProps = profileProps();
    page.props = {
        ...noPasswordProps,
        security: {
            ...noPasswordProps.security,
            hasPassword: false,
        },
    };

    render(<AccountProfile />);

    expect(screen.getByText('Not created yet')).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Set a password' }),
    ).toBeVisible();
});

it('submits the password change form via put to changeUrl', () => {
    render(<AccountProfile />);

    // In password card with hasPassword: true, click Change
    const changeButtons = screen.getAllByRole('button', { name: 'Change' });
    // The password row is the third Change button (phone, email, password)
    const passwordChangeBtn = changeButtons[changeButtons.length - 1]!;
    fireEvent.click(passwordChangeBtn);

    expect(screen.getByLabelText('Current password')).toHaveAttribute(
        'autocomplete',
        'current-password',
    );
    expect(screen.getByLabelText('New password')).toHaveAttribute(
        'autocomplete',
        'new-password',
    );
    expect(screen.getByLabelText('Confirm new password')).toHaveAttribute(
        'autocomplete',
        'new-password',
    );

    fireEvent.change(screen.getByLabelText('Current password'), {
        target: { value: 'CurrentPassword123' },
    });
    fireEvent.change(screen.getByLabelText('New password'), {
        target: { value: 'NewPassword1234' },
    });
    fireEvent.change(screen.getByLabelText('Confirm new password'), {
        target: { value: 'NewPassword1234' },
    });

    fireEvent.click(screen.getByRole('button', { name: 'Change password' }));

    expect(putSpy).toHaveBeenCalledWith(
        '/en/my-account/security/password',
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('renders the forgot password link for verified emails and nothing extra for unverified', () => {
    const { unmount } = render(<AccountProfile />);

    expect(
        screen.getByRole('button', { name: 'Forgot your password?' }),
    ).toBeVisible();

    unmount();

    const unverifiedProps = profileProps();
    page.props = {
        ...unverifiedProps,
        security: {
            ...unverifiedProps.security,
            emailVerified: false,
        },
    };

    render(<AccountProfile />);

    expect(
        screen.queryByRole('button', { name: 'Forgot your password?' }),
    ).not.toBeInTheDocument();
});

it('renders masked phone and resend control after successful request and allows changing number', () => {
    phoneRequestShouldFail = false;
    render(<AccountProfile />);

    const changeButtons = screen.getAllByRole('button', { name: 'Change' });
    fireEvent.click(changeButtons[0]!); // Phone row
    const phoneInput = screen.getByLabelText('New WhatsApp number');
    fireEvent.change(phoneInput, { target: { value: '+201001234567' } });

    fireEvent.click(screen.getByRole('button', { name: 'Send WhatsApp code' }));

    expect(
        screen.getByText(
            (_, element) =>
                element?.tagName === 'P' &&
                /We sent the code to \+966•••4567/.test(
                    element?.textContent ?? '',
                ),
        ),
    ).toBeVisible();
    expect(screen.getByText(/Resend code in 60 s/)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Change number' })).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Change number' }));
    expect(screen.getByLabelText('New WhatsApp number')).toBeVisible();
    expect(
        screen.queryByText(
            (_, element) =>
                element?.tagName === 'P' &&
                /We sent the code to \+966•••4567/.test(
                    element?.textContent ?? '',
                ),
        ),
    ).not.toBeInTheDocument();
});

it('renders the add email prompt when user has no email and supports dismissal and triggering email edit', () => {
    const baseProps = profileProps();
    page.props = {
        ...baseProps,
        profile: {
            ...baseProps.profile,
            email: {
                value: null,
                verified: false,
                pending: null,
            },
        },
    };

    render(<AccountProfile />);

    const prompt = screen.getByTestId('add-email-prompt');
    expect(prompt).toBeVisible();
    expect(screen.getByText('Add your email address')).toBeVisible();

    // Trigger edit via prompt CTA
    const triggerBtn = screen.getByTestId('trigger-add-email');
    fireEvent.click(triggerBtn);
    expect(screen.getByLabelText('New email address')).toBeVisible();

    // Dismiss prompt
    const dismissBtn = screen.getByTestId('dismiss-email-prompt');
    fireEvent.click(dismissBtn);
    expect(screen.queryByTestId('add-email-prompt')).not.toBeInTheDocument();
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
                personal_card_title: 'My details',
                contact_card_title: 'Contact',
                name: 'Name',
                edit: 'Edit',
                change: 'Change',
                verify: 'Verify',
                verified: 'Verified',
                unverified: 'Not verified',
                not_set: 'Not added',
                first_name: 'First name',
                last_name: 'Last name',
                email: 'Email address',
                phone: 'WhatsApp number',
                preferred_locale: 'Preferred language',
                display_currency: 'Display currency',
                save: 'Save changes',
                saved: 'Your details have been saved.',
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
                pending_email: 'New email awaiting verification',
                pending_phone: 'New number awaiting verification',
                email_link_invalid: 'Invalid link.',
                phone_code_invalid: 'Invalid code.',
                add_email_prompt_title: 'Add your email address',
                add_email_prompt_action: 'Add email',
                add_email_prompt_dismiss: 'Dismiss prompt',
            },
            security: {
                title: 'Security',
                card_title: 'Password',
                state_set: 'Set',
                state_missing: 'Not created yet',
                forgot: 'Forgot your password?',
                current_password: 'Current password',
                new_password: 'New password',
                confirm_password: 'Confirm new password',
                change_password: 'Change password',
                set_password: 'Set a password',
                password_changed: 'Your password was updated securely.',
                reset_link_button: 'Email me a password link',
                reset_link_sent: 'We emailed you a password-change link.',
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
            verification: {
                verified: 'Verified',
                unverified: 'Not verified',
                pending: 'Verification pending',
                send_code: 'Send code',
                verify: 'Verify',
                code: 'Verification code',
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
            hasPassword: true,
        },
        securityActions: {
            resetLinkUrl: '/en/my-account/security/password-link',
            changeUrl: '/en/my-account/security/password',
            setupUrl: '/en/my-account/security/password',
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
