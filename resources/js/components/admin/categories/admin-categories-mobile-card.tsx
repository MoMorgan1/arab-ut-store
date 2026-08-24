'use no memo'; // TanStack Table exposes mutable row objects.

import type { Row } from '@tanstack/react-table';
import { Bot, Eye, EyeOff, UserCheck } from 'lucide-react';
import React from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { AdminCategoryRow, AdminTranslations } from '@/types/admin';

export type AdminCategoriesMobileCardProps = {
    adminUi: AdminTranslations;
    canManage: boolean;
    dateFormatter: Intl.DateTimeFormat;
    locale: 'ar' | 'en';
    onToggleVisibility: (category: AdminCategoryRow) => void;
    row: Row<AdminCategoryRow>;
};

export default function AdminCategoriesMobileCard({
    adminUi,
    canManage,
    dateFormatter,
    onToggleVisibility,
    row,
}: AdminCategoriesMobileCardProps) {
    const copy = adminUi.categories;
    const category = row.original;
    const isAutomation = category.isAutomation;
    const isHidden = category.adminHidden;

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
                        htmlFor={`select-mobile-category-${category.id}`}
                    >
                        <Checkbox
                            aria-label={`${copy.selectRow} ${category.name}`}
                            checked={row.getIsSelected()}
                            id={`select-mobile-category-${category.id}`}
                            onCheckedChange={(checked) =>
                                row.toggleSelected(Boolean(checked))
                            }
                        />
                    </label>
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-sm font-bold whitespace-nowrap text-foreground">
                            <bdi>{category.name}</bdi>
                        </span>
                        <span className="text-xs [overflow-wrap:anywhere] text-muted-foreground">
                            <bdi>{category.slug}</bdi>
                        </span>
                    </div>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <AdminBadge
                        icon={isAutomation ? Bot : UserCheck}
                        variant={isAutomation ? 'neutral' : 'info'}
                    >
                        {category.source
                            ? category.source.name
                            : copy.sourceManual}
                    </AdminBadge>
                    {isHidden ? (
                        <AdminBadge icon={EyeOff} variant="danger">
                            {copy.stateAdminHidden}
                        </AdminBadge>
                    ) : !category.isVisible ? (
                        <AdminBadge icon={EyeOff} variant="warning">
                            {copy.stateAutomationHidden}
                        </AdminBadge>
                    ) : (
                        <AdminBadge icon={Eye} variant="success">
                            {copy.stateVisible}
                        </AdminBadge>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-2 text-xs">
                <div>
                    <span className="text-muted-foreground">
                        {copy.products}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {category.productsCount}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        {copy.visibleProducts}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {category.visibleProductsCount}
                    </span>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-2 border-t border-border/40 pt-2.5 text-xs">
                <div>
                    <span className="text-muted-foreground">
                        {copy.sortOrder}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {category.sortOrder}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        {copy.updatedAt}:{' '}
                    </span>
                    <span className="font-semibold text-foreground tabular-nums">
                        <bdi>
                            {category.updatedAt
                                ? dateFormatter.format(
                                      new Date(category.updatedAt),
                                  )
                                : '—'}
                        </bdi>
                    </span>
                </div>
            </div>

            {canManage ? (
                <div className="flex items-center justify-end border-t border-border/60 pt-2.5">
                    <Button
                        aria-label={
                            isHidden ? copy.restoreToStore : copy.hideFromStore
                        }
                        className={`min-h-11 min-w-11 gap-1.5 text-xs font-medium ${
                            isHidden
                                ? 'text-primary hover:text-primary'
                                : 'text-destructive hover:text-destructive'
                        }`}
                        onClick={() => onToggleVisibility(category)}
                        type="button"
                        variant="outline"
                    >
                        {isHidden ? (
                            <>
                                <Eye aria-hidden="true" className="size-4" />
                                <span>{copy.restoreToStore}</span>
                            </>
                        ) : (
                            <>
                                <EyeOff aria-hidden="true" className="size-4" />
                                <span>{copy.hideFromStore}</span>
                            </>
                        )}
                    </Button>
                </div>
            ) : null}
        </article>
    );
}
