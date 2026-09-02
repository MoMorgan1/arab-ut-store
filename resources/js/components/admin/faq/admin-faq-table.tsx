import { ArrowDown, ArrowUp, Eye, EyeOff, Pencil, Trash2 } from 'lucide-react';
import React from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DATE_LOCALE } from '@/lib/date-locale';
import type { AdminFaqRow, AdminTranslations } from '@/types/admin';

export type AdminFaqTableProps = {
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

export default function AdminFaqTable({
    canManage,
    copy,
    entries,
    movingId,
    togglingId,
    onDeleteClick,
    onEditClick,
    onMoveClick,
    onToggleVisibility,
}: AdminFaqTableProps) {
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
            <div className="hidden rounded-lg border border-border bg-card p-12 text-center md:block">
                <p className="text-sm text-muted-foreground">
                    {copy.noEntries}
                </p>
            </div>
        );
    }

    return (
        <div className="hidden overflow-x-auto rounded-lg border border-border bg-card md:block">
            <Table aria-label={copy.tableLabel}>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-16 text-center">
                            {copy.position}
                        </TableHead>
                        <TableHead>{copy.question}</TableHead>
                        <TableHead className="w-28">{copy.status}</TableHead>
                        <TableHead className="w-44">{copy.updatedAt}</TableHead>
                        {canManage ? (
                            <TableHead className="w-64 text-end">
                                {copy.actions}
                            </TableHead>
                        ) : null}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {entries.map((entry, index) => {
                        const isFirst = index === 0;
                        const isLast = index === entries.length - 1;
                        const isBusy =
                            movingId === entry.id || togglingId === entry.id;

                        return (
                            <TableRow key={entry.id}>
                                <TableCell className="text-center font-mono text-xs text-muted-foreground">
                                    {index + 1}
                                </TableCell>
                                <TableCell className="max-w-md">
                                    <div
                                        className="font-semibold text-foreground"
                                        dir="rtl"
                                    >
                                        {entry.questionAr}
                                    </div>
                                    <div
                                        className="mt-0.5 text-xs text-muted-foreground"
                                        dir="ltr"
                                    >
                                        {entry.questionEn}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <AdminBadge
                                        variant={
                                            entry.isVisible
                                                ? 'success'
                                                : 'neutral'
                                        }
                                    >
                                        {entry.isVisible
                                            ? copy.stateVisible
                                            : copy.stateHidden}
                                    </AdminBadge>
                                </TableCell>
                                <TableCell className="text-xs text-muted-foreground">
                                    {formatDate(entry.updatedAt)}
                                </TableCell>
                                {canManage ? (
                                    <TableCell className="text-end">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                aria-label={`${copy.moveUp} (${entry.questionEn})`}
                                                className="size-8 p-0"
                                                disabled={isFirst || isBusy}
                                                onClick={() =>
                                                    onMoveClick(entry, 'up')
                                                }
                                                size="icon"
                                                type="button"
                                                variant="ghost"
                                            >
                                                <ArrowUp className="size-4" />
                                            </Button>
                                            <Button
                                                aria-label={`${copy.moveDown} (${entry.questionEn})`}
                                                className="size-8 p-0"
                                                disabled={isLast || isBusy}
                                                onClick={() =>
                                                    onMoveClick(entry, 'down')
                                                }
                                                size="icon"
                                                type="button"
                                                variant="ghost"
                                            >
                                                <ArrowDown className="size-4" />
                                            </Button>
                                            <Button
                                                aria-label={`${copy.edit} (${entry.questionEn})`}
                                                className="size-8 p-0"
                                                disabled={isBusy}
                                                onClick={() =>
                                                    onEditClick(entry)
                                                }
                                                size="icon"
                                                type="button"
                                                variant="ghost"
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                aria-label={`${entry.isVisible ? copy.hideFromStore : copy.showInStore} (${entry.questionEn})`}
                                                className="size-8 p-0"
                                                disabled={isBusy}
                                                onClick={() =>
                                                    onToggleVisibility(entry)
                                                }
                                                size="icon"
                                                type="button"
                                                variant="ghost"
                                            >
                                                {entry.isVisible ? (
                                                    <EyeOff className="size-4" />
                                                ) : (
                                                    <Eye className="size-4" />
                                                )}
                                            </Button>
                                            <Button
                                                aria-label={`${copy.delete} (${entry.questionEn})`}
                                                className="size-8 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                disabled={isBusy}
                                                onClick={() =>
                                                    onDeleteClick(entry)
                                                }
                                                size="icon"
                                                type="button"
                                                variant="ghost"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </TableCell>
                                ) : null}
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
