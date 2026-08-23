import { beforeEach, describe, expect, it } from 'vitest';
import {
    applyDocumentShell,
    shellForComponent,
    shellForcesDarkAppearance,
} from '@/lib/document-shell';

const root = () => document.documentElement;

const themeColor = () =>
    document.head
        .querySelector<HTMLMetaElement>('meta[name="theme-color"]')
        ?.content.toLowerCase() ?? null;

beforeEach(() => {
    root().className = '';
    root().style.colorScheme = '';
    document.head
        .querySelectorAll('meta[name="theme-color"]')
        .forEach((meta) => meta.remove());
});

describe('shellForComponent', () => {
    it.each(['admin/overview', 'admin/orders/index', 'admin/settings'])(
        'resolves %s to the admin shell',
        (component) => {
            expect(shellForComponent(component)).toBe('admin');
        },
    );

    it.each(['store/home', 'store/coins'])(
        'resolves %s to the store shell',
        (component) => {
            expect(shellForComponent(component)).toBe('store');
        },
    );

    it.each(['account/overview', 'auth/login'])(
        'resolves %s to the neutral app shell',
        (component) => {
            expect(shellForComponent(component)).toBe('app');
        },
    );
});

describe('shellForcesDarkAppearance', () => {
    it('pins dark for the admin shell only', () => {
        expect(shellForcesDarkAppearance('admin')).toBe(true);
        expect(shellForcesDarkAppearance('store')).toBe(false);
        expect(shellForcesDarkAppearance('app')).toBe(false);
    });
});

describe('applyDocumentShell', () => {
    it('swaps the store palette for the admin palette', () => {
        applyDocumentShell('store/home', false);

        expect(root().classList.contains('store-document')).toBe(true);
        expect(root().classList.contains('admin-document')).toBe(false);

        // The regression: a client-side visit into the dashboard used to leave
        // store-document in place, so every gold accent resolved to the
        // storefront palette until the visitor refreshed.
        applyDocumentShell('admin/overview', false);

        expect(root().classList.contains('admin-document')).toBe(true);
        expect(root().classList.contains('store-document')).toBe(false);
    });

    it('leaves the admin palette when navigating back out to the store', () => {
        applyDocumentShell('admin/overview', false);
        applyDocumentShell('store/home', false);

        expect(root().classList.contains('admin-document')).toBe(false);
        expect(root().classList.contains('store-document')).toBe(true);
    });

    it('keeps the admin shell dark even when the visitor prefers light', () => {
        applyDocumentShell('admin/overview', false);

        expect(root().classList.contains('dark')).toBe(true);
        expect(root().style.colorScheme).toBe('dark');
    });

    it('honours the visitor preference outside the admin shell', () => {
        applyDocumentShell('store/home', false);

        expect(root().classList.contains('dark')).toBe(false);
        expect(root().style.colorScheme).toBe('light');

        applyDocumentShell('store/home', true);

        expect(root().classList.contains('dark')).toBe(true);
    });

    it('drops the forced dark class on the way out of admin', () => {
        applyDocumentShell('admin/overview', false);
        applyDocumentShell('account/overview', false);

        expect(root().classList.contains('dark')).toBe(false);
    });

    it('keeps the theme colour in step with the shell', () => {
        applyDocumentShell('store/home', false);
        expect(themeColor()).toBe('#0d0b08');

        applyDocumentShell('admin/overview', false);
        expect(themeColor()).toBe('#080705');

        applyDocumentShell('account/overview', false);
        expect(themeColor()).toBeNull();
    });

    it('reuses an existing theme colour tag rather than stacking duplicates', () => {
        applyDocumentShell('store/home', false);
        applyDocumentShell('admin/overview', false);

        expect(
            document.head.querySelectorAll('meta[name="theme-color"]'),
        ).toHaveLength(1);
    });
});
