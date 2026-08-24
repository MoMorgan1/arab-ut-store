'use no memo'; // TanStack Table exposes mutable row objects.

import { Link, usePage } from '@inertiajs/react';
import type { Row } from '@tanstack/react-table';
import {
    CheckCircle2,
    Clock,
    Copy,
    Edit,
    Eye,
    Pause,
    Play,
    XCircle,
} from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import type { AdminBadgeVariant } from '@/components/admin/admin-badge';
import { Button } from '@/components/ui/button';
import type { AdminCouponRow, AdminTranslations } from '@/types/admin';

export type AdminCouponsMobileCardProps = {
    adminUi: AdminTranslations;
    locale: 'ar' | 'en';
    onDuplicate: (coupon: AdminCouponRow) => void;
    onEdit: (coupon: AdminCouponRow) => void;
    onToggle: (coupon: AdminCouponRow, targetActive: boolean) => void;
    permissions: string[];
    row: Row<AdminCouponRow>;
    showUrlTemplate?: string;
};

function formatMoneySar(halalah: number, locale: 'ar' | 'en'): string {
    const riyals = halalah / 100;
    return (
        new Intl.NumberFormat(locale, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(riyals) + ' SAR'
    );
}

function resolveStatusBadge(
    status: AdminCouponRow['status'],
    copy: AdminTranslations['coupons'],
): { label: string; variant: AdminBadgeVariant; icon: typeof CheckCircle2 } {
    switch (status) {
        case 'active':
            return { label: copy.active, variant: 'success', icon: CheckCircle2 };
        case 'scheduled':
            return { label: copy.scheduled, variant: 'warning', icon: Clock };
        case 'paused':
            return { label: copy.paused, variant: 'neutral', icon: Pause };
        case 'expired':
            return { label: copy.expired, variant: 'danger', icon: XCircle };
        case 'exhausted':
            return { label: copy.exhausted, variant: 'danger', icon: XCircle };
        default:
            return { label: copy.active, variant: 'neutral', icon: CheckCircle2 };
    }
}

export default function AdminCouponsMobileCard({
    adminUi,
    locale,
    onDuplicate,
    onEdit,
    onToggle,
    permissions,
    row,
    showUrlTemplate,
}: AdminCouponsMobileCardProps) {
    const copy = adminUi.coupons;
    const coupon = row.original;
    const canManage = permissions.includes('marketing.manage');
    const { url } = usePage();
    const isLocalized = url.startsWith('/en/admin');
    const detailUrl = showUrlTemplate
        ? showUrlTemplate.replace('__ID__', coupon.id)
        : (isLocalized
            ? `/en/admin/marketing/coupons/${coupon.id}`
            : `/admin/marketing/coupons/${coupon.id}`);

    const badge = resolveStatusBadge(coupon.status, copy);
    const isPercent = coupon.discountType === 'percent';
    const discountValue = isPercent
        ? `${coupon.value}%`
        : formatMoneySar(coupon.value, locale);

    const description =
        locale === 'ar'
            ? coupon.descriptionAr || coupon.descriptionEn
            : coupon.descriptionEn || coupon.descriptionAr;

    const hasLimit = coupon.usageLimit !== null && coupon.usageLimit > 0;
    const usagePercent = hasLimit
        ? Math.min(100, Math.round((coupon.usedCount / (coupon.usageLimit as number)) * 100))
        : 0;

    const starts = coupon.startsAt ? coupon.startsAt.slice(0, 10) : null;
    const ends = coupon.endsAt ? coupon.endsAt.slice(0, 10) : null;

    let windowText = copy.always;
    if (starts && ends) {
        windowText = copy.window.replace(':from', starts).replace(':until', ends);
    } else if (starts) {
        windowText = copy.from.replace(':date', starts);
    } else if (ends) {
        windowText = copy.until.replace(':date', ends);
    }

    let scopeText = copy.scopeOrder;
    if (coupon.scope === 'category') {
        scopeText = copy.scopeCategory;
        if (coupon.targets && coupon.targets.length > 0) {
            scopeText += `: ${coupon.targets.map((t) => t.name).join(', ')}`;
        }
    } else if (coupon.scope === 'product') {
        scopeText = copy.scopeProduct;
        if (coupon.targets && coupon.targets.length > 0) {
            scopeText += `: ${coupon.targets.map((t) => t.name).join(', ')}`;
        }
    } else if (coupon.scope === 'service') {
        scopeText = `${copy.scopeService}: ${coupon.serviceType || 'Any'}`;
    }

    return (
        <article
            className="flex flex-col gap-3.5 rounded-xl border border-border bg-card p-4 shadow-xs"
            role="listitem"
        >
            {/* Header: Code + Status Badge */}
            <div className="flex items-start justify-between gap-2">
                <div className="flex flex-col gap-1">
                    <Link
                        className="font-mono text-base font-bold tracking-tight text-foreground underline decoration-border underline-offset-4 hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring"
                        href={detailUrl}
                    >
                        {coupon.code}
                    </Link>
                    {description ? (
                        <p className="text-xs text-muted-foreground">{description}</p>
                    ) : null}
                </div>
                <AdminBadge icon={badge.icon} variant={badge.variant}>
                    {badge.label}
                </AdminBadge>
            </div>

            {/* Metrics Grid */}
            <div className="grid grid-cols-2 gap-3 rounded-lg bg-muted/25 p-3 text-xs">
                {/* Discount & Cap */}
                <div className="flex flex-col gap-0.5">
                    <span className="text-muted-foreground">{copy.columns.discount}</span>
                    <span className="font-semibold tabular-nums text-foreground">
                        {discountValue}
                    </span>
                    {isPercent && coupon.maximumDiscountHalalah ? (
                        <span className="text-[11px] text-muted-foreground">
                            {copy.capAmount.replace(':amount', formatMoneySar(coupon.maximumDiscountHalalah, locale))}
                        </span>
                    ) : null}
                    {coupon.minimumOrderHalalah > 0 ? (
                        <span className="text-[11px] text-muted-foreground">
                            {copy.minAmount.replace(':amount', formatMoneySar(coupon.minimumOrderHalalah, locale))}
                        </span>
                    ) : null}
                </div>

                {/* Usage Progress */}
                <div className="flex flex-col gap-1">
                    <span className="text-muted-foreground">{copy.columns.usage}</span>
                    <span className="font-semibold tabular-nums text-foreground">
                        {hasLimit
                            ? copy.progressText
                                  .replace(':used', String(coupon.usedCount))
                                  .replace(':limit', String(coupon.usageLimit))
                            : copy.progressUnlimited.replace(':used', String(coupon.usedCount))}
                    </span>
                    {hasLimit ? (
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full ${
                                    usagePercent >= 100
                                        ? 'bg-status-danger'
                                        : usagePercent >= 80
                                          ? 'bg-status-warning'
                                          : 'bg-primary'
                                }`}
                                style={{ width: `${usagePercent}%` }}
                            />
                        </div>
                    ) : null}
                </div>

                {/* Scope */}
                <div className="flex flex-col gap-0.5">
                    <span className="text-muted-foreground">{copy.columns.scope}</span>
                    <span className="truncate font-medium text-foreground" title={scopeText}>
                        {scopeText}
                    </span>
                </div>

                {/* Window */}
                <div className="flex flex-col gap-0.5">
                    <span className="text-muted-foreground">{copy.columns.window}</span>
                    <span className="font-medium text-foreground">{windowText}</span>
                </div>
            </div>

            {/* Actions Bar */}
            <div className="flex items-center justify-between border-t border-border/60 pt-3">
                <Link
                    className="inline-flex min-h-11 items-center gap-1.5 rounded-md px-3 text-xs font-medium text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                    href={detailUrl}
                >
                    <Eye aria-hidden="true" className="size-4" />
                    <span>{copy.viewDetails}</span>
                </Link>

                {canManage ? (
                    <div className="flex items-center gap-1">
                        <Button
                            aria-label={copy.editButton}
                            className="min-h-11 min-w-11"
                            onClick={() => onEdit(coupon)}
                            size="icon"
                            type="button"
                            variant="ghost"
                        >
                            <Edit aria-hidden="true" className="size-4" />
                        </Button>

                        <Button
                            aria-label={coupon.isActive ? copy.pauseButton : copy.resumeButton}
                            className={`min-h-11 min-w-11 ${
                                coupon.isActive ? 'text-status-warning' : 'text-status-success'
                            }`}
                            onClick={() => onToggle(coupon, !coupon.isActive)}
                            size="icon"
                            type="button"
                            variant="ghost"
                        >
                            {coupon.isActive ? (
                                <Pause aria-hidden="true" className="size-4" />
                            ) : (
                                <Play aria-hidden="true" className="size-4" />
                            )}
                        </Button>

                        <Button
                            aria-label={copy.duplicateButton}
                            className="min-h-11 min-w-11 text-muted-foreground"
                            onClick={() => onDuplicate(coupon)}
                            size="icon"
                            type="button"
                            variant="ghost"
                        >
                            <Copy aria-hidden="true" className="size-4" />
                        </Button>
                    </div>
                ) : null}
            </div>
        </article>
    );
}
