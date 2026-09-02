import { ArrowDown, ArrowUp, Eye, EyeOff, Pencil, Trash2 } from 'lucide-react';
import React from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { Button } from '@/components/ui/button';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AdminFaqRow, AdminTranslations } from '@/types/admin';

export type AdminFaqMobileCardProps = {
    canManage: boolean;
    copy: NonNullable<AdminTranslations['faq']>;
    entries: AdminFaqRow[];
    movingId: string | null;
    togglingId: string | null;
    onDeleteClick: (entry: AdminFaqRow) => void;
    onEditClick: (entry: AdminFaqRow) => void;
    onMoveClick: (entry: AdminFaqRow, direction: 'up' | 'down') => void;
    onToggleVisibility: (entry: AdminFaqRow) => void;
};

export default function AdminFaqMobileCard({
    canManage,
    copy,
    entries,
    movingId,
    togglingId,
    onDeleteClick,
    onEditClick,
    onMoveClick,
    onToggleVisibility,
}: AdminFaqMobileCardProps) {
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const formatDate = (dateStr: string) => {
        if (!dateStr) {
            return '—';
        }

        try {
            return dateFormatter.format(new Date(dateStr));
        } catch {
            return dateStr;
        }
    };

    if (entries.length === 0) {
        return (
            <div className="rounded-lg border border-border bg-card p-8 text-center md:hidden">
                <p className="text-sm text-muted-foreground">
                    {copy.noEntries}
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 md:hidden">
            {entries.map((entry, index) => {
                const isFirst = index === 0;
                const isLast = index === entries.length - 1;
                const isBusy = movingId === entry.id || togglingId === entry.id;

                return (
                    <div
                        className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4"
                        key={entry.id}
                    >
                        <div className="flex items-center justify-between gap-2 border-b border-border/60 pb-2">
                            <span className="font-mono text-xs font-semibold text-muted-foreground">
                                #{index + 1}
                            </span>
                            <AdminBadge
                                variant={
                                    entry.isVisible ? 'success' : 'neutral'
                                }
                            >
                                {entry.isVisible
                                    ? copy.stateVisible
                                    : copy.stateHidden}
                            </AdminBadge>
                        </div>

                        <div className="space-y-1">
                            <div
                                className="text-base font-semibold text-foreground"
                                dir="rtl"
                            >
                                {entry.questionAr}
                            </div>
                            <div
                                className="text-xs text-muted-foreground"
                                dir="ltr"
                            >
                                {entry.questionEn}
                            </div>
                        </div>

                        <div className="text-xs text-muted-foreground">
                            <span>{copy.updatedAt}: </span>
                            <span>{formatDate(entry.updatedAt)}</span>
                        </div>

                        {canManage ? (
                            <div className="flex flex-wrap items-center gap-2 border-t border-border/60 pt-3">
                                <Button
                                    aria-label={`${copy.moveUp} (${entry.questionEn})`}
                                    className="min-h-11 min-w-11 gap-1.5"
                                    disabled={isFirst || isBusy}
                                    onClick={() => onMoveClick(entry, 'up')}
                                    size="sm"
                                    type="button"
                                    variant="outline"
                                >
                                    <ArrowUp className="size-4" />
                                    <span>{copy.moveUp}</span>
                                </Button>
                                <Button
                                    aria-label={`${copy.moveDown} (${entry.questionEn})`}
                                    className="min-h-11 min-w-11 gap-1.5"
                                    disabled={isLast || isBusy}
                                    onClick={() => onMoveClick(entry, 'down')}
                                    size="sm"
                                    type="button"
                                    variant="outline"
                                >
                                    <ArrowDown className="size-4" />
                                    <span>{copy.moveDown}</span>
                                </Button>
                                <Button
                                    aria-label={`${copy.edit} (${entry.questionEn})`}
                                    className="min-h-11 min-w-11 gap-1.5"
                                    disabled={isBusy}
                                    onClick={() => onEditClick(entry)}
                                    size="sm"
                                    type="button"
                                    variant="outline"
                                >
                                    <Pencil className="size-4" />
                                    <span>{copy.edit}</span>
                                </Button>
                                <Button
                                    aria-label={`${entry.isVisible ? copy.hideFromStore : copy.showInStore} (${entry.questionEn})`}
                                    className="min-h-11 min-w-11 gap-1.5"
                                    disabled={isBusy}
                                    onClick={() => onToggleVisibility(entry)}
                                    size="sm"
                                    type="button"
                                    variant="outline"
                                >
                                    {entry.isVisible ? (
                                        <>
                                            <EyeOff className="size-4" />
                                            <span>{copy.hideFromStore}</span>
                                        </>
                                    ) : (
                                        <>
                                            <Eye className="size-4" />
                                            <span>{copy.showInStore}</span>
                                        </>
                                    )}
                                </Button>
                                <Button
                                    aria-label={`${copy.delete} (${entry.questionEn})`}
                                    className="min-h-11 min-w-11 gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    disabled={isBusy}
                                    onClick={() => onDeleteClick(entry)}
                                    size="sm"
                                    type="button"
                                    variant="outline"
                                >
                                    <Trash2 className="size-4" />
                                    <span>{copy.delete}</span>
                                </Button>
                            </div>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}
