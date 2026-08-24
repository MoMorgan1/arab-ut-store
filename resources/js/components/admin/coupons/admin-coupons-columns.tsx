'use no memo'; // TanStack Table exposes mutable row and table objects.

import { Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
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

export type CouponSortKey = 'created_at' | 'code' | 'used_count' | 'value';

export type CouponColumnOptions = {
    adminUi: AdminTranslations;
    currentSort: CouponSortKey;
    currentDirection: 'asc' | 'desc';
    locale: 'ar' | 'en';
    onSortChange: (sort: CouponSortKey, direction: 'asc' | 'desc') => void;
    onEdit: (coupon: AdminCouponRow) => void;
    onToggle: (coupon: AdminCouponRow, targetActive: boolean) => void;
    onDuplicate: (coupon: AdminCouponRow) => void;
    permissions: string[];
    showUrlTemplate: string;
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
            return {
                label: copy.active,
                variant: 'success',
                icon: CheckCircle2,
            };
        case 'scheduled':
            return { label: copy.scheduled, variant: 'warning', icon: Clock };
        case 'paused':
            return { label: copy.paused, variant: 'neutral', icon: Pause };
        case 'expired':
            return { label: copy.expired, variant: 'danger', icon: XCircle };
        case 'exhausted':
            return { label: copy.exhausted, variant: 'danger', icon: XCircle };
        default:
            return {
                label: copy.active,
                variant: 'neutral',
                icon: CheckCircle2,
            };
    }
}

