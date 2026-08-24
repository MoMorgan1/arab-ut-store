'use no memo';

import { useHttp } from '@inertiajs/react';
import React, { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Spinner } from '@/components/ui/spinner';
import type { AdminProductDetail, AdminTranslations } from '@/types/admin';

export type AdminProductEditDialogProps = {
    adminUi: AdminTranslations;
    onConflict: () => void;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: {
        descriptionAr: string | null;
        descriptionEn: string | null;
        isVisible: boolean;
        nameAr: string;
        nameEn: string;
        sortOrder: number;
        updatedAt: string;
    }) => void;
    open: boolean;
    product: AdminProductDetail;
    updateUrl: string;
};

type ProductExpectation = {
    description_ar: string | null;
    description_en: string | null;
    is_visible: boolean;
    name_ar: string;
    name_en: string;
    sort_order: number;
};

type ProductPayload = ProductExpectation & {
    expected: ProductExpectation;
};

type ProductResponse = {
    data: {
        descriptionAr: string | null;
        descriptionEn: string | null;
        isVisible: boolean;
        nameAr: string;
        nameEn: string;
        sortOrder: number;
        updatedAt: string;
    };
};

type FieldErrors = {
    description_ar?: string;
    description_en?: string;
    expected?: string;
    general?: string;
    is_visible?: string;
    name_ar?: string;
    name_en?: string;
    sort_order?: string;
    unexpected_fields?: string;
};

