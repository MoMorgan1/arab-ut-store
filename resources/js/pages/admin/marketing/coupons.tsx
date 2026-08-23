'use no memo'; // TanStack Table's mutable instances are not React Compiler compatible.

import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

import AdminMobileNavigation from '@/components/admin/admin-mobile-navigation';
import AdminMobileTabBar from '@/components/admin/admin-mobile-tabbar';
import AdminPasswordConfirmDialog from '@/components/admin/admin-password-confirm-dialog';
import AdminSidebar from '@/components/admin/admin-sidebar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AdminCouponRow,
    AdminCouponsPageProps,
    AdminCouponsQueryState,
} from '@/types/admin';

type CouponFormData = {
    code: string;
    description_ar: string;
    description_en: string;
    discount_type: 'percent' | 'fixed';
    value: string;
    minimum_order_halalah: string;
    maximum_discount_halalah: string;
    usage_limit: string;
    per_user_limit: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
};

const emptyForm: CouponFormData = {
    code: '',
    description_ar: '',
    description_en: '',
    discount_type: 'percent',
    value: '',
    minimum_order_halalah: '0',
    maximum_discount_halalah: '',
    usage_limit: '',
    per_user_limit: '',
    starts_at: '',
    ends_at: '',
    is_active: true,
};

function couponToForm(coupon: AdminCouponRow): CouponFormData {
    return {
        code: coupon.code,
        description_ar: '',
        description_en: '',
        discount_type: coupon.discountType,
        value: String(coupon.value),
        minimum_order_halalah: String(coupon.minimumOrderHalalah),
        maximum_discount_halalah:
            coupon.maximumDiscountHalalah !== null
                ? String(coupon.maximumDiscountHalalah)
                : '',
        usage_limit:
            coupon.usageLimit !== null ? String(coupon.usageLimit) : '',
        per_user_limit:
            coupon.perUserLimit !== null ? String(coupon.perUserLimit) : '',
        starts_at: coupon.startsAt ? coupon.startsAt.slice(0, 10) : '',
        ends_at: coupon.endsAt ? coupon.endsAt.slice(0, 10) : '',
        is_active: coupon.isActive,
    };
}

