import { Link, router } from '@inertiajs/react';
import {
    LayoutDashboard,
    LogOut,
    PackageSearch,
    ShieldCheck,
    UserRound,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type {
    AccountDestination,
    AccountNavigationItem,
    AccountTranslations,
} from '@/types/account';

const destinationIcons: Record<AccountDestination, LucideIcon> = {
    overview: LayoutDashboard,
    orders: PackageSearch,
    wallet: WalletCards,
    profile: UserRound,
};

type AccountNavigationProps = {
    adminUrl?: string | null;
    current: AccountDestination;
    items: AccountNavigationItem[];
    logoutUrl: string;
    sections?: AccountTranslations['profile']['sections'];
    translations: AccountTranslations['navigation'];
};

export default function AccountNavigation({
    adminUrl,
    current,
    items,
    logoutUrl,
    sections,
    translations,
}: AccountNavigationProps) {
    function logout() {
        router.flushAll();
        router.post(logoutUrl);
    }

    return (
        <div className="account-navigation-wrap">
            <nav aria-label={translations.label} className="account-navigation">
                <ul>
                    {items.map((item) => {
                        const Icon = destinationIcons[item.key];
                        const selected = item.key === current;

                        return (
                            <li key={item.key}>
                                <Link
                                    aria-current={selected ? 'page' : undefined}
                                    className="account-navigation__link"
                                    href={item.url}
                                >
                                    <Icon aria-hidden="true" />
                                    <span>{item.label}</span>
                                </Link>
                                {item.key === 'profile' &&
                                selected &&
                                sections ? (
                                    <ul className="account-navigation__sections">
                                        <li>
                                            <a href={`${item.url}#personal`}>
                                                {sections.personal}
                                            </a>
                                        </li>
                                        <li>
                                            <a href={`${item.url}#contact`}>
                                                {sections.contact}
                                            </a>
                                        </li>
                                        <li>
                                            <a href={`${item.url}#security`}>
                                                {sections.security}
                                            </a>
                                        </li>
                                        <li>
                                            <a href={`${item.url}#support`}>
                                                {sections.support}
                                            </a>
                                        </li>
                                    </ul>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            </nav>
            {adminUrl ? (
                <Link
                    className="account-navigation__link account-navigation__link--admin"
                    href={adminUrl}
                >
                    <ShieldCheck aria-hidden="true" />
                    <span>{translations.admin}</span>
                </Link>
            ) : null}
            <button
                className="account-navigation__logout"
                onClick={logout}
                type="button"
            >
                <LogOut aria-hidden="true" />
                <span>{translations.logout}</span>
            </button>
        </div>
    );
}
