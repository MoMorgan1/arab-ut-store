'use no memo';

import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    CircleDollarSign,
    Clock,
    Copy,
    Edit,
    Info,
    Pause,
    Play,
    ShoppingBag,
    Users,
    XCircle,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import type { AdminBadgeVariant } from '@/components/admin/admin-badge';
import AdminMobileTabBar from '@/components/admin/admin-mobile-tabbar';
import { formatAdminMoney } from '@/components/admin/admin-money';
import AdminPasswordConfirmDialog from '@/components/admin/admin-password-confirm-dialog';
import AdminSidebar from '@/components/admin/admin-sidebar';
import AdminCouponDrawer from '@/components/admin/coupons/admin-coupon-drawer';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
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
import type { AdminCouponDetailPageProps, AdminTranslations } from '@/types/admin';

function resolveStatusBadge(
    status: string,
    copy: AdminTranslations['coupons'],
): { label: string; variant: AdminBadgeVariant; icon: typeof CheckCircle2 } {
    switch (status) {
        case 'active':
            return { label: copy.active, variant: 'success', icon: CheckCircle2 };
        case 'scheduled':
            return { label: copy.scheduled, variant: 'warning', icon: Clock };
        case 'paused':
            return { label: copy.paused, variant: 'neutral', icon: Pause };
        case 'expired':
            return { label: copy.expired, variant: 'danger', icon: XCircle };
        case 'exhausted':
            return { label: copy.exhausted, variant: 'danger', icon: XCircle };
        default:
            return { label: copy.active, variant: 'neutral', icon: CheckCircle2 };
    }
}

