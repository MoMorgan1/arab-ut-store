import { Link } from '@inertiajs/react';
import { CircleAlert, ExternalLink, ShoppingBag, Truck } from 'lucide-react';
import type { ReactNode } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import AdminFulfillmentRowAction from '@/components/admin/fulfillment/admin-fulfillment-row-action';
import {
    alarmHint,
    formatShortAge,
    mostUrgentAlarm,
} from '@/components/admin/fulfillment/admin-fulfillment-state';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { DATE_LOCALE } from '@/lib/date-locale';
import type {
    AdminFulfillmentAction,
    AdminFulfillmentRow,
    AdminTranslations,
} from '@/types/admin';

export type AdminFulfillmentDetailSheetProps = {
    adminUi: AdminTranslations;
    canAct: boolean;
    canSeeCost: boolean;
    isPending: boolean;
    locale: 'ar' | 'en';
    onAction: (row: AdminFulfillmentRow, action: AdminFulfillmentAction) => void;
    onClose: () => void;
    orderUrlTemplate: string;
    row: AdminFulfillmentRow;
};

/**
 * One row, opened.
 *
 * The established admin drawer rather than an expanding table row: the admin
 * has no expandable-row pattern anywhere, and a diagnostics panel is not the
 * place to invent one.
 *
 * On an unplaced item most of this is honest emptiness, and that is the
 * finding rather than a gap - there is no job row, so there is nothing to
 * read, nothing to poll and nothing to resume. Every supplier field shows an
 * em dash and the note says the fields are absent rather than unknown.
 */
