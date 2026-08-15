import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import AccountNavigation from '@/components/account/account-navigation';
import type { AccountNavigationItem } from '@/types/account';

const inertia = vi.hoisted(() => ({
    flushAll: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

it('keeps destination navigation separate from the POST logout action', () => {
    const items: AccountNavigationItem[] = [
        { key: 'overview', label: 'Overview', url: '/en/my-account' },
        { key: 'orders', label: 'Orders', url: '/en/my-account/orders' },
        { key: 'wallet', label: 'Wallet', url: '/en/my-account/wallet' },
        { key: 'profile', label: 'Profile', url: '/en/my-account/profile' },
        {
            key: 'security',
            label: 'Security',
            url: '/en/my-account/security',
        },
        { key: 'support', label: 'Support', url: '/en/my-account/support' },
    ];

    render(
        <AccountNavigation
            current="orders"
            items={items}
            logoutUrl="/logout"
            translations={{
                label: 'My Account sections',
                overview: 'Overview',
                orders: 'Orders',
                wallet: 'Wallet',
                profile: 'Profile',
                security: 'Security',
                support: 'Support',
                logout: 'Log out',
            }}
        />,
    );

    expect(
        screen.getByRole('navigation', { name: 'My Account sections' }),
    ).toBeVisible();
    expect(screen.getAllByRole('link')).toHaveLength(6);
    expect(screen.getByRole('link', { name: 'Orders' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(
        screen.queryByRole('link', { name: 'Log out' }),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Log out' }));

    expect(inertia.flushAll).toHaveBeenCalledOnce();
    expect(inertia.post).toHaveBeenCalledWith('/logout');
});
