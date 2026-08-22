import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminMobileNavigation from '@/components/admin/admin-mobile-navigation';
import AdminSidebar from '@/components/admin/admin-sidebar';
import AdminLayout from '@/layouts/admin-layout';
import ChatRootLayout from '@/layouts/chat-root-layout';
import type { AdminNavigationItem } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    flushAll: vi.fn(),
    post: vi.fn(),
}));
const pageState = vi.hoisted(() => ({
    component: 'admin/overview',
    url: '/en/admin',
    props: {
        locale: 'en',
        chat: { enabled: true, demoAssistant: false },
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

const navigation: AdminNavigationItem[] = [
    { key: 'overview', label: 'Overview', url: '/en/admin' },
    { key: 'orders', label: 'Orders', url: '/en/admin/orders' },
    {
        key: 'security',
        label: 'MFA Security',
        url: '/en/admin/security/mfa',
    },
];

const adminUi = englishAdminUi;

describe('Admin shell', () => {
    beforeEach(() => {
        pageState.component = 'admin/overview';
        pageState.url = '/en/admin';
        pageState.props = {
            locale: 'en',
            chat: { enabled: true, demoAssistant: false },
        };
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders only server-supplied destinations and marks the current page', () => {
        render(
            <AdminSidebar
                adminIdentity={{ name: 'Operations Owner', role: 'admin' }}
                adminUi={adminUi}
                current="overview"
                direction="ltr"
                logoutUrl="/logout"
                navigation={navigation}
            />,
        );

        expect(screen.getAllByRole('link')).toHaveLength(3);
        expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByRole('link', { name: 'Orders' })).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'MFA Security' }),
        ).not.toHaveAttribute('aria-current');
        expect(screen.queryByText(/customers|wallet|chat/i)).toBeNull();
    });

    it('keeps the actor identity visible and logout separate from navigation', () => {
        render(
            <AdminSidebar
                adminIdentity={{
                    name: 'An unusually long operations owner display name',
                    role: 'admin',
                }}
                adminUi={adminUi}
                current="security"
                direction="ltr"
                logoutUrl="/logout"
                navigation={navigation}
            />,
        );

        expect(
            screen.getByText('An unusually long operations owner display name'),
        ).toBeVisible();
        expect(screen.getByText('Admin')).toBeVisible();
        expect(
            screen.queryByRole('link', { name: 'Log out' }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Log out' }));

        expect(inertia.flushAll).toHaveBeenCalledOnce();
        expect(inertia.post).toHaveBeenCalledWith('/logout');
    });

    it('does not mount the customer ChatWidget on an Admin component', () => {
        render(
            <ChatRootLayout>
                <p>Private operations</p>
            </ChatRootLayout>,
        );

        expect(screen.getByText('Private operations')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Open chat' }),
        ).not.toBeInTheDocument();
    });

    it('provides a skip target and the same server navigation in the shell', () => {
        pageState.props = {
            locale: 'en',
            direction: 'ltr',
            adminUi,
            adminIdentity: { name: 'Operations Owner', role: 'admin' },
            adminNavigation: navigation,
            permissions: ['dashboard.view'],
            logoutUrl: '/logout',
        };

        render(
            <AdminLayout>
                <h1>Operations dashboard</h1>
            </AdminLayout>,
        );

        expect(
            screen.getByRole('link', { name: 'Skip to content' }),
        ).toHaveAttribute('href', '#admin-main-content');
        expect(screen.getByRole('main')).toHaveAttribute(
            'id',
            'admin-main-content',
        );
        expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            screen.getByRole('button', { name: 'Open Admin navigation' }),
        ).toBeInTheDocument();
    });

    it('opens a labelled modal mobile sheet with only supplied destinations', () => {
        render(
            <AdminMobileNavigation
                adminIdentity={{ name: 'Operations Owner', role: 'admin' }}
                adminUi={adminUi}
                current="overview"
                direction="ltr"
                logoutUrl="/logout"
                navigation={navigation}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Open Admin navigation' }),
        );

        expect(screen.getByRole('dialog')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Close Admin navigation' }),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('link')).toHaveLength(3);
    });
});
