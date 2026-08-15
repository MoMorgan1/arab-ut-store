import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountSecurity from '@/pages/account/security';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/my-account/security',
}));
const excluded = vi.hoisted(() => [] as string[][]);

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => page,
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        dontRemember: (...fields: string[]) => excluded.push(fields),
        errors: {},
        post: vi.fn(),
        processing: false,
        put: vi.fn(),
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
    page.props = securityProps('change');
});

afterEach(cleanup);

it('renders change mode with password-manager contracts and verified recovery', () => {
    render(<AccountSecurity />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'Security' }),
    ).toBeVisible();
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
    expect(
        screen.getByRole('link', { name: 'View recovery option' }),
    ).toHaveAttribute('href', '/en/forgot-password');
    expect(excluded).toContainEqual([
        'current_password',
        'password',
        'password_confirmation',
    ]);
});

it('renders setup mode without asking for a nonexistent current password', () => {
    page.props = securityProps('setup');
    render(<AccountSecurity />);

    expect(screen.queryByLabelText('Current password')).not.toBeInTheDocument();
    expect(screen.getByText('Create an account password')).toBeVisible();
    expect(screen.getByText(/no verified email/i)).toBeVisible();
});

function securityProps(passwordMode: 'change' | 'setup') {
    return {
        locale: 'en',
        accountUi: {
            eyebrow: 'Arab UT account',
            security: {
                title: 'Security',
                description: 'Manage your password and account protection.',
                current_password: 'Current password',
                new_password: 'New password',
                confirm_password: 'Confirm new password',
                change_password: 'Change password',
                set_password: 'Set a password',
                password_changed: 'Your password was updated securely.',
                social_login_notice: 'You can set an account password.',
                change_title: 'Change your password',
                setup_title: 'Create an account password',
                change_description: 'Protect this change.',
                setup_description: 'Use recent verification.',
                recovery_title: 'Account recovery',
                recovery_email: 'Use your verified email.',
                recovery_whatsapp:
                    'This account has no verified email. Use WhatsApp support for recovery.',
                recovery_action: 'View recovery option',
            },
        },
        security: {
            passwordMode,
            passwordRules: 'minlength:8',
            recoveryMode: passwordMode === 'change' ? 'email' : 'whatsapp',
            recoveryUrl:
                passwordMode === 'change'
                    ? '/en/forgot-password'
                    : 'https://wa.me/1',
        },
        securityActions: {
            changePasswordUrl: '/en/my-account/security/password',
            setupPasswordUrl: '/en/my-account/security/password',
        },
    };
}
