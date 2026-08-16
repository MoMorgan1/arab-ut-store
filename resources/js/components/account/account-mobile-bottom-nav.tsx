import { Link } from '@inertiajs/react';
import {
    LayoutDashboard,
    LifeBuoy,
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
    security: ShieldCheck,
    support: LifeBuoy,
};

type AccountMobileBottomNavProps = {
    current: AccountDestination;
    items: AccountNavigationItem[];
    translations: AccountTranslations['navigation'];
};

export function AccountMobileBottomNav({
    current,
    items,
    translations,
}: AccountMobileBottomNavProps) {
    return (
        <nav
            aria-label={translations.label}
            className="account-mobile-bottom-nav"
        >
            <div className="account-mobile-bottom-nav__inner">
                {items.map((item) => {
                    const Icon = destinationIcons[item.key] || LayoutDashboard;
                    const selected = item.key === current;

                    return (
                        <Link
                            aria-current={selected ? 'page' : undefined}
                            className={[
                                'account-mobile-bottom-nav__item',
                                selected ? 'account-mobile-bottom-nav__item--active' : '',
                            ]
                                .filter(Boolean)
                                .join(' ')}
                            href={item.url}
                            key={item.key}
                        >
                            <span className="account-mobile-bottom-nav__icon-wrap">
                                <Icon aria-hidden="true" />
                            </span>
                            <span className="account-mobile-bottom-nav__label">
                                {item.label}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}

export default AccountMobileBottomNav;
