'use no memo';

import { router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Edit3,
    LoaderCircle,
    Power,
    PowerOff,
    Tag,
} from 'lucide-react';
import React, { useRef, useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import AdminPasswordConfirmDialog from '@/components/admin/admin-password-confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    AdminServicePricingData,
    AdminServicePricingSchedule,
    AdminServicePricingUrls,
    AdminTranslations,
} from '@/types/admin';

export type AdminServicePricingSectionProps = {
    adminUi: AdminTranslations;
    confirmPasswordUrl?: string;
    direction: 'rtl' | 'ltr';
    locale: 'ar' | 'en';
    servicePricing: AdminServicePricingData;
    servicePricingUrls: AdminServicePricingUrls | null;
};

type ActionAlert = {
    text: string;
    type: 'success' | 'error';
};

const FUT_RANKS = [1, 2, 3, 4, 5, 6] as const;
const RIVALS_STEPS = [
    '7:6',
    '6:5',
    '5:4',
    '4:3',
    '3:2',
    '2:1',
    '1:elite',
] as const;

function getCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export default function AdminServicePricingSection({
    adminUi,
    confirmPasswordUrl,
    locale,
    servicePricing,
    servicePricingUrls,
}: AdminServicePricingSectionProps) {
    const copy = adminUi.settings;
    const pricingCopy = copy.servicePricing;

    const [alertState, setAlertState] = useState<ActionAlert | null>(null);

    // Edit price dialog state
    const [editDialogOpen, setEditDialogOpen] = useState(false);
    const [editingSchedule, setEditingSchedule] =
        useState<AdminServicePricingSchedule | null>(null);
    const [futRanks, setFutRanks] = useState<Record<string, string>>({});
    const [futUrgent, setFutUrgent] = useState<string>('');
    const [rivalsSteps, setRivalsSteps] = useState<Record<string, string>>({});
    const [editSubmitting, setEditSubmitting] = useState(false);
    const [editErrors, setEditErrors] = useState<Record<string, string>>({});

    // Status (activate / deactivate) dialog state
    const [statusDialogOpen, setStatusDialogOpen] = useState(false);
    const [statusSchedule, setStatusSchedule] =
        useState<AdminServicePricingSchedule | null>(null);
    const [statusAction, setStatusAction] = useState<'activate' | 'deactivate'>(
        'deactivate',
    );
    const [statusSubmitting, setStatusSubmitting] = useState(false);
    const [statusError, setStatusError] = useState<string | null>(null);

    // Password confirm dialog state
    const [passwordConfirmOpen, setPasswordConfirmOpen] = useState(false);
    const pendingAction = useRef<(() => Promise<void>) | null>(null);

    const dateFormatter = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const openEditDialog = (schedule: AdminServicePricingSchedule) => {
        setEditingSchedule(schedule);
        setEditErrors({});

        if (schedule.serviceType === 'fut_champions') {
            const rawRanks = (schedule.configuration.ranks ?? {}) as Record<
                string,
                number
            >;
            const ranksState: Record<string, string> = {};
            FUT_RANKS.forEach((rank) => {
                ranksState[String(rank)] = String(
                    rawRanks[String(rank)] ?? rawRanks[rank] ?? '',
                );
            });
            setFutRanks(ranksState);
            setFutUrgent(
                String(schedule.configuration.urgent_surcharge_halalah ?? ''),
            );
        } else if (schedule.serviceType === 'rivals') {
            const rawSteps = (schedule.configuration.steps ?? {}) as Record<
                string,
                number
            >;
            const stepsState: Record<string, string> = {};
            RIVALS_STEPS.forEach((step) => {
                stepsState[step] = String(rawSteps[step] ?? '');
            });
            setRivalsSteps(stepsState);
        }

        setEditDialogOpen(true);
    };

    const openStatusDialog = (
        schedule: AdminServicePricingSchedule,
        action: 'activate' | 'deactivate',
    ) => {
        setStatusSchedule(schedule);
        setStatusAction(action);
        setStatusError(null);
        setStatusDialogOpen(true);
    };

    const executePriceUpdate = async () => {
        if (!editingSchedule || !servicePricingUrls) {
            return;
        }

        setEditSubmitting(true);
        setEditErrors({});

        const url = servicePricingUrls.updateUrlTemplate.replace(
            '__SERVICE__',
            editingSchedule.serviceType,
        );

        let configuration: Record<string, unknown> = {};

        if (editingSchedule.serviceType === 'fut_champions') {
            const parsedRanks: Record<string, number> = {};

            for (const rank of FUT_RANKS) {
                const val = parseInt(futRanks[String(rank)] || '0', 10);
                parsedRanks[String(rank)] = val;
            }

            configuration = {
                ranks: parsedRanks,
                urgent_surcharge_halalah: parseInt(futUrgent || '0', 10),
            };
        } else if (editingSchedule.serviceType === 'rivals') {
            const parsedSteps: Record<string, number> = {};

            for (const step of RIVALS_STEPS) {
                const val = parseInt(rivalsSteps[step] || '0', 10);
                parsedSteps[step] = val;
            }

            configuration = {
                steps: parsedSteps,
            };
        }

        const payload = {
            configuration,
            expected_version: editingSchedule.version,
        };

        try {
            const response = await fetch(url, {
                body: JSON.stringify(payload),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                method: 'POST',
            });

            if (response.status === 423) {
                setEditDialogOpen(false);
                pendingAction.current = executePriceUpdate;
                setPasswordConfirmOpen(true);

                return;
            }

            if (response.status === 409) {
                setEditDialogOpen(false);
                setAlertState({
                    text: pricingCopy.messages.conflictError,
                    type: 'error',
                });
                router.reload({ only: ['servicePricing'] });

                return;
            }

            if (response.status === 403) {
                setEditErrors({
                    _general: copy.messages.forbiddenError,
                });

                return;
            }

            if (response.status === 422) {
                const data = (await response.json().catch(() => null)) as {
                    errors?: Record<string, string[] | string>;
                    message?: string;
                } | null;

                const errorsMap: Record<string, string> = {};

                if (data?.errors) {
                    for (const [key, val] of Object.entries(data.errors)) {
                        errorsMap[key] = Array.isArray(val) ? val[0] : val;
                    }
                }

                if (Object.keys(errorsMap).length === 0) {
                    errorsMap._general =
                        data?.message || pricingCopy.messages.validationError;
                }

                setEditErrors(errorsMap);

                return;
            }

            if (!response.ok) {
                const data = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                setEditErrors({
                    _general: data?.message || copy.messages.genericError,
                });

                return;
            }

            setEditDialogOpen(false);
            setAlertState({
                text: pricingCopy.messages.pricingUpdated,
                type: 'success',
            });
            router.reload({ only: ['servicePricing'] });
        } catch {
            setEditErrors({
                _general: copy.messages.networkError,
            });
        } finally {
            setEditSubmitting(false);
        }
    };

    const executeStatusChange = async () => {
        if (!statusSchedule || !servicePricingUrls) {
            return;
        }

        setStatusSubmitting(true);
        setStatusError(null);

        const url = servicePricingUrls.statusUrlTemplate.replace(
            '__SERVICE__',
            statusSchedule.serviceType,
        );

        const payload = {
            action: statusAction,
            expected_active: statusSchedule.isActive,
        };

        try {
            const response = await fetch(url, {
                body: JSON.stringify(payload),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                method: 'POST',
            });

            if (response.status === 423) {
                setStatusDialogOpen(false);
                pendingAction.current = executeStatusChange;
                setPasswordConfirmOpen(true);

                return;
            }

            if (response.status === 409) {
                setStatusDialogOpen(false);
                setAlertState({
                    text: pricingCopy.messages.conflictError,
                    type: 'error',
                });
                router.reload({ only: ['servicePricing'] });

                return;
            }

            if (response.status === 403) {
                setStatusError(copy.messages.forbiddenError);

                return;
            }

            if (!response.ok) {
                const data = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                setStatusError(data?.message || copy.messages.genericError);

                return;
            }

            setStatusDialogOpen(false);
            setAlertState({
                text: pricingCopy.messages.statusUpdated,
                type: 'success',
            });
            router.reload({ only: ['servicePricing'] });
        } catch {
            setStatusError(copy.messages.networkError);
        } finally {
            setStatusSubmitting(false);
        }
    };

    const getServiceName = (serviceType: string): string => {
        if (serviceType === 'fut_champions') {
            return pricingCopy.futChampions;
        }

        if (serviceType === 'rivals') {
            return pricingCopy.rivals;
        }

        return serviceType;
    };

    return (
        <section
            aria-labelledby="admin-service-pricing-title"
            className="rounded-lg border border-border bg-card p-6 shadow-xs"
            id="service-pricing"
        >
            <div className="flex flex-col gap-6">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Tag
                                aria-hidden="true"
                                className="size-5 text-primary"
                            />
                            <h2
                                className="font-display text-xl font-bold tracking-tight text-foreground"
                                id="admin-service-pricing-title"
                            >
                                {copy.servicePricingSection}
                            </h2>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {copy.servicePricingDescription}
                        </p>
                    </div>
                </header>

                {alertState ? (
                    <div
                        className={`flex items-center justify-between gap-4 rounded-md border p-4 text-sm font-medium ${
                            alertState.type === 'success'
                                ? 'border-status-success/30 bg-status-success/10 text-status-success'
                                : 'border-destructive/50 bg-destructive/10 text-destructive'
                        }`}
                        role="alert"
                    >
                        <div className="flex items-center gap-2">
                            {alertState.type === 'success' ? (
                                <CheckCircle2
                                    aria-hidden="true"
                                    className="size-5 shrink-0"
                                />
                            ) : (
                                <AlertCircle
                                    aria-hidden="true"
                                    className="size-5 shrink-0"
                                />
                            )}
                            <span>{alertState.text}</span>
                        </div>
                        <button
                            className="min-h-11 cursor-pointer touch-manipulation rounded-md px-3 text-xs font-semibold hover:opacity-80 focus-visible:outline-2 focus-visible:outline-ring"
                            onClick={() => setAlertState(null)}
                            type="button"
                        >
                            {adminUi.common.cancel}
                        </button>
                    </div>
                ) : null}

                {/* Service Cards */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {servicePricing.schedules.map((schedule) => {
                        const serviceName = getServiceName(
                            schedule.serviceType,
                        );
                        const isFut = schedule.serviceType === 'fut_champions';
                        const rawRanks = (schedule.configuration.ranks ??
                            {}) as Record<string, number>;
                        const urgentPrice = (schedule.configuration
                            .urgent_surcharge_halalah ?? 0) as number;
                        const rawSteps = (schedule.configuration.steps ??
                            {}) as Record<string, number>;

                        return (
                            <article
                                className="flex flex-col justify-between rounded-lg border border-border bg-card/60 p-5 shadow-xs"
                                key={schedule.serviceType}
                            >
                                <div className="flex flex-col gap-4">
                                    <header className="flex flex-wrap items-start justify-between gap-3 border-b border-border/80 pb-4">
                                        <div className="space-y-1.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="font-display text-lg font-bold text-foreground">
                                                    {serviceName}
                                                </h3>
                                                <AdminBadge
                                                    variant={
                                                        schedule.isActive
                                                            ? 'success'
                                                            : 'danger'
                                                    }
                                                >
                                                    {schedule.isActive
                                                        ? pricingCopy.active
                                                        : pricingCopy.inactive}
                                                </AdminBadge>
                                                <AdminBadge variant="neutral">
                                                    {pricingCopy.version.replace(
                                                        ':version',
                                                        String(
                                                            schedule.version,
                                                        ),
                                                    )}
                                                </AdminBadge>
                                            </div>
                                            {schedule.updatedAt ? (
                                                <p className="text-xs text-muted-foreground tabular-nums">
                                                    {pricingCopy.lastUpdated.replace(
                                                        ':date',
                                                        dateFormatter.format(
                                                            new Date(
                                                                schedule.updatedAt,
                                                            ),
                                                        ),
                                                    )}
                                                </p>
                                            ) : null}
                                        </div>

                                        {servicePricingUrls ? (
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Button
                                                    className="min-h-11 touch-manipulation gap-1.5"
                                                    onClick={() =>
                                                        openEditDialog(schedule)
                                                    }
                                                    type="button"
                                                    variant="outline"
                                                >
                                                    <Edit3
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                    <span>
                                                        {pricingCopy.editPrices}
                                                    </span>
                                                </Button>
                                                <Button
                                                    className="min-h-11 touch-manipulation gap-1.5"
                                                    onClick={() =>
                                                        openStatusDialog(
                                                            schedule,
                                                            schedule.isActive
                                                                ? 'deactivate'
                                                                : 'activate',
                                                        )
                                                    }
                                                    type="button"
                                                    variant={
                                                        schedule.isActive
                                                            ? 'destructive'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {schedule.isActive ? (
                                                        <>
                                                            <PowerOff
                                                                aria-hidden="true"
                                                                className="size-4"
                                                            />
                                                            <span>
                                                                {
                                                                    pricingCopy
                                                                        .actions
                                                                        .deactivate
                                                                }
                                                            </span>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <Power
                                                                aria-hidden="true"
                                                                className="size-4"
                                                            />
                                                            <span>
                                                                {
                                                                    pricingCopy
                                                                        .actions
                                                                        .reactivate
                                                                }
                                                            </span>
                                                        </>
                                                    )}
                                                </Button>
                                            </div>
                                        ) : null}
                                    </header>

                                    {/* Price Schedule Table */}
                                    <div
                                        className="overflow-x-auto rounded-md border border-border/70"
                                        data-testid={`pricing-table-${schedule.serviceType}`}
                                    >
                                        <Table>
                                            <TableHeader>
                                                <TableRow className="bg-muted/40">
                                                    <TableHead>
                                                        {isFut
                                                            ? pricingCopy.tableRank
                                                            : pricingCopy.tableStep}
                                                    </TableHead>
                                                    <TableHead className="text-end">
                                                        {pricingCopy.tablePrice}
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {isFut ? (
                                                    <>
                                                        {FUT_RANKS.map(
                                                            (rank) => {
                                                                const priceHalalah =
                                                                    rawRanks[
                                                                        String(
                                                                            rank,
                                                                        )
                                                                    ] ??
                                                                    rawRanks[
                                                                        rank
                                                                    ] ??
                                                                    0;

                                                                return (
                                                                    <TableRow
                                                                        key={
                                                                            rank
                                                                        }
                                                                    >
                                                                        <TableCell className="font-medium">
                                                                            {pricingCopy
                                                                                .ranks[
                                                                                String(
                                                                                    rank,
                                                                                )
                                                                            ] ??
                                                                                `Rank ${rank}`}
                                                                        </TableCell>
                                                                        <TableCell className="text-end font-semibold text-foreground tabular-nums">
                                                                            {formatAdminMoney(
                                                                                {
                                                                                    amountMinor:
                                                                                        String(
                                                                                            priceHalalah,
                                                                                        ),
                                                                                    currency:
                                                                                        'SAR',
                                                                                },
                                                                                locale,
                                                                            )}
                                                                        </TableCell>
                                                                    </TableRow>
                                                                );
                                                            },
                                                        )}
                                                        <TableRow className="bg-accent/20">
                                                            <TableCell className="font-medium text-primary">
                                                                {
                                                                    pricingCopy.urgentSurcharge
                                                                }
                                                            </TableCell>
                                                            <TableCell className="text-end font-semibold text-primary tabular-nums">
                                                                {formatAdminMoney(
                                                                    {
                                                                        amountMinor:
                                                                            String(
                                                                                urgentPrice,
                                                                            ),
                                                                        currency:
                                                                            'SAR',
                                                                    },
                                                                    locale,
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                    </>
                                                ) : (
                                                    RIVALS_STEPS.map((step) => {
                                                        const priceHalalah =
                                                            rawSteps[step] ?? 0;

                                                        return (
                                                            <TableRow
                                                                key={step}
                                                            >
                                                                <TableCell className="font-medium">
                                                                    {pricingCopy
                                                                        .steps[
                                                                        step
                                                                    ] ?? step}
                                                                </TableCell>
                                                                <TableCell className="text-end font-semibold text-foreground tabular-nums">
                                                                    {formatAdminMoney(
                                                                        {
                                                                            amountMinor:
                                                                                String(
                                                                                    priceHalalah,
                                                                                ),
                                                                            currency:
                                                                                'SAR',
                                                                        },
                                                                        locale,
                                                                    )}
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    })
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            </div>

            {/* Edit Prices Dialog */}
            <Dialog onOpenChange={setEditDialogOpen} open={editDialogOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingSchedule
                                ? pricingCopy.editDialog.title.replace(
                                      ':service',
                                      getServiceName(
                                          editingSchedule.serviceType,
                                      ),
                                  )
                                : ''}
                        </DialogTitle>
                        <DialogDescription>
                            {editingSchedule
                                ? pricingCopy.editDialog.description.replace(
                                      ':service',
                                      getServiceName(
                                          editingSchedule.serviceType,
                                      ),
                                  )
                                : ''}
                        </DialogDescription>
                    </DialogHeader>

                    {editErrors._general ? (
                        <p
                            className="text-xs font-medium text-destructive"
                            role="alert"
                        >
                            {editErrors._general}
                        </p>
                    ) : null}

                    {editingSchedule ? (
                        <div className="flex flex-col gap-4 py-2">
                            {editingSchedule.serviceType === 'fut_champions' ? (
                                <>
                                    <div className="flex flex-col gap-3">
                                        {FUT_RANKS.map((rank) => {
                                            const fieldKey = `configuration.ranks.${rank}`;
                                            const error =
                                                editErrors[fieldKey] ||
                                                editErrors[
                                                    'configuration.ranks'
                                                ] ||
                                                editErrors.configuration;
                                            const currentVal =
                                                futRanks[String(rank)] || '';

                                            return (
                                                <div
                                                    className="flex flex-col gap-1.5"
                                                    key={rank}
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <Label
                                                            className="text-xs font-semibold"
                                                            htmlFor={`input-fut-rank-${rank}`}
                                                        >
                                                            {pricingCopy.ranks[
                                                                String(rank)
                                                            ] ??
                                                                `Rank ${rank}`}{' '}
                                                            (
                                                            {
                                                                pricingCopy.tableHalalah
                                                            }
                                                            )
                                                        </Label>
                                                        <span className="text-xs text-muted-foreground tabular-nums">
                                                            {formatAdminMoney(
                                                                {
                                                                    amountMinor:
                                                                        currentVal ||
                                                                        '0',
                                                                    currency:
                                                                        'SAR',
                                                                },
                                                                locale,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <Input
                                                        aria-describedby={
                                                            error
                                                                ? `input-fut-rank-${rank}-error`
                                                                : undefined
                                                        }
                                                        aria-invalid={!!error}
                                                        className="min-h-11 touch-manipulation text-xs tabular-nums"
                                                        disabled={
                                                            editSubmitting
                                                        }
                                                        id={`input-fut-rank-${rank}`}
                                                        inputMode="numeric"
                                                        min={1}
                                                        onChange={(e) =>
                                                            setFutRanks(
                                                                (prev) => ({
                                                                    ...prev,
                                                                    [String(
                                                                        rank,
                                                                    )]:
                                                                        e.target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                        required
                                                        step={1}
                                                        type="number"
                                                        value={currentVal}
                                                    />
                                                    {error ? (
                                                        <p
                                                            className="text-xs font-medium text-destructive"
                                                            id={`input-fut-rank-${rank}-error`}
                                                            role="alert"
                                                        >
                                                            {error}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            );
                                        })}
                                    </div>

                                    <div className="flex flex-col gap-1.5 border-t border-border pt-3">
                                        <div className="flex items-center justify-between">
                                            <Label
                                                className="text-xs font-semibold text-primary"
                                                htmlFor="input-fut-urgent"
                                            >
                                                {pricingCopy.urgentSurcharge} (
                                                {pricingCopy.tableHalalah})
                                            </Label>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {formatAdminMoney(
                                                    {
                                                        amountMinor:
                                                            futUrgent || '0',
                                                        currency: 'SAR',
                                                    },
                                                    locale,
                                                )}
                                            </span>
                                        </div>
                                        <Input
                                            aria-describedby={
                                                editErrors[
                                                    'configuration.urgent_surcharge_halalah'
                                                ]
                                                    ? 'input-fut-urgent-error'
                                                    : undefined
                                            }
                                            aria-invalid={
                                                !!editErrors[
                                                    'configuration.urgent_surcharge_halalah'
                                                ]
                                            }
                                            className="min-h-11 touch-manipulation text-xs tabular-nums"
                                            disabled={editSubmitting}
                                            id="input-fut-urgent"
                                            inputMode="numeric"
                                            min={1}
                                            onChange={(e) =>
                                                setFutUrgent(e.target.value)
                                            }
                                            required
                                            step={1}
                                            type="number"
                                            value={futUrgent}
                                        />
                                        {editErrors[
                                            'configuration.urgent_surcharge_halalah'
                                        ] ? (
                                            <p
                                                className="text-xs font-medium text-destructive"
                                                id="input-fut-urgent-error"
                                                role="alert"
                                            >
                                                {
                                                    editErrors[
                                                        'configuration.urgent_surcharge_halalah'
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                </>
                            ) : (
                                <div className="flex flex-col gap-3">
                                    {RIVALS_STEPS.map((step) => {
                                        const fieldKey = `configuration.steps.${step}`;
                                        const error =
                                            editErrors[fieldKey] ||
                                            editErrors['configuration.steps'] ||
                                            editErrors.configuration;
                                        const currentVal =
                                            rivalsSteps[step] || '';

                                        return (
                                            <div
                                                className="flex flex-col gap-1.5"
                                                key={step}
                                            >
                                                <div className="flex items-center justify-between">
                                                    <Label
                                                        className="text-xs font-semibold"
                                                        htmlFor={`input-rivals-step-${step}`}
                                                    >
                                                        {pricingCopy.steps[
                                                            step
                                                        ] ?? step}{' '}
                                                        (
                                                        {
                                                            pricingCopy.tableHalalah
                                                        }
                                                        )
                                                    </Label>
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        {formatAdminMoney(
                                                            {
                                                                amountMinor:
                                                                    currentVal ||
                                                                    '0',
                                                                currency: 'SAR',
                                                            },
                                                            locale,
                                                        )}
                                                    </span>
                                                </div>
                                                <Input
                                                    aria-describedby={
                                                        error
                                                            ? `input-rivals-step-${step}-error`
                                                            : undefined
                                                    }
                                                    aria-invalid={!!error}
                                                    className="min-h-11 touch-manipulation text-xs tabular-nums"
                                                    disabled={editSubmitting}
                                                    id={`input-rivals-step-${step}`}
                                                    inputMode="numeric"
                                                    min={1}
                                                    onChange={(e) =>
                                                        setRivalsSteps(
                                                            (prev) => ({
                                                                ...prev,
                                                                [step]: e.target
                                                                    .value,
                                                            }),
                                                        )
                                                    }
                                                    required
                                                    step={1}
                                                    type="number"
                                                    value={currentVal}
                                                />
                                                {error ? (
                                                    <p
                                                        className="text-xs font-medium text-destructive"
                                                        id={`input-rivals-step-${step}-error`}
                                                        role="alert"
                                                    >
                                                        {error}
                                                    </p>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    ) : null}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                className="min-h-11 touch-manipulation"
                                disabled={editSubmitting}
                                type="button"
                                variant="outline"
                            >
                                {pricingCopy.editDialog.cancel}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11 touch-manipulation gap-2"
                            disabled={editSubmitting}
                            onClick={() => void executePriceUpdate()}
                            type="button"
                            variant="default"
                        >
                            {editSubmitting ? (
                                <>
                                    <LoaderCircle
                                        aria-hidden="true"
                                        className="size-4 animate-spin"
                                    />
                                    <span>{pricingCopy.editingPrices}</span>
                                </>
                            ) : (
                                <span>{pricingCopy.editDialog.confirm}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Status (Activate / Deactivate) Confirmation Dialog */}
            <Dialog onOpenChange={setStatusDialogOpen} open={statusDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {statusSchedule
                                ? statusAction === 'deactivate'
                                    ? pricingCopy.deactivateDialog.title.replace(
                                          ':service',
                                          getServiceName(
                                              statusSchedule.serviceType,
                                          ),
                                      )
                                    : pricingCopy.activateDialog.title.replace(
                                          ':service',
                                          getServiceName(
                                              statusSchedule.serviceType,
                                          ),
                                      )
                                : ''}
                        </DialogTitle>
                        <DialogDescription>
                            {statusSchedule
                                ? statusAction === 'deactivate'
                                    ? pricingCopy.deactivateDialog.description.replace(
                                          ':service',
                                          getServiceName(
                                              statusSchedule.serviceType,
                                          ),
                                      )
                                    : pricingCopy.activateDialog.description.replace(
                                          ':service',
                                          getServiceName(
                                              statusSchedule.serviceType,
                                          ),
                                      )
                                : ''}
                        </DialogDescription>
                    </DialogHeader>

                    {statusError ? (
                        <p
                            className="text-xs font-medium text-destructive"
                            role="alert"
                        >
                            {statusError}
                        </p>
                    ) : null}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                className="min-h-11 touch-manipulation"
                                disabled={statusSubmitting}
                                type="button"
                                variant="outline"
                            >
                                {statusAction === 'deactivate'
                                    ? pricingCopy.deactivateDialog.cancel
                                    : pricingCopy.activateDialog.cancel}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11 touch-manipulation gap-2"
                            disabled={statusSubmitting}
                            onClick={() => void executeStatusChange()}
                            type="button"
                            variant={
                                statusAction === 'deactivate'
                                    ? 'destructive'
                                    : 'default'
                            }
                        >
                            {statusSubmitting ? (
                                <>
                                    <LoaderCircle
                                        aria-hidden="true"
                                        className="size-4 animate-spin"
                                    />
                                    <span>
                                        {statusAction === 'deactivate'
                                            ? pricingCopy.actions.deactivating
                                            : pricingCopy.actions.reactivating}
                                    </span>
                                </>
                            ) : (
                                <span>
                                    {statusAction === 'deactivate'
                                        ? pricingCopy.deactivateDialog.confirm
                                        : pricingCopy.activateDialog.confirm}
                                </span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Replay password confirmation dialog */}
            <AdminPasswordConfirmDialog
                confirmPasswordUrl={confirmPasswordUrl}
                description="For security, please enter your password to confirm this pricing schedule change."
                onConfirmed={() => {
                    if (pendingAction.current) {
                        const action = pendingAction.current;
                        pendingAction.current = null;
                        void action();
                    }
                }}
                onOpenChange={setPasswordConfirmOpen}
                open={passwordConfirmOpen}
                title="Confirm your password"
            />
        </section>
    );
}
