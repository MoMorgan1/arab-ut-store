import { ArrowDown, ArrowUp, ArrowUpDown, LoaderCircle } from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import AdminFulfillmentRowAction from '@/components/admin/fulfillment/admin-fulfillment-row-action';
import {
    formatShortAge,
    fulfillmentBadge,
    signalDetail,
} from '@/components/admin/fulfillment/admin-fulfillment-state';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    AdminFulfillmentAction,
    AdminFulfillmentRow,
    AdminFulfillmentSort,
    AdminTranslations,
} from '@/types/admin';

export type AdminFulfillmentTableProps = {
    adminUi: AdminTranslations;
    canAct: boolean;
    canSeeCost: boolean;
    currentDirection: 'asc' | 'desc';
    currentSort: AdminFulfillmentSort;
    isFiltered: boolean;
    isNavigating: boolean;
    items: AdminFulfillmentRow[];
    locale: 'ar' | 'en';
    onAction: (row: AdminFulfillmentRow, action: AdminFulfillmentAction) => void;
    onOpenRow: (row: AdminFulfillmentRow) => void;
    onResetFilters: () => void;
    onSortChange: (sort: AdminFulfillmentSort, direction: 'asc' | 'desc') => void;
    pendingRowId: string | null;
};

export default function AdminFulfillmentTable({
    adminUi,
    canAct,
    canSeeCost,
    currentDirection,
    currentSort,
    isFiltered,
    isNavigating,
    items,
    locale,
    onAction,
    onOpenRow,
    onResetFilters,
    onSortChange,
    pendingRowId,
}: AdminFulfillmentTableProps) {
    const copy = adminUi.fulfillment;
    // One clock for the whole render, so two rows a millisecond apart cannot
    // disagree about what "now" is and print ages that do not line up.
    const now = Date.now();
    const columnCount = 6 + (canSeeCost ? 1 : 0) + (canAct ? 1 : 0);

    return (
        <div aria-busy={isNavigating} className="relative">
            {isNavigating ? (
                <div
                    aria-live="polite"
                    className="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-background/90"
                >
                    <div className="flex items-center gap-2 rounded-md border border-border bg-popover px-4 py-2 text-sm font-medium text-popover-foreground shadow-md">
                        <LoaderCircle
                            aria-hidden="true"
                            className="size-4 animate-spin motion-reduce:hidden"
                        />
                        <span>{copy.loading}</span>
                    </div>
                </div>
            ) : null}

            <div
                aria-label={copy.tableLabel}
                className="hidden rounded-lg border border-border bg-card shadow-xs md:block"
                role="region"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-[13%]">{copy.columnOrder}</TableHead>
                            <TableHead className="w-[14%]">{copy.columnService}</TableHead>
                            <TableHead className="w-[13%]">{copy.columnSupplier}</TableHead>
                            <SortableHead
                                direction={currentDirection}
                                label={copy.columnWaiting}
                                onSortChange={onSortChange}
                                sort="paid_at"
                                currentSort={currentSort}
                                width="13%"
                            />
                            <TableHead className="w-[16%]">{copy.columnState}</TableHead>
                            <SortableHead
                                direction={currentDirection}
                                label={copy.columnSignal}
                                onSortChange={onSortChange}
                                sort="observed_at"
                                currentSort={currentSort}
                                width="12%"
                            />
                            {canSeeCost ? (
                                <SortableHead
                                    align="end"
                                    direction={currentDirection}
                                    label={copy.columnCost}
                                    onSortChange={onSortChange}
                                    sort="actual_cost"
                                    currentSort={currentSort}
                                    width="10%"
                                />
                            ) : null}
                            {canAct ? (
                                <TableHead className="w-[9%] text-end">
                                    <span className="sr-only">{copy.columnAction}</span>
                                </TableHead>
                            ) : null}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.length > 0 ? (
                            items.map((row) => {
                                const badge = fulfillmentBadge(row, adminUi);

                                return (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <div className="flex flex-col items-start gap-0.5">
                                                <button
                                                    className="text-sm font-semibold whitespace-nowrap text-foreground tabular-nums underline decoration-border underline-offset-4 transition-colors hover:text-primary hover:decoration-primary focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                                                    onClick={() => onOpenRow(row)}
                                                    type="button"
                                                >
                                                    <bdi>{row.orderNumber}</bdi>
                                                </button>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {row.paidAt
                                                        ? copy.paidAgo.replace(
                                                              ':age',
                                                              formatShortAge(row.paidAt, now),
                                                          )
                                                        : '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col items-start gap-0.5">
                                                <span className="flex flex-wrap gap-1">
                                                    <span className="rounded-sm bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground">
                                                        {adminUi.orders.services[row.service] ??
                                                            row.service}
                                                    </span>
                                                    {row.placement?.phase === 'challenge' ? (
                                                        <span className="rounded-sm border border-border px-1.5 py-0.5 text-xs text-muted-foreground">
                                                            {copy.phase.challenge}
                                                        </span>
                                                    ) : null}
                                                </span>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    <Progress copy={copy} row={row} />
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {row.placement ? (
                                                <div className="flex flex-col items-start gap-0.5">
                                                    <span className="rounded-sm border border-border px-1.5 py-0.5 text-xs text-muted-foreground uppercase">
                                                        {row.placement.supplier}
                                                    </span>
                                                    <span className="font-mono text-xs text-muted-foreground">
                                                        <bdi>
                                                            {row.placement.reference}
                                                            {row.placement.challengeCount > 0
                                                                ? ` · ${copy.challengeCount.replace(':count', String(row.placement.challengeCount))}`
                                                                : ''}
                                                        </bdi>
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col items-start gap-0.5">
                                                <span className="text-sm font-semibold whitespace-nowrap text-foreground tabular-nums">
                                                    <bdi>
                                                        {row.paidAt
                                                            ? formatShortAge(row.paidAt, now)
                                                            : '—'}
                                                    </bdi>
                                                </span>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {row.placement?.placedAt
                                                        ? copy.atSupplier.replace(
                                                              ':age',
                                                              formatShortAge(
                                                                  row.placement.placedAt,
                                                                  now,
                                                              ),
                                                          )
                                                        : copy.neverPlaced}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col items-start gap-1">
                                                {badge ? (
                                                    <AdminBadge
                                                        icon={badge.icon}
                                                        variant={badge.variant}
                                                    >
                                                        {badge.label}
                                                    </AdminBadge>
                                                ) : null}
                                                <span className="text-xs text-muted-foreground">
                                                    {row.job
                                                        ? (adminUi.statuses[row.job.status] ??
                                                          row.job.status)
                                                        : copy.noJob}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col items-start gap-0.5">
                                                {row.job?.observedState ? (
                                                    <span className="font-mono text-xs text-foreground">
                                                        <bdi>{row.job.observedState}</bdi>
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {signalDetail(row, adminUi, (iso) =>
                                                        formatShortAge(iso, now),
                                                    )}
                                                </span>
                                            </div>
                                        </TableCell>
                                        {canSeeCost ? (
                                            <TableCell className="text-end">
                                                {row.cost ? (
                                                    <span className="text-sm font-semibold text-foreground tabular-nums">
                                                        <bdi>
                                                            {formatAdminMoney(row.cost, locale)}
                                                        </bdi>
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        {row.job ? copy.notReported : '—'}
                                                    </span>
                                                )}
                                            </TableCell>
                                        ) : null}
                                        {canAct ? (
                                            <TableCell className="text-end">
                                                <AdminFulfillmentRowAction
                                                    copy={copy}
                                                    isPending={pendingRowId === row.id}
                                                    onAction={(action) => onAction(row, action)}
                                                    row={row}
                                                />
                                            </TableCell>
                                        ) : null}
                                    </TableRow>
                                );
                            })
                        ) : (
                            <TableRow>
                                <TableCell className="h-36 text-center" colSpan={columnCount}>
                                    <EmptyFulfillment
                                        copy={copy}
                                        isFiltered={isFiltered}
                                        onResetFilters={onResetFilters}
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            <div
                aria-label={`${copy.tableLabel} mobile`}
                className="flex flex-col gap-3 md:hidden"
                role="list"
            >
                {items.length > 0 ? (
                    items.map((row) => (
                        <MobileCard
                            adminUi={adminUi}
                            canAct={canAct}
                            canSeeCost={canSeeCost}
                            isPending={pendingRowId === row.id}
                            key={row.id}
                            locale={locale}
                            now={now}
                            onAction={onAction}
                            onOpenRow={onOpenRow}
                            row={row}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-dashed border-border px-5 py-10">
                        <EmptyFulfillment
                            copy={copy}
                            isFiltered={isFiltered}
                            onResetFilters={onResetFilters}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

function SortableHead({
    align = 'start',
    currentSort,
    direction,
    label,
    onSortChange,
    sort,
    width,
}: {
    align?: 'start' | 'end';
    currentSort: AdminFulfillmentSort;
    direction: 'asc' | 'desc';
    label: string;
    onSortChange: (sort: AdminFulfillmentSort, direction: 'asc' | 'desc') => void;
    sort: AdminFulfillmentSort;
    width: string;
}) {
    const isActive = currentSort === sort;
    const Icon = isActive ? (direction === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown;

    return (
        <TableHead
            aria-sort={isActive ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}
            className={align === 'end' ? 'text-end' : undefined}
            style={{ width }}
        >
            <button
                className="inline-flex min-h-11 items-center gap-1.5 font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                onClick={() =>
                    onSortChange(sort, isActive && direction === 'asc' ? 'desc' : 'asc')
                }
                type="button"
            >
                {label}
                <Icon
                    aria-hidden="true"
                    className={
                        isActive ? 'size-3.5 text-primary' : 'size-3.5 opacity-50'
                    }
                />
            </button>
        </TableHead>
    );
}

function Progress({
    copy,
    row,
}: {
    copy: AdminTranslations['fulfillment'];
    row: AdminFulfillmentRow;
}) {
    if (!row.progress) {
        return <>—</>;
    }

    if (row.progress.unit === 'solves') {
        return (
            <bdi>
                {copy.progressSolves
                    .replace(':done', String(row.progress.done))
                    .replace(':total', String(row.progress.total))}
            </bdi>
        );
    }

    // Coins read in thousands, the way the store prices and the suppliers
    // report them. A raw 600000 down a narrow column is unreadable.
    return (
        <bdi>{`${thousands(row.progress.done)} / ${thousands(row.progress.total)}`}</bdi>
    );
}

function thousands(value: number): string {
    return value >= 1000 ? `${Math.round(value / 1000)}K` : String(value);
}

function MobileCard({
    adminUi,
    canAct,
    canSeeCost,
    isPending,
    locale,
    now,
    onAction,
    onOpenRow,
    row,
}: {
    adminUi: AdminTranslations;
    canAct: boolean;
    canSeeCost: boolean;
    isPending: boolean;
    locale: 'ar' | 'en';
    now: number;
    onAction: (row: AdminFulfillmentRow, action: AdminFulfillmentAction) => void;
    onOpenRow: (row: AdminFulfillmentRow) => void;
    row: AdminFulfillmentRow;
}) {
    const copy = adminUi.fulfillment;
    const badge = fulfillmentBadge(row, adminUi);
    // The action gets its own row on a card rather than a corner: a 44px
    // control crammed beside a badge is a mis-tap that spends money.
    const isPurchase = row.actions.includes('send');

    return (
        <article
            className="flex flex-col gap-2 rounded-lg border border-border bg-card p-3 text-card-foreground"
            role="listitem"
        >
            <div className="flex items-center justify-between gap-2">
                <button
                    className="text-sm font-bold whitespace-nowrap text-foreground tabular-nums underline decoration-border underline-offset-4"
                    onClick={() => onOpenRow(row)}
                    type="button"
                >
                    <bdi>{row.orderNumber}</bdi>
                </button>
                {badge ? (
                    <AdminBadge className="shrink-0" icon={badge.icon} variant={badge.variant}>
                        {badge.label}
                    </AdminBadge>
                ) : null}
            </div>

            <div className="flex items-center justify-between gap-2">
                <span className="truncate text-sm font-semibold text-foreground">
                    {row.placement ? (
                        <bdi>{`${row.placement.supplier.toUpperCase()} · ${row.placement.reference}`}</bdi>
                    ) : (
                        <span className="text-muted-foreground">{copy.noJob}</span>
                    )}
                </span>
                {canSeeCost ? (
                    <strong className="shrink-0 text-sm font-bold text-foreground tabular-nums">
                        {row.cost ? (
                            <bdi>{formatAdminMoney(row.cost, locale)}</bdi>
                        ) : (
                            <span className="text-xs font-normal text-muted-foreground">
                                {row.job ? copy.notReported : '—'}
                            </span>
                        )}
                    </strong>
                ) : null}
            </div>

            <div className="flex flex-wrap items-center gap-1.5 text-xs">
                <span className="rounded-sm bg-secondary px-1.5 py-0.5 text-[11px] text-secondary-foreground">
                    {adminUi.orders.services[row.service] ?? row.service}
                </span>
                {row.job?.observedState ? (
                    <span className="font-mono text-[11px] text-muted-foreground">
                        <bdi>{row.job.observedState}</bdi>
                    </span>
                ) : null}
                <span className="ms-auto text-[11px] text-muted-foreground tabular-nums">
                    <bdi>
                        {row.paidAt ? formatShortAge(row.paidAt, now) : '—'}
                        {' · '}
                        {signalDetail(row, adminUi, (iso) => formatShortAge(iso, now))}
                    </bdi>
                </span>
            </div>

            {canAct && row.actions.length > 0 ? (
                <div
                    className={
                        isPurchase
                            ? undefined
                            : 'flex items-center justify-end gap-2'
                    }
                >
                    <AdminFulfillmentRowAction
                        copy={copy}
                        fullWidth={isPurchase}
                        isPending={isPending}
                        onAction={(action) => onAction(row, action)}
                        row={row}
                    />
                </div>
            ) : null}
        </article>
    );
}

function EmptyFulfillment({
    copy,
    isFiltered,
    onResetFilters,
}: {
    copy: AdminTranslations['fulfillment'];
    isFiltered: boolean;
    onResetFilters: () => void;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 text-center text-muted-foreground">
            <p className="text-sm font-medium">
                {isFiltered ? copy.noItemsMatching : copy.noItems}
            </p>
            {isFiltered ? (
                <Button
                    className="min-h-11"
                    onClick={onResetFilters}
                    type="button"
                    variant="outline"
                >
                    {copy.resetFilters}
                </Button>
            ) : null}
        </div>
    );
}
