import { Link } from '@inertiajs/react';
import { useState } from 'react';

import AppIcon from '@/components/account/app-icon';
import type { AppIconName } from '@/components/account/app-icon';
import { useKeyboardOpen } from '@/hooks/use-keyboard-open';
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
    const isKeyboardOpen = useKeyboardOpen();
    // The tab lights up the moment it is pressed, rather than when the server
    // answers. On a phone the round trip is what made the bar feel heavy, and
    // this removes the wait from what the finger sees.
    const [pending, setPending] = useState<AccountDestination | null>(null);

    // Filter to ensure strictly the 4 destinations
    const bottomNavItems = items.filter((item) =>
        ALLOWED_KEYS.includes(item.key),
    );

    return (
        <nav
            aria-label={translations.label}
            className={cn(
                'arabut-bottom-bar',
                'account-mobile-bottom-nav',
                isKeyboardOpen && 'arabut-bottom-bar--keyboard-open',
            )}
        >
            <div
                className={cn(
                    'arabut-bottom-bar__inner',
                    'account-mobile-bottom-nav__inner',
                )}
            >
                {bottomNavItems.map((item) => {
                    const name = destinationIcons[item.key] || 'grid';
                    const selected = item.key === current;
                    const optimisticallySelected =
                        selected || pending === item.key;
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
                                'arabut-bottom-bar__item',
                                'account-mobile-bottom-nav__item',
                                optimisticallySelected &&
                                    'arabut-bottom-bar__item--active',
                            )}
                            href={item.url}
                            key={item.key}
                            // Every destination in this bar is on screen at all
                            // times, so the bar warms them all: the tap itself
                            // costs no server round trip.
                            cacheFor="1m"
                            prefetch="hover"
                            onStart={() => setPending(item.key)}
                        >
                            <span className="account-mobile-bottom-nav__icon-wrap">
                                <AppIcon name={name} />
                            </span>
                            <span className="arabut-bottom-bar__label account-mobile-bottom-nav__label">
                                {label}
                            </span>
                            {attention.includes(item.key) ? (
                                <>
                                    <span
                                        aria-hidden="true"
                                        className="arabut-bottom-bar__dot"
                                    />
                                    {/* The dot is decorative, so the state it
                                        signals needs a text equivalent or the
                                        customer is never told something is
                                        waiting for them. */}
                                    <span className="sr-only">
                                        {translations.attention}
                                    </span>
                                </>
                            ) : null}
                        </Link>
                    );
                })}
                {adminUrl ? (
                    <Link
                        className="arabut-bottom-bar__item account-mobile-bottom-nav__item"
                        href={adminUrl}
                    >
                        <span className="account-mobile-bottom-nav__icon-wrap">
                            <AppIcon name="shield" />
                        </span>
                        <span className="arabut-bottom-bar__label account-mobile-bottom-nav__label">
                            {translations.admin}
                        </span>
                    </Link>
                ) : null}
            </div>
        </nav>
    );
}

export default AccountMobileBottomNav;
