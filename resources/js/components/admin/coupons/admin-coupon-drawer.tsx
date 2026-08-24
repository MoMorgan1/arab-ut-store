'use no memo';

import { router } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState } from 'react';

import {
    formatHalalahToSar,
    parseSarToHalalah,
} from '@/components/admin/admin-money';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { AdminCouponRow, AdminTranslations } from '@/types/admin';

export type CouponFormData = {
    code: string;
    description_ar: string;
    description_en: string;
    discount_type: 'percent' | 'fixed';
    value: string;
    minimum_order_halalah: string;
    maximum_discount_halalah: string;
    usage_limit: string;
    per_user_limit: string;
    scope: 'order' | 'category' | 'product' | 'service';
    service_type: string;
    category_ids: number[];
    product_ids: number[];
    first_order_only: boolean;
    excludes_promoted_items: boolean;
    is_active: boolean;
    starts_at: string;
    ends_at: string;
};

export const emptyCouponForm: CouponFormData = {
    code: '',
    description_ar: '',
    description_en: '',
    discount_type: 'percent',
    value: '',
    minimum_order_halalah: '0.00',
    maximum_discount_halalah: '',
    usage_limit: '',
    per_user_limit: '',
    scope: 'order',
    service_type: 'coins',
    category_ids: [],
    product_ids: [],
    first_order_only: false,
    excludes_promoted_items: false,
    is_active: true,
    starts_at: '',
    ends_at: '',
};

export function couponToFormData(coupon: AdminCouponRow): CouponFormData {
    return {
        code: coupon.code,
        description_ar: coupon.descriptionAr || '',
        description_en: coupon.descriptionEn || '',
        discount_type: coupon.discountType,
        value:
            coupon.discountType === 'fixed'
                ? formatHalalahToSar(coupon.value)
                : String(coupon.value),
        minimum_order_halalah: formatHalalahToSar(coupon.minimumOrderHalalah),
        maximum_discount_halalah:
            coupon.maximumDiscountHalalah !== null
                ? formatHalalahToSar(coupon.maximumDiscountHalalah)
                : '',
        usage_limit:
            coupon.usageLimit !== null ? String(coupon.usageLimit) : '',
        per_user_limit:
            coupon.perUserLimit !== null ? String(coupon.perUserLimit) : '',
        scope: coupon.scope,
        service_type: coupon.serviceType || 'coins',
        category_ids: coupon.categoryIds ? [...coupon.categoryIds] : [],
        product_ids: coupon.productIds ? [...coupon.productIds] : [],
        first_order_only: coupon.firstOrderOnly,
        excludes_promoted_items: coupon.excludesPromotedItems,
        is_active: coupon.isActive,
        starts_at: coupon.startsAt ? coupon.startsAt.slice(0, 10) : '',
        ends_at: coupon.endsAt ? coupon.endsAt.slice(0, 10) : '',
    };
}

export type AdminCouponDrawerProps = {
    adminUi: AdminTranslations;
    categories: Array<{ id: number; publicId: string; name: string }>;
    createUrl: string;
    editingCoupon: AdminCouponRow | null;
    mode: 'create' | 'edit' | null;
    onClose: () => void;
    onSaved?: () => void;
    products: Array<{ id: number; publicId: string; name: string }>;
    serviceTypes: Array<{ value: string; label: string }>;
    updateUrlTemplate: string;
};

