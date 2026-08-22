import { Link } from '@inertiajs/react';
import {
    LayoutDashboard,
    PackageSearch,
    ShieldCheck,
    UserRound,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { cn } from '@/lib/utils';
import type {
    AccountDestination,
    AccountNavigationItem,
    AccountTranslations,
} from '@/types/account';

const destinationIcons: Record<string, LucideIcon> = {
    overview: LayoutDashboard,
    orders: PackageSearch,
    wallet: WalletCards,
    profile: UserRound,
};

type AccountMobileBottomNavProps = {
    adminUrl?: string | null;
    bottomNav?: { home: string; account: string };
    current: AccountDestination;
    items: AccountNavigationItem[];
    translations: AccountTranslations['navigation'];
};

const ALLOWED_KEYS: AccountDestination[] = [
    'overview',
    'orders',
    'wallet',
    'profile',
];

export function AccountMobileBottomNav({
    adminUrl,
    bottomNav,
    current,
    items,
    translations,
}: AccountMobileBottomNavProps) {
    const [isKeyboardOpen, setIsKeyboardOpen] = useState(false);

    useEffect(() => {
        const closeTimer = { current: undefined as number | undefined };

        function clearPendingClose() {
            if (closeTimer.current !== undefined) {
                window.clearTimeout(closeTimer.current);
                closeTimer.current = undefined;
            }
        }

        const handleFocusIn = (event: FocusEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.tagName === 'SELECT')
            ) {
                clearPendingClose();
                setIsKeyboardOpen(true);
            }
        };

        const handleFocusOut = (event: FocusEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                !target ||
                (target.tagName !== 'INPUT' &&
                    target.tagName !== 'TEXTAREA' &&
                    target.tagName !== 'SELECT')
            ) {
                return;
            }

            clearPendingClose();
            closeTimer.current = window.setTimeout(() => {
                closeTimer.current = undefined;
                setIsKeyboardOpen(false);
            }, 120);
        };

        window.addEventListener('focusin', handleFocusIn);
        window.addEventListener('focusout', handleFocusOut);

        return () => {
            clearPendingClose();
            window.removeEventListener('focusin', handleFocusIn);
            window.removeEventListener('focusout', handleFocusOut);
        };
    }, []);

    // Active key mapping: security/support map to profile (الحساب)
    const effectiveActiveKey =
        current === 'security' || current === 'support' ? 'profile' : current;

    // Filter to ensure strictly the 4 destinations
    const bottomNavItems = items.filter((item) =>
        ALLOWED_KEYS.includes(item.key),
    );

    return (
        <nav
            aria-label={translations.label}
            className={cn(
                'account-mobile-bottom-nav',
                isKeyboardOpen && 'account-mobile-bottom-nav--keyboard-open',
            )}
        >
            <div className="account-mobile-bottom-nav__inner">
                {bottomNavItems.map((item) => {
                    const Icon = destinationIcons[item.key] || LayoutDashboard;
                    const selected = item.key === effectiveActiveKey;
                    const label =
                        item.key === 'overview'
                            ? (bottomNav?.home ?? item.label)
                            : item.key === 'profile'
                              ? (bottomNav?.account ?? item.label)
                              : item.label;

                    return (
                        <Link
                            aria-current={selected ? 'page' : undefined}
                            className={cn(
                                'account-mobile-bottom-nav__item',
                                selected &&
                                    'account-mobile-bottom-nav__item--active',
                            )}
                            href={item.url}
                            key={item.key}
                        >
                            <span className="account-mobile-bottom-nav__icon-wrap">
                                <Icon aria-hidden="true" />
                            </span>
                            <span className="account-mobile-bottom-nav__label">
                                {label}
                            </span>
                        </Link>
                    );
                })}
                {adminUrl ? (
                    <Link
                        className="account-mobile-bottom-nav__item"
                        href={adminUrl}
                    >
                        <span className="account-mobile-bottom-nav__icon-wrap">
                            <ShieldCheck aria-hidden="true" />
                        </span>
                        <span className="account-mobile-bottom-nav__label">
                            {translations.admin}
                        </span>
                    </Link>
                ) : null}
            </div>
        </nav>
    );
}

export default AccountMobileBottomNav;
