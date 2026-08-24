'use no memo';

import { Head, router } from '@inertiajs/react';
import {
    Award,
    CheckCircle2,
    Coins,
    Pencil,
    Sparkles,
    Users,
    XCircle,
} from 'lucide-react';
import React, { useState } from 'react';

import AdminLoyaltyTierDialog from '@/components/admin/loyalty/admin-loyalty-tier-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AdminLoyaltyPageProps, AdminLoyaltyTier } from '@/types/admin';

function formatSarAmount(amountMinor: string): string {
    const halalah = parseInt(amountMinor, 10);

    if (isNaN(halalah)) {
        return '0.00 SAR';
    }

    const sar = (halalah / 100).toFixed(2);

    return `${sar} SAR`;
}

export default function AdminLoyaltyPage({
    adminUi,
    direction,
    kpis,
    permissions,
    tiers: initialTiers,
    updateTierUrlTemplate,
}: AdminLoyaltyPageProps) {
    const copy = adminUi.loyalty;
    const canManage = permissions.includes('loyalty.manage');

    const [tiers, setTiers] = useState<AdminLoyaltyTier[]>(initialTiers);
    const [selectedTier, setSelectedTier] = useState<AdminLoyaltyTier | null>(
        null,
    );
    const [dialogOpen, setDialogOpen] = useState(false);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);

    const totalCustomers = Object.values(kpis.customersPerTier).reduce(
        (sum, count) => sum + count,
        0,
    );

    const handleEditClick = (tier: AdminLoyaltyTier) => {
        setSelectedTier(tier);
        setDialogOpen(true);
        setSuccessMessage(null);
    };

    const handleTierUpdated = (updatedTier: AdminLoyaltyTier) => {
        setTiers((prev) =>
            prev.map((t) => (t.id === updatedTier.id ? updatedTier : t)),
        );
        setSuccessMessage(copy.editDialog.successMessage);
        router.reload({ only: ['tiers', 'kpis'] });
    };

    return (
        <article className="space-y-8" dir={direction}>
            <Head title={copy.headTitle} />

            <header className="flex flex-col gap-3 border-b border-border pb-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Award
                                aria-hidden="true"
                                className="size-6 text-primary"
                            />
                            <h1 className="font-display text-2xl font-bold tracking-tight text-foreground md:text-3xl">
                                {copy.title}
                            </h1>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {copy.description}
                        </p>
                    </div>
                </div>
            </header>

            {successMessage ? (
                <div
                    className="flex items-center gap-3 rounded-lg border border-primary/20 bg-primary/10 p-4 text-sm font-medium text-foreground"
                    role="status"
                >
                    <CheckCircle2
                        aria-hidden="true"
                        className="size-5 text-primary"
                    />
                    <span>{successMessage}</span>
                </div>
            ) : null}

            {/* KPI Strip */}
            <section
                aria-label={copy.kpi.totalCustomers}
                className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Card className="border-border bg-card">
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-xs font-semibold text-muted-foreground">
                            {copy.kpi.cashbackLast30Days}
                        </CardTitle>
                        <Coins
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                    </CardHeader>
                    <CardContent>
                        <div className="font-display text-2xl font-bold text-foreground tabular-nums">
                            {formatSarAmount(
                                kpis.cashbackCreditedLast30Days.amountMinor,
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card className="border-border bg-card">
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-xs font-semibold text-muted-foreground">
                            {copy.kpi.totalCustomers}
                        </CardTitle>
                        <Users
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                    </CardHeader>
                    <CardContent>
                        <div className="font-display text-2xl font-bold text-foreground tabular-nums">
                            {totalCustomers}
                        </div>
                    </CardContent>
                </Card>

                <Card className="border-border bg-card sm:col-span-2 lg:col-span-1">
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-xs font-semibold text-muted-foreground">
                            {copy.kpi.customersPerTier}
                        </CardTitle>
                        <Sparkles
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {tiers.map((tier) => {
                                const count =
                                    kpis.customersPerTier[tier.key] ?? 0;

                                return (
                                    <Badge
                                        className="gap-1.5 px-2.5 py-1 text-xs font-semibold"
                                        key={tier.id}
                                        variant="outline"
                                    >
                                        <span>{tier.nameEn}:</span>
                                        <span className="text-foreground tabular-nums">
                                            {count}
                                        </span>
                                    </Badge>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            </section>

            {/* Loyalty Tiers Table */}
            <Card className="border-border bg-card">
                <CardHeader>
                    <CardTitle className="text-lg font-bold text-foreground">
                        {copy.title}
                    </CardTitle>
                    <CardDescription className="text-xs text-muted-foreground">
                        {copy.description}
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0 sm:p-6 sm:pt-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="border-border hover:bg-transparent">
                                    <TableHead className="w-16 text-center text-xs font-semibold">
                                        {copy.table.rank}
                                    </TableHead>
                                    <TableHead className="text-xs font-semibold">
                                        {copy.table.nameAr}
                                    </TableHead>
                                    <TableHead className="text-xs font-semibold">
                                        {copy.table.nameEn}
                                    </TableHead>
                                    <TableHead className="text-xs font-semibold">
                                        {copy.table.threshold}
                                    </TableHead>
                                    <TableHead className="text-xs font-semibold">
                                        {copy.table.cashbackRate}
                                    </TableHead>
                                    <TableHead className="text-xs font-semibold">
                                        {copy.table.status}
                                    </TableHead>
                                    {canManage ? (
                                        <TableHead className="text-end text-xs font-semibold">
                                            {copy.table.actions}
                                        </TableHead>
                                    ) : null}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tiers.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            className="h-24 text-center text-sm text-muted-foreground"
                                            colSpan={canManage ? 7 : 6}
                                        >
                                            {copy.table.noTiers}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    tiers.map((tier) => (
                                        <TableRow
                                            className="border-border hover:bg-accent/40"
                                            key={tier.id}
                                        >
                                            <TableCell className="text-center font-bold text-muted-foreground tabular-nums">
                                                #{tier.rank}
                                            </TableCell>
                                            <TableCell
                                                className="font-semibold text-foreground"
                                                dir="rtl"
                                            >
                                                {tier.nameAr}
                                            </TableCell>
                                            <TableCell className="font-medium text-foreground">
                                                {tier.nameEn}
                                            </TableCell>
                                            <TableCell className="font-medium text-foreground tabular-nums">
                                                {formatSarAmount(
                                                    tier.minimumLifetimeSpend
                                                        .amountMinor,
                                                )}
                                            </TableCell>
                                            <TableCell className="font-semibold text-primary tabular-nums">
                                                {tier.cashbackPercent}
                                                <span className="ms-1.5 text-[11px] font-normal text-muted-foreground">
                                                    ({tier.cashbackBasisPoints}{' '}
                                                    bps)
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {tier.isActive ? (
                                                    <Badge
                                                        className="gap-1 bg-emerald-500/15 text-emerald-500 hover:bg-emerald-500/25"
                                                        variant="secondary"
                                                    >
                                                        <CheckCircle2
                                                            aria-hidden="true"
                                                            className="size-3"
                                                        />
                                                        <span>
                                                            {copy.table.active}
                                                        </span>
                                                    </Badge>
                                                ) : (
                                                    <Badge
                                                        className="gap-1 text-muted-foreground"
                                                        variant="outline"
                                                    >
                                                        <XCircle
                                                            aria-hidden="true"
                                                            className="size-3"
                                                        />
                                                        <span>
                                                            {
                                                                copy.table
                                                                    .inactive
                                                            }
                                                        </span>
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            {canManage ? (
                                                <TableCell className="text-end">
                                                    <Button
                                                        aria-label={`${copy.table.edit} ${tier.nameEn}`}
                                                        className="min-h-11 gap-1.5 text-xs font-semibold"
                                                        onClick={() =>
                                                            handleEditClick(
                                                                tier,
                                                            )
                                                        }
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Pencil
                                                            aria-hidden="true"
                                                            className="size-3.5"
                                                        />
                                                        <span>
                                                            {copy.table.edit}
                                                        </span>
                                                    </Button>
                                                </TableCell>
                                            ) : null}
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <AdminLoyaltyTierDialog
                adminUi={adminUi}
                onOpenChange={setDialogOpen}
                onSuccess={handleTierUpdated}
                open={dialogOpen}
                tier={selectedTier}
                updateTierUrlTemplate={updateTierUrlTemplate}
            />
        </article>
    );
}
