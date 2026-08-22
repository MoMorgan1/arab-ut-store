import { Link } from '@inertiajs/react';
import { LayoutDashboard, ShieldCheck, ShoppingBag, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type { AdminNavigationProps } from '@/components/admin/admin-sidebar';
import type { AdminNavigationItem } from '@/types/admin';

const navigationIcons: Record<AdminNavigationItem['key'], LucideIcon> = {
    overview: LayoutDashboard,
    orders: ShoppingBag,
    customers: Users,
    security: ShieldCheck,
};

export default function AdminMobileTabBar({
    adminUi,
    current,
    navigation,
}: Pick<AdminNavigationProps, 'adminUi' | 'current' | 'navigation'>) {
    const quickLabel = adminUi.navigation.quick ?? 'quick navigation';
    const navAriaLabel = `${adminUi.brand} ${quickLabel}`;

    return (
        <nav
            aria-label={navAriaLabel}
            className="fixed inset-x-0 bottom-0 z-40 flex min-h-[56px] items-stretch justify-around border-t border-sidebar-border bg-sidebar px-1 pb-[env(safe-area-inset-bottom)] text-sidebar-foreground md:hidden"
        >
            <ul className="flex w-full items-stretch justify-around">
                {navigation.map((item) => {
                    const Icon = navigationIcons[item.key] ?? LayoutDashboard;
                    const selected = item.key === current;

                    return (
                        <li className="flex flex-1" key={item.key}>
                            <Link
                                aria-current={selected ? 'page' : undefined}
                                className="flex min-h-[56px] min-w-[44px] flex-1 flex-col items-center justify-center gap-1 px-1 py-1.5 text-center text-xs font-medium text-sidebar-foreground/70 transition-colors hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring aria-[current=page]:text-primary motion-reduce:transition-none"
                                href={item.url}
                            >
                                <Icon
                                    aria-hidden="true"
                                    className="size-5 shrink-0"
                                />
                                <span className="max-w-[72px] truncate text-[11px] leading-none font-medium">
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
