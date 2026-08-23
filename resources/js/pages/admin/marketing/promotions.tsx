'use no memo'; // TanStack Table's mutable instances are not React Compiler compatible.

import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

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
    AdminPromotionRow,
    AdminPromotionsPageProps,
    AdminPromotionsQueryState,
} from '@/types/admin';

type PromotionFormData = {
    name_ar: string;
    name_en: string;
    badge_ar: string;
    badge_en: string;
    scope: 'all' | 'category' | 'service';
    category: string;
    service_type: string;
    discount_type: 'percent' | 'fixed';
    value: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
};

const serviceTypes = [
    'coins',
    'sbc',
    'objectives',
    'rivals',
    'fut_champions',
] as const;

const emptyForm: PromotionFormData = {
    name_ar: '',
    name_en: '',
    badge_ar: '',
    badge_en: '',
    scope: 'all',
    category: '',
    service_type: '',
    discount_type: 'percent',
    value: '',
    starts_at: '',
    ends_at: '',
    is_active: true,
};

function promotionToForm(promotion: AdminPromotionRow): PromotionFormData {
    return {
        name_ar: promotion.nameAr,
        name_en: promotion.nameEn,
        badge_ar: promotion.badgeAr ?? '',
        badge_en: promotion.badgeEn ?? '',
        scope: promotion.scope,
        category:
            promotion.scope === 'category' ? (promotion.categoryId ?? '') : '',
        service_type: promotion.serviceType ?? '',
        discount_type: promotion.discountType,
        value: String(promotion.value),
        starts_at: promotion.startsAt ? promotion.startsAt.slice(0, 10) : '',
        ends_at: promotion.endsAt ? promotion.endsAt.slice(0, 10) : '',
        is_active: promotion.isActive,
    };
}

