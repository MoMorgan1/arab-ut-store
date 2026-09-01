'use no memo'; // TanStack Table's mutable instances are not React Compiler compatible.

import { Head, router, usePage } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import type { VisibilityState } from '@tanstack/react-table';
import { Copy } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import AdminCouponDrawer from '@/components/admin/coupons/admin-coupon-drawer';
import { getAdminCouponColumns } from '@/components/admin/coupons/admin-coupons-columns';
import type { CouponSortKey } from '@/components/admin/coupons/admin-coupons-columns';
import AdminCouponsTable from '@/components/admin/coupons/admin-coupons-table';
import AdminCouponsToolbar from '@/components/admin/coupons/admin-coupons-toolbar';
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
import type {
    AdminCouponRow,
    AdminCouponsPageProps,
    AdminCouponsQueryState,
    AdminTranslations,
} from '@/types/admin';

export default function AdminCouponsPage() {
    const { props, url } = usePage<AdminCouponsPageProps>();
    const copy = props.adminUi.coupons;
    const isLocalized = url.startsWith('/en/admin');
    const pathname = isLocalized
        ? '/en/admin/marketing/coupons'
        : '/admin/marketing/coupons';

    // State for drawer
    const [drawerMode, setDrawerMode] = useState<'create' | 'edit' | null>(
        null,
    );
    const [editingCoupon, setEditingCoupon] = useState<AdminCouponRow | null>(
        null,
    );

    // State for toggle status dialog
    const [toggleCoupon, setToggleCoupon] = useState<AdminCouponRow | null>(
        null,
    );
    const [toggleTargetActive, setToggleTargetActive] = useState(false);
    const [toggling, setToggling] = useState(false);

    // State for duplicate dialog
    const [duplicateCoupon, setDuplicateCoupon] =
        useState<AdminCouponRow | null>(null);
    const [duplicateCode, setDuplicateCode] = useState('');
    const [duplicating, setDuplicating] = useState(false);

    // Notifications
    const [actionMessage, setActionMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    // Column visibility
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const currentSort: CouponSortKey =
        (props.filters.sort as CouponSortKey) || 'created_at';
    const currentDirection = props.filters.direction || 'desc';

    const isFiltered = Boolean(
        props.filters.search ||
        (props.filters.status && props.filters.status !== 'all') ||
        props.filters.scope ||
        props.filters.discount_type,
    );

    const visitCoupons = useCallback(
        (newFilters: Partial<AdminCouponsQueryState>) => {
            const merged = { ...props.filters, ...newFilters };
            const cleanParams: Record<string, string | number> = {};

            for (const [k, v] of Object.entries(merged)) {
                if (v !== null && v !== undefined && v !== '') {
                    cleanParams[k] = v;
                }
            }

            router.get(pathname, cleanParams, {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        },
        [pathname, props.filters],
    );

    const handleSortChange = (
        sortKey: CouponSortKey,
        direction: 'asc' | 'desc',
    ) => {
        visitCoupons({ sort: sortKey, direction, page: 1 });
    };

    const handleResetFilters = () => {
        visitCoupons({
            search: null,
            status: null,
            scope: null,
            discount_type: null,
            page: 1,
        });
    };

    const openCreateDrawer = () => {
        setEditingCoupon(null);
        setDrawerMode('create');
    };

    const openEditDrawer = (coupon: AdminCouponRow) => {
        setEditingCoupon(coupon);
        setDrawerMode('edit');
    };

    const openToggleDialog = (
        coupon: AdminCouponRow,
        targetActive: boolean,
    ) => {
        setToggleCoupon(coupon);
        setToggleTargetActive(targetActive);
    };

    const confirmToggle = async () => {
        if (!toggleCoupon) {
            return;
        }

        const coupon = toggleCoupon;
        const targetActive = toggleTargetActive;
        setToggleCoupon(null);

        setToggling(true);
        setActionMessage(null);
        const targetUrl = props.statusUrlTemplate.replace('__ID__', coupon.id);

        try {
            const res = await fetch(targetUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ is_active: targetActive }),
                credentials: 'same-origin',
            });

            if (!res.ok) {
                setActionMessage({
                    type: 'error',
                    text: copy.messages.genericError,
                });
            } else {
                setActionMessage({
                    type: 'success',
                    text: copy.messages.toggled,
                });
                router.reload();
            }
        } catch {
            setActionMessage({
                type: 'error',
                text: copy.messages.networkError,
            });
        } finally {
            setToggling(false);
        }
    };

    const openDuplicateDialog = (coupon: AdminCouponRow) => {
        setDuplicateCoupon(coupon);
        setDuplicateCode('');
        setDuplicateDialogOpen(true);
    };

    const [duplicateDialogOpen, setDuplicateDialogOpen] = useState(false);

    const confirmDuplicate = async () => {
        if (!duplicateCoupon) {
            return;
        }

        const coupon = duplicateCoupon;
        setDuplicateDialogOpen(false);

        setDuplicating(true);
        setActionMessage(null);
        const targetUrl = props.duplicateUrlTemplate.replace(
            '__ID__',
            coupon.id,
        );

        try {
            const res = await fetch(targetUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    code: duplicateCode.trim()
                        ? duplicateCode.toUpperCase().trim()
                        : null,
                }),
                credentials: 'same-origin',
            });

            if (!res.ok) {
                setActionMessage({
                    type: 'error',
                    text: copy.messages.genericError,
                });
            } else {
                setActionMessage({
                    type: 'success',
                    text: copy.messages.duplicated,
                });
                router.reload();
            }
        } catch {
            setActionMessage({
                type: 'error',
                text: copy.messages.networkError,
            });
        } finally {
            setDuplicating(false);
        }
    };

    // Columns configuration
    const columns = useMemo(
        () =>
            getAdminCouponColumns({
                adminUi: props.adminUi,
                currentSort,
                currentDirection,
                locale: props.locale,
                onSortChange: handleSortChange,
                onEdit: openEditDrawer,
                onToggle: openToggleDialog,
                onDuplicate: openDuplicateDialog,
                permissions: props.permissions,
                showUrlTemplate: props.showUrlTemplate,
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [
            props.adminUi,
            currentSort,
            currentDirection,
            props.locale,
            props.permissions,
            props.showUrlTemplate,
        ],
    );

    const table = useReactTable({
        data: props.coupons,
        columns,
        getCoreRowModel: getCoreRowModel(),
        onColumnVisibilityChange: setColumnVisibility,
        state: {
            columnVisibility,
        },
    });

    return (
        <>
            <Head title={copy.headTitle} />

            <article className="space-y-6" dir={props.direction}>
                {/* Header */}
                <header className="flex flex-col gap-1 border-b border-border pb-5">
                    <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                        {copy.title}
                    </h1>
                    <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                        {copy.description}
                    </p>
                </header>

                {actionMessage ? (
                    <Alert
                        variant={
                            actionMessage.type === 'error'
                                ? 'destructive'
                                : 'default'
                        }
                    >
                        <AlertDescription>
                            {actionMessage.text}
                        </AlertDescription>
                    </Alert>
                ) : null}

                {/* Toolbar with tabs, search, filters & create button */}
                <AdminCouponsToolbar
                    adminUi={props.adminUi}
                    counts={props.counts}
                    filterOptions={props.filterOptions}
                    filters={props.filters}
                    isNavigating={false}
                    onCreateClick={openCreateDrawer}
                    onFilterChange={visitCoupons}
                    onResetFilters={handleResetFilters}
                    permissions={props.permissions}
                    table={table}
                />

                {/* Table (Desktop) / Mobile Cards (Mobile) */}
                <AdminCouponsTable
                    adminUi={props.adminUi}
                    isFiltered={isFiltered}
                    isNavigating={false}
                    locale={props.locale}
                    onDuplicate={openDuplicateDialog}
                    onEdit={openEditDrawer}
                    onResetFilters={handleResetFilters}
                    onToggle={openToggleDialog}
                    permissions={props.permissions}
                    showUrlTemplate={props.showUrlTemplate}
                    table={table}
                />

                {/* Pagination */}
                <CouponsPagination
                    adminUi={props.adminUi}
                    onPageChange={(page) => visitCoupons({ page })}
                    pagination={props.pagination}
                />
            </article>

            {/* Create / Edit Drawer */}
            <AdminCouponDrawer
                // Keyed so a different coupon (or switching create/edit) mounts a
                // fresh drawer with its own initial state, instead of an effect
                // writing state on every render pass.
                key={`${drawerMode ?? 'closed'}-${editingCoupon?.id ?? 'new'}`}
                adminUi={props.adminUi}
                categories={props.categories}
                createUrl={props.createUrl}
                editingCoupon={editingCoupon}
                mode={drawerMode}
                onClose={() => setDrawerMode(null)}
                products={props.products}
                serviceTypes={props.serviceTypes}
                updateUrlTemplate={props.updateUrlTemplate}
            />

            {/* Toggle Pause / Resume Confirmation Dialog */}
            {toggleCoupon ? (
                <Dialog
                    onOpenChange={(open) => !open && setToggleCoupon(null)}
                    open={toggleCoupon !== null}
                >
                    <DialogContent dir="ltr">
                        <DialogHeader>
                            <DialogTitle>
                                {toggleTargetActive
                                    ? copy.activateTitle
                                    : copy.deactivateTitle}
                            </DialogTitle>
                            <DialogDescription>
                                {toggleTargetActive
                                    ? copy.activateDescription.replace(
                                          ':code',
                                          toggleCoupon.code,
                                      )
                                    : copy.deactivateDescription.replace(
                                          ':code',
                                          toggleCoupon.code,
                                      )}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="flex flex-row items-center justify-end gap-2 pt-2">
                            <Button
                                className="min-h-11"
                                disabled={toggling}
                                onClick={() => setToggleCoupon(null)}
                                type="button"
                                variant="outline"
                            >
                                {copy.cancelButton}
                            </Button>
                            <Button
                                className={`min-h-11 ${
                                    toggleTargetActive
                                        ? ''
                                        : 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
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
            ) : null}

            {/* Duplicate Dialog */}
            {duplicateCoupon ? (
                <Dialog
                    onOpenChange={(open) =>
                        !open && setDuplicateDialogOpen(false)
                    }
                    open={duplicateDialogOpen}
                >
                    <DialogContent dir="ltr">
                        <DialogHeader>
                            <DialogTitle>{copy.duplicateTitle}</DialogTitle>
                            <DialogDescription>
                                {copy.duplicateDescription.replace(
                                    ':code',
                                    duplicateCoupon.code,
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="flex flex-col gap-1.5 py-2">
                            <Label htmlFor="list-dup-code">
                                {copy.duplicateCodeLabel}
                            </Label>
                            <Input
                                className="min-h-11 font-mono uppercase"
                                id="list-dup-code"
                                maxLength={24}
                                onChange={(e) =>
                                    setDuplicateCode(
                                        e.target.value.toUpperCase(),
                                    )
                                }
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
                                <span>
                                    {duplicating
                                        ? copy.duplicating
                                        : copy.confirmDuplicate}
                                </span>
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </>
    );
}

function CouponsPagination({
    adminUi,
    onPageChange,
    pagination,
}: {
    adminUi: AdminTranslations;
    onPageChange: (page: number) => void;
    pagination: AdminCouponsPageProps['pagination'];
}) {
    const copy = adminUi.orders;

    if (pagination.lastPage <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
            <span className="tabular-nums">
                {copy.page} {pagination.currentPage} {copy.of}{' '}
                {pagination.lastPage}
            </span>
            <div className="flex gap-2">
                <Button
                    className="min-h-11"
                    disabled={pagination.currentPage <= 1}
                    onClick={() => onPageChange(pagination.currentPage - 1)}
                    size="sm"
                    type="button"
                    variant="outline"
                >
                    {copy.previous}
                </Button>
                <Button
                    className="min-h-11"
                    disabled={pagination.currentPage >= pagination.lastPage}
                    onClick={() => onPageChange(pagination.currentPage + 1)}
                    size="sm"
                    type="button"
                    variant="outline"
                >
                    {copy.next}
                </Button>
            </div>
        </div>
    );
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
