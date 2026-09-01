import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import AdminMobileTabBar from '@/components/admin/admin-mobile-tabbar';
import AdminSidebar from '@/components/admin/admin-sidebar';
import type {
    AdminIdentity,
    AdminNavigationChild,
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

function normalizePath(url: string): string {
    return url.split('?')[0].replace(/\/$/, '');
}

function currentDestination(
    url: string,
    navigation: AdminNavigationItem[],
): AdminNavigationItem['key'] | AdminNavigationChild['key'] {
    const pathname = normalizePath(url);

    // Children first: a group parent shares its URL with its first child, and
    // the sidebar highlights the child link for that URL.
    const destinations = navigation.flatMap((item) => [
        ...(item.children ?? []),
        item,
    ]);

    const exact = destinations.find(
        (destination) => normalizePath(destination.url) === pathname,
    );

    if (exact) {
        return exact.key;
    }

    // Detail pages (for example a coupon) live under their list URL.
    const ancestor = destinations
        .filter((destination) =>
            pathname.startsWith(`${normalizePath(destination.url)}/`),
        )
        .sort((a, b) => b.url.length - a.url.length)[0];

    return ancestor?.key ?? 'overview';
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
            <header className="flex min-h-[calc(3.5rem+env(safe-area-inset-top))] items-center gap-3 border-b border-border bg-card px-[max(1rem,env(safe-area-inset-right))] pt-[max(0.5rem,env(safe-area-inset-top))] pl-[max(1rem,env(safe-area-inset-left))] md:hidden">
                <img
                    alt=""
                    height="32"
                    src="/images/arabut-logo-header.webp"
                    width="32"
                    className="h-8 w-8 shrink-0 object-contain"
                />
                <span
                    className="font-display text-lg font-bold tracking-tight text-foreground"
                    translate="no"
                >
                    {page.props.adminUi.brand}
                </span>
            </header>
            <div className="md:grid md:grid-cols-[16rem_minmax(0,1fr)]">
                <AdminSidebar {...navigationProps} />
                <main
                    className="min-w-0 pt-[max(1.5rem,env(safe-area-inset-top))] pr-[max(1.25rem,env(safe-area-inset-right))] pb-[max(5.5rem,calc(env(safe-area-inset-bottom)+4.5rem))] pl-[max(1.25rem,env(safe-area-inset-left))] md:pt-8 md:pr-10 md:pb-12 md:pl-10"
                    id="admin-main-content"
                    tabIndex={-1}
                >
                    {children}
                </main>
            </div>
            <AdminMobileTabBar
                adminUi={page.props.adminUi}
                current={current}
                navigation={page.props.adminNavigation}
            />
        </div>
    );
}