export default function AdminCouponsPage() {
    const { props, url } = usePage<AdminCouponsPageProps>();
    const copy = props.adminUi.coupons;
    const pathname = new URL(url, window.location.origin).pathname;

    const [dialogMode, setDialogMode] = useState<'create' | 'edit' | null>(
        null,
    );
    const [editingCoupon, setEditingCoupon] = useState<AdminCouponRow | null>(
        null,
    );
    const [formData, setFormData] = useState<CouponFormData>(emptyForm);
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    const [toggleCoupon, setToggleCoupon] = useState<AdminCouponRow | null>(
        null,
    );
    const [toggleTargetActive, setToggleTargetActive] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [toggleMessage, setToggleMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    const [passwordModalOpen, setPasswordModalOpen] = useState(false);
    const pendingAction = useRef<(() => void) | null>(null);

    const visitCoupons = useCallback(
        (filters: AdminCouponsQueryState) => {
            router.get(
                pathname,
                Object.fromEntries(
                    Object.entries(filters).filter(
                        ([, v]) => v !== null && v !== undefined,
                    ),
                ),
                {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                },
            );
        },
        [pathname],
    );

    function openCreate() {
        setFormData(emptyForm);
        setFormErrors({});
        setSaveMessage(null);
        setEditingCoupon(null);
        setDialogMode('create');
    }

    function openEdit(coupon: AdminCouponRow) {
        setFormData(couponToForm(coupon));
        setFormErrors({});
        setSaveMessage(null);
        setEditingCoupon(coupon);
        setDialogMode('edit');
    }

    function closeDialog() {
        if (saving) {
            return;
        }

        setDialogMode(null);
    }

    function handleFieldChange(
        field: keyof CouponFormData,
        value: string | boolean,
    ) {
        setFormData((prev) => ({ ...prev, [field]: value }));
        setFormErrors((prev) => {
            const next = { ...prev };
            delete next[field];

            return next;
        });
    }

    async function submitForm() {
        setSaving(true);
        setSaveMessage(null);
        setFormErrors({});

        const isEdit = dialogMode === 'edit' && editingCoupon !== null;
        const url = isEdit
            ? `/admin/api/marketing/coupons/${editingCoupon.id}`
            : '/admin/api/marketing/coupons';
        const method = isEdit ? 'PUT' : 'POST';

        const payload: Record<string, unknown> = {
            code: formData.code.toUpperCase(),
            description_ar: formData.description_ar || null,
            description_en: formData.description_en || null,
            discount_type: formData.discount_type,
            value: Number(formData.value),
            minimum_order_halalah: Number(
                formData.minimum_order_halalah || '0',
            ),
            maximum_discount_halalah: formData.maximum_discount_halalah
                ? Number(formData.maximum_discount_halalah)
                : null,
            usage_limit: formData.usage_limit
                ? Number(formData.usage_limit)
                : null,
            per_user_limit: formData.per_user_limit
                ? Number(formData.per_user_limit)
                : null,
            starts_at: formData.starts_at || null,
            ends_at: formData.ends_at || null,
            is_active: formData.is_active,
        };

        try {
            const res = await fetch(url, {
                body: JSON.stringify(payload),
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                method,
            });

            if (res.status === 422) {
                const json = (await res.json()) as {
                    errors: Record<string, string[]>;
                };
                const mapped: Record<string, string> = {};

                for (const [k, v] of Object.entries(json.errors)) {
                    mapped[k] = v[0] ?? '';
                }

                setFormErrors(mapped);
                setSaveMessage({
                    type: 'error',
                    text: copy.messages.validationError,
                });
            } else if (res.status === 423 || res.status === 403) {
                setSaveMessage({
                    type: 'error',
                    text: copy.messages.forbiddenError,
                });
            } else if (!res.ok) {
                setSaveMessage({
                    type: 'error',
                    text: copy.messages.genericError,
                });
            } else {
                setSaveMessage({
                    type: 'success',
                    text: isEdit
                        ? copy.messages.updated
                        : copy.messages.created,
                });
                setDialogMode(null);
                router.reload({ only: ['coupons', 'pagination', 'counts'] });
            }
        } catch {
            setSaveMessage({ type: 'error', text: copy.messages.networkError });
        } finally {
            setSaving(false);
        }
    }

    function requestSave() {
        pendingAction.current = submitForm;
        setPasswordModalOpen(true);
    }

    function requestToggle(coupon: AdminCouponRow, targetActive: boolean) {
        setToggleCoupon(coupon);
        setToggleTargetActive(targetActive);
        setToggleMessage(null);
    }

    async function confirmToggle() {
        if (!toggleCoupon) {
            return;
        }

        const execute = async () => {
            setToggling(true);
            setToggleMessage(null);

            try {
                const res = await fetch(
                    `/admin/api/marketing/coupons/${toggleCoupon.id}/status`,
                    {
                        body: JSON.stringify({ is_active: toggleTargetActive }),
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': getCsrfToken(),
                        },
                        method: 'POST',
                    },
                );

                if (!res.ok) {
                    setToggleMessage({
                        type: 'error',
                        text: copy.messages.genericError,
                    });
                } else {
                    setToggleCoupon(null);
                    router.reload({
                        only: ['coupons', 'pagination', 'counts'],
                    });
                }
            } catch {
                setToggleMessage({
                    type: 'error',
                    text: copy.messages.networkError,
                });
            } finally {
                setToggling(false);
            }
        };

        pendingAction.current = execute;
        setPasswordModalOpen(true);
    }

    return (
        <div className="admin-document-layout" dir="ltr">
            <Head title={copy.headTitle} />
            <AdminSidebar
                adminIdentity={props.adminIdentity}
                adminUi={props.adminUi}
                current="marketing"
                direction={props.direction}
                logoutUrl={props.logoutUrl}
                navigation={props.adminNavigation}
            />
            <AdminMobileNavigation
                adminIdentity={props.adminIdentity}
                adminUi={props.adminUi}
                current="marketing"
                direction={props.direction}
                logoutUrl={props.logoutUrl}
                navigation={props.adminNavigation}
            />
            <main className="admin-main">
                <article className="space-y-6" dir={props.direction}>
                    <header className="flex flex-col gap-4 border-b border-border pb-5 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                                {copy.title}
                            </h1>
                            <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                                {copy.description}
                            </p>
                        </div>
                        {props.permissions.includes('marketing.manage') ? (
                            <Button
                                className="shrink-0"
                                onClick={openCreate}
                                type="button"
                            >
                                {copy.createButton}
                            </Button>
                        ) : null}
                    </header>

                    {saveMessage ? (
                        <Alert
                            variant={
                                saveMessage.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                        >
                            <AlertTitle>{saveMessage.text}</AlertTitle>
                        </Alert>
                    ) : null}

                    <CouponsTable
                        copy={copy}
                        coupons={props.coupons}
                        onEdit={openEdit}
                        onToggle={requestToggle}
                        permissions={props.permissions}
                    />

                    <CouponsPagination
                        copy={props.adminUi.orders}
                        onPageChange={(page) =>
                            visitCoupons({ ...props.filters, page })
                        }
                        pagination={props.pagination}
                    />
                </article>
            </main>
            <AdminMobileTabBar
                adminUi={props.adminUi}
                current="marketing"
                navigation={props.adminNavigation}
            />

            {/* Create / Edit Dialog */}
            <Dialog
                open={dialogMode !== null}
                onOpenChange={(open) => !open && closeDialog()}
            >
                <DialogContent className="max-w-lg" dir="ltr">
                    <DialogHeader>
                        <DialogTitle>
                            {dialogMode === 'edit'
                                ? copy.editTitle
                                : copy.createTitle}
                        </DialogTitle>
                    </DialogHeader>
                    <CouponForm
                        copy={copy}
                        data={formData}
                        errors={formErrors}
                        onChange={handleFieldChange}
                    />
                    {saveMessage ? (
                        <Alert
                            variant={
                                saveMessage.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                        >
                            <AlertDescription>
                                {saveMessage.text}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <DialogFooter>
                        <Button
                            disabled={saving}
                            onClick={closeDialog}
                            type="button"
                            variant="outline"
                        >
                            {copy.cancelButton}
                        </Button>
                        <Button
                            disabled={saving}
                            onClick={requestSave}
                            type="button"
                        >
                            {saving ? copy.savingButton : copy.saveButton}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Toggle Confirmation Dialog */}
            {toggleCoupon ? (
                <Dialog
                    open={toggleCoupon !== null}
                    onOpenChange={(open) => !open && setToggleCoupon(null)}
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
                        {toggleMessage ? (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {toggleMessage.text}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                        <DialogFooter>
                            <Button
                                disabled={toggling}
                                onClick={() => setToggleCoupon(null)}
                                type="button"
                                variant="outline"
                            >
                                {copy.cancelButton}
                            </Button>
                            <Button
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

            <AdminPasswordConfirmDialog
                open={passwordModalOpen}
                onOpenChange={(open) => {
                    setPasswordModalOpen(open);

                    if (!open) {
                        pendingAction.current = null;
                    }
                }}
                onConfirmed={() => {
                    setPasswordModalOpen(false);
                    pendingAction.current?.();
                    pendingAction.current = null;
                }}
                title={copy.passwordModalTitle}
                description={copy.passwordModalDescription}
                passwordLabel={copy.passwordLabel}
                passwordPlaceholder={copy.passwordPlaceholder}
                confirmButtonText={copy.confirmPasswordButton}
                confirmingButtonText={copy.confirmingPassword}
                cancelButtonText={props.adminUi.common.cancel}
            />
        </div>
    );
}

function CouponsTable({
    copy,
    coupons,
    onEdit,
    onToggle,
    permissions,
}: {
    copy: AdminCouponsPageProps['adminUi']['coupons'];
    coupons: AdminCouponRow[];
    onEdit: (coupon: AdminCouponRow) => void;
    onToggle: (coupon: AdminCouponRow, targetActive: boolean) => void;
    permissions: string[];
}) {
    const canManage = permissions.includes('marketing.manage');

    if (coupons.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                {copy.noCoupons}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        {Object.values(copy.columns).map((label) => (
                            <th
                                className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                key={label}
                            >
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {coupons.map((coupon) => (
                        <tr className="hover:bg-muted/30" key={coupon.id}>
                            <td className="px-4 py-3 font-mono font-semibold">
                                {coupon.code}
                            </td>
                            <td className="px-4 py-3">
                                <Badge variant="outline">
                                    {coupon.discountType === 'percent'
                                        ? copy.typePercentBadge.replace(
                                              ':value',
                                              String(coupon.value),
                                          )
                                        : copy.typeFixedBadge.replace(
                                              ':value',
                                              String(coupon.value / 100),
                                          )}
                                </Badge>
                            </td>
                            <td className="px-4 py-3 text-muted-foreground">
                                {coupon.startsAt === null &&
                                coupon.endsAt === null
                                    ? copy.always
                                    : coupon.endsAt === null
                                      ? copy.from.replace(
                                            ':date',
                                            coupon.startsAt?.slice(0, 10) ?? '',
                                        )
                                      : coupon.startsAt === null
                                        ? copy.until.replace(
                                              ':date',
                                              coupon.endsAt.slice(0, 10),
                                          )
                                        : copy.window
                                              .replace(
                                                  ':from',
                                                  coupon.startsAt.slice(0, 10),
                                              )
                                              .replace(
                                                  ':until',
                                                  coupon.endsAt.slice(0, 10),
                                              )}
                            </td>
                            <td className="px-4 py-3 text-muted-foreground">
                                {coupon.usageLimit === null
                                    ? `${coupon.usedCount} / ${copy.unlimited}`
                                    : copy.usageOf
                                          .replace(
                                              ':used',
                                              String(coupon.usedCount),
                                          )
                                          .replace(
                                              ':limit',
                                              String(coupon.usageLimit),
                                          )}
                            </td>
                            <td className="px-4 py-3">
                                <Badge
                                    variant={
                                        coupon.isActive
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {coupon.isActive
                                        ? copy.active
                                        : copy.inactive}
                                </Badge>
                            </td>
                            <td className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                    {canManage ? (
                                        <>
                                            <Button
                                                className="h-8 text-xs"
                                                onClick={() => onEdit(coupon)}
                                                size="sm"
                                                type="button"
                                                variant="outline"
                                            >
                                                {copy.editButton}
                                            </Button>
                                            <Button
                                                className="h-8 text-xs"
                                                onClick={() =>
                                                    onToggle(
                                                        coupon,
                                                        !coupon.isActive,
                                                    )
                                                }
                                                size="sm"
                                                type="button"
                                                variant={
                                                    coupon.isActive
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                {coupon.isActive
                                                    ? copy.deactivateTitle
                                                    : copy.activateTitle}
                                            </Button>
                                        </>
                                    ) : null}
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function CouponForm({
    copy,
    data,
    errors,
    onChange,
}: {
    copy: AdminCouponsPageProps['adminUi']['coupons'];
    data: CouponFormData;
    errors: Record<string, string>;
    onChange: (field: keyof CouponFormData, value: string | boolean) => void;
}) {
    return (
        <div
            className="flex flex-col gap-4 overflow-y-auto py-2"
            style={{ maxHeight: '60vh' }}
        >
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-code">{copy.codeLabel}</Label>
                <Input
                    id="coupon-code"
                    placeholder={copy.codePlaceholder}
                    value={data.code}
                    onChange={(e) =>
                        onChange('code', e.target.value.toUpperCase())
                    }
                    className="font-mono uppercase"
                    aria-describedby="coupon-code-help"
                />
                <p
                    className="text-xs text-muted-foreground"
                    id="coupon-code-help"
                >
                    {copy.codeHelp}
                </p>
                {errors.code ? (
                    <p className="text-xs text-destructive">{errors.code}</p>
                ) : null}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-type">{copy.typeLabel}</Label>
                <Select
                    value={data.discount_type}
                    onValueChange={(v) => onChange('discount_type', v)}
                >
                    <SelectTrigger id="coupon-type">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="percent">
                            {copy.typePercent}
                        </SelectItem>
                        <SelectItem value="fixed">{copy.typeFixed}</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-value">{copy.valueLabel}</Label>
                <Input
                    id="coupon-value"
                    inputMode="numeric"
                    min="1"
                    type="number"
                    value={data.value}
                    onChange={(e) => onChange('value', e.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                    {data.discount_type === 'percent'
                        ? copy.valuePercentHelp
                        : copy.valueFixedHelp}
                </p>
                {errors.value ? (
                    <p className="text-xs text-destructive">{errors.value}</p>
                ) : null}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-min-order">
                    {copy.minimumOrderLabel}
                </Label>
                <Input
                    id="coupon-min-order"
                    inputMode="numeric"
                    min="0"
                    type="number"
                    value={data.minimum_order_halalah}
                    onChange={(e) =>
                        onChange('minimum_order_halalah', e.target.value)
                    }
                />
                <p className="text-xs text-muted-foreground">
                    {copy.minimumOrderHelp}
                </p>
            </div>

            {data.discount_type === 'percent' ? (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="coupon-max-discount">
                        {copy.maximumDiscountLabel}
                    </Label>
                    <Input
                        id="coupon-max-discount"
                        inputMode="numeric"
                        min="0"
                        type="number"
                        value={data.maximum_discount_halalah}
                        onChange={(e) =>
                            onChange('maximum_discount_halalah', e.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {copy.maximumDiscountHelp}
                    </p>
                </div>
            ) : null}

            <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="coupon-usage-limit">
                        {copy.usageLimitLabel}
                    </Label>
                    <Input
                        id="coupon-usage-limit"
                        inputMode="numeric"
                        min="1"
                        type="number"
                        value={data.usage_limit}
                        onChange={(e) =>
                            onChange('usage_limit', e.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {copy.usageLimitHelp}
                    </p>
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="coupon-per-user-limit">
                        {copy.perUserLimitLabel}
                    </Label>
                    <Input
                        id="coupon-per-user-limit"
                        inputMode="numeric"
                        min="1"
                        type="number"
                        value={data.per_user_limit}
                        onChange={(e) =>
                            onChange('per_user_limit', e.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {copy.perUserLimitHelp}
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="coupon-starts-at">
                        {copy.startsAtLabel}
                    </Label>
                    <Input
                        id="coupon-starts-at"
                        type="date"
                        value={data.starts_at}
                        onChange={(e) => onChange('starts_at', e.target.value)}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="coupon-ends-at">{copy.endsAtLabel}</Label>
                    <Input
                        id="coupon-ends-at"
                        type="date"
                        value={data.ends_at}
                        onChange={(e) => onChange('ends_at', e.target.value)}
                    />
                    {errors.ends_at ? (
                        <p className="text-xs text-destructive">
                            {errors.ends_at}
                        </p>
                    ) : null}
                </div>
            </div>

            <div className="flex items-center gap-3">
                <Checkbox
                    id="coupon-is-active"
                    checked={data.is_active}
                    onCheckedChange={(checked) =>
                        onChange('is_active', checked === true)
                    }
                />
                <Label htmlFor="coupon-is-active">{copy.isActiveLabel}</Label>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-description-ar">
                    {copy.descriptionArLabel}
                </Label>
                <Input
                    id="coupon-description-ar"
                    dir="rtl"
                    value={data.description_ar}
                    onChange={(e) => onChange('description_ar', e.target.value)}
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="coupon-description-en">
                    {copy.descriptionEnLabel}
                </Label>
                <Input
                    id="coupon-description-en"
                    dir="ltr"
                    value={data.description_en}
                    onChange={(e) => onChange('description_en', e.target.value)}
                />
            </div>
        </div>
    );
}

function CouponsPagination({
    copy,
    onPageChange,
    pagination,
}: {
    copy: AdminCouponsPageProps['adminUi']['orders'];
    onPageChange: (page: number) => void;
    pagination: AdminCouponsPageProps['pagination'];
}) {
    if (pagination.lastPage <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
            <span>
                {copy.page} {pagination.currentPage} {copy.of}{' '}
                {pagination.lastPage}
            </span>
            <div className="flex gap-2">
                <Button
                    disabled={pagination.currentPage <= 1}
                    onClick={() => onPageChange(pagination.currentPage - 1)}
                    size="sm"
                    type="button"
                    variant="outline"
                >
                    {copy.previous}
                </Button>
                <Button
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