export default function AdminPromotionsPage() {
    const { props, url } = usePage<AdminPromotionsPageProps>();
    const copy = props.adminUi.promotions;
    const pathname = new URL(url, window.location.origin).pathname;

    const [dialogMode, setDialogMode] = useState<'create' | 'edit' | null>(
        null,
    );
    const [editingPromotion, setEditingPromotion] =
        useState<AdminPromotionRow | null>(null);
    const [formData, setFormData] = useState<PromotionFormData>(emptyForm);
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    const [togglePromotion, setTogglePromotion] =
        useState<AdminPromotionRow | null>(null);
    const [toggleTargetActive, setToggleTargetActive] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [toggleMessage, setToggleMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    const [passwordModalOpen, setPasswordModalOpen] = useState(false);
    const pendingAction = useRef<(() => void) | null>(null);

    const visitPromotions = useCallback(
        (filters: AdminPromotionsQueryState) => {
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
        setEditingPromotion(null);
        setDialogMode('create');
    }

    function openEdit(promotion: AdminPromotionRow) {
        setFormData(promotionToForm(promotion));
        setFormErrors({});
        setSaveMessage(null);
        setEditingPromotion(promotion);
        setDialogMode('edit');
    }

    function closeDialog() {
        if (saving) {
            return;
        }

        setDialogMode(null);
    }

    function handleFieldChange(
        field: keyof PromotionFormData,
        value: string | boolean,
    ) {
        setFormData((prev) => ({
            ...prev,
            ...(field === 'scope' ? { category: '', service_type: '' } : {}),
            [field]: value,
        }));
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

        const isEdit = dialogMode === 'edit' && editingPromotion !== null;
        const url = isEdit
            ? `/admin/api/marketing/promotions/${editingPromotion.id}`
            : '/admin/api/marketing/promotions';
        const method = isEdit ? 'PUT' : 'POST';

        const payload: Record<string, unknown> = {
            name_ar: formData.name_ar,
            name_en: formData.name_en,
            badge_ar: formData.badge_ar || null,
            badge_en: formData.badge_en || null,
            scope: formData.scope,
            category:
                formData.scope === 'category'
                    ? formData.category || null
                    : null,
            service_type:
                formData.scope === 'service'
                    ? formData.service_type || null
                    : null,
            discount_type: formData.discount_type,
            value: Number(formData.value),
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
                router.reload({
                    only: ['promotions', 'pagination', 'counts'],
                });
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

    function requestToggle(
        promotion: AdminPromotionRow,
        targetActive: boolean,
    ) {
        setTogglePromotion(promotion);
        setToggleTargetActive(targetActive);
        setToggleMessage(null);
    }

    async function confirmToggle() {
        if (!togglePromotion) {
            return;
        }

        const execute = async () => {
            setToggling(true);
            setToggleMessage(null);

            try {
                const res = await fetch(
                    `/admin/api/marketing/promotions/${togglePromotion.id}/status`,
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
                    setTogglePromotion(null);
                    router.reload({
                        only: ['promotions', 'pagination', 'counts'],
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
                current="marketingPromotions"
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

                    <PromotionsTable
                        copy={copy}
                        onEdit={openEdit}
                        onToggle={requestToggle}
                        permissions={props.permissions}
                        promotions={props.promotions}
                    />

                    <PromotionsPagination
                        copy={props.adminUi.orders}
                        onPageChange={(page) =>
                            visitPromotions({ ...props.filters, page })
                        }
                        pagination={props.pagination}
                    />
                </article>
            </main>
            <AdminMobileTabBar
                adminUi={props.adminUi}
                current="marketingPromotions"
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
                    <PromotionForm
                        categories={props.categories}
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
            {togglePromotion ? (
                <Dialog
                    open={togglePromotion !== null}
                    onOpenChange={(open) => !open && setTogglePromotion(null)}
                >
                    <DialogContent dir="ltr">
                        <DialogHeader>
                            <DialogTitle>
                                {toggleTargetActive
                                    ? copy.activateTitle
                                    : copy.deactivateTitle}
                            </DialogTitle>
                            <DialogDescription>
                                {(toggleTargetActive
                                    ? copy.activateDescription
                                    : copy.deactivateDescription
                                ).replace(':name', togglePromotion.nameEn)}
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
                                onClick={() => setTogglePromotion(null)}
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

function scopeLabel(
    promotion: AdminPromotionRow,
    copy: AdminPromotionsPageProps['adminUi']['promotions'],
): string {
    if (promotion.scope === 'category') {
        return copy.scopeCategoryBadge.replace(
            ':category',
            promotion.categoryName ?? '—',
        );
    }

    if (promotion.scope === 'service') {
        return copy.scopeServiceBadge.replace(
            ':service',
            promotion.serviceType ?? '—',
        );
    }

    return copy.scopeAllBadge;
}

function PromotionsTable({
    copy,
    promotions,
    onEdit,
    onToggle,
    permissions,
}: {
    copy: AdminPromotionsPageProps['adminUi']['promotions'];
    promotions: AdminPromotionRow[];
    onEdit: (promotion: AdminPromotionRow) => void;
    onToggle: (promotion: AdminPromotionRow, targetActive: boolean) => void;
    permissions: string[];
}) {
    const canManage = permissions.includes('marketing.manage');

    if (promotions.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                {copy.noPromotions}
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
                    {promotions.map((promotion) => (
                        <tr className="hover:bg-muted/30" key={promotion.id}>
                            <td className="px-4 py-3">
                                <span className="font-semibold">
                                    {promotion.nameEn}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {promotion.nameAr}
                                </span>
                            </td>
                            <td className="px-4 py-3">
                                <Badge variant="outline">
                                    {scopeLabel(promotion, copy)}
                                </Badge>
                            </td>
                            <td className="px-4 py-3">
                                <Badge variant="outline">
                                    {promotion.discountType === 'percent'
                                        ? copy.typePercentBadge.replace(
                                              ':value',
                                              String(promotion.value),
                                          )
                                        : copy.typeFixedBadge.replace(
                                              ':value',
                                              String(promotion.value / 100),
                                          )}
                                </Badge>
                            </td>
                            <td className="px-4 py-3 text-muted-foreground">
                                {promotion.startsAt === null &&
                                promotion.endsAt === null
                                    ? copy.always
                                    : promotion.endsAt === null
                                      ? copy.from.replace(
                                            ':date',
                                            promotion.startsAt?.slice(0, 10) ??
                                                '',
                                        )
                                      : promotion.startsAt === null
                                        ? copy.until.replace(
                                              ':date',
                                              promotion.endsAt.slice(0, 10),
                                          )
                                        : copy.window
                                              .replace(
                                                  ':from',
                                                  promotion.startsAt.slice(
                                                      0,
                                                      10,
                                                  ),
                                              )
                                              .replace(
                                                  ':until',
                                                  promotion.endsAt.slice(0, 10),
                                              )}
                            </td>
                            <td className="px-4 py-3">
                                <Badge
                                    variant={
                                        promotion.isActive
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {promotion.isActive
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
                                                onClick={() =>
                                                    onEdit(promotion)
                                                }
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
                                                        promotion,
                                                        !promotion.isActive,
                                                    )
                                                }
                                                size="sm"
                                                type="button"
                                                variant={
                                                    promotion.isActive
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                {promotion.isActive
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

function PromotionForm({
    categories,
    copy,
    data,
    errors,
    onChange,
}: {
    categories: AdminPromotionsPageProps['categories'];
    copy: AdminPromotionsPageProps['adminUi']['promotions'];
    data: PromotionFormData;
    errors: Record<string, string>;
    onChange: (field: keyof PromotionFormData, value: string | boolean) => void;
}) {
    return (
        <div
            className="flex flex-col gap-4 overflow-y-auto py-2"
            style={{ maxHeight: '60vh' }}
        >
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="promotion-name-en">{copy.nameEnLabel}</Label>
                <Input
                    id="promotion-name-en"
                    placeholder={copy.nameEnPlaceholder}
                    value={data.name_en}
                    onChange={(e) => onChange('name_en', e.target.value)}
                />
                {errors.name_en ? (
                    <p className="text-xs text-destructive">{errors.name_en}</p>
                ) : null}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="promotion-name-ar">{copy.nameArLabel}</Label>
                <Input
                    id="promotion-name-ar"
                    dir="rtl"
                    placeholder={copy.nameArPlaceholder}
                    value={data.name_ar}
                    onChange={(e) => onChange('name_ar', e.target.value)}
                />
                {errors.name_ar ? (
                    <p className="text-xs text-destructive">{errors.name_ar}</p>
                ) : null}
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="promotion-badge-ar">
                        {copy.badgeArLabel}
                    </Label>
                    <Input
                        id="promotion-badge-ar"
                        dir="rtl"
                        maxLength={24}
                        placeholder={copy.badgeArPlaceholder}
                        value={data.badge_ar}
                        onChange={(e) => onChange('badge_ar', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        {copy.badgeArHelp}
                    </p>
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="promotion-badge-en">
                        {copy.badgeEnLabel}
                    </Label>
                    <Input
                        id="promotion-badge-en"
                        dir="ltr"
                        maxLength={24}
                        placeholder={copy.badgeEnPlaceholder}
                        value={data.badge_en}
                        onChange={(e) => onChange('badge_en', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        {copy.badgeEnHelp}
                    </p>
                </div>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="promotion-scope">{copy.scopeLabel}</Label>
                <Select
                    value={data.scope}
                    onValueChange={(v) => onChange('scope', v)}
                >
                    <SelectTrigger id="promotion-scope">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{copy.scopeAll}</SelectItem>
                        <SelectItem value="category">
                            {copy.scopeCategory}
                        </SelectItem>
                        <SelectItem value="service">
                            {copy.scopeService}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            {data.scope === 'category' ? (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="promotion-category">
                        {copy.categoryLabel}
                    </Label>
                    <Select
                        value={data.category}
                        onValueChange={(v) => onChange('category', v)}
                    >
                        <SelectTrigger id="promotion-category">
                            <SelectValue
                                placeholder={copy.categoryPlaceholder}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {categories.map((category) => (
                                <SelectItem
                                    key={category.id}
                                    value={category.id}
                                >
                                    {category.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.category ? (
                        <p className="text-xs text-destructive">
                            {errors.category}
                        </p>
                    ) : null}
                </div>
            ) : null}

            {data.scope === 'service' ? (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="promotion-service-type">
                        {copy.serviceTypeLabel}
                    </Label>
                    <Select
                        value={data.service_type}
                        onValueChange={(v) => onChange('service_type', v)}
                    >
                        <SelectTrigger id="promotion-service-type">
                            <SelectValue
                                placeholder={copy.serviceTypePlaceholder}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {serviceTypes.map((serviceType) => (
                                <SelectItem
                                    key={serviceType}
                                    value={serviceType}
                                >
                                    {serviceType}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.service_type ? (
                        <p className="text-xs text-destructive">
                            {errors.service_type}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="promotion-type">{copy.typeLabel}</Label>
                <Select
                    value={data.discount_type}
                    onValueChange={(v) => onChange('discount_type', v)}
                >
                    <SelectTrigger id="promotion-type">
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
                <Label htmlFor="promotion-value">{copy.valueLabel}</Label>
                <Input
                    id="promotion-value"
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

            <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="promotion-starts-at">
                        {copy.startsAtLabel}
                    </Label>
                    <Input
                        id="promotion-starts-at"
                        type="date"
                        value={data.starts_at}
                        onChange={(e) => onChange('starts_at', e.target.value)}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="promotion-ends-at">
                        {copy.endsAtLabel}
                    </Label>
                    <Input
                        id="promotion-ends-at"
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
                    id="promotion-is-active"
                    checked={data.is_active}
                    onCheckedChange={(checked) =>
                        onChange('is_active', checked === true)
                    }
                />
                <Label htmlFor="promotion-is-active">
                    {copy.isActiveLabel}
                </Label>
            </div>
        </div>
    );
}

function PromotionsPagination({
    copy,
    onPageChange,
    pagination,
}: {
    copy: AdminPromotionsPageProps['adminUi']['orders'];
    onPageChange: (page: number) => void;
    pagination: AdminPromotionsPageProps['pagination'];
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