export function getAdminCouponColumns({
    adminUi,
    currentSort,
    currentDirection,
    locale,
    onSortChange,
    onEdit,
    onToggle,
    onDuplicate,
    permissions,
    showUrlTemplate,
}: CouponColumnOptions): ColumnDef<AdminCouponRow>[] {
    const copy = adminUi.coupons;
    const canManage = permissions.includes('marketing.manage');

    const sortHeader = (sortKey: CouponSortKey, label: string) => {
        const isSorted = currentSort === sortKey;
        const nextDirection = isSorted
            ? currentDirection === 'asc'
                ? 'desc'
                : 'asc'
            : sortKey === 'code'
              ? 'asc'
              : 'desc';
        const direction =
            currentDirection === 'asc'
                ? adminUi.products.sortAscending
                : adminUi.products.sortDescending;
        const ariaLabel = isSorted
            ? `${label}, ${direction}`
            : adminUi.products.sortBy.replace(':column', label);

        return (
            <button
                aria-label={ariaLabel}
                className="inline-flex min-h-11 items-center gap-1.5 font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                onClick={() => onSortChange(sortKey, nextDirection)}
                type="button"
            >
                <span>{label}</span>
                {isSorted ? (
                    currentDirection === 'asc' ? (
                        <ArrowUp
                            aria-hidden="true"
                            className="size-3.5 text-primary"
                        />
                    ) : (
                        <ArrowDown
                            aria-hidden="true"
                            className="size-3.5 text-primary"
                        />
                    )
                ) : (
                    <ArrowUpDown
                        aria-hidden="true"
                        className="size-3.5 opacity-50"
                    />
                )}
            </button>
        );
    };

    return [
        // 1. Code & Description
        {
            accessorKey: 'code',
            cell: ({ row }) => {
                const coupon = row.original;
                const detailUrl = showUrlTemplate.replace('__ID__', coupon.id);

                const description =
                    locale === 'ar'
                        ? coupon.descriptionAr || coupon.descriptionEn
                        : coupon.descriptionEn || coupon.descriptionAr;

                return (
                    <div className="flex max-w-[200px] flex-col gap-0.5">
                        <Link
                            className="font-mono text-sm font-bold tracking-tight text-foreground underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring"
                            href={detailUrl}
                        >
                            {coupon.code}
                        </Link>
                        {description ? (
                            <span
                                className="truncate text-xs text-muted-foreground"
                                title={description}
                            >
                                {description}
                            </span>
                        ) : null}
                    </div>
                );
            },
            header: () => sortHeader('code', copy.columns.code),
            id: 'code',
        },

        // 2. Discount & Cap / Min
        {
            accessorKey: 'value',
            cell: ({ row }) => {
                const coupon = row.original;
                const isPercent = coupon.discountType === 'percent';
                const mainText = isPercent
                    ? `${coupon.value}%`
                    : formatMoneySar(coupon.value, locale);

                const subItems: string[] = [];

                if (
                    isPercent &&
                    coupon.maximumDiscountHalalah !== null &&
                    coupon.maximumDiscountHalalah > 0
                ) {
                    subItems.push(
                        copy.capAmount.replace(
                            ':amount',
                            formatMoneySar(
                                coupon.maximumDiscountHalalah,
                                locale,
                            ),
                        ),
                    );
                }

                if (coupon.minimumOrderHalalah > 0) {
                    subItems.push(
                        copy.minAmount.replace(
                            ':amount',
                            formatMoneySar(coupon.minimumOrderHalalah, locale),
                        ),
                    );
                }

                return (
                    <div className="flex flex-col gap-0.5">
                        <span className="text-sm font-semibold text-foreground tabular-nums">
                            {mainText}
                        </span>
                        {subItems.length > 0 ? (
                            <span className="text-xs text-muted-foreground">
                                {subItems.join(' • ')}
                            </span>
                        ) : null}
                    </div>
                );
            },
            header: () => sortHeader('value', copy.columns.discount),
            id: 'discount',
        },

        // 3. Scope
        {
            accessorKey: 'scope',
            cell: ({ row }) => {
                const coupon = row.original;
                let scopeLabel = copy.scopeOrder;
                let detailText: string | null = null;

                if (coupon.scope === 'category') {
                    scopeLabel = copy.scopeCategory;

                    if (coupon.targets && coupon.targets.length > 0) {
                        detailText = coupon.targets
                            .map((t) => t.name)
                            .join(', ');
                    }
                } else if (coupon.scope === 'product') {
                    scopeLabel = copy.scopeProduct;

                    if (coupon.targets && coupon.targets.length > 0) {
                        detailText = coupon.targets
                            .map((t) => t.name)
                            .join(', ');
                    }
                } else if (coupon.scope === 'service') {
                    scopeLabel = copy.scopeService;

                    if (coupon.serviceType) {
                        detailText = coupon.serviceType;
                    }
                }

                return (
                    <div className="flex max-w-[160px] flex-col gap-0.5">
                        <span className="text-xs font-medium text-foreground">
                            {scopeLabel}
                        </span>
                        {detailText ? (
                            <span
                                className="truncate text-xs text-muted-foreground"
                                title={detailText}
                            >
                                {detailText}
                            </span>
                        ) : null}
                    </div>
                );
            },
            header: copy.columns.scope,
            id: 'scope',
        },

        // 4. Eligibility
        {
            id: 'eligibility',
            cell: ({ row }) => {
                const coupon = row.original;
                const badges: string[] = [];

                if (coupon.firstOrderOnly) {
                    badges.push(copy.firstOrderOnlyLabel);
                }

                if (coupon.excludesPromotedItems) {
                    badges.push(copy.excludesPromotedLabel);
                }

                if (badges.length === 0) {
                    badges.push(copy.allCustomers);
                }

                return (
                    <div className="flex flex-col gap-0.5 text-xs text-muted-foreground">
                        {badges.map((b) => (
                            <span key={b} className="whitespace-nowrap">
                                {b}
                            </span>
                        ))}
                    </div>
                );
            },
            header: copy.columns.eligibility,
        },

        // 5. Usage with progress bar
        {
            accessorKey: 'usedCount',
            cell: ({ row }) => {
                const coupon = row.original;
                const hasLimit =
                    coupon.usageLimit !== null && coupon.usageLimit > 0;
                const percent = hasLimit
                    ? Math.min(
                          100,
                          Math.round(
                              (coupon.usedCount /
                                  (coupon.usageLimit as number)) *
                                  100,
                          ),
                      )
                    : 0;

                return (
                    <div className="flex max-w-[140px] min-w-[100px] flex-col gap-1.5">
                        <span className="text-xs font-medium text-foreground tabular-nums">
                            {hasLimit
                                ? copy.progressText
                                      .replace(
                                          ':used',
                                          String(coupon.usedCount),
                                      )
                                      .replace(
                                          ':limit',
                                          String(coupon.usageLimit),
                                      )
                                : copy.progressUnlimited.replace(
                                      ':used',
                                      String(coupon.usedCount),
                                  )}
                        </span>
                        {hasLimit ? (
                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    className={`h-full transition-all duration-300 ${
                                        percent >= 100
                                            ? 'bg-status-danger'
                                            : percent >= 80
                                              ? 'bg-status-warning'
                                              : 'bg-primary'
                                    }`}
                                    style={{ width: `${percent}%` }}
                                />
                            </div>
                        ) : null}
                    </div>
                );
            },
            header: () => sortHeader('used_count', copy.columns.usage),
            id: 'usage',
        },

        // 6. Window
        {
            accessorKey: 'startsAt',
            cell: ({ row }) => {
                const coupon = row.original;
                const starts = coupon.startsAt
                    ? coupon.startsAt.slice(0, 10)
                    : null;
                const ends = coupon.endsAt ? coupon.endsAt.slice(0, 10) : null;

                let windowText = copy.always;

                if (starts && ends) {
                    windowText = copy.window
                        .replace(':from', starts)
                        .replace(':until', ends);
                } else if (starts) {
                    windowText = copy.from.replace(':date', starts);
                } else if (ends) {
                    windowText = copy.until.replace(':date', ends);
                }

                return (
                    <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                        {windowText}
                    </span>
                );
            },
            header: copy.columns.window,
            id: 'window',
        },

        // 7. Status
        {
            accessorKey: 'status',
            cell: ({ row }) => {
                const coupon = row.original;
                const badge = resolveStatusBadge(coupon.status, copy);

                return (
                    <AdminBadge icon={badge.icon} variant={badge.variant}>
                        {badge.label}
                    </AdminBadge>
                );
            },
            header: copy.columns.status,
            id: 'status',
        },

        // 8. Actions
        {
            id: 'actions',
            enableHiding: false,
            header: copy.columns.actions,
            cell: ({ row }) => {
                const coupon = row.original;
                const detailUrl = showUrlTemplate.replace('__ID__', coupon.id);

                return (
                    <div className="flex items-center gap-1">
                        <Link
                            aria-label={copy.viewDetails}
                            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                            href={detailUrl}
                            title={copy.viewDetails}
                        >
                            <Eye aria-hidden="true" className="size-4" />
                        </Link>

                        {canManage ? (
                            <>
                                <Button
                                    aria-label={copy.editButton}
                                    className="min-h-11 min-w-11 p-2"
                                    onClick={() => onEdit(coupon)}
                                    size="icon"
                                    title={copy.editButton}
                                    type="button"
                                    variant="ghost"
                                >
                                    <Edit
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                </Button>

                                <Button
                                    aria-label={
                                        coupon.isActive
                                            ? copy.pauseButton
                                            : copy.resumeButton
                                    }
                                    className={`min-h-11 min-w-11 p-2 ${
                                        coupon.isActive
                                            ? 'text-status-warning hover:bg-status-warning/10'
                                            : 'text-status-success hover:bg-status-success/10'
                                    }`}
                                    onClick={() =>
                                        onToggle(coupon, !coupon.isActive)
                                    }
                                    size="icon"
                                    title={
                                        coupon.isActive
                                            ? copy.pauseButton
                                            : copy.resumeButton
                                    }
                                    type="button"
                                    variant="ghost"
                                >
                                    {coupon.isActive ? (
                                        <Pause
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    ) : (
                                        <Play
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    )}
                                </Button>

                                <Button
                                    aria-label={copy.duplicateButton}
                                    className="min-h-11 min-w-11 p-2 text-muted-foreground hover:text-foreground"
                                    onClick={() => onDuplicate(coupon)}
                                    size="icon"
                                    title={copy.duplicateButton}
                                    type="button"
                                    variant="ghost"
                                >
                                    <Copy
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                </Button>
                            </>
                        ) : null}
                    </div>
                );
            },
        },
    ];
}
