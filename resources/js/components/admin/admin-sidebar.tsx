import { Link, router } from '@inertiajs/react';
import { LayoutDashboard, LogOut, ShieldCheck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type {
    AdminIdentity,
    AdminNavigationItem,
    AdminTranslations,
} from '@/types/admin';

const navigationIcons: Record<AdminNavigationItem['key'], LucideIcon> = {
    overview: LayoutDashboard,
    security: ShieldCheck,
};

export type AdminNavigationProps = {
    adminIdentity: AdminIdentity;
    adminUi: AdminTranslations;
    current: AdminNavigationItem['key'];
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
        <nav aria-label={adminUi.brand} className="admin-navigation">
            <ul>
                {navigation.map((item) => {
                    const Icon = navigationIcons[item.key];
                    const selected = item.key === current;

                    return (
                        <li key={item.key}>
                            <Link
                                aria-current={selected ? 'page' : undefined}
                                className="admin-navigation__link"
                                href={item.url}
                                onClick={onNavigate}
                            >
                                <Icon aria-hidden="true" />
                                <span>{item.label}</span>
                            </Link>
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
        <div className="admin-identity">
            <span aria-hidden="true" className="admin-identity__mark">
                {identity.name.trim().charAt(0).toUpperCase() || 'A'}
            </span>
            <span className="admin-identity__copy">
                <strong>{identity.name}</strong>
                <small>{role}</small>
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
            className="admin-navigation__logout"
            onClick={logout}
            type="button"
        >
            <LogOut aria-hidden="true" />
            <span>{label}</span>
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
        <aside className="admin-sidebar">
            <div className="admin-brand">
                <img
                    alt=""
                    height="48"
                    src="/images/arabut-logo-header.webp"
                    width="48"
                />
                <span translate="no">{adminUi.brand}</span>
            </div>
            <AdminNavigationList
                adminUi={adminUi}
                current={current}
                navigation={navigation}
            />
            <div className="admin-sidebar__session">
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
