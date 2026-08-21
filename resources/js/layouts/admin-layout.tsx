import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import AdminMobileNavigation from '@/components/admin/admin-mobile-navigation';
import AdminSidebar from '@/components/admin/admin-sidebar';
import type {
    AdminIdentity,
    AdminNavigationItem,
    AdminOverviewPageProps,
    AdminTranslations,
} from '@/types/admin';

type AdminShellProps = Partial<
    Pick<
        AdminOverviewPageProps,
        | 'adminIdentity'
        | 'adminNavigation'
        | 'adminUi'
        | 'direction'
        | 'locale'
        | 'logoutUrl'
    >
>;

function hasShellProps(props: AdminShellProps): props is {
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    adminUi: AdminTranslations;
    direction: 'rtl' | 'ltr';
    locale: 'ar' | 'en';
    logoutUrl: string;
} {
    return (
        props.adminIdentity !== undefined &&
        props.adminNavigation !== undefined &&
        props.adminUi !== undefined &&
        props.direction !== undefined &&
        props.locale !== undefined &&
        props.logoutUrl !== undefined
    );
}

function currentDestination(
    url: string,
    navigation: AdminNavigationItem[],
): AdminNavigationItem['key'] {
    const pathname = url.split('?')[0].replace(/\/$/, '');
    const match = navigation.find(
        (item) => item.url.split('?')[0].replace(/\/$/, '') === pathname,
    );

    return match?.key ?? 'overview';
}

export default function AdminLayout({ children }: PropsWithChildren) {
    const page = usePage<AdminShellProps>();

    // MFA enrollment predates the full Admin shell and intentionally exposes
    // no actor/navigation props. Preserve its focused, secret-safe surface.
    if (!hasShellProps(page.props)) {
        return children;
    }

    const current = currentDestination(page.url, page.props.adminNavigation);
    const skipLabel =
        page.props.locale === 'ar' ? 'تخطَّ إلى المحتوى' : 'Skip to content';
    const navigationProps = {
        adminIdentity: page.props.adminIdentity,
        adminUi: page.props.adminUi,
        current,
        direction: page.props.direction,
        logoutUrl: page.props.logoutUrl,
        navigation: page.props.adminNavigation,
    };

    return (
        <div
            className="admin-shell"
            dir={page.props.direction}
            lang={page.props.locale}
        >
            <a className="admin-skip-link" href="#admin-main-content">
                {skipLabel}
            </a>
            <AdminMobileNavigation {...navigationProps} />
            <div className="admin-shell__grid">
                <AdminSidebar {...navigationProps} />
                <main id="admin-main-content" tabIndex={-1}>
                    {children}
                </main>
            </div>
        </div>
    );
}
