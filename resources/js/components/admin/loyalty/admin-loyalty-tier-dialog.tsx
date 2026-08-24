'use no memo';

import { useHttp } from '@inertiajs/react';
import React, { useState } from 'react';

import {
    formatHalalahToSar,
    parseSarToHalalah,
} from '@/components/admin/admin-money';
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
import type { AdminLoyaltyTier, AdminTranslations } from '@/types/admin';

export type AdminLoyaltyTierDialogProps = {
    adminUi: AdminTranslations;
    onOpenChange: (open: boolean) => void;
    onSuccess: (updatedTier: AdminLoyaltyTier) => void;
    open: boolean;
    tier: AdminLoyaltyTier | null;
    updateTierUrlTemplate: string;
};

type UpdateTierPayload = {
    cashback_basis_points: number;
    is_active: boolean;
    minimum_lifetime_spend_halalah: number;
    name_ar: string;
    name_en: string;
};

type UpdateTierResponse = {
    data: AdminLoyaltyTier;
};

type FieldErrors = {
    cashback_basis_points?: string;
    general?: string;
    is_active?: string;
    minimum_lifetime_spend_halalah?: string;
    name_ar?: string;
    name_en?: string;
};

export default function AdminLoyaltyTierDialog({
    adminUi,
    onOpenChange,
    onSuccess,
    open,
    tier,
    updateTierUrlTemplate,
}: AdminLoyaltyTierDialogProps) {
    const copy = adminUi.loyalty.editDialog;
    const validationCopy = adminUi.loyalty.validation;

    const [nameAr, setNameAr] = useState(tier?.nameAr ?? '');
    const [nameEn, setNameEn] = useState(tier?.nameEn ?? '');
    const [thresholdSar, setThresholdSar] = useState(
        tier
            ? formatHalalahToSar(tier.minimumLifetimeSpend.amountMinor)
            : '0.00',
    );
    const [cashbackPercent, setCashbackPercent] = useState(
        tier ? (tier.cashbackBasisPoints / 100).toString() : '0',
    );
    const [isActive, setIsActive] = useState(tier?.isActive ?? true);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

    const [prevOpen, setPrevOpen] = useState(open);
    const [prevTierId, setPrevTierId] = useState(tier?.id);

    if (open !== prevOpen || tier?.id !== prevTierId) {
        setPrevOpen(open);
        setPrevTierId(tier?.id);

        if (open && tier) {
            setNameAr(tier.nameAr);
            setNameEn(tier.nameEn);
            setThresholdSar(
                formatHalalahToSar(tier.minimumLifetimeSpend.amountMinor),
            );
            setCashbackPercent((tier.cashbackBasisPoints / 100).toString());
            setIsActive(tier.isActive);
            setFieldErrors({});
        }
    }

    const updateUrl = tier
        ? updateTierUrlTemplate.replace('__ID__', tier.id)
        : '';

    const http = useHttp<UpdateTierPayload, UpdateTierResponse>(
        'put',
        updateUrl,
        {
            cashback_basis_points: tier?.cashbackBasisPoints ?? 0,
            is_active: tier?.isActive ?? true,
            minimum_lifetime_spend_halalah: tier
                ? Number(tier.minimumLifetimeSpend.amountMinor)
                : 0,
            name_ar: tier?.nameAr ?? '',
            name_en: tier?.nameEn ?? '',
        },
    );

    if (!tier) {
        return null;
    }

    const isRankOne = tier.rank === 1;

    const executeTierUpdate = async () => {
        const trimmedNameAr = nameAr.trim();
        const trimmedNameEn = nameEn.trim();
        const parsedSpendSar = parseFloat(thresholdSar);
        const parsedCashbackRate = parseFloat(cashbackPercent);

        const errors: FieldErrors = {};

        if (
            !trimmedNameAr ||
            trimmedNameAr.length < 2 ||
            trimmedNameAr.length > 40
        ) {
            errors.name_ar = 'Arabic name must be between 2 and 40 characters.';
        }

        if (
            !trimmedNameEn ||
            trimmedNameEn.length < 2 ||
            trimmedNameEn.length > 40
        ) {
            errors.name_en =
                'English name must be between 2 and 40 characters.';
        }

        if (isNaN(parsedSpendSar) || parsedSpendSar < 0) {
            errors.minimum_lifetime_spend_halalah =
                'Spend threshold must be 0 SAR or greater.';
        }

        if (isRankOne && parsedSpendSar !== 0) {
            errors.minimum_lifetime_spend_halalah = validationCopy.rankOneZero;
        }

        if (
            isNaN(parsedCashbackRate) ||
            parsedCashbackRate < 0 ||
            parsedCashbackRate > 20
        ) {
            errors.cashback_basis_points =
                'Cashback rate must be between 0% and 20% (0–2000 basis points).';
        }

        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);

            return;
        }

        setFieldErrors({});

        const spendHalalah = isRankOne ? 0 : parseSarToHalalah(thresholdSar);
        const basisPoints = Math.round(parsedCashbackRate * 100);

        const payload: UpdateTierPayload = {
            cashback_basis_points: basisPoints,
            is_active: isActive,
            minimum_lifetime_spend_halalah: spendHalalah,
            name_ar: trimmedNameAr,
            name_en: trimmedNameEn,
        };

        http.setData(payload);

        let handled = false;

        try {
            await http.submit('put', updateUrl, {
                headers: { Accept: 'application/json' },
                onError: (validationErrors) => {
                    handled = true;
                    setFieldErrors({
                        cashback_basis_points:
                            validationErrors.cashback_basis_points,
                        general:
                            validationErrors.unexpected_fields ||
                            copy.updateFailed,
                        is_active: validationErrors.is_active,
                        minimum_lifetime_spend_halalah:
                            validationErrors.minimum_lifetime_spend_halalah,
                        name_ar: validationErrors.name_ar,
                        name_en: validationErrors.name_en,
                    });
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 422) {
                        const resErrors =
                            (
                                response.data as {
                                    errors?: Record<string, string>;
                                }
                            )?.errors ?? {};

                        setFieldErrors({
                            cashback_basis_points:
                                resErrors.cashback_basis_points,
                            general:
                                resErrors.unexpected_fields ||
                                copy.updateFailed,
                            is_active: resErrors.is_active,
                            minimum_lifetime_spend_halalah:
                                resErrors.minimum_lifetime_spend_halalah,
                            name_ar: resErrors.name_ar,
                            name_en: resErrors.name_en,
                        });

                        return false;
                    }

                    setFieldErrors({ general: copy.updateFailed });

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFieldErrors({ general: copy.updateFailed });

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
            // Handled in callbacks
        }

        if (!handled && !http.processing) {
            setFieldErrors({ general: copy.updateFailed });
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        void executeTierUpdate();
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && !http.processing) {
            setFieldErrors({});
            onOpenChange(false);
        }
    };

    const parsedBp = isNaN(parseFloat(cashbackPercent))
        ? 0
        : Math.round(parseFloat(cashbackPercent) * 100);

    return (
        <>
            <Dialog onOpenChange={handleOpenChange} open={open}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {copy.title.replace(':name', tier.nameEn)}
                        </DialogTitle>
                        <DialogDescription>
                            {copy.description}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="flex flex-col gap-4"
                        onSubmit={handleSubmit}
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="admin-loyalty-name-ar"
                                >
                                    {copy.nameArLabel}{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    aria-describedby={
                                        fieldErrors.name_ar
                                            ? 'admin-loyalty-name-ar-error'
                                            : undefined
                                    }
                                    aria-invalid={!!fieldErrors.name_ar}
                                    className="min-h-11 text-xs"
                                    disabled={http.processing}
                                    dir="rtl"
                                    id="admin-loyalty-name-ar"
                                    maxLength={40}
                                    onChange={(e) => {
                                        setNameAr(e.target.value);
                                        setFieldErrors((prev) => ({
                                            ...prev,
                                            name_ar: undefined,
                                        }));
                                    }}
                                    required
                                    value={nameAr}
                                />
                                {fieldErrors.name_ar ? (
                                    <p
                                        className="text-xs font-medium text-destructive"
                                        id="admin-loyalty-name-ar-error"
                                        role="alert"
                                    >
                                        {fieldErrors.name_ar}
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="admin-loyalty-name-en"
                                >
                                    {copy.nameEnLabel}{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    aria-describedby={
                                        fieldErrors.name_en
                                            ? 'admin-loyalty-name-en-error'
                                            : undefined
                                    }
                                    aria-invalid={!!fieldErrors.name_en}
                                    className="min-h-11 text-xs"
                                    disabled={http.processing}
                                    dir="ltr"
                                    id="admin-loyalty-name-en"
                                    maxLength={40}
                                    onChange={(e) => {
                                        setNameEn(e.target.value);
                                        setFieldErrors((prev) => ({
                                            ...prev,
                                            name_en: undefined,
                                        }));
                                    }}
                                    required
                                    value={nameEn}
                                />
                                {fieldErrors.name_en ? (
                                    <p
                                        className="text-xs font-medium text-destructive"
                                        id="admin-loyalty-name-en-error"
                                        role="alert"
                                    >
                                        {fieldErrors.name_en}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="admin-loyalty-threshold"
                                >
                                    {copy.thresholdLabel}{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    aria-describedby={
                                        fieldErrors.minimum_lifetime_spend_halalah
                                            ? 'admin-loyalty-threshold-error'
                                            : undefined
                                    }
                                    aria-invalid={
                                        !!fieldErrors.minimum_lifetime_spend_halalah
                                    }
                                    className="min-h-11 text-xs tabular-nums"
                                    disabled={http.processing || isRankOne}
                                    id="admin-loyalty-threshold"
                                    min="0"
                                    onChange={(e) => {
                                        setThresholdSar(e.target.value);
                                        setFieldErrors((prev) => ({
                                            ...prev,
                                            minimum_lifetime_spend_halalah:
                                                undefined,
                                        }));
                                    }}
                                    required
                                    step="0.01"
                                    type="number"
                                    value={thresholdSar}
                                />
                                {fieldErrors.minimum_lifetime_spend_halalah ? (
                                    <p
                                        className="text-xs font-medium text-destructive"
                                        id="admin-loyalty-threshold-error"
                                        role="alert"
                                    >
                                        {
                                            fieldErrors.minimum_lifetime_spend_halalah
                                        }
                                    </p>
                                ) : isRankOne ? (
                                    <p className="text-[11px] text-muted-foreground">
                                        {validationCopy.rankOneZero}
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="admin-loyalty-cashback"
                                >
                                    {copy.cashbackLabel}{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    aria-describedby={
                                        fieldErrors.cashback_basis_points
                                            ? 'admin-loyalty-cashback-error'
                                            : 'admin-loyalty-cashback-help'
                                    }
                                    aria-invalid={
                                        !!fieldErrors.cashback_basis_points
                                    }
                                    className="min-h-11 text-xs tabular-nums"
                                    disabled={http.processing}
                                    id="admin-loyalty-cashback"
                                    max="20"
                                    min="0"
                                    onChange={(e) => {
                                        setCashbackPercent(e.target.value);
                                        setFieldErrors((prev) => ({
                                            ...prev,
                                            cashback_basis_points: undefined,
                                        }));
                                    }}
                                    required
                                    step="0.1"
                                    type="number"
                                    value={cashbackPercent}
                                />
                                {fieldErrors.cashback_basis_points ? (
                                    <p
                                        className="text-xs font-medium text-destructive"
                                        id="admin-loyalty-cashback-error"
                                        role="alert"
                                    >
                                        {fieldErrors.cashback_basis_points}
                                    </p>
                                ) : (
                                    <p
                                        className="text-[11px] text-muted-foreground"
                                        id="admin-loyalty-cashback-help"
                                    >
                                        {copy.cashbackBpHelp
                                            .replace(':bp', parsedBp.toString())
                                            .replace(
                                                ':percent',
                                                `${cashbackPercent}%`,
                                            )}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-start gap-3 rounded-lg border border-border bg-card/60 p-3">
                            <Checkbox
                                checked={isActive}
                                disabled={http.processing}
                                id="admin-loyalty-active"
                                onCheckedChange={(checked) =>
                                    setIsActive(checked === true)
                                }
                            />
                            <div className="flex flex-col gap-0.5">
                                <Label
                                    className="cursor-pointer text-xs font-semibold text-foreground"
                                    htmlFor="admin-loyalty-active"
                                >
                                    {copy.activeLabel}
                                </Label>
                                <p className="text-[11px] text-muted-foreground">
                                    {copy.activeHelp}
                                </p>
                            </div>
                        </div>

                        {fieldErrors.general ? (
                            <p
                                className="text-xs font-medium text-destructive"
                                role="alert"
                            >
                                {fieldErrors.general}
                            </p>
                        ) : null}

                        <DialogFooter className="gap-2 sm:gap-0">
                            <DialogClose asChild>
                                <Button
                                    className="min-h-11"
                                    disabled={http.processing}
                                    type="button"
                                    variant="outline"
                                >
                                    {copy.cancelButton}
                                </Button>
                            </DialogClose>
                            <Button
                                className="min-h-11 gap-2"
                                disabled={http.processing}
                                type="submit"
                                variant="default"
                            >
                                {http.processing ? (
                                    <>
                                        <Spinner />
                                        <span>{copy.savingButton}</span>
                                    </>
                                ) : (
                                    <span>{copy.saveButton}</span>
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