export default function AdminCouponDetailPage() {
    const { props, url } = usePage<AdminCouponDetailPageProps>();
    const copy = props.adminUi.coupons;
    const isLocalized = url.startsWith('/en/admin');
    const orderBasePath = isLocalized ? '/en/admin/orders' : '/admin/orders';
    const customerBasePath = isLocalized ? '/en/admin/customers' : '/admin/customers';

    const canManage = props.permissions.includes('marketing.manage');
    const [editDrawerOpen, setEditDrawerOpen] = useState(false);
    const [duplicateDialogOpen, setDuplicateDialogOpen] = useState(false);
    const [duplicateCode, setDuplicateCode] = useState('');
    const [duplicating, setDuplicating] = useState(false);

    const [toggleDialogOpen, setToggleDialogOpen] = useState(false);
    const [toggling, setToggling] = useState(false);

    const [actionMessage, setActionMessage] = useState<{
        type: 'error' | 'success';
        text: string;
    } | null>(null);

    const [passwordModalOpen, setPasswordModalOpen] = useState(false);
    const pendingAction = useRef<(() => void) | null>(null);

    const coupon = props.coupon;
    const kpis = props.kpis;
    const badge = resolveStatusBadge(coupon.status, copy);

    const usagePercent =
        kpis.usageLimit !== null && kpis.usageLimit > 0
            ? Math.min(100, Math.round((kpis.usedCount / kpis.usageLimit) * 100))
            : 0;

    const maxDailyRedemptions = useMemo(() => {
        if (!props.chart || props.chart.length === 0) return 1;
        return Math.max(1, ...props.chart.map((p) => p.redemptions));
    }, [props.chart]);

    // Handlers
    const requestToggle = () => {
        setToggleDialogOpen(true);
    };

    const confirmToggle = () => {
        setToggleDialogOpen(false);
        pendingAction.current = async () => {
            setToggling(true);
            setActionMessage(null);
            try {
                const res = await fetch(props.statusUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify({ is_active: !coupon.isActive }),
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    setActionMessage({ type: 'error', text: copy.messages.genericError });
                } else {
                    setActionMessage({ type: 'success', text: copy.messages.toggled });
                    router.reload();
                }
            } catch {
                setActionMessage({ type: 'error', text: copy.messages.networkError });
            } finally {
                setToggling(false);
            }
        };
        setPasswordModalOpen(true);
    };

    const requestDuplicate = () => {
        setDuplicateCode('');
        setDuplicateDialogOpen(true);
    };

    const confirmDuplicate = () => {
        setDuplicateDialogOpen(false);
        pendingAction.current = async () => {
            setDuplicating(true);
            setActionMessage(null);
            try {
                const res = await fetch(props.duplicateUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify({
                        code: duplicateCode.trim() ? duplicateCode.toUpperCase().trim() : null,
                    }),
                    credentials: 'same-origin',
                });
                if (!res.ok) {
                    setActionMessage({ type: 'error', text: copy.messages.genericError });
                } else {
                    const json = (await res.json()) as { data: { id: string } };
                    setActionMessage({ type: 'success', text: copy.messages.duplicated });
                    const newDetailUrl = isLocalized
                        ? `/en/admin/marketing/coupons/${json.data.id}`
                        : `/admin/marketing/coupons/${json.data.id}`;
                    router.visit(newDetailUrl);
                }
            } catch {
                setActionMessage({ type: 'error', text: copy.messages.networkError });
            } finally {
                setDuplicating(false);
            }
        };
        setPasswordModalOpen(true);
    };

    return (
        <div className="admin-document-layout" dir="ltr">
            <Head title={`${coupon.code} — ${copy.headTitle}`} />
            <AdminSidebar
                adminIdentity={props.adminIdentity}
                adminUi={props.adminUi}
                current="marketingCoupons"
                direction={props.direction}
                logoutUrl={props.logoutUrl}
                navigation={props.adminNavigation}
            />

            <main className="admin-main">
                <article className="space-y-6" dir={props.direction}>
                    {/* Header */}
                    <header className="flex flex-col gap-4 border-b border-border pb-5">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Link
                                className="inline-flex min-h-11 items-center gap-1.5 font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                                href={props.listUrl}
                            >
                                <ArrowLeft aria-hidden="true" className="size-4" />
                                <span>{copy.backToCoupons}</span>
                            </Link>
                        </div>

                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-col gap-1.5">
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="font-mono text-2xl font-bold tracking-tight text-foreground md:text-3xl">
                                        {coupon.code}
                                    </h1>
                                    <AdminBadge icon={badge.icon} variant={badge.variant}>
                                        {badge.label}
                                    </AdminBadge>
                                </div>
                                {(coupon.descriptionEn || coupon.descriptionAr) ? (
                                    <p className="text-sm text-muted-foreground">
                                        {coupon.descriptionEn || coupon.descriptionAr}
                                    </p>
                                ) : null}
                            </div>

                            {/* Header Actions */}
                            {canManage ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        className="min-h-11 gap-1.5 text-xs"
                                        onClick={requestDuplicate}
                                        type="button"
                                        variant="outline"
                                    >
                                        <Copy aria-hidden="true" className="size-4" />
                                        <span>{copy.duplicateButton}</span>
                                    </Button>

                                    <Button
                                        className={`min-h-11 gap-1.5 text-xs ${
                                            coupon.isActive
                                                ? 'text-status-warning border-status-warning/40 hover:bg-status-warning/10'
                                                : 'text-status-success border-status-success/40 hover:bg-status-success/10'
                                        }`}
                                        onClick={requestToggle}
                                        type="button"
                                        variant="outline"
                                    >
                                        {coupon.isActive ? (
                                            <>
                                                <Pause aria-hidden="true" className="size-4" />
                                                <span>{copy.pauseButton}</span>
                                            </>
                                        ) : (
                                            <>
                                                <Play aria-hidden="true" className="size-4" />
                                                <span>{copy.resumeButton}</span>
                                            </>
                                        )}
                                    </Button>

                                    <Button
                                        className="min-h-11 gap-1.5 text-xs"
                                        onClick={() => setEditDrawerOpen(true)}
                                        type="button"
                                    >
                                        <Edit aria-hidden="true" className="size-4" />
                                        <span>{copy.editButton}</span>
                                    </Button>
                                </div>
                            ) : null}
                        </div>
                    </header>

                    {actionMessage ? (
                        <Alert variant={actionMessage.type === 'error' ? 'destructive' : 'default'}>
                            <AlertDescription>{actionMessage.text}</AlertDescription>
                        </Alert>
                    ) : null}

                    {/* KPI Strip */}
                    <section aria-label={copy.performanceTitle}>
                        <dl className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                            {/* KPI 1: Redemptions */}
                            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-4">
                                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                                    <ShoppingBag aria-hidden="true" className="size-4 shrink-0 text-primary" />
                                    <span className="truncate">{copy.kpiRedemptions}</span>
                                </dt>
                                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                                    {kpis.usageLimit !== null
                                        ? `${kpis.usedCount} / ${kpis.usageLimit}`
                                        : `${kpis.usedCount} / ∞`}
                                </dd>
                                {kpis.usageLimit !== null ? (
                                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className={`h-full ${
                                                usagePercent >= 100
                                                    ? 'bg-status-danger'
                                                    : usagePercent >= 80
                                                      ? 'bg-status-warning'
                                                      : 'bg-primary'
                                            }`}
                                            style={{ width: `${usagePercent}%` }}
                                        />
                                    </div>
                                ) : (
                                    <span className="text-[11px] text-muted-foreground">
                                        {copy.unlimited}
                                    </span>
                                )}
                            </div>

                            {/* KPI 2: Unique Customers */}
                            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-4">
                                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                                    <Users aria-hidden="true" className="size-4 shrink-0 text-primary" />
                                    <span className="truncate">{copy.kpiUniqueCustomers}</span>
                                </dt>
                                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                                    {kpis.uniqueCustomers}
                                </dd>
                                <span className="text-[11px] text-muted-foreground">
                                    {copy.kpiPaidOrdersNote}
                                </span>
                            </div>

                            {/* KPI 3: Revenue Attributed */}
                            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-4">
                                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                                    <CircleDollarSign aria-hidden="true" className="size-4 shrink-0 text-primary" />
                                    <span className="truncate">{copy.kpiRevenueAttributed}</span>
                                </dt>
                                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                                    {formatAdminMoney(kpis.revenueAttributed, props.locale)}
                                </dd>
                                <span className="text-[11px] text-muted-foreground">
                                    {copy.kpiPaidOrdersNote}
                                </span>
                            </div>

                            {/* KPI 4: Total Discount Given */}
                            <div className="flex flex-col justify-between gap-1.5 rounded-xl border border-border bg-card p-4">
                                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground sm:text-sm">
                                    <CircleDollarSign aria-hidden="true" className="size-4 shrink-0 text-primary" />
                                    <span className="truncate">{copy.kpiTotalDiscount}</span>
                                </dt>
                                <dd className="text-xl font-bold tracking-tight text-foreground tabular-nums sm:text-2xl lg:text-3xl">
                                    {formatAdminMoney(kpis.totalDiscountGiven, props.locale)}
                                </dd>
                                <span className="text-[11px] text-muted-foreground">
                                    {copy.kpiPaidOrdersNote}
                                </span>
                            </div>
                        </dl>
                    </section>

                    {/* Released Redemptions Notice */}
                    {kpis.releasedRedemptionsCount > 0 ? (
                        <div className="flex items-start gap-2.5 rounded-xl border border-primary/20 bg-primary/10 p-4 text-xs leading-relaxed text-foreground">
                            <Info aria-hidden="true" className="size-4 shrink-0 text-primary mt-0.5" />
                            <div className="flex flex-col gap-0.5">
                                <p className="font-semibold text-primary">
                                    {copy.releasedNotice.replace(
                                        ':count',
                                        String(kpis.releasedRedemptionsCount),
                                    )}
                                </p>
                                <p className="text-muted-foreground">
                                    {copy.cancelledReleasesRedemptionHelp}
                                </p>
                            </div>
                        </div>
                    ) : null}

                    {/* Grid: Daily Chart + Rules Summary */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                        {/* Redemptions over time chart */}
                        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 lg:col-span-7">
                            <h2 className="text-base font-bold tracking-tight text-foreground">
                                {copy.chartTitle}
                            </h2>

                            {props.chart && props.chart.length > 0 ? (
                                <div className="flex flex-col gap-3">
                                    <div
                                        aria-label={copy.chartTitle}
                                        className="flex h-48 items-end gap-2 overflow-x-auto pt-6 pb-2"
                                        role="img"
                                    >
                                        {props.chart.map((point) => {
                                            const barHeightPercent = Math.max(
                                                12,
                                                Math.round(
                                                    (point.redemptions / maxDailyRedemptions) * 100,
                                                ),
                                            );
                                            return (
                                                <div
                                                    className="group relative flex flex-1 min-w-[28px] flex-col items-center gap-1"
                                                    key={point.date}
                                                >
                                                    {/* Tooltip */}
                                                    <div className="pointer-events-none absolute -top-10 z-10 hidden whitespace-nowrap rounded-md border border-border bg-popover px-2 py-1 text-[11px] font-medium text-popover-foreground shadow-md group-hover:block">
                                                        {point.date}: {point.redemptions} {copy.kpiRedemptions}
                                                    </div>
                                                    <div
                                                        className="w-full rounded-t-sm bg-primary/80 transition-all hover:bg-primary"
                                                        style={{ height: `${barHeightPercent}%` }}
                                                    />
                                                    <span className="text-[10px] text-muted-foreground tabular-nums">
                                                        {point.date.slice(5)}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            ) : (
                                <div className="flex h-48 items-center justify-center rounded-lg border border-dashed border-border text-center text-xs text-muted-foreground">
                                    {copy.noChartData}
                                </div>
                            )}
                        </div>

                        {/* Rules in force summary list */}
                        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 lg:col-span-5">
                            <h2 className="text-base font-bold tracking-tight text-foreground">
                                {copy.rulesTitle}
                            </h2>
                            <dl className="divide-y divide-border/60 text-xs">
                                {props.rules.map((rule) => (
                                    <div
                                        className="flex flex-col gap-0.5 py-2.5 first:pt-0 last:pb-0"
                                        key={rule.key}
                                    >
                                        <dt className="font-medium text-muted-foreground">
                                            {rule.label}
                                        </dt>
                                        <dd className="font-semibold text-foreground">
                                            {rule.value}
                                        </dd>
                                        {rule.description ? (
                                            <p className="text-[11px] text-muted-foreground">
                                                {rule.description}
                                            </p>
                                        ) : null}
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </div>

                    {/* Recent Redemptions Table */}
                    <section aria-label={copy.recentRedemptionsTitle} className="space-y-3">
                        <h2 className="text-base font-bold tracking-tight text-foreground">
                            {copy.recentRedemptionsTitle}
                        </h2>

                        <div className="overflow-x-auto rounded-xl border border-border bg-card shadow-xs">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{copy.orderColumn}</TableHead>
                                        <TableHead>{copy.customerColumn}</TableHead>
                                        <TableHead>{copy.totalColumn}</TableHead>
                                        <TableHead>{copy.discountColumn}</TableHead>
                                        <TableHead>{copy.statusColumn}</TableHead>
                                        <TableHead>{copy.dateColumn}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {props.recentRedemptions && props.recentRedemptions.length > 0 ? (
                                        props.recentRedemptions.map((redemption) => (
                                            <TableRow key={redemption.id}>
                                                <TableCell>
                                                    <Link
                                                        className="font-mono text-xs font-semibold text-foreground underline decoration-border underline-offset-4 hover:text-primary focus-visible:outline-2 focus-visible:outline-ring"
                                                        href={`${orderBasePath}/${redemption.orderId}`}
                                                    >
                                                        {redemption.orderNumber}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        <Link
                                                            className="text-xs font-medium text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                                                            href={`${customerBasePath}/${redemption.customer.id}`}
                                                        >
                                                            {redemption.customer.name}
                                                        </Link>
                                                        <span className="text-[11px] text-muted-foreground">
                                                            {redemption.customer.email}
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-xs font-semibold tabular-nums text-foreground">
                                                    {formatAdminMoney(redemption.orderTotal, props.locale)}
                                                </TableCell>
                                                <TableCell className="text-xs font-semibold tabular-nums text-status-success">
                                                    {formatAdminMoney(redemption.discount, props.locale)}
                                                </TableCell>
                                                <TableCell>
                                                    <AdminBadge
                                                        variant={
                                                            redemption.isPaid
                                                                ? 'success'
                                                                : redemption.orderStatus === 'cancelled'
                                                                  ? 'danger'
                                                                  : 'neutral'
                                                        }
                                                    >
                                                        {redemption.orderStatus}
                                                    </AdminBadge>
                                                </TableCell>
                                                <TableCell className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                                    {redemption.redeemedAt.slice(0, 16).replace('T', ' ')}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                className="h-24 text-center text-xs text-muted-foreground"
                                                colSpan={6}
                                            >
                                                {copy.noRecentRedemptions}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                </article>
            </main>

            <AdminMobileTabBar
                adminUi={props.adminUi}
                current="marketingCoupons"
                navigation={props.adminNavigation}
            />

            {/* Create / Edit Drawer */}
            <AdminCouponDrawer
                adminUi={props.adminUi}
                categories={props.categories}
                createUrl={props.updateUrl}
                editingCoupon={coupon}
                mode={editDrawerOpen ? 'edit' : null}
                onClose={() => setEditDrawerOpen(false)}
                products={props.products}
                serviceTypes={props.serviceTypes}
                updateUrlTemplate={props.updateUrl}
            />

            {/* Duplicate Dialog */}
            <Dialog onOpenChange={setDuplicateDialogOpen} open={duplicateDialogOpen}>
                <DialogContent dir="ltr">
                    <DialogHeader>
                        <DialogTitle>{copy.duplicateTitle}</DialogTitle>
                        <DialogDescription>
                            {copy.duplicateDescription.replace(':code', coupon.code)}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col gap-1.5 py-2">
                        <Label htmlFor="dup-code">{copy.duplicateCodeLabel}</Label>
                        <Input
                            className="min-h-11 font-mono uppercase"
                            id="dup-code"
                            maxLength={24}
                            onChange={(e) => setDuplicateCode(e.target.value.toUpperCase())}
                            placeholder={copy.duplicateCodePlaceholder}
                            value={duplicateCode}
                        />
                    </div>
                    <DialogFooter className="flex flex-row items-center justify-end gap-2 pt-2">
                        <Button
                            className="min-h-11"
                            disabled={duplicating}
                            onClick={() => setDuplicateDialogOpen(false)}
                            type="button"
                            variant="outline"
                        >
                            {copy.cancelButton}
                        </Button>
                        <Button
                            className="min-h-11 gap-1.5"
                            disabled={duplicating}
                            onClick={confirmDuplicate}
                            type="button"
                        >
                            <Copy aria-hidden="true" className="size-4" />
                            <span>{duplicating ? copy.duplicating : copy.confirmDuplicate}</span>
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Toggle Pause / Resume Dialog */}
            <Dialog onOpenChange={setToggleDialogOpen} open={toggleDialogOpen}>
                <DialogContent dir="ltr">
                    <DialogHeader>
                        <DialogTitle>
                            {coupon.isActive ? copy.deactivateTitle : copy.activateTitle}
                        </DialogTitle>
                        <DialogDescription>
                            {coupon.isActive
                                ? copy.deactivateDescription.replace(':code', coupon.code)
                                : copy.activateDescription.replace(':code', coupon.code)}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="flex flex-row items-center justify-end gap-2 pt-2">
                        <Button
                            className="min-h-11"
                            disabled={toggling}
                            onClick={() => setToggleDialogOpen(false)}
                            type="button"
                            variant="outline"
                        >
                            {copy.cancelButton}
                        </Button>
                        <Button
                            className={`min-h-11 ${
                                coupon.isActive ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90' : ''
                            }`}
                            disabled={toggling}
                            onClick={confirmToggle}
                            type="button"
                        >
                            {copy.confirmToggle}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Password confirmation dialog */}
            <AdminPasswordConfirmDialog
                confirmButtonText={copy.confirmPasswordButton}
                confirmingButtonText={copy.confirmingPassword}
                description={copy.passwordModalDescription}
                onConfirmed={() => {
                    setPasswordModalOpen(false);
                    pendingAction.current?.();
                    pendingAction.current = null;
                }}
                onOpenChange={(open) => {
                    setPasswordModalOpen(open);
                    if (!open) {
                        pendingAction.current = null;
                    }
                }}
                open={passwordModalOpen}
                passwordLabel={copy.passwordLabel}
                passwordPlaceholder={copy.passwordPlaceholder}
                title={copy.passwordModalTitle}
            />
        </div>
    );
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
