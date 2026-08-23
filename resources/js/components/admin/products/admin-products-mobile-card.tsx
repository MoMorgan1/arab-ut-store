'use no memo'; // TanStack Table exposes mutable row objects.

import { Link, usePage } from '@inertiajs/react';
import type { Row } from '@tanstack/react-table';
import { Bot, Eye, EyeOff, UserCheck } from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import { Checkbox } from '@/components/ui/checkbox';
import type { AdminProductRow, AdminTranslations } from '@/types/admin';

export type AdminProductsMobileCardProps = {
    adminUi: AdminTranslations;
    dateFormatter: Intl.DateTimeFormat;
    locale: 'ar' | 'en';
    row: Row<AdminProductRow>;
};

export default function AdminProductsMobileCard({
    adminUi,
    dateFormatter,
    row,
}: AdminProductsMobileCardProps) {
    const { url } = usePage();
    const isLocalized = url.startsWith('/en/admin');
    const basePath = isLocalized ? '/en/admin/products' : '/admin/products';
    const copy = adminUi.products;
    const orderServices = adminUi.orders.services ?? {};
    const product = row.original;
    const detailUrl = `${basePath}/${product.id}`;
    const isManual = product.authority === 'manual';
    const isVisible = product.isVisible;
    const serviceLabel =
        orderServices[product.serviceType] ?? product.serviceType;

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
                        htmlFor={`select-mobile-product-${product.id}`}
                    >
                        <Checkbox
                            aria-label={`${copy.selectRow} ${product.name}`}
                            checked={row.getIsSelected()}
                            id={`select-mobile-product-${product.id}`}
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
                            <bdi>{product.name}</bdi>
                        </Link>
                        <span className="text-xs [overflow-wrap:anywhere] text-muted-foreground">
                            <bdi>{product.slug}</bdi>
                        </span>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    <AdminBadge
                        icon={isManual ? UserCheck : Bot}
                        variant={isManual ? 'info' : 'neutral'}
                    >
                        {isManual
                            ? copy.authorityManual
                            : copy.authorityAutomation}
                    </AdminBadge>
                    <AdminBadge
                        icon={isVisible ? Eye : EyeOff}
                        variant={isVisible ? 'success' : 'neutral'}
                    >
                        {isVisible
                            ? copy.visibilityVisible
                            : copy.visibilityHidden}
                    </AdminBadge>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-2 text-xs">
                <div>
                    <span className="text-muted-foreground">
                        {copy.service}:{' '}
                    </span>
                    <span className="font-semibold text-foreground">
                        {serviceLabel}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        {copy.source}:{' '}
                    </span>
                    <span className="font-semibold text-foreground">
                        {product.source
                            ? product.source.name
                            : copy.sourceManual}
                    </span>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-2 border-t border-border/40 pt-2.5 text-xs">
                <div>
                    <span className="text-muted-foreground">
                        {copy.variantsCount}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {product.variantsCount}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        {copy.sortOrder}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {product.sortOrder}
                    </span>
                </div>
            </div>

            <div className="flex items-center justify-between gap-3 border-t border-border/60 pt-2.5">
                <span className="text-xs text-muted-foreground tabular-nums">
                    {copy.updatedAt}:{' '}
                    <bdi>
                        {product.updatedAt
                            ? dateFormatter.format(new Date(product.updatedAt))
                            : '—'}
                    </bdi>
                </span>
                <Link
                    className="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-xs font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                    href={detailUrl}
                >
                    {copy.viewDetail}
                </Link>
            </div>
        </article>
    );
}
