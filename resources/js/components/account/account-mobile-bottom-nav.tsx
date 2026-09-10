import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import AppIcon from '@/components/account/app-icon';
import type { AppIconName } from '@/components/account/app-icon';
import { cn } from '@/lib/utils';
import type {
    AccountDestination,
    AccountNavigationItem,
    AccountTranslations,
} from '@/types/account';

const destinationIcons: Record<AccountDestination, AppIconName> = {
    overview: 'grid',
    orders: 'cube',
    wallet: 'wallet',
    profile: 'user',
};

type AccountMobileBottomNavProps = {
    adminUrl?: string | null;
    bottomNav?: { home: string; account: string };
    current: AccountDestination;
    items: AccountNavigationItem[];
    /**
     * Destinations that still need the customer (an unverified number or
     * email). Rendered as a quiet dot, never a count.
     */
    attention?: AccountDestination[];
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
    attention = [],
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

    // Filter to ensure strictly the 4 destinations
    const bottomNavItems = items.filter((item) =>
        ALLOWED_KEYS.includes(item.key),
    );
    const fits = bottomNavItems.length + (adminUrl ? 1 : 0) <= 4;

    return (
        <nav
            aria-label={translations.label}
            className={cn(
                'account-mobile-bottom-nav',
                isKeyboardOpen && 'account-mobile-bottom-nav--keyboard-open',
            )}
        >
            <div
                className={cn(
                    'account-mobile-bottom-nav__inner',
                    fits && 'account-mobile-bottom-nav__inner--fits',
                )}
            >
                {bottomNavItems.map((item) => {
                    const name = destinationIcons[item.key] || 'grid';
                    const selected = item.key === current;
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
                                <AppIcon name={name} />
                            </span>
                            <span className="account-mobile-bottom-nav__label">
                                {label}
                            </span>
                            {attention.includes(item.key) ? (
                                <span
                                    aria-hidden="true"
                                    className="account-mobile-bottom-nav__dot"
                                />
                            ) : null}
                        </Link>
                    );
                })}
                {adminUrl ? (
                    <Link
                        className="account-mobile-bottom-nav__item"
                        href={adminUrl}
                    >
                        <span className="account-mobile-bottom-nav__icon-wrap">
                            <AppIcon name="shield" />
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
