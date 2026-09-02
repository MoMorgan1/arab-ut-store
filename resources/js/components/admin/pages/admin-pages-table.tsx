import { Link } from '@inertiajs/react';
import { FileText, Pencil } from 'lucide-react';
import React from 'react';

import { buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { AdminStorePageRow, AdminTranslations } from '@/types/admin';

export type AdminPagesTableProps = {
    copy: NonNullable<AdminTranslations['pages']>;
    pages: AdminStorePageRow[];
};

export default function AdminPagesTable({ copy, pages }: AdminPagesTableProps) {
    return (
        <div className="space-y-4">
            {/* Desktop Table */}
            <div className="hidden overflow-x-auto rounded-lg border border-border bg-card md:block">
                <Table aria-label={copy.tableLabel}>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-[35%] text-start">
                                {copy.page}
                            </TableHead>
                            <TableHead className="w-[15%] text-start">
                                {copy.address}
                            </TableHead>
                            <TableHead className="w-[15%] text-center">
                                {copy.blockCount}
                            </TableHead>
                            <TableHead className="w-[20%] text-start">
                                {copy.updatedLabel}
                            </TableHead>
                            <TableHead className="w-[15%] text-end">
                                {copy.actions}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {pages.map((page) => (
                            <TableRow key={page.key}>
                                <TableCell className="text-start">
                                    <div className="space-y-0.5">
                                        <p className="font-medium text-foreground">
                                            {page.titleAr}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {page.titleEn}
                                        </p>
                                    </div>
                                </TableCell>
                                <TableCell className="text-start font-mono text-xs text-muted-foreground">
                                    {page.address}
                                </TableCell>
                                <TableCell className="text-center text-sm text-muted-foreground">
                                    {page.blockCount}
                                </TableCell>
                                <TableCell className="text-start text-sm text-muted-foreground">
                                    {page.updatedLabel}
                                </TableCell>
                                <TableCell className="text-end">
                                    <Link
                                        className={cn(
                                            buttonVariants({
                                                variant: 'outline',
                                                size: 'sm',
                                            }),
                                            'inline-flex min-h-9 items-center gap-1.5',
                                        )}
                                        href={page.editUrl}
                                    >
                                        <Pencil
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        <span>{copy.edit}</span>
                                    </Link>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {/* Mobile Cards */}
            <div className="grid gap-3 md:hidden">
                {pages.map((page) => (
                    <article
                        className="rounded-lg border border-border bg-card p-4 shadow-xs"
                        key={page.key}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <FileText
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <h2 className="font-semibold text-foreground">
                                        {page.titleAr}
                                    </h2>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {page.titleEn}
                                </p>
                            </div>
                        </div>

                        <div className="mt-3 grid grid-cols-2 gap-2 border-t border-border/60 pt-3 text-xs">
                            <div>
                                <span className="text-muted-foreground">
                                    {copy.address}:
                                </span>
                                <p className="font-mono text-foreground">
                                    {page.address}
                                </p>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    {copy.blockCount}:
                                </span>
                                <p className="font-medium text-foreground">
                                    {page.blockCount}
                                </p>
                            </div>
                            <div className="col-span-2">
                                <span className="text-muted-foreground">
                                    {copy.updatedLabel}:
                                </span>
                                <p className="font-medium text-foreground">
                                    {page.updatedLabel}
                                </p>
                            </div>
                        </div>

                        <div className="mt-4 border-t border-border/60 pt-3 text-end">
                            <Link
                                className={cn(
                                    buttonVariants({
                                        variant: 'outline',
                                        size: 'default',
                                    }),
                                    'inline-flex min-h-11 w-full items-center justify-center gap-2',
                                )}
                                href={page.editUrl}
                            >
                                <Pencil aria-hidden="true" className="size-4" />
                                <span>{copy.edit}</span>
                            </Link>
                        </div>
                    </article>
                ))}
            </div>
        </div>
    );
}
