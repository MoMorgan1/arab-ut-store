export type DocumentShell = 'store' | 'admin' | 'app';

/**
 * The palettes live on the root element as `html.store-document` and
 * `html.admin-document`, and `resources/views/app.blade.php` stamps the right
 * one from the resolved Inertia component. Blade only runs on a full document
 * load, so a client-side Inertia visit changes the page without changing the
 * palette — which is how the admin dashboard ended up rendering in the
 * storefront's palette, losing every gold accent, until the visitor refreshed.
 */
export function shellForComponent(component: string): DocumentShell {
    if (component.startsWith('admin/')) {
        return 'admin';
    }

    if (component.startsWith('store/')) {
        return 'store';
    }

    return 'app';
}

/** The admin shell is dark-only, so it pins `dark` over the visitor's preference. */
export function shellForcesDarkAppearance(shell: DocumentShell): boolean {
    return shell === 'admin';
}

const THEME_COLORS: Record<DocumentShell, string | null> = {
    store: '#0d0b08',
    admin: '#080705',
    app: null,
};

function applyThemeColor(shell: DocumentShell): void {
    const meta = document.head.querySelector<HTMLMetaElement>(
        'meta[name="theme-color"]',
    );
    const color = THEME_COLORS[shell];

    if (color === null) {
        meta?.remove();

        return;
    }

    if (meta !== null) {
        meta.content = color;

        return;
    }

    const created = document.createElement('meta');
    created.name = 'theme-color';
    created.content = color;
    document.head.append(created);
}

/**
 * Mirrors what app.blade.php writes on a full load. Keep the two in step: a
 * class added there must be added here, or it will survive only until the
 * visitor navigates.
 */
export function applyDocumentShell(
    component: string,
    prefersDark: boolean,
): DocumentShell {
    if (typeof document === 'undefined') {
        return 'app';
    }

    const shell = shellForComponent(component);
    const root = document.documentElement;
    const isDark = shellForcesDarkAppearance(shell) || prefersDark;

    root.classList.toggle('store-document', shell === 'store');
    root.classList.toggle('admin-document', shell === 'admin');
    root.classList.toggle('dark', isDark);
    root.style.colorScheme = isDark ? 'dark' : 'light';

    applyThemeColor(shell);

    return shell;
}
