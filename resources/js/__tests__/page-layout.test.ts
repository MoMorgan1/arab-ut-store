import { describe, expect, it } from 'vitest';
import AdminLayout from '@/layouts/admin-layout';
import AuthLayout from '@/layouts/auth-layout';
import ChatRootLayout from '@/layouts/chat-root-layout';
import {
    resolveApplicationLayout,
    usesAdminLayout,
    usesAuthLayout,
} from '@/lib/page-layout';

describe('usesAdminLayout', () => {
    it.each([
        'admin/overview',
        'admin/settings',
        'admin/orders/index',
        'admin/orders/show',
    ])('uses the privileged Admin shell for %s', (page) => {
        expect(usesAdminLayout(page)).toBe(true);
    });

    it.each(['store/home', 'account/overview', 'auth/login'])(
        'does not apply the Admin shell to %s',
        (page) => {
            expect(usesAdminLayout(page)).toBe(false);
        },
    );
});

describe('usesAuthLayout', () => {
    it.each(['account/overview', 'account/orders', 'account/profile'])(
        'keeps %s outside the legacy application shell',
        (page) => {
            expect(usesAuthLayout(page)).toBe(false);
        },
    );

    it('keeps store pages outside the legacy shell', () => {
        expect(usesAuthLayout('store/home')).toBe(false);
    });

    it('uses the dedicated authentication shell for auth pages', () => {
        expect(usesAuthLayout('auth/login')).toBe(true);
    });
});

describe('resolveApplicationLayout', () => {
    it.each([
        'store/home',
        'store/category',
        'account/overview',
        'account/orders',
    ])('resolves %s directly to ChatRootLayout component', (page) => {
        const layout = resolveApplicationLayout(page);
        expect(layout).toBe(ChatRootLayout);
        expect(typeof layout).toBe('function');
    });

    it.each(['auth/login', 'auth/register', 'auth/forgot-password'])(
        'resolves %s to nested [ChatRootLayout, AuthLayout] array',
        (page) => {
            const layout = resolveApplicationLayout(page);
            expect(Array.isArray(layout)).toBe(true);
            expect(layout).toEqual([ChatRootLayout, AuthLayout]);
        },
    );

    it.each([
        'admin/overview',
        'admin/settings',
        'admin/orders/index',
        'admin/orders/show',
    ])('resolves %s to nested [ChatRootLayout, AdminLayout] array', (page) => {
        expect(resolveApplicationLayout(page)).toEqual([
            ChatRootLayout,
            AdminLayout,
        ]);
    });

    it('never returns a render callback function', () => {
        const storeLayout = resolveApplicationLayout('store/home');
        const authLayout = resolveApplicationLayout('auth/login');

        // A render callback would return JSX/ReactElement when invoked with props or a child,
        // whereas a Component reference is the component function itself.
        expect(storeLayout).toBe(ChatRootLayout);
        expect(authLayout).toEqual([ChatRootLayout, AuthLayout]);
    });
});
