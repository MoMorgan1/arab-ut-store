import { Link } from '@inertiajs/react';

import AppIcon from '@/components/account/app-icon';
import type { AppIconName } from '@/components/account/app-icon';
import type { AdminNavigationProps } from '@/components/admin/admin-sidebar';
import { useKeyboardOpen } from '@/hooks/use-keyboard-open';
import { cn } from '@/lib/utils';
import type { AdminNavigationItem } from '@/types/admin';

const PRIMARY_TAB_KEYS = [
    'overview',
    'orders',
    'customers',
    'products',
    'more',
] as const;

type PrimaryTabKey = (typeof PRIMARY_TAB_KEYS)[number];

/**
 * The admin tab bar draws from the same icon set as the customer bottom bar, so
 * the two surfaces read as one family rather than two products.
 */
const navigationIcons: Record<PrimaryTabKey, AppIconName> = {
    overview: 'grid',
    orders: 'cube',
    customers: 'user',
    products: 'wallet',
    more: 'ellipsis',
};

export default function AdminMobileTabBar({
    adminUi,
    current,
    navigation,
}: Pick<AdminNavigationProps, 'adminUi' | 'current' | 'navigation'>) {
    const quickLabel = adminUi.navigation.quick ?? 'quick navigation';
    const navAriaLabel = `${adminUi.brand} ${quickLabel}`;
    const isKeyboardOpen = useKeyboardOpen();

    // Selected explicitly rather than mapped over `navigation`, preserving
    // destination order and filtering. `navigation` is permission-gated, so a
    // Staff user legitimately resolves fewer tabs here.
    const primaryTabs = PRIMARY_TAB_KEYS.flatMap((key) => {
        const directItem = navigation.find(
            (entry): entry is AdminNavigationItem => entry.key === key,
        );

        if (directItem !== undefined) {
            return [{ ...directItem, key }];
        }

        for (const entry of navigation) {
            const child = entry.children?.find((c) => c.key === key);

            if (child !== undefined) {
                return [{ ...child, key }];
            }
        }

        return [];
    });

    return (
        <nav
            aria-label={navAriaLabel}
            className={cn(
                'arabut-bottom-bar md:hidden',
                isKeyboardOpen && 'arabut-bottom-bar--keyboard-open',
            )}
        >
            <ul className="arabut-bottom-bar__inner list-none">
                {primaryTabs.map((item) => {
                    const name = navigationIcons[item.key];
                    const selected = item.key === current;

                    return (
                        <li className="flex min-w-0 flex-1" key={item.key}>
                            <Link
                                aria-current={selected ? 'page' : undefined}
                                className={cn(
                                    'arabut-bottom-bar__item',
                                    selected &&
                                        'arabut-bottom-bar__item--active',
                                )}
                                href={item.url}
                            >
                                <AppIcon name={name} />
                                <span className="arabut-bottom-bar__label">
                                    {item.label}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
