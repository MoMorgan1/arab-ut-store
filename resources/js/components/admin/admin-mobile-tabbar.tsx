import { Link } from '@inertiajs/react';
import {
    Ellipsis,
    LayoutGrid,
    Package,
    ShoppingBag,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type { AdminNavigationProps } from '@/components/admin/admin-sidebar';
import type { AdminNavigationItem } from '@/types/admin';

const PRIMARY_TAB_KEYS = [
    'overview',
    'orders',
    'customers',
    'products',
    'more',
] as const;

type PrimaryTabKey = (typeof PRIMARY_TAB_KEYS)[number];

const navigationIcons: Record<PrimaryTabKey, LucideIcon> = {
    overview: LayoutGrid,
    orders: ShoppingBag,
    customers: Users,
    products: Package,
    more: Ellipsis,
};

export default function AdminMobileTabBar({
    adminUi,
    current,
    navigation,
}: Pick<AdminNavigationProps, 'adminUi' | 'current' | 'navigation'>) {
    const quickLabel = adminUi.navigation.quick ?? 'quick navigation';
    const navAriaLabel = `${adminUi.brand} ${quickLabel}`;

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
            className="fixed inset-x-0 bottom-0 z-40 flex min-h-[56px] items-stretch border-t border-sidebar-border bg-sidebar pb-[env(safe-area-inset-bottom)] text-sidebar-foreground md:hidden"
        >
            <ul className="flex w-full items-stretch">
                {primaryTabs.map((item) => {
                    const Icon = navigationIcons[item.key];
                    const selected = item.key === current;

                    return (
                        <li className="flex flex-1" key={item.key}>
                            <Link
                                aria-current={selected ? 'page' : undefined}
                                className="flex min-h-[56px] min-w-[44px] flex-1 flex-col items-center justify-center gap-1 border-t-2 border-transparent px-1 py-1.5 text-center text-xs font-medium text-muted-foreground transition-colors hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring aria-[current=page]:border-primary aria-[current=page]:font-bold aria-[current=page]:text-primary motion-reduce:transition-none"
                                href={item.url}
                            >
                                <Icon
                                    aria-hidden="true"
                                    className="size-5 shrink-0"
                                />
                                <span className="max-w-[72px] truncate text-[11px] leading-none">
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