export default function AdminCouponDrawer({
    adminUi,
    categories,
    createUrl,
    editingCoupon,
    mode,
    onClose,
    onSaved,
    products,
    serviceTypes,
    updateUrlTemplate,
}: AdminCouponDrawerProps) {
    const copy = adminUi.coupons;
    const [formData, setFormData] = useState<CouponFormData>(() =>
        mode === 'edit' && editingCoupon
            ? couponToFormData(editingCoupon)
            : emptyCouponForm,
    );
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState<{
        type: 'error' | 'success';
        text: string;
    } | null>(null);

    const handleFieldChange = (
        field: keyof CouponFormData,
        value: string | boolean | number[],
    ) => {
        setFormData((prev) => ({ ...prev, [field]: value }));
        setFormErrors((prev) => {
            const next = { ...prev };
            delete next[field];

            return next;
        });
    };

    const toggleCategoryId = (id: number) => {
        setFormData((prev) => {
            const exists = prev.category_ids.includes(id);
            const next = exists
                ? prev.category_ids.filter((cId) => cId !== id)
                : [...prev.category_ids, id];

            return { ...prev, category_ids: next };
        });
    };

    const toggleProductId = (id: number) => {
        setFormData((prev) => {
            const exists = prev.product_ids.includes(id);
            const next = exists
                ? prev.product_ids.filter((pId) => pId !== id)
                : [...prev.product_ids, id];

            return { ...prev, product_ids: next };
        });
    };

    const submitForm = async () => {
        setSaving(true);
        setSaveMessage(null);
        setFormErrors({});

        const isEdit = mode === 'edit' && editingCoupon !== null;
        const targetUrl = isEdit
            ? updateUrlTemplate.replace('__ID__', editingCoupon.id)
            : createUrl;
        const method = isEdit ? 'PUT' : 'POST';

        const payload: Record<string, unknown> = {
            code: formData.code.toUpperCase().trim(),
            description_ar: formData.description_ar
                ? formData.description_ar.trim()
                : null,
            description_en: formData.description_en
                ? formData.description_en.trim()
                : null,
            discount_type: formData.discount_type,
            value:
                formData.discount_type === 'fixed'
                    ? parseSarToHalalah(formData.value)
                    : Number(formData.value),
            minimum_order_halalah: parseSarToHalalah(
                formData.minimum_order_halalah || '0',
            ),
            maximum_discount_halalah:
                formData.discount_type === 'percent' &&
                formData.maximum_discount_halalah
                    ? parseSarToHalalah(formData.maximum_discount_halalah)
                    : null,
            usage_limit: formData.usage_limit
                ? Number(formData.usage_limit)
                : null,
            per_user_limit: formData.per_user_limit
                ? Number(formData.per_user_limit)
                : null,
            scope: formData.scope,
            service_type:
                formData.scope === 'service' ? formData.service_type : null,
            category_ids:
                formData.scope === 'category' ? formData.category_ids : [],
            product_ids:
                formData.scope === 'product' ? formData.product_ids : [],
            first_order_only: formData.first_order_only,
            excludes_promoted_items: formData.excludes_promoted_items,
            is_active: formData.is_active,
            starts_at: formData.starts_at || null,
            ends_at: formData.ends_at || null,
        };

        try {
            const res = await fetch(targetUrl, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
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
            } else if (res.status === 403) {
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
                onClose();

                if (onSaved) {
                    onSaved();
                } else {
                    router.reload();
                }
            }
        } catch {
            setSaveMessage({ type: 'error', text: copy.messages.networkError });
        } finally {
            setSaving(false);
        }
    };

    const isOpen = mode !== null;

    return (
        <>
            <Sheet
                onOpenChange={(open) => !open && !saving && onClose()}
                open={isOpen}
            >
                <SheetContent
                    className="flex max-h-screen w-full flex-col overflow-y-auto motion-reduce:animate-none motion-reduce:transition-none sm:max-w-xl"
                    side="right"
                >
                    <SheetHeader className="border-b border-border pb-4">
                        <SheetTitle className="text-lg font-bold text-foreground">
                            {mode === 'edit'
                                ? copy.editTitle
                                : copy.createTitle}
                        </SheetTitle>
                        <SheetDescription className="text-xs text-muted-foreground">
                            {copy.description}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex flex-1 flex-col gap-6 py-4">
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

                        {/* SECTION 1: BASICS */}
                        <section className="flex flex-col gap-3 rounded-lg border border-border bg-card/60 p-4">
                            <h2 className="text-sm font-semibold text-primary">
                                1. {copy.sectionBasics}
                            </h2>

                            {/* Code */}
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="coupon-code">
                                    {copy.codeLabel}
                                </Label>
                                <Input
                                    aria-describedby="coupon-code-hint"
                                    className="min-h-11 font-mono uppercase"
                                    id="coupon-code"
                                    maxLength={24}
                                    onChange={(e) =>
                                        handleFieldChange(
                                            'code',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    placeholder={copy.codePlaceholder}
                                    value={formData.code}
                                />
                                <p
                                    className="text-xs text-muted-foreground"
                                    id="coupon-code-hint"
                                >
                                    {copy.codeHelp} •{' '}
                                    <span className="font-medium text-primary">
                                        {copy.codeUppercaseHint}
                                    </span>
                                </p>
                                {formErrors.code ? (
                                    <p className="text-xs text-destructive">
                                        {formErrors.code}
                                    </p>
                                ) : null}
                            </div>

                            {/* Descriptions */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-desc-ar">
                                        {copy.descriptionArLabel}
                                    </Label>
                                    <Input
                                        className="min-h-11"
                                        dir="rtl"
                                        id="coupon-desc-ar"
                                        onChange={(e) =>
                                            handleFieldChange(
                                                'description_ar',
                                                e.target.value,
                                            )
                                        }
                                        value={formData.description_ar}
                                    />
                                    {formErrors.description_ar ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.description_ar}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-desc-en">
                                        {copy.descriptionEnLabel}
                                    </Label>
                                    <Input
                                        className="min-h-11"
                                        dir="ltr"
                                        id="coupon-desc-en"
                                        onChange={(e) =>
                                            handleFieldChange(
                                                'description_en',
                                                e.target.value,
                                            )
                                        }
                                        value={formData.description_en}
                                    />
                                    {formErrors.description_en ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.description_en}
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                        </section>

                        {/* SECTION 2: DISCOUNT */}
                        <section className="flex flex-col gap-3 rounded-lg border border-border bg-card/60 p-4">
                            <h2 className="text-sm font-semibold text-primary">
                                2. {copy.sectionDiscount}
                            </h2>

                            {/* Discount Type Segmented Control */}
                            <div className="flex flex-col gap-1.5">
                                <Label>{copy.typeLabel}</Label>
                                <div className="grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            handleFieldChange(
                                                'discount_type',
                                                'percent',
                                            )
                                        }
                                        className={`inline-flex min-h-11 items-center justify-center rounded-md border text-sm font-medium transition-colors ${
                                            formData.discount_type === 'percent'
                                                ? 'border-primary bg-primary/20 font-semibold text-primary'
                                                : 'border-border bg-background text-muted-foreground hover:bg-muted'
                                        }`}
                                    >
                                        {copy.typePercent}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            handleFieldChange(
                                                'discount_type',
                                                'fixed',
                                            )
                                        }
                                        className={`inline-flex min-h-11 items-center justify-center rounded-md border text-sm font-medium transition-colors ${
                                            formData.discount_type === 'fixed'
                                                ? 'border-primary bg-primary/20 font-semibold text-primary'
                                                : 'border-border bg-background text-muted-foreground hover:bg-muted'
                                        }`}
                                    >
                                        {copy.typeFixed}
                                    </button>
                                </div>
                            </div>

                            {/* Value */}
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="coupon-val">
                                    {copy.valueLabel}
                                </Label>
                                <Input
                                    className="min-h-11"
                                    id="coupon-val"
                                    inputMode="decimal"
                                    min="0.01"
                                    onChange={(e) =>
                                        handleFieldChange(
                                            'value',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={
                                        formData.discount_type === 'fixed'
                                            ? '0.00'
                                            : '10'
                                    }
                                    step={
                                        formData.discount_type === 'fixed'
                                            ? '0.01'
                                            : '1'
                                    }
                                    type="number"
                                    value={formData.value}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {formData.discount_type === 'percent'
                                        ? copy.valuePercentHelp
                                        : copy.valueFixedHelp}
                                </p>
                                {formErrors.value ? (
                                    <p className="text-xs text-destructive">
                                        {formErrors.value}
                                    </p>
                                ) : null}
                            </div>

                            {/* Max Discount & Min Order */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {formData.discount_type === 'percent' ? (
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="coupon-max-disc">
                                            {copy.maximumDiscountLabel}
                                        </Label>
                                        <Input
                                            className="min-h-11"
                                            id="coupon-max-disc"
                                            inputMode="decimal"
                                            min="0"
                                            onChange={(e) =>
                                                handleFieldChange(
                                                    'maximum_discount_halalah',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="0.00"
                                            step="0.01"
                                            type="number"
                                            value={
                                                formData.maximum_discount_halalah
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {copy.maximumDiscountHelp}
                                        </p>
                                        {formErrors.maximum_discount_halalah ? (
                                            <p className="text-xs text-destructive">
                                                {
                                                    formErrors.maximum_discount_halalah
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}

                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-min-order">
                                        {copy.minimumOrderLabel}
                                    </Label>
                                    <Input
                                        className="min-h-11"
                                        id="coupon-min-order"
                                        inputMode="decimal"
                                        min="0"
                                        onChange={(e) =>
                                            handleFieldChange(
                                                'minimum_order_halalah',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="0.00"
                                        step="0.01"
                                        type="number"
                                        value={formData.minimum_order_halalah}
                                    />
                                    <div className="flex items-start gap-1.5 pt-0.5 text-xs text-muted-foreground">
                                        <Info
                                            aria-hidden="true"
                                            className="size-3.5 shrink-0 text-primary"
                                        />
                                        <span>
                                            {copy.minimumOrderEligibleHelp}
                                        </span>
                                    </div>
                                    {formErrors.minimum_order_halalah ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.minimum_order_halalah}
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                        </section>

                        {/* SECTION 3: WHAT IT APPLIES TO */}
                        <section className="flex flex-col gap-3 rounded-lg border border-border bg-card/60 p-4">
                            <h2 className="text-sm font-semibold text-primary">
                                3. {copy.sectionAppliesTo}
                            </h2>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="coupon-scope">
                                    {copy.scopeLabel}
                                </Label>
                                <Select
                                    onValueChange={(val) =>
                                        handleFieldChange(
                                            'scope',
                                            val as CouponFormData['scope'],
                                        )
                                    }
                                    value={formData.scope}
                                >
                                    <SelectTrigger
                                        className="min-h-11"
                                        id="coupon-scope"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            className="min-h-11"
                                            value="order"
                                        >
                                            {copy.scopeOrder}
                                        </SelectItem>
                                        <SelectItem
                                            className="min-h-11"
                                            value="service"
                                        >
                                            {copy.scopeService}
                                        </SelectItem>
                                        <SelectItem
                                            className="min-h-11"
                                            value="category"
                                        >
                                            {copy.scopeCategory}
                                        </SelectItem>
                                        <SelectItem
                                            className="min-h-11"
                                            value="product"
                                        >
                                            {copy.scopeProduct}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    {copy.scopeHelp}
                                </p>
                            </div>

                            {/* Category Picker */}
                            {formData.scope === 'category' ? (
                                <div className="flex flex-col gap-2 rounded-md border border-border bg-background p-3">
                                    <span className="text-xs font-medium text-foreground">
                                        {copy.targetCategoriesLabel}
                                    </span>
                                    <div className="flex max-h-48 flex-col gap-2 overflow-y-auto">
                                        {categories.map((cat) => {
                                            const isChecked =
                                                formData.category_ids.includes(
                                                    cat.id,
                                                );

                                            return (
                                                <label
                                                    key={cat.id}
                                                    className="flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-2 hover:bg-muted/40"
                                                >
                                                    <Checkbox
                                                        checked={isChecked}
                                                        className="size-5"
                                                        onCheckedChange={() =>
                                                            toggleCategoryId(
                                                                cat.id,
                                                            )
                                                        }
                                                    />
                                                    <span className="text-sm text-foreground">
                                                        {cat.name}
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                    {formErrors.category_ids ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.category_ids}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            {/* Product Picker */}
                            {formData.scope === 'product' ? (
                                <div className="flex flex-col gap-2 rounded-md border border-border bg-background p-3">
                                    <span className="text-xs font-medium text-foreground">
                                        {copy.targetProductsLabel}
                                    </span>
                                    <div className="flex max-h-48 flex-col gap-2 overflow-y-auto">
                                        {products.map((prod) => {
                                            const isChecked =
                                                formData.product_ids.includes(
                                                    prod.id,
                                                );

                                            return (
                                                <label
                                                    key={prod.id}
                                                    className="flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-2 hover:bg-muted/40"
                                                >
                                                    <Checkbox
                                                        checked={isChecked}
                                                        className="size-5"
                                                        onCheckedChange={() =>
                                                            toggleProductId(
                                                                prod.id,
                                                            )
                                                        }
                                                    />
                                                    <span className="text-sm text-foreground">
                                                        {prod.name}
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                    {formErrors.product_ids ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.product_ids}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            {/* Service Type Picker */}
                            {formData.scope === 'service' ? (
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-service-type">
                                        {copy.serviceTypeLabel}
                                    </Label>
                                    <Select
                                        onValueChange={(val) =>
                                            handleFieldChange(
                                                'service_type',
                                                val,
                                            )
                                        }
                                        value={formData.service_type}
                                    >
                                        <SelectTrigger
                                            className="min-h-11"
                                            id="coupon-service-type"
                                        >
                                            <SelectValue
                                                placeholder={
                                                    copy.serviceTypePlaceholder
                                                }
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {serviceTypes.map((st) => (
                                                <SelectItem
                                                    className="min-h-11"
                                                    key={st.value}
                                                    value={st.value}
                                                >
                                                    {st.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {formErrors.service_type ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.service_type}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}
                        </section>

                        {/* SECTION 4: WHO CAN USE IT */}
                        <section className="flex flex-col gap-4 rounded-lg border border-border bg-card/60 p-4">
                            <h2 className="text-sm font-semibold text-primary">
                                4. {copy.sectionEligibility}
                            </h2>

                            {/* First Order Only */}
                            <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-md hover:bg-muted/30">
                                <Checkbox
                                    checked={formData.first_order_only}
                                    className="mt-0.5 size-5"
                                    id="coupon-first-order"
                                    onCheckedChange={(c) =>
                                        handleFieldChange(
                                            'first_order_only',
                                            c === true,
                                        )
                                    }
                                />
                                <div className="flex flex-col gap-0.5">
                                    <span className="text-sm font-medium text-foreground">
                                        {copy.firstOrderOnlyLabel}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {copy.firstOrderOnlyHelp}
                                    </span>
                                </div>
                            </label>

                            {/* Excludes Promoted Items */}
                            <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-md hover:bg-muted/30">
                                <Checkbox
                                    checked={formData.excludes_promoted_items}
                                    className="mt-0.5 size-5"
                                    id="coupon-exclude-promos"
                                    onCheckedChange={(c) =>
                                        handleFieldChange(
                                            'excludes_promoted_items',
                                            c === true,
                                        )
                                    }
                                />
                                <div className="flex flex-col gap-0.5">
                                    <span className="text-sm font-medium text-foreground">
                                        {copy.excludesPromotedLabel}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {copy.excludesPromotedHelp}
                                    </span>
                                </div>
                            </label>

                            {/* Is Active / Paused */}
                            <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-md hover:bg-muted/30">
                                <Checkbox
                                    checked={formData.is_active}
                                    className="mt-0.5 size-5"
                                    id="coupon-active-status"
                                    onCheckedChange={(c) =>
                                        handleFieldChange(
                                            'is_active',
                                            c === true,
                                        )
                                    }
                                />
                                <div className="flex flex-col gap-0.5">
                                    <span className="text-sm font-medium text-foreground">
                                        {copy.isActiveLabel}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {copy.isPausedHelp}
                                    </span>
                                </div>
                            </label>
                        </section>

                        {/* SECTION 5: LIMITS AND SCHEDULE */}
                        <section className="flex flex-col gap-3 rounded-lg border border-border bg-card/60 p-4">
                            <h2 className="text-sm font-semibold text-primary">
                                5. {copy.sectionLimits}
                            </h2>

                            {/* Limits */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-usage-limit">
                                        {copy.usageLimitLabel}
                                    </Label>
                                    <Input
                                        className="min-h-11"
                                        id="coupon-usage-limit"
                                        inputMode="numeric"
                                        min="1"
                                        onChange={(e) =>
                                            handleFieldChange(
                                                'usage_limit',
                                                e.target.value,
                                            )
                                        }
                                        type="number"
                                        value={formData.usage_limit}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {copy.usageLimitHelp}
                                    </p>
                                    {formErrors.usage_limit ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.usage_limit}
                                        </p>
                                    ) : null}
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-user-limit">
                                        {copy.perUserLimitLabel}
                                    </Label>
                                    <Input
                                        className="min-h-11"
                                        id="coupon-user-limit"
                                        inputMode="numeric"
                                        min="1"
                                        onChange={(e) =>
                                            handleFieldChange(
                                                'per_user_limit',
                                                e.target.value,
                                            )
                                        }
                                        type="number"
                                        value={formData.per_user_limit}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {copy.perUserLimitHelp}
                                    </p>
                                    {formErrors.per_user_limit ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.per_user_limit}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            {/* Mandatory Release copy */}
                            <div className="flex items-start gap-2 rounded-md bg-muted/40 p-2.5 text-xs text-muted-foreground">
                                <Info
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-primary"
                                />
                                <span>
                                    {copy.cancelledReleasesRedemptionHelp}
                                </span>
                            </div>

                            {/* Dates */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-starts">
                                        {copy.startsAtLabel}
                                    </Label>
                                    <Input
                                        className="min-h-11"
                                        id="coupon-starts"
                                        onChange={(e) =>
                                            handleFieldChange(
                                                'starts_at',
                                                e.target.value,
                                            )
                                        }
                                        type="date"
                                        value={formData.starts_at}
                                    />
                                    {formErrors.starts_at ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.starts_at}
                                        </p>
                                    ) : null}
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="coupon-ends">
                                        {copy.endsAtLabel}
                                    </Label>
                                    <Input
                                        className="min-h-11"
                                        id="coupon-ends"
                                        onChange={(e) =>
                                            handleFieldChange(
                                                'ends_at',
                                                e.target.value,
                                            )
                                        }
                                        type="date"
                                        value={formData.ends_at}
                                    />
                                    {formErrors.ends_at ? (
                                        <p className="text-xs text-destructive">
                                            {formErrors.ends_at}
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                        </section>
                    </div>

                    <SheetFooter className="flex flex-row items-center justify-end gap-2 border-t border-border pt-4">
                        <Button
                            className="min-h-11"
                            disabled={saving}
                            onClick={onClose}
                            type="button"
                            variant="outline"
                        >
                            {copy.cancelButton}
                        </Button>
                        <Button
                            className="min-h-11"
                            disabled={saving}
                            onClick={submitForm}
                            type="button"
                        >
                            <span>
                                {saving ? copy.savingButton : copy.saveButton}
                            </span>
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        </>
    );
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
