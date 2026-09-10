import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import AccountMobileBottomNav from '@/components/account/account-mobile-bottom-nav';
import type { AccountNavigationItem } from '@/types/account';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        ...rest
    }: React.PropsWithChildren<{ href: string }>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const items: AccountNavigationItem[] = [
    { key: 'overview', label: 'Overview', url: '/my-account' },
    { key: 'orders', label: 'Orders', url: '/my-account/orders' },
    { key: 'wallet', label: 'Wallet', url: '/my-account/wallet' },
    { key: 'profile', label: 'Profile', url: '/my-account/profile' },
];

const translations = {
    label: 'Account sections',
    overview: 'Overview',
    orders: 'Orders',
    wallet: 'Wallet',
    profile: 'Profile',
    security: 'Security',
    support: 'Support',
    logout: 'Log out',
    attention: 'Needs a step',
};

it('marks the current destination and shares the width between the four items', () => {
    render(
        <AccountMobileBottomNav
            current="wallet"
            items={items}
            translations={translations}
        />,
    );

    const links = screen.getAllByRole('link');
    expect(links).toHaveLength(4);
    expect(screen.getByRole('link', { name: 'Wallet' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(screen.getByRole('link', { name: 'Wallet' })).toHaveClass(
        'arabut-bottom-bar__item--active',
    );

    // Every item shares one track, so a fifth destination could never be
    // clipped off the end of the bar.
    for (const link of links) {
        expect(link).toHaveClass('arabut-bottom-bar__item');
    }
});

it('announces a destination that still needs the customer', () => {
    render(
        <AccountMobileBottomNav
            attention={['profile']}
            current="overview"
            items={items}
            translations={translations}
        />,
    );

    // The dot is decorative, so the meaning has to reach the accessible name.
    expect(
        screen.getByRole('link', { name: /Profile.*Needs a step/ }),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: /Wallet.*Needs a step/ }),
    ).not.toBeInTheDocument();
});

it('adds no attention text when nothing is outstanding', () => {
    render(
        <AccountMobileBottomNav
            current="overview"
            items={items}
            translations={translations}
        />,
    );

    expect(screen.queryByText('Needs a step')).not.toBeInTheDocument();
});

it('keeps the admin destination inside the same four-item track', () => {
    render(
        <AccountMobileBottomNav
            adminUrl="/admin"
            current="overview"
            items={items}
            translations={{ ...translations, admin: 'Admin' }}
        />,
    );

    const links = screen.getAllByRole('link');
    // The admin destination is additive, not a replacement: an admin sees five.
    expect(links).toHaveLength(5);
    expect(screen.getByRole('link', { name: 'Admin' })).toBeInTheDocument();

    // Every item shares one track, so the fifth cannot overflow the bar the way
    // a fixed minimum width would have pushed it past the edge.
    for (const link of links) {
        expect(link).toHaveClass('arabut-bottom-bar__item');
    }
});