export default function AdminFulfillmentDetailSheet({
    adminUi,
    canAct,
    canSeeCost,
    isPending,
    locale,
    onAction,
    onClose,
    orderUrlTemplate,
    row,
}: AdminFulfillmentDetailSheetProps) {
    const copy = adminUi.fulfillment;
    const now = Date.now();
    const alarm = mostUrgentAlarm(row.alarms);
    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });
    const absolute = (iso: string | null): string =>
        iso ? dateFormatter.format(new Date(iso)) : '—';

    return (
        <Sheet
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            open
        >
            <SheetContent
                className="flex max-h-screen w-full flex-col overflow-y-auto motion-reduce:animate-none motion-reduce:transition-none sm:max-w-xl"
                closeLabel={copy.detail.close}
                side="right"
            >
                <SheetHeader className="border-b border-border pb-4">
                    <SheetTitle className="text-lg font-bold text-foreground tabular-nums">
                        <bdi>{row.orderNumber}</bdi>
                    </SheetTitle>
                    <SheetDescription className="text-xs leading-relaxed text-muted-foreground">
                        {[
                            adminUi.orders.services[row.service] ?? row.service,
                            adminUi.orders.platforms[row.platform] ?? row.platform,
                            row.paidAt
                                ? copy.paidAgo.replace(':age', formatShortAge(row.paidAt, now))
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-1 flex-col gap-6 py-4">
                    {alarm ? (
                        <section className="flex flex-col gap-3 rounded-md border border-status-danger/30 bg-status-danger/5 p-3 shadow-xs">
                            <header className="flex items-center gap-2 border-b border-border/60 pb-2">
                                <CircleAlert
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-status-danger"
                                />
                                <h3 className="text-xs font-bold text-foreground">
                                    {copy.alarm[alarm.kind] ?? alarm.kind}
                                </h3>
                                {alarm.kind === 'stalled' && alarm.circuitOpen ? (
                                    <AdminBadge className="ms-auto" variant="info">
                                        {copy.circuitOpen}
                                    </AdminBadge>
                                ) : null}
                            </header>
                            <Fields>
                                <Field label={copy.detail.alarmRaised}>
                                    {alarm.raisedAt
                                        ? formatShortAge(alarm.raisedAt, now)
                                        : '—'}
                                </Field>
                                <Field label={copy.detail.alarmNotified}>
                                    {alarm.notifiedAt
                                        ? formatShortAge(alarm.notifiedAt, now)
                                        : copy.detail.alarmNotQueued}
                                </Field>
                                <Field label={copy.detail.alarmResolved}>
                                    {copy.detail.alarmStillOpen}
                                </Field>
                            </Fields>
                            {alarmHint(alarm, adminUi) ? (
                                <p className="m-0 text-xs leading-relaxed text-muted-foreground">
                                    {alarmHint(alarm, adminUi)}
                                </p>
                            ) : null}
                        </section>
                    ) : null}

                    {row.blocker ? (
                        <section className="flex flex-col gap-3 rounded-md border border-primary/20 bg-background p-3 shadow-xs">
                            <header className="flex items-center gap-2 border-b border-border/60 pb-2">
                                <CircleAlert
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-primary"
                                />
                                <h3 className="text-xs font-bold text-foreground">
                                    {copy.detail.blockerHeading}
                                </h3>
                            </header>
                            <Fields>
                                <Field label={copy.detail.blockerCode}>
                                    {/* The publisher's own word, passed
                                        through rather than translated: it is
                                        the spelling in the log, in
                                        `integration_events.last_error` and in
                                        the alarm mail, and an operator
                                        searching wants one spelling. */}
                                    <span className="font-mono">
                                        <bdi>{row.blocker.reason}</bdi>
                                    </span>
                                </Field>
                            </Fields>
                            <p className="m-0 text-xs leading-relaxed text-muted-foreground">
                                {row.blocker.blocks
                                    ? copy.detail.blockerBlocks
                                    : copy.detail.blockerClears}
                            </p>
                        </section>
                    ) : null}

                    <section className="flex flex-col gap-3 rounded-md border border-primary/20 bg-background p-3 shadow-xs">
                        <header className="flex items-center gap-2 border-b border-border/60 pb-2">
                            <ShoppingBag aria-hidden="true" className="size-4 shrink-0 text-primary" />
                            <h3 className="text-xs font-bold text-foreground">
                                {copy.detail.itemHeading}
                            </h3>
                        </header>
                        <Fields>
                            <Field label={copy.detail.itemOrder}>
                                <bdi>{row.orderNumber}</bdi>
                            </Field>
                            <Field label={copy.detail.itemService}>
                                {adminUi.orders.services[row.service] ?? row.service}
                            </Field>
                            <Field label={copy.detail.itemPlatform}>
                                {adminUi.orders.platforms[row.platform] ?? row.platform}
                            </Field>
                            <Field label={copy.detail.itemStatus}>
                                {adminUi.statuses[row.itemStatus] ?? row.itemStatus}
                            </Field>
                            <Field label={copy.detail.itemPaidAt}>
                                <bdi>{absolute(row.paidAt)}</bdi>
                            </Field>
                        </Fields>
                    </section>

                    <section className="flex flex-col gap-3 rounded-md border border-primary/20 bg-background p-3 shadow-xs">
                        <header className="flex items-center gap-2 border-b border-border/60 pb-2">
                            <Truck aria-hidden="true" className="size-4 shrink-0 text-primary" />
                            <h3 className="text-xs font-bold text-foreground">
                                {copy.detail.supplierHeading}
                            </h3>
                        </header>
                        <Fields>
                            <Field label={copy.detail.supplierName}>
                                {row.placement ? row.placement.supplier.toUpperCase() : '—'}
                            </Field>
                            <Field label={copy.detail.supplierReference}>
                                {row.placement ? (
                                    <span className="font-mono">
                                        <bdi>{row.placement.reference}</bdi>
                                    </span>
                                ) : (
                                    '—'
                                )}
                            </Field>
                            <Field label={copy.detail.supplierPhase}>
                                {row.placement
                                    ? (copy.phase[row.placement.phase] ?? row.placement.phase)
                                    : '—'}
                            </Field>
                            <Field label={copy.detail.supplierPlacedAt}>
                                <bdi>{absolute(row.placement?.placedAt ?? null)}</bdi>
                            </Field>
                            <Field label={copy.detail.supplierObservedAt}>
                                <bdi>{absolute(row.job?.observedAt ?? null)}</bdi>
                            </Field>
                            <Field label={copy.detail.supplierObservedState}>
                                {row.job?.observedState ? (
                                    <span className="font-mono">
                                        <bdi>{row.job.observedState}</bdi>
                                    </span>
                                ) : (
                                    '—'
                                )}
                            </Field>
                            {canSeeCost ? (
                                <Field label={copy.detail.supplierCost}>
                                    {row.cost ? (
                                        <bdi>{formatAdminMoney(row.cost, locale)}</bdi>
                                    ) : row.job ? (
                                        copy.notReported
                                    ) : (
                                        '—'
                                    )}
                                </Field>
                            ) : null}
                        </Fields>
                        {row.job === null ? (
                            <p className="m-0 text-xs leading-relaxed text-muted-foreground">
                                {copy.detail.noJobNote}
                            </p>
                        ) : null}
                    </section>
                </div>

                <SheetFooter className="flex flex-row items-center justify-end gap-2 border-t border-border pt-4">
                    <Button asChild className="min-h-11 gap-2" variant="outline">
                        <Link href={orderUrlTemplate.replace('__ID__', row.orderNumber)}>
                            <ExternalLink aria-hidden="true" className="size-4" />
                            <span>{copy.openOrder}</span>
                        </Link>
                    </Button>
                    {canAct && row.actions.length > 0 ? (
                        <AdminFulfillmentRowAction
                            copy={copy}
                            fullWidth
                            isPending={isPending}
                            onAction={(action) => onAction(row, action)}
                            row={row}
                        />
                    ) : null}
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function Fields({ children }: { children: ReactNode }) {
    return <dl className="m-0 flex flex-col divide-y divide-border/40 text-xs">{children}</dl>;
}

function Field({ children, label }: { children: ReactNode; label: string }) {
    return (
        <div className="flex min-h-11 flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
            <dt className="font-semibold text-muted-foreground">{label}</dt>
            <dd className="m-0 text-end text-foreground tabular-nums">{children}</dd>
        </div>
    );
}
