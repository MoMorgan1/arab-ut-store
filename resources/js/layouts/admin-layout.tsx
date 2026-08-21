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
            className="min-h-dvh overflow-x-clip bg-background [font-family:'Thmanyah_Sans',Tahoma,Arial,sans-serif] text-foreground"
            dir={page.props.direction}
            lang={page.props.locale}
        >
            <a
                className="fixed start-4 top-4 z-50 min-h-[44px] -translate-y-[200%] rounded-md bg-primary px-4 py-2.5 font-bold text-primary-foreground transition-transform focus-visible:translate-y-0 focus-visible:ring-2 focus-visible:ring-ring motion-reduce:transition-none motion-reduce:duration-[0.01ms]"
                href="#admin-main-content"
            >
                {skipLabel}
            </a>
            <AdminMobileNavigation {...navigationProps} />
            <div className="md:grid md:grid-cols-[16rem_minmax(0,1fr)]">
                <AdminSidebar {...navigationProps} />
                <main
                    className="min-w-0 p-6 px-[max(1rem,env(safe-area-inset-right))] pt-[max(1.5rem,env(safe-area-inset-top))] pb-[max(2rem,env(safe-area-inset-bottom))] pl-[max(1rem,env(safe-area-inset-left))] md:p-8"
                    id="admin-main-content"
                    tabIndex={-1}
                >
                    <div className="mx-auto w-full max-w-7xl">{children}</div>
                </main>
            </div>
        </div>
    );
}
