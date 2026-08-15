import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountProfile from '@/pages/account/profile';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/my-account/profile',
}));
const excluded = vi.hoisted(() => [] as string[][]);

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => page,
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        dontRemember: (...fields: string[]) => excluded.push(fields),
        errors: {},
        patch: vi.fn(),
        post: vi.fn(),
        processing: false,
        recentlySuccessful: false,
        reset: vi.fn(),
        setData: vi.fn(),
    }),
}));

vi.mock('@/layouts/my-account-layout', () => ({
    default: ({ children }: React.PropsWithChildren) => <main>{children}</main>,
}));

beforeEach(() => {
    excluded.length = 0;
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
    expect(screen.getByLabelText('New email address')).toHaveAttribute(
        'autocomplete',
        'email',
    );
    expect(screen.getByLabelText('New WhatsApp number')).toHaveAttribute(
        'autocomplete',
        'tel',
    );
    expect(screen.queryByDisplayValue(/\$2y\$/)).not.toBeInTheDocument();
});

it('excludes every secret identity field from remembered Inertia state', () => {
    render(<AccountProfile />);

    expect(excluded).toContainEqual(['current_password']);
    expect(excluded).toContainEqual(['code']);
});

function profileProps() {
    return {
        locale: 'en',
        accountUi: {
            eyebrow: 'Arab UT account',
            profile: {
                title: 'Profile',
                description: 'Update your verified account details securely.',
                personal_title: 'Personal details',
                contact_title: 'Verified contact details',
                first_name: 'First name',
                last_name: 'Last name',
                email: 'Email address',
                phone: 'WhatsApp number',
                preferred_locale: 'Preferred language',
                display_currency: 'Display currency',
                save: 'Save changes',
                saved: 'Your details have been saved.',
                new_email: 'New email address',
                request_email: 'Send verification link',
                new_phone: 'New WhatsApp number',
                send_phone_code: 'Send WhatsApp code',
                phone_code: '6-digit verification code',
                confirm_phone: 'Confirm new number',
                current_password: 'Current password',
                sensitive_hint:
                    'Confirm your identity before changing contact details.',
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
            passwordConfirmationRequired: true,
        },
        profileActions: {
            updateUrl: '/en/my-account/profile',
            emailRequestUrl: '/en/my-account/profile/email',
            phoneRequestUrl: '/en/my-account/profile/phone',
            phoneConfirmUrl: '/en/my-account/profile/phone/confirm',
        },
        displayCurrencies: ['SAR', 'AED'],
    };
}
