import { Link, router } from '@inertiajs/react';
import {
    Award,
    LayoutDashboard,
    LogOut,
    Megaphone,
    Package,
    Settings,
    ShoppingBag,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type {
    AdminIdentity,
    AdminNavigationChild,
    AdminNavigationItem,
    AdminTranslations,
} from '@/types/admin';

const navigationIcons: Record<AdminNavigationItem['key'], LucideIcon> = {
    overview: LayoutDashboard,
    orders: ShoppingBag,
    customers: Users,
    marketing: Megaphone,
    products: Package,
    marketingLoyalty: Award,
    settings: Settings,
};

export type AdminNavigationProps = {
    adminIdentity: AdminIdentity;
    adminUi: AdminTranslations;
    current: AdminNavigationItem['key'] | AdminNavigationChild['key'];
    direction: 'rtl' | 'ltr';
    logoutUrl: string;
    navigation: AdminNavigationItem[];
};

type NavigationListProps = Pick<
    AdminNavigationProps,
    'adminUi' | 'current' | 'navigation'
> & {
    onNavigate?: () => void;
};

export function AdminNavigationList({
    adminUi,
    current,
    navigation,
    onNavigate,
}: NavigationListProps) {
    return (
        <nav aria-label={adminUi.brand} className="w-full">
            <ul className="flex flex-col gap-1">
                {navigation.map((item) => {
                    const Icon = navigationIcons[item.key];
                    const childSelected =
                        item.children?.some((child) => child.key === current) ??
                        false;
                    const selected = item.key === current || childSelected;
                    const children =
                        item.key === current || childSelected
                            ? (item.children ?? [])
                            : [];

                    return (
                        <li key={item.key}>
                            <Link
                                aria-current={selected ? 'page' : undefined}
                                className="flex min-h-[44px] items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring aria-[current=page]:bg-sidebar-accent aria-[current=page]:text-sidebar-accent-foreground"
                                href={item.url}
                                onClick={onNavigate}
                            >
                                <Icon
                                    aria-hidden="true"
                                    className="h-[18px] w-[18px] shrink-0"
                                />
                                <span className="min-w-0 [overflow-wrap:anywhere]">
                                    {item.label}
                                </span>
                            </Link>
                            {children.length > 0 ? (
                                <ul
                                    aria-label={item.label}
                                    className="ms-6 mt-1 flex flex-col gap-1 border-s border-sidebar-border ps-3"
                                >
                                    {children.map((child) => (
                                        <li key={child.key}>
                                            <Link
                                                aria-current={
                                                    child.key === current
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className="flex min-h-[44px] items-center rounded-md px-3 py-2 text-sm text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring aria-[current=page]:bg-sidebar-accent aria-[current=page]:font-semibold aria-[current=page]:text-sidebar-accent-foreground"
                                                href={child.url}
                                                onClick={onNavigate}
                                            >
                                                <span className="min-w-0 [overflow-wrap:anywhere]">
                                                    {child.label}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

export function AdminActorIdentity({
    identity,
    direction,
}: {
    identity: AdminIdentity;
    direction: 'rtl' | 'ltr';
}) {
    const role =
        direction === 'rtl'
            ? identity.role === 'admin'
                ? 'مدير'
                : 'موظف'
            : identity.role === 'admin'
              ? 'Admin'
              : 'Staff';

    return (
        <div className="flex min-w-0 items-center gap-3">
            <span
                aria-hidden="true"
                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md border bg-accent text-sm font-bold text-foreground"
            >
                {identity.name.trim().charAt(0).toUpperCase() || 'A'}
            </span>
            <span className="flex min-w-0 flex-col gap-0.5">
                <strong className="min-w-0 text-sm leading-snug font-semibold [overflow-wrap:anywhere] text-sidebar-foreground">
                    {identity.name}
                </strong>
                <small className="text-xs text-sidebar-foreground/70">
                    {role}
                </small>
            </span>
        </div>
    );
}

export function AdminLogoutButton({
    label,
    logoutUrl,
    onLogout,
}: {
    label: string;
    logoutUrl: string;
    onLogout?: () => void;
}) {
    function logout() {
        onLogout?.();
        router.flushAll();
        router.post(logoutUrl);
    }

    return (
        <button
            className="flex min-h-[44px] w-full cursor-pointer items-center justify-start gap-3 rounded-md px-3 py-2 text-sm font-medium text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            onClick={logout}
            type="button"
        >
            <LogOut aria-hidden="true" className="h-[18px] w-[18px] shrink-0" />
            <span className="min-w-0 [overflow-wrap:anywhere]">{label}</span>
        </button>
    );
}

export default function AdminSidebar({
    adminIdentity,
    adminUi,
    current,
    direction,
    logoutUrl,
    navigation,
}: AdminNavigationProps) {
    return (
        <aside className="admin-sidebar sticky top-0 hidden h-dvh w-64 flex-col gap-6 overflow-y-auto border-e border-sidebar-border bg-sidebar p-[max(1.25rem,env(safe-area-inset-top))_1rem_max(1.25rem,env(safe-area-inset-bottom))] text-sidebar-foreground md:flex">
            <div className="flex items-center gap-3 border-b border-sidebar-border pb-4">
                <img
                    alt=""
                    height="40"
                    src="/images/arabut-logo-header.webp"
                    width="40"
                    className="h-10 w-10 shrink-0 object-contain"
                />
                <span
                    className="font-display text-lg font-bold tracking-tight text-sidebar-foreground"
                    translate="no"
                >
                    {adminUi.brand}
                </span>
            </div>
            <AdminNavigationList
                adminUi={adminUi}
                current={current}
                navigation={navigation}
            />
            <div className="mt-auto flex flex-col gap-4 border-t border-sidebar-border pt-4">
                <AdminActorIdentity
                    direction={direction}
                    identity={adminIdentity}
                />
                <AdminLogoutButton
                    label={adminUi.common.logout}
                    logoutUrl={logoutUrl}
                />
            </div>
        </aside>
    );
}
