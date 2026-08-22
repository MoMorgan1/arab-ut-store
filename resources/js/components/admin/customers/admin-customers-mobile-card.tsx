'use no memo'; // TanStack Table exposes mutable row objects.

import { Link, usePage } from '@inertiajs/react';
import type { Row } from '@tanstack/react-table';
import { CheckCircle2, XCircle } from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import { Checkbox } from '@/components/ui/checkbox';
import type { AdminCustomerRow, AdminTranslations } from '@/types/admin';

export type AdminCustomersMobileCardProps = {
    adminUi: AdminTranslations;
    dateFormatter: Intl.DateTimeFormat;
    locale: 'ar' | 'en';
    row: Row<AdminCustomerRow>;
};

export default function AdminCustomersMobileCard({
    adminUi,
    dateFormatter,
    locale,
    row,
}: AdminCustomersMobileCardProps) {
    const { url } = usePage();
    const isLocalized = url.startsWith('/en/admin');
    const basePath = isLocalized ? '/en/admin/customers' : '/admin/customers';
    const copy = adminUi.customers;
    const customer = row.original;
    const detailUrl = `${basePath}/${customer.id}`;
    const isActive = customer.isActive;

    return (
        <article
            className="flex flex-col gap-2.5 rounded-lg border border-border bg-card p-3.5 text-card-foreground transition-colors data-[selected=true]:border-primary/50 data-[selected=true]:bg-muted/40 motion-reduce:transition-none"
            data-selected={row.getIsSelected() ? 'true' : undefined}
            role="listitem"
        >
            <div className="flex flex-wrap items-start justify-between gap-2 border-b border-border/60 pb-2.5">
                <div className="flex min-w-0 items-start gap-2">
                    <label
                        className="-ms-2 -mt-2 flex min-h-11 min-w-11 cursor-pointer items-center justify-center"
                        htmlFor={`select-mobile-customer-${customer.id}`}
                    >
                        <Checkbox
                            aria-label={`${copy.selectRow} ${customer.name}`}
                            checked={row.getIsSelected()}
                            id={`select-mobile-customer-${customer.id}`}
                            onCheckedChange={(checked) =>
                                row.toggleSelected(Boolean(checked))
                            }
                        />
                    </label>
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <Link
                            className="text-sm font-bold whitespace-nowrap text-foreground underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                            href={detailUrl}
                        >
                            <bdi>{customer.name}</bdi>
                        </Link>
                        <span className="text-xs [overflow-wrap:anywhere] text-muted-foreground">
                            <bdi>{customer.id}</bdi>
                        </span>
                    </div>
                </div>
                <AdminBadge
                    className="shrink-0"
                    icon={isActive ? CheckCircle2 : XCircle}
                    variant={isActive ? 'success' : 'danger'}
                >
                    {isActive ? copy.statusActive : copy.statusSuspended}
                </AdminBadge>
            </div>

            <div className="min-w-0">
                <p className="text-sm [overflow-wrap:anywhere] text-muted-foreground">
                    {customer.email}
                </p>
                {customer.phone ? (
                    <p className="text-sm text-muted-foreground tabular-nums">
                        <bdi>{customer.phone}</bdi>
                    </p>
                ) : null}
            </div>

            <div className="grid grid-cols-2 gap-2 border-t border-border/40 pt-2.5 text-xs">
                <div>
                    <span className="text-muted-foreground">
                        {copy.ordersCount}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {customer.ordersCount}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        {copy.walletBalance}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {formatAdminMoney(customer.walletBalance, locale)}
                    </span>
                </div>
            </div>

            <div className="flex items-end justify-between gap-3 border-t border-border/60 pt-2.5">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="text-xs text-muted-foreground">
                        {copy.totalSpent}
                    </span>
                    <strong className="text-base font-bold text-foreground tabular-nums">
                        <bdi>
                            {formatAdminMoney(customer.totalSpent, locale)}
                        </bdi>
                    </strong>
                </div>
                <div className="flex flex-col items-end gap-0.5">
                    <span className="text-xs text-muted-foreground">
                        {copy.createdAt}
                    </span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        <bdi>
                            {customer.createdAt
                                ? dateFormatter.format(
                                      new Date(customer.createdAt),
                                  )
                                : '—'}
                        </bdi>
                    </span>
                </div>
            </div>
        </article>
    );
}
