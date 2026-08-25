'use no memo';

import { router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Edit3,
    LoaderCircle,
    Plus,
    Power,
    PowerOff,
    Tag,
    Trash2,
} from 'lucide-react';
import React, { useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import {
    formatAdminMoney,
    formatHalalahToSar,
    parseSarToHalalah,
} from '@/components/admin/admin-money';
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
import { DATE_LOCALE } from '@/lib/date-locale';
import { formatInteger } from '@/lib/money';
import type {
    AdminServicePricingData,
    AdminServicePricingSchedule,
    AdminServicePricingUrls,
    AdminTranslations,
} from '@/types/admin';

export type AdminServicePricingSectionProps = {
    adminUi: AdminTranslations;
    direction: 'rtl' | 'ltr';
    locale: 'ar' | 'en';
    servicePricing: AdminServicePricingData;
    servicePricingUrls: AdminServicePricingUrls | null;
};

type ActionAlert = {
    text: string;
    type: 'success' | 'error';
};

type CoinsTierDraft = { upTo: string; step: string };

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
    locale,
    servicePricing,
    servicePricingUrls,
}: AdminServicePricingSectionProps) {
    const copy = adminUi.settings;
    const pricingCopy = copy.servicePricing;
    const coinsCopy = pricingCopy.coinsQuantities;

    const [alertState, setAlertState] = useState<ActionAlert | null>(null);

    // Edit price dialog state
    const [editDialogOpen, setEditDialogOpen] = useState(false);
    const [editingSchedule, setEditingSchedule] =
        useState<AdminServicePricingSchedule | null>(null);
    const [futRanks, setFutRanks] = useState<Record<string, string>>({});
    const [futUrgent, setFutUrgent] = useState<string>('');
    const [rivalsSteps, setRivalsSteps] = useState<Record<string, string>>({});
    const [coinsMinimum, setCoinsMinimum] = useState<string>('');
    const [coinsUnitDraft, setCoinsUnitDraft] = useState<string>('');
    const [coinsTiers, setCoinsTiers] = useState<CoinsTierDraft[]>([]);
    const [coinsPresets, setCoinsPresets] = useState<string>('');
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

    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const openEditDialog = (schedule: AdminServicePricingSchedule) => {
        setEditingSchedule(schedule);
        setEditErrors({});
        setAlertState(null);

        if (schedule.serviceType === 'fut_champions') {
            const rawRanks = (schedule.configuration.ranks ?? {}) as Record<
                string,
                number
            >;
            const ranksState: Record<string, string> = {};
            FUT_RANKS.forEach((rank) => {
                const val = rawRanks[String(rank)] ?? rawRanks[rank];
                ranksState[String(rank)] =
                    val !== undefined ? formatHalalahToSar(val) : '';
            });
            setFutRanks(ranksState);
            setFutUrgent(
                typeof schedule.configuration.urgent_surcharge_halalah ===
                    'number' ||
                    typeof schedule.configuration.urgent_surcharge_halalah ===
                        'string'
                    ? formatHalalahToSar(
                          schedule.configuration.urgent_surcharge_halalah,
                      )
                    : '',
            );
        } else if (schedule.serviceType === 'rivals') {
            const rawSteps = (schedule.configuration.steps ?? {}) as Record<
                string,
                number
            >;
            const stepsState: Record<string, string> = {};
            RIVALS_STEPS.forEach((step) => {
                const val = rawSteps[step];
                stepsState[step] =
                    val !== undefined ? formatHalalahToSar(val) : '';
            });
            setRivalsSteps(stepsState);
        } else if (schedule.serviceType === 'coins') {
            const rawTiers = (schedule.configuration.tiers ?? []) as Array<{
                upTo?: number;
                step?: number;
            }>;
            const rawPresets = (schedule.configuration.presets ??
                []) as number[];

            setCoinsMinimum(
                typeof schedule.configuration.minimum === 'number'
                    ? String(schedule.configuration.minimum)
                    : '',
            );
            setCoinsUnitDraft(
                typeof schedule.configuration.roundingUnit === 'number'
                    ? String(schedule.configuration.roundingUnit)
                    : '',
            );
            setCoinsTiers(
                rawTiers.map((tier) => ({
                    step: tier.step !== undefined ? String(tier.step) : '',
                    upTo: tier.upTo !== undefined ? String(tier.upTo) : '',
                })),
            );
            setCoinsPresets(rawPresets.join(', '));
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
                parsedRanks[String(rank)] = parseSarToHalalah(
                    futRanks[String(rank)] || '0',
                );
            }

            configuration = {
                ranks: parsedRanks,
                urgent_surcharge_halalah: parseSarToHalalah(futUrgent || '0'),
            };
        } else if (editingSchedule.serviceType === 'rivals') {
            const parsedSteps: Record<string, number> = {};

            for (const step of RIVALS_STEPS) {
                parsedSteps[step] = parseSarToHalalah(rivalsSteps[step] || '0');
            }

            configuration = {
                steps: parsedSteps,
            };
        } else if (editingSchedule.serviceType === 'coins') {
            configuration = {
                minimum: Number(coinsMinimum) || 0,
                roundingUnit: Number(coinsUnitDraft) || 0,
                presets: coinsPresets
                    .split(',')
                    .map((preset) => preset.trim())
                    .filter((preset) => preset !== '')
                    .map((preset) => Number(preset) || 0),
                tiers: coinsTiers.map((tier) => ({
                    step: Number(tier.step) || 0,
                    upTo: Number(tier.upTo) || 0,
                })),
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

        if (serviceType === 'coins') {
            return pricingCopy.coins;
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
                        const isCoins = schedule.serviceType === 'coins';
                        const coinsMin = (schedule.configuration.minimum ??
                            0) as number;
                        const coinsTierList = (schedule.configuration.tiers ??
                            []) as Array<{ upTo: number; step: number }>;
                        const coinsUnit = (schedule.configuration
                            .roundingUnit ?? 0) as number;
                        const coinsPresetList = (schedule.configuration
                            .presets ?? []) as number[];
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
                                                        {isCoins
                                                            ? coinsCopy.editLimits
                                                            : pricingCopy.editPrices}
                                                    </span>
                                                </Button>
                                                {isCoins ? null : (
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
                                                )}
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
                                                        {isCoins
                                                            ? coinsCopy.band
                                                            : isFut
                                                              ? pricingCopy.tableRank
                                                              : pricingCopy.tableStep}
                                                    </TableHead>
                                                    <TableHead className="text-end">
                                                        {isCoins
                                                            ? coinsCopy.quantityStep
                                                            : pricingCopy.tablePrice}
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {isCoins ? (
                                                    <>
                                                        <TableRow className="bg-accent/20">
                                                            <TableCell className="font-medium text-primary">
                                                                {
                                                                    coinsCopy.minimum
                                                                }
                                                            </TableCell>
                                                            <TableCell className="text-end font-semibold text-primary tabular-nums">
                                                                {formatInteger(
                                                                    coinsMin,
                                                                    locale,
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                        <TableRow className="bg-accent/20">
                                                            <TableCell className="font-medium text-primary">
                                                                {
                                                                    coinsCopy.roundingUnit
                                                                }
                                                            </TableCell>
                                                            <TableCell className="text-end font-semibold text-primary tabular-nums">
                                                                {formatInteger(
                                                                    coinsUnit,
                                                                    locale,
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                        {coinsTierList.map(
                                                            (tier, index) => {
                                                                const from =
                                                                    index === 0
                                                                        ? coinsMin
                                                                        : (coinsTierList[
                                                                              index -
                                                                                  1
                                                                          ]
                                                                              ?.upTo ??
                                                                          coinsMin);

                                                                return (
                                                                    <TableRow
                                                                        key={
                                                                            tier.upTo
                                                                        }
                                                                    >
                                                                        <TableCell className="font-medium tabular-nums">
                                                                            {coinsCopy.bandRange
                                                                                .replace(
                                                                                    ':from',
                                                                                    formatInteger(
                                                                                        from,
                                                                                        locale,
                                                                                    ),
                                                                                )
                                                                                .replace(
                                                                                    ':to',
                                                                                    formatInteger(
                                                                                        tier.upTo,
                                                                                        locale,
                                                                                    ),
                                                                                )}
                                                                        </TableCell>
                                                                        <TableCell className="text-end font-semibold text-foreground tabular-nums">
                                                                            {formatInteger(
                                                                                tier.step,
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
                                                                    coinsCopy.maximum
                                                                }
                                                            </TableCell>
                                                            <TableCell className="text-end font-semibold text-primary tabular-nums">
                                                                {formatInteger(
                                                                    coinsTierList[
                                                                        coinsTierList.length -
                                                                            1
                                                                    ]?.upTo ??
                                                                        coinsMin,
                                                                    locale,
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                        <TableRow>
                                                            <TableCell className="align-top font-medium">
                                                                {
                                                                    coinsCopy.presets
                                                                }
                                                            </TableCell>
                                                            <TableCell className="text-end text-xs text-muted-foreground tabular-nums">
                                                                {coinsPresetList
                                                                    .map(
                                                                        (
                                                                            preset,
                                                                        ) =>
                                                                            formatInteger(
                                                                                preset,
                                                                                locale,
                                                                            ),
                                                                    )
                                                                    .join(
                                                                        ' · ',
                                                                    )}
                                                            </TableCell>
                                                        </TableRow>
                                                    </>
                                                ) : isFut ? (
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
                            {!editingSchedule
                                ? ''
                                : editingSchedule.serviceType === 'coins'
                                  ? coinsCopy.dialogTitle
                                  : pricingCopy.editDialog.title.replace(
                                        ':service',
                                        getServiceName(
                                            editingSchedule.serviceType,
                                        ),
                                    )}
                        </DialogTitle>
                        <DialogDescription>
                            {!editingSchedule
                                ? ''
                                : editingSchedule.serviceType === 'coins'
                                  ? coinsCopy.dialogDescription
                                  : pricingCopy.editDialog.description.replace(
                                        ':service',
                                        getServiceName(
                                            editingSchedule.serviceType,
                                        ),
                                    )}
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
                            {editingSchedule.serviceType === 'coins' ? (
                                <div className="flex flex-col gap-4">
                                    <div className="flex flex-col gap-1.5">
                                        <Label
                                            className="text-xs font-semibold"
                                            htmlFor="input-coins-minimum"
                                        >
                                            {coinsCopy.minimum}
                                        </Label>
                                        <Input
                                            aria-describedby={
                                                editErrors[
                                                    'configuration.minimum'
                                                ]
                                                    ? 'input-coins-minimum-error'
                                                    : undefined
                                            }
                                            aria-invalid={
                                                !!editErrors[
                                                    'configuration.minimum'
                                                ]
                                            }
                                            className="min-h-11 touch-manipulation text-xs tabular-nums"
                                            disabled={editSubmitting}
                                            id="input-coins-minimum"
                                            inputMode="numeric"
                                            min="1"
                                            onChange={(e) =>
                                                setCoinsMinimum(e.target.value)
                                            }
                                            required
                                            step="1"
                                            type="number"
                                            value={coinsMinimum}
                                        />
                                        {editErrors['configuration.minimum'] ? (
                                            <p
                                                className="text-xs font-medium text-destructive"
                                                id="input-coins-minimum-error"
                                                role="alert"
                                            >
                                                {
                                                    editErrors[
                                                        'configuration.minimum'
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="flex flex-col gap-1.5">
                                        <Label
                                            className="text-xs font-semibold"
                                            htmlFor="input-coins-unit"
                                        >
                                            {coinsCopy.roundingUnit}
                                        </Label>
                                        <Input
                                            aria-describedby={
                                                editErrors[
                                                    'configuration.roundingUnit'
                                                ]
                                                    ? 'input-coins-unit-hint input-coins-unit-error'
                                                    : 'input-coins-unit-hint'
                                            }
                                            aria-invalid={
                                                !!editErrors[
                                                    'configuration.roundingUnit'
                                                ]
                                            }
                                            className="min-h-11 touch-manipulation text-xs tabular-nums"
                                            disabled={editSubmitting}
                                            id="input-coins-unit"
                                            inputMode="numeric"
                                            min="1"
                                            onChange={(e) =>
                                                setCoinsUnitDraft(
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            step="1"
                                            type="number"
                                            value={coinsUnitDraft}
                                        />
                                        <p
                                            className="text-xs text-muted-foreground"
                                            id="input-coins-unit-hint"
                                        >
                                            {coinsCopy.roundingUnitHint}
                                        </p>
                                        {editErrors[
                                            'configuration.roundingUnit'
                                        ] ? (
                                            <p
                                                className="text-xs font-medium text-destructive"
                                                id="input-coins-unit-error"
                                                role="alert"
                                            >
                                                {
                                                    editErrors[
                                                        'configuration.roundingUnit'
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="flex flex-col gap-3 border-t border-border/70 pt-4">
                                        {editErrors['configuration.tiers'] ||
                                        editErrors.configuration ? (
                                            <p
                                                className="text-xs font-medium text-destructive"
                                                role="alert"
                                            >
                                                {editErrors[
                                                    'configuration.tiers'
                                                ] ?? editErrors.configuration}
                                            </p>
                                        ) : null}

                                        {coinsTiers.map((tier, index) => {
                                            const upToKey = `configuration.tiers.${index}.upTo`;
                                            const stepKey = `configuration.tiers.${index}.step`;
                                            const error =
                                                editErrors[upToKey] ||
                                                editErrors[stepKey] ||
                                                editErrors[
                                                    `configuration.tiers.${index}`
                                                ];
                                            const bandStart =
                                                index === 0
                                                    ? Number(coinsMinimum) || 0
                                                    : Number(
                                                          coinsTiers[index - 1]
                                                              ?.upTo,
                                                      ) || 0;

                                            return (
                                                <div
                                                    className="flex flex-col gap-1.5"
                                                    key={index}
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <Label
                                                            className="text-xs font-semibold"
                                                            htmlFor={`input-coins-tier-${index}-upto`}
                                                        >
                                                            {coinsCopy.band}{' '}
                                                            {index + 1}
                                                        </Label>
                                                        {coinsTiers.length >
                                                        1 ? (
                                                            <button
                                                                aria-label={coinsCopy.removeBand.replace(
                                                                    ':index',
                                                                    String(
                                                                        index +
                                                                            1,
                                                                    ),
                                                                )}
                                                                className="inline-flex min-h-11 min-w-11 cursor-pointer touch-manipulation items-center justify-center gap-1 rounded-md px-2 text-xs font-semibold text-muted-foreground hover:text-destructive focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50"
                                                                disabled={
                                                                    editSubmitting
                                                                }
                                                                onClick={() =>
                                                                    setCoinsTiers(
                                                                        (
                                                                            prev,
                                                                        ) =>
                                                                            prev.filter(
                                                                                (
                                                                                    _,
                                                                                    i,
                                                                                ) =>
                                                                                    i !==
                                                                                    index,
                                                                            ),
                                                                    )
                                                                }
                                                                type="button"
                                                            >
                                                                <Trash2
                                                                    aria-hidden="true"
                                                                    className="size-3.5"
                                                                />
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                    <div className="grid grid-cols-2 gap-2">
                                                        <Input
                                                            aria-label={`${coinsCopy.band} ${index + 1} — ${coinsCopy.bandUpTo}`}
                                                            aria-invalid={
                                                                !!error
                                                            }
                                                            className="min-h-11 touch-manipulation text-xs tabular-nums"
                                                            disabled={
                                                                editSubmitting
                                                            }
                                                            id={`input-coins-tier-${index}-upto`}
                                                            inputMode="numeric"
                                                            min="1"
                                                            onChange={(e) =>
                                                                setCoinsTiers(
                                                                    (prev) =>
                                                                        prev.map(
                                                                            (
                                                                                row,
                                                                                i,
                                                                            ) =>
                                                                                i ===
                                                                                index
                                                                                    ? {
                                                                                          ...row,
                                                                                          upTo: e
                                                                                              .target
                                                                                              .value,
                                                                                      }
                                                                                    : row,
                                                                        ),
                                                                )
                                                            }
                                                            required
                                                            step="1"
                                                            type="number"
                                                            value={tier.upTo}
                                                        />
                                                        <Input
                                                            aria-label={`${coinsCopy.band} ${index + 1} — ${coinsCopy.quantityStep}`}
                                                            aria-invalid={
                                                                !!error
                                                            }
                                                            className="min-h-11 touch-manipulation text-xs tabular-nums"
                                                            disabled={
                                                                editSubmitting
                                                            }
                                                            id={`input-coins-tier-${index}-step`}
                                                            inputMode="numeric"
                                                            min="1"
                                                            onChange={(e) =>
                                                                setCoinsTiers(
                                                                    (prev) =>
                                                                        prev.map(
                                                                            (
                                                                                row,
                                                                                i,
                                                                            ) =>
                                                                                i ===
                                                                                index
                                                                                    ? {
                                                                                          ...row,
                                                                                          step: e
                                                                                              .target
                                                                                              .value,
                                                                                      }
                                                                                    : row,
                                                                        ),
                                                                )
                                                            }
                                                            required
                                                            step="1"
                                                            type="number"
                                                            value={tier.step}
                                                        />
                                                    </div>
                                                    <p className="text-xs text-muted-foreground tabular-nums">
                                                        {coinsCopy.bandPreview
                                                            .replace(
                                                                ':from',
                                                                formatInteger(
                                                                    bandStart,
                                                                    locale,
                                                                ),
                                                            )
                                                            .replace(
                                                                ':to',
                                                                formatInteger(
                                                                    Number(
                                                                        tier.upTo,
                                                                    ) || 0,
                                                                    locale,
                                                                ),
                                                            )
                                                            .replace(
                                                                ':step',
                                                                formatInteger(
                                                                    Number(
                                                                        tier.step,
                                                                    ) || 0,
                                                                    locale,
                                                                ),
                                                            )}
                                                    </p>
                                                    {error ? (
                                                        <p
                                                            className="text-xs font-medium text-destructive"
                                                            role="alert"
                                                        >
                                                            {error}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            );
                                        })}

                                        <button
                                            className="inline-flex min-h-11 cursor-pointer touch-manipulation items-center justify-center gap-1.5 self-start rounded-md border border-dashed border-border px-3 text-xs font-semibold text-muted-foreground hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50"
                                            disabled={editSubmitting}
                                            onClick={() =>
                                                setCoinsTiers((prev) => [
                                                    ...prev,
                                                    { step: '', upTo: '' },
                                                ])
                                            }
                                            type="button"
                                        >
                                            <Plus
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            <span>{coinsCopy.addBand}</span>
                                        </button>

                                        <p className="text-xs text-muted-foreground">
                                            {coinsCopy.bandsHint}
                                        </p>
                                    </div>

                                    <div className="flex flex-col gap-1.5 border-t border-border/70 pt-4">
                                        <Label
                                            className="text-xs font-semibold"
                                            htmlFor="input-coins-presets"
                                        >
                                            {coinsCopy.presets}
                                        </Label>
                                        <Input
                                            aria-describedby={
                                                editErrors[
                                                    'configuration.presets'
                                                ]
                                                    ? 'input-coins-presets-hint input-coins-presets-error'
                                                    : 'input-coins-presets-hint'
                                            }
                                            aria-invalid={
                                                !!editErrors[
                                                    'configuration.presets'
                                                ]
                                            }
                                            className="min-h-11 touch-manipulation text-xs tabular-nums"
                                            disabled={editSubmitting}
                                            id="input-coins-presets"
                                            onChange={(e) =>
                                                setCoinsPresets(e.target.value)
                                            }
                                            type="text"
                                            value={coinsPresets}
                                        />
                                        <p
                                            className="text-xs text-muted-foreground"
                                            id="input-coins-presets-hint"
                                        >
                                            {coinsCopy.presetsHint}
                                        </p>
                                        {editErrors['configuration.presets'] ? (
                                            <p
                                                className="text-xs font-medium text-destructive"
                                                id="input-coins-presets-error"
                                                role="alert"
                                            >
                                                {
                                                    editErrors[
                                                        'configuration.presets'
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                            ) : editingSchedule.serviceType ===
                              'fut_champions' ? (
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
                                                                pricingCopy.tablePrice
                                                            }
                                                            )
                                                        </Label>
                                                        <span className="text-xs text-muted-foreground tabular-nums">
                                                            {formatAdminMoney(
                                                                {
                                                                    amountMinor:
                                                                        String(
                                                                            parseSarToHalalah(
                                                                                currentVal ||
                                                                                    '0',
                                                                            ),
                                                                        ),
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
                                                        inputMode="decimal"
                                                        placeholder="0.00"
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
                                                        step="0.01"
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
                                                {pricingCopy.tablePrice})
                                            </Label>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {formatAdminMoney(
                                                    {
                                                        amountMinor: String(
                                                            parseSarToHalalah(
                                                                futUrgent ||
                                                                    '0',
                                                            ),
                                                        ),
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
                                            inputMode="decimal"
                                            placeholder="0.00"
                                            onChange={(e) =>
                                                setFutUrgent(e.target.value)
                                            }
                                            required
                                            step="0.01"
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
                                                        {pricingCopy.tablePrice}
                                                        )
                                                    </Label>
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        {formatAdminMoney(
                                                            {
                                                                amountMinor:
                                                                    String(
                                                                        parseSarToHalalah(
                                                                            currentVal ||
                                                                                '0',
                                                                        ),
                                                                    ),
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
                                                    inputMode="decimal"
                                                    placeholder="0.00"
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
                                                    step="0.01"
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
        </section>
    );
}
