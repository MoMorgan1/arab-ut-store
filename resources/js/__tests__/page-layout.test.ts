import { describe, expect, it } from 'vitest';
import AuthLayout from '@/layouts/auth-layout';
import ChatRootLayout from '@/layouts/chat-root-layout';
import { resolveApplicationLayout, usesAuthLayout } from '@/lib/page-layout';

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

    it('never returns a render callback function', () => {
        const storeLayout = resolveApplicationLayout('store/home');
        const authLayout = resolveApplicationLayout('auth/login');

        // A render callback would return JSX/ReactElement when invoked with props or a child,
        // whereas a Component reference is the component function itself.
        expect(storeLayout).toBe(ChatRootLayout);
        expect(authLayout).toEqual([ChatRootLayout, AuthLayout]);
    });
});