export default function AdminProductEditDialog({
    adminUi,
    onConflict,
    onOpenChange,
    onSuccess,
    open,
    product,
    updateUrl,
}: AdminProductEditDialogProps) {
    const copy = adminUi.productDetail;
    const [nameAr, setNameAr] = useState(product.nameAr);
    const [nameEn, setNameEn] = useState(product.nameEn);
    const [descriptionAr, setDescriptionAr] = useState(
        product.descriptionAr ?? '',
    );
    const [descriptionEn, setDescriptionEn] = useState(
        product.descriptionEn ?? '',
    );
    const [isVisible, setIsVisible] = useState(product.isVisible);
    const [sortOrder, setSortOrder] = useState(String(product.sortOrder));
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

    // Reseed the form when the dialog opens.
    const [prevOpen, setPrevOpen] = useState(open);

    if (open !== prevOpen) {
        setPrevOpen(open);

        if (open) {
            setNameAr(product.nameAr);
            setNameEn(product.nameEn);
            setDescriptionAr(product.descriptionAr ?? '');
            setDescriptionEn(product.descriptionEn ?? '');
            setIsVisible(product.isVisible);
            setSortOrder(String(product.sortOrder));
            setFieldErrors({});
        }
    }

    const expectation = (): ProductExpectation => ({
        description_ar: product.descriptionAr,
        description_en: product.descriptionEn,
        is_visible: product.isVisible,
        name_ar: product.nameAr,
        name_en: product.nameEn,
        sort_order: product.sortOrder,
    });

    const http = useHttp<ProductPayload, ProductResponse>('post', updateUrl, {
        ...expectation(),
        expected: expectation(),
    });

    const parsedSortOrder = Number.parseInt(sortOrder, 10);
    const validSortOrder = Number.isNaN(parsedSortOrder) ? 0 : parsedSortOrder;

    const isChanged =
        nameAr.trim() !== product.nameAr ||
        nameEn.trim() !== product.nameEn ||
        (descriptionAr.trim() === '' ? null : descriptionAr.trim()) !==
            product.descriptionAr ||
        (descriptionEn.trim() === '' ? null : descriptionEn.trim()) !==
            product.descriptionEn ||
        isVisible !== product.isVisible ||
        validSortOrder !== product.sortOrder;

    const executeProductUpdate = async () => {
        const trimmedNameAr = nameAr.trim();
        const trimmedNameEn = nameEn.trim();
        const trimmedDescAr =
            descriptionAr.trim() === '' ? null : descriptionAr.trim();
        const trimmedDescEn =
            descriptionEn.trim() === '' ? null : descriptionEn.trim();

        const errors: FieldErrors = {};

        if (!trimmedNameAr) {
            errors.name_ar = 'Arabic name is required.';
        }

        if (!trimmedNameEn) {
            errors.name_en = 'English name is required.';
        }

        if (Number.isNaN(parsedSortOrder) || parsedSortOrder < 0) {
            errors.sort_order = 'Sort order must be a non-negative number.';
        }

        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);

            return;
        }

        setFieldErrors({});
        const payload: ProductPayload = {
            description_ar: trimmedDescAr,
            description_en: trimmedDescEn,
            expected: expectation(),
            is_visible: isVisible,
            name_ar: trimmedNameAr,
            name_en: trimmedNameEn,
            sort_order: validSortOrder,
        };

        http.setData(payload);

        let handled = false;

        try {
            await http.submit('post', updateUrl, {
                headers: { Accept: 'application/json' },
                onError: (validationErrors) => {
                    handled = true;
                    setFieldErrors({
                        description_ar: validationErrors.description_ar,
                        description_en: validationErrors.description_en,
                        expected: validationErrors.expected,
                        general:
                            validationErrors.unexpected_fields ||
                            validationErrors.expected ||
                            copy.updateFailed,
                        is_visible: validationErrors.is_visible,
                        name_ar: validationErrors.name_ar,
                        name_en: validationErrors.name_en,
                        sort_order: validationErrors.sort_order,
                    });
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 409) {
                        onOpenChange(false);
                        onConflict();

                        return false;
                    }

                    if (response.status === 422) {
                        const resData = response.data as {
                            errors?: Record<string, string>;
                            message?: string;
                            reason?: string;
                        };

                        if (resData?.reason === 'product_not_editable') {
                            setFieldErrors({
                                general: copy.notEditableError,
                            });

                            return false;
                        }

                        const resErrors = resData?.errors ?? {};
                        setFieldErrors({
                            description_ar: resErrors.description_ar,
                            description_en: resErrors.description_en,
                            expected: resErrors.expected,
                            general:
                                resErrors.unexpected_fields ||
                                resErrors.expected ||
                                resData?.message ||
                                copy.updateFailed,
                            is_visible: resErrors.is_visible,
                            name_ar: resErrors.name_ar,
                            name_en: resErrors.name_en,
                            sort_order: resErrors.sort_order,
                        });

                        return false;
                    }

                    if (response.status === 403) {
                        setFieldErrors({ general: copy.forbiddenError });

                        return false;
                    }

                    setFieldErrors({ general: copy.updateFailed });

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFieldErrors({ general: copy.networkError });

                    return false;
                },
                onSuccess: (response) => {
                    handled = true;
                    onOpenChange(false);
                    setFieldErrors({});
                    onSuccess(response.data);
                },
            });
        } catch {
            if (!handled) {
                setFieldErrors({ general: copy.updateFailed });
            }
        }
    };

    return (
        <>
            <Dialog onOpenChange={onOpenChange} open={open}>
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto sm:max-w-[540px]"
                    onCloseAutoFocus={(e) => e.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>{copy.editTitle}</DialogTitle>
                        <DialogDescription>
                            {copy.editDescription}
                        </DialogDescription>
                    </DialogHeader>

                    {fieldErrors.general ? (
                        <div
                            aria-live="polite"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-xs text-destructive"
                            role="alert"
                        >
                            {fieldErrors.general}
                        </div>
                    ) : null}

                    <div className="grid gap-4 py-2">
                        <div className="grid gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="product-name-ar"
                            >
                                {copy.nameArLabel}{' '}
                                <span
                                    aria-hidden="true"
                                    className="text-destructive"
                                >
                                    *
                                </span>
                            </Label>
                            <Input
                                aria-describedby={
                                    fieldErrors.name_ar
                                        ? 'product-name-ar-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(fieldErrors.name_ar)}
                                className="min-h-11 text-sm md:text-xs"
                                disabled={http.processing}
                                id="product-name-ar"
                                onChange={(e) => setNameAr(e.target.value)}
                                required
                                value={nameAr}
                            />
                            {fieldErrors.name_ar ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="product-name-ar-error"
                                >
                                    {fieldErrors.name_ar}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="product-name-en"
                            >
                                {copy.nameEnLabel}{' '}
                                <span
                                    aria-hidden="true"
                                    className="text-destructive"
                                >
                                    *
                                </span>
                            </Label>
                            <Input
                                aria-describedby={
                                    fieldErrors.name_en
                                        ? 'product-name-en-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(fieldErrors.name_en)}
                                className="min-h-11 text-sm md:text-xs"
                                disabled={http.processing}
                                id="product-name-en"
                                onChange={(e) => setNameEn(e.target.value)}
                                required
                                value={nameEn}
                            />
                            {fieldErrors.name_en ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="product-name-en-error"
                                >
                                    {fieldErrors.name_en}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="product-desc-ar"
                            >
                                {copy.descriptionArLabel}
                            </Label>
                            <textarea
                                aria-describedby={
                                    fieldErrors.description_ar
                                        ? 'product-desc-ar-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(
                                    fieldErrors.description_ar,
                                )}
                                className="flex min-h-[76px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-xs"
                                disabled={http.processing}
                                id="product-desc-ar"
                                onChange={(e) =>
                                    setDescriptionAr(e.target.value)
                                }
                                rows={3}
                                value={descriptionAr}
                            />
                            {fieldErrors.description_ar ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="product-desc-ar-error"
                                >
                                    {fieldErrors.description_ar}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="product-desc-en"
                            >
                                {copy.descriptionEnLabel}
                            </Label>
                            <textarea
                                aria-describedby={
                                    fieldErrors.description_en
                                        ? 'product-desc-en-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(
                                    fieldErrors.description_en,
                                )}
                                className="flex min-h-[76px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-xs"
                                disabled={http.processing}
                                id="product-desc-en"
                                onChange={(e) =>
                                    setDescriptionEn(e.target.value)
                                }
                                rows={3}
                                value={descriptionEn}
                            />
                            {fieldErrors.description_en ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="product-desc-en-error"
                                >
                                    {fieldErrors.description_en}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="product-sort-order"
                            >
                                {copy.sortOrderLabel}
                            </Label>
                            <Input
                                aria-describedby="product-sort-order-help"
                                aria-invalid={Boolean(fieldErrors.sort_order)}
                                className="min-h-11 text-sm md:text-xs"
                                disabled={http.processing}
                                id="product-sort-order"
                                min={0}
                                onChange={(e) => setSortOrder(e.target.value)}
                                type="number"
                                value={sortOrder}
                            />
                            <p
                                className="text-[11px] text-muted-foreground"
                                id="product-sort-order-help"
                            >
                                {copy.sortOrderHelp}
                            </p>
                            {fieldErrors.sort_order ? (
                                <p className="text-xs text-destructive">
                                    {fieldErrors.sort_order}
                                </p>
                            ) : null}
                        </div>

                        <div className="flex items-start gap-3 rounded-md border border-border/80 bg-muted/20 p-3">
                            <label
                                className="-ms-2 -mt-2 flex min-h-11 min-w-11 cursor-pointer items-center justify-center"
                                htmlFor="product-is-visible"
                            >
                                <Checkbox
                                    checked={isVisible}
                                    disabled={http.processing}
                                    id="product-is-visible"
                                    onCheckedChange={(checked) =>
                                        setIsVisible(Boolean(checked))
                                    }
                                />
                            </label>
                            <div className="grid gap-0.5">
                                <Label
                                    className="cursor-pointer text-xs font-semibold"
                                    htmlFor="product-is-visible"
                                >
                                    {copy.isVisibleLabel}
                                </Label>
                                <p className="text-[11px] text-muted-foreground">
                                    {copy.isVisibleHelp}
                                </p>
                            </div>
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                className="min-h-11 text-sm md:text-xs"
                                disabled={http.processing}
                                type="button"
                                variant="outline"
                            >
                                {copy.cancelButton}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11 text-sm md:text-xs"
                            disabled={!isChanged || http.processing}
                            onClick={executeProductUpdate}
                            type="button"
                        >
                            {http.processing ? (
                                <>
                                    <Spinner className="size-3.5" />
                                    <span>{copy.savingButton}</span>
                                </>
                            ) : (
                                <span>{copy.saveButton}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
