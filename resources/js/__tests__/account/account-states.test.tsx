import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountSectionError from '@/components/account/account-section-error';
import AccountSupport from '@/pages/account/support';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/my-account/support?order=01TEST',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => page,
}));

vi.mock('@/layouts/my-account-layout', () => ({
    default: ({ children }: React.PropsWithChildren) => (
        <main data-testid="account-layout">{children}</main>
    ),
}));

beforeEach(() => {
    page.props = supportProps();
});

afterEach(cleanup);

it('renders safe contact destinations and keeps order context outside their URLs', () => {
    render(<AccountSupport />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'Support' }),
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
    page.props = supportProps({
        available: false,
        emailUrl: null,
        orderNumber: null,
        whatsappUrl: null,
    });
    render(<AccountSupport />);

    expect(screen.getByTestId('account-layout')).toBeVisible();
    expect(
        screen.getByRole('heading', { name: 'Support unavailable' }),
    ).toBeVisible();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
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

function supportProps(
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
        support,
    };
}
