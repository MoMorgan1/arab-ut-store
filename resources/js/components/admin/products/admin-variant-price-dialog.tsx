'use no memo';

import { useHttp } from '@inertiajs/react';
import { AlertCircle, AlertTriangle } from 'lucide-react';
import React, { useState } from 'react';

import { formatAdminMoney } from '@/components/admin/admin-money';
import AdminPasswordConfirmDialog from '@/components/admin/admin-password-confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Spinner } from '@/components/ui/spinner';
import type {
    AdminProductVariant,
    AdminTranslations,
    SbcCompletionPricing,
    SbcCompletionPricingTier,
} from '@/types/admin';

export type AdminVariantPriceDialogProps = {
    adminUi: AdminTranslations;
    confirmPasswordUrl?: string;
    locale: 'ar' | 'en';
    onConflict: (
        variantId: string,
        current: { effectivePriceHalalah: number; priceVersion: number },
    ) => void;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: {
        adminCompletionPricing: SbcCompletionPricing | null;
        adminPriceHalalah: number | null;
        effectivePriceHalalah: number;
        hasOverride: boolean;
        priceVersion: number;
        variant: string;
    }) => void;
    open: boolean;
    priceUrl: string;
    variant: AdminProductVariant;
};

type PricePayload = {
    completion_pricing: SbcCompletionPricing | null;
    expected_price_version: number;
    price_halalah: number | null;
};

type PriceResponse = {
    data: {
        effectivePriceHalalah: number;
        hasOverride: boolean;
        priceVersion: number;
        variant: string;
    };
};

type TierInputState = {
    completions: number;
    multiplierBps: number;
    totalMinor: string;
};

export function getVariantCompletionPricing(
    variant: AdminProductVariant,
): SbcCompletionPricing | null {
    if (
        variant.adminCompletionPricing &&
        Array.isArray(variant.adminCompletionPricing.tiers) &&
        variant.adminCompletionPricing.tiers.length > 0
    ) {
        return variant.adminCompletionPricing;
    }

    if (
        variant.configuration &&
        typeof variant.configuration === 'object' &&
        'completionPricing' in variant.configuration
    ) {
        const cp = variant.configuration
            .completionPricing as SbcCompletionPricing | null;

        if (cp && Array.isArray(cp.tiers) && cp.tiers.length > 0) {
            return cp;
        }
    }

    return null;
}

export default function AdminVariantPriceDialog({
    adminUi,
    confirmPasswordUrl,
    locale,
    onConflict,
    onOpenChange,
    onSuccess,
    open,
    priceUrl,
    variant,
}: AdminVariantPriceDialogProps) {
    const copy = adminUi.productDetail;
    const declaredPricing = getVariantCompletionPricing(variant);
    const hasTiers = declaredPricing !== null;

    // Pre-fill initial halalah price
    const initialHalalah =
        variant.adminPriceHalalah !== undefined &&
        variant.adminPriceHalalah !== null
            ? String(variant.adminPriceHalalah)
            : variant.salePrice
              ? variant.salePrice.amountMinor
              : variant.price.amountMinor;

    const [singlePriceHalalah, setSinglePriceHalalah] =
        useState(initialHalalah);
    // Seeded at mount rather than reseeded in an effect: the page keys this
    // dialog by variant and price version, so a new variant or a reprice
    // remounts it with fresh values instead of cascading a render.
    const [tiersState, setTiersState] = useState<TierInputState[]>(() =>
        declaredPricing
            ? declaredPricing.tiers.map((tier) => ({
                  completions: tier.completions,
                  multiplierBps: tier.multiplierBps,
                  totalMinor: String(tier.totalMinor),
              }))
            : [],
    );
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [tierTableError, setTierTableError] = useState<string | null>(null);
    const [passwordConfirmOpen, setPasswordConfirmOpen] = useState(false);

    const http = useHttp<PricePayload, PriceResponse>('post', priceUrl, {
        completion_pricing: declaredPricing,
        expected_price_version: variant.priceVersion,
        price_halalah: parseInt(initialHalalah, 10) || null,
    });

    const handleTierChange = (index: number, value: string) => {
        setTierTableError(null);
        setTiersState((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], totalMinor: value };

            return next;
        });
    };

    const executePriceOverride = async () => {
        setGeneralError(null);
        setTierTableError(null);

        let finalPriceHalalah: number;
        let finalCompletionPricing: SbcCompletionPricing | null = null;

        if (hasTiers && declaredPricing) {
            if (tiersState.length === 0) {
                setTierTableError(copy.priceOverrideFailed);

                return;
            }

            const parsedTiers: SbcCompletionPricingTier[] = [];

            for (let i = 0; i < tiersState.length; i++) {
                const parsedVal = parseInt(tiersState[i].totalMinor, 10);

                if (Number.isNaN(parsedVal) || parsedVal <= 0) {
                    setTierTableError(copy.positivePriceRequired);

                    return;
                }

                parsedTiers.push({
                    completions: tiersState[i].completions,
                    multiplierBps: tiersState[i].multiplierBps,
                    totalMinor: parsedVal,
                });
            }

            finalPriceHalalah = parsedTiers[0].totalMinor;
            finalCompletionPricing = {
                maximum: declaredPricing.maximum,
                repeatable: declaredPricing.repeatable,
                tiers: parsedTiers,
                version: 1,
            };
        } else {
            const parsed = parseInt(singlePriceHalalah, 10);

            if (Number.isNaN(parsed) || parsed <= 0) {
                setGeneralError(copy.positivePriceRequired);

                return;
            }

            finalPriceHalalah = parsed;
            finalCompletionPricing = null;
        }

        http.setData({
            completion_pricing: finalCompletionPricing,
            expected_price_version: variant.priceVersion,
            price_halalah: finalPriceHalalah,
        });

        let handled = false;

        try {
            await http.submit('post', priceUrl, {
                headers: { Accept: 'application/json' },
                onError: (errors) => {
                    handled = true;

                    if (errors.completion_pricing) {
                        setTierTableError(errors.completion_pricing);
                    } else {
                        setGeneralError(
                            errors.price_halalah ||
                                errors.expected_price_version ||
                                errors.payload ||
                                copy.priceOverrideFailed,
                        );
                    }
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 423) {
                        setPasswordConfirmOpen(true);

                        return false;
                    }

                    if (response.status === 409) {
                        const body =
                            typeof response.data === 'string'
                                ? (JSON.parse(response.data) as {
                                      current?: {
                                          effectivePriceHalalah?: number;
                                          priceVersion?: number;
                                      };
                                  })
                                : (response.data as {
                                      current?: {
                                          effectivePriceHalalah?: number;
                                          priceVersion?: number;
                                      };
                                  });
                        onOpenChange(false);
                        onConflict(variant.id, {
                            effectivePriceHalalah:
                                body?.current?.effectivePriceHalalah ??
                                finalPriceHalalah,
                            priceVersion:
                                body?.current?.priceVersion ??
                                variant.priceVersion + 1,
                        });

                        return false;
                    }

                    if (response.status === 403) {
                        setGeneralError(copy.forbiddenError);

                        return false;
                    }

                    if (response.status === 422) {
                        const body =
                            typeof response.data === 'string'
                                ? (JSON.parse(response.data) as {
                                      errors?: Record<
                                          string,
                                          string[] | string
                                      >;
                                      message?: string;
                                  })
                                : (response.data as {
                                      errors?: Record<
                                          string,
                                          string[] | string
                                      >;
                                      message?: string;
                                  });

                        const completionError =
                            body?.errors?.completion_pricing;

                        if (completionError) {
                            setTierTableError(
                                Array.isArray(completionError)
                                    ? completionError[0]
                                    : completionError,
                            );

                            return false;
                        }

                        const priceError = body?.errors?.price_halalah;

                        if (priceError) {
                            setGeneralError(
                                Array.isArray(priceError)
                                    ? priceError[0]
                                    : priceError,
                            );

                            return false;
                        }

                        setGeneralError(
                            body?.message || copy.priceOverrideFailed,
                        );

                        return false;
                    }

                    setGeneralError(copy.priceOverrideFailed);

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setGeneralError(copy.networkError);

                    return false;
                },
                onSuccess: (response) => {
                    handled = true;
                    onOpenChange(false);
                    setGeneralError(null);
                    setTierTableError(null);
                    onSuccess({
                        adminCompletionPricing: finalCompletionPricing,
                        adminPriceHalalah: finalPriceHalalah,
                        effectivePriceHalalah:
                            response.data.effectivePriceHalalah ??
                            finalPriceHalalah,
                        hasOverride: response.data.hasOverride ?? true,
                        priceVersion:
                            response.data.priceVersion ??
                            variant.priceVersion + 1,
                        variant: response.data.variant || variant.id,
                    });
                },
            });
        } catch {
            if (!handled) {
                setGeneralError(copy.priceOverrideFailed);
            }
        }
    };

    const handlePasswordConfirmed = () => {
        setPasswordConfirmOpen(false);
        void executePriceOverride();
    };

    return (
        <>
            <Dialog onOpenChange={onOpenChange} open={open}>
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto sm:max-w-[560px]"
                    onCloseAutoFocus={(e) => e.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {copy.priceOverrideDialogTitle}
                        </DialogTitle>
                        <DialogDescription>
                            {copy.priceOverrideDialogDescription}
                        </DialogDescription>
                    </DialogHeader>

                    {/* Reprice warning alert */}
                    <Alert className="border-amber-500/30 bg-amber-500/10 text-foreground">
                        <AlertTriangle className="size-4 text-amber-600 dark:text-amber-400" />
                        <AlertDescription className="text-xs leading-relaxed text-muted-foreground">
                            {copy.repriceWarning}
                        </AlertDescription>
                    </Alert>

                    {generalError ? (
                        <Alert role="alert" variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>{copy.priceOverrideFailed}</AlertTitle>
                            <AlertDescription>{generalError}</AlertDescription>
                        </Alert>
                    ) : null}

                    {hasTiers ? (
                        /* SBC Completion Pricing Tier Table */
                        <div className="flex flex-col gap-4 py-2">
                            <div className="flex flex-col gap-1">
                                <h3 className="text-xs font-bold tracking-wider text-foreground uppercase">
                                    {copy.tierTableTitle}
                                </h3>
                                <p className="text-[11px] text-muted-foreground">
                                    {copy.tierTableDescription}
                                </p>
                            </div>

                            {tierTableError ? (
                                <div
                                    className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-xs text-destructive"
                                    role="alert"
                                >
                                    {tierTableError}
                                </div>
                            ) : null}

                            <div className="flex flex-col divide-y divide-border/60 rounded-lg border border-border bg-card p-3 shadow-xs">
                                {tiersState.map((tier, idx) => {
                                    const parsedHalalah =
                                        parseInt(tier.totalMinor, 10) || 0;
                                    const formattedEquivalent =
                                        formatAdminMoney(
                                            {
                                                amountMinor:
                                                    String(parsedHalalah),
                                                currency: 'SAR',
                                            },
                                            locale,
                                        );

                                    return (
                                        <div
                                            className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                                            key={tier.completions}
                                        >
                                            <div className="flex flex-col gap-0.5">
                                                <span className="text-xs font-semibold text-foreground">
                                                    {copy.tierCountLabel.replace(
                                                        ':count',
                                                        String(
                                                            tier.completions,
                                                        ),
                                                    )}
                                                </span>
                                                <span className="text-[11px] text-muted-foreground tabular-nums">
                                                    {tier.multiplierBps / 100}%
                                                </span>
                                            </div>

                                            <div className="flex flex-1 flex-col gap-1 sm:max-w-[260px]">
                                                <div className="flex items-center gap-2">
                                                    <Input
                                                        aria-label={`${copy.tierTotalHalalah} ${tier.completions}`}
                                                        className="min-h-11 min-w-11 text-xs tabular-nums"
                                                        disabled={
                                                            http.processing
                                                        }
                                                        id={`tier-total-${tier.completions}`}
                                                        inputMode="numeric"
                                                        min={1}
                                                        onChange={(e) =>
                                                            handleTierChange(
                                                                idx,
                                                                e.target.value,
                                                            )
                                                        }
                                                        type="number"
                                                        value={tier.totalMinor}
                                                    />
                                                </div>
                                                <span className="text-[11px] text-muted-foreground tabular-nums">
                                                    ≈ {formattedEquivalent}
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    ) : (
                        /* Single Price Field for Non-Tiered Variant */
                        <div className="flex flex-col gap-4 py-2">
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="variant-price-halalah"
                                >
                                    {copy.priceHalalahLabel}{' '}
                                    <span
                                        aria-hidden="true"
                                        className="text-destructive"
                                    >
                                        *
                                    </span>
                                </Label>
                                <Input
                                    aria-describedby="variant-price-halalah-help"
                                    className="min-h-11 min-w-11 text-sm tabular-nums md:text-xs"
                                    disabled={http.processing}
                                    id="variant-price-halalah"
                                    inputMode="numeric"
                                    min={1}
                                    onChange={(e) =>
                                        setSinglePriceHalalah(e.target.value)
                                    }
                                    required
                                    type="number"
                                    value={singlePriceHalalah}
                                />
                                <p
                                    className="text-[11px] text-muted-foreground tabular-nums"
                                    id="variant-price-halalah-help"
                                >
                                    {copy.priceHalalahHelp
                                        .replace(
                                            ':halalah',
                                            singlePriceHalalah || '0',
                                        )
                                        .replace(
                                            ':sar',
                                            formatAdminMoney(
                                                {
                                                    amountMinor:
                                                        singlePriceHalalah ||
                                                        '0',
                                                    currency: 'SAR',
                                                },
                                                locale,
                                            ),
                                        )}
                                </p>
                            </div>
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                className="min-h-11 min-w-11 text-sm md:text-xs"
                                disabled={http.processing}
                                type="button"
                                variant="outline"
                            >
                                {copy.cancelButton}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11 min-w-11 text-sm md:text-xs"
                            disabled={http.processing}
                            onClick={() => void executePriceOverride()}
                            type="button"
                        >
                            {http.processing ? (
                                <>
                                    <Spinner className="size-3.5" />
                                    <span>{copy.savingOverrideButton}</span>
                                </>
                            ) : (
                                <span>{copy.saveOverrideButton}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AdminPasswordConfirmDialog
                cancelButtonText={adminUi.common.cancel}
                confirmButtonText={copy.confirmPasswordButton}
                confirmPasswordUrl={confirmPasswordUrl}
                confirmingButtonText={copy.confirmingPassword}
                description={copy.passwordModalDescription}
                invalidPasswordText={copy.invalidPassword}
                onConfirmed={handlePasswordConfirmed}
                onOpenChange={setPasswordConfirmOpen}
                open={passwordConfirmOpen}
                passwordLabel={copy.passwordLabel}
                passwordPlaceholder={copy.passwordPlaceholder}
                title={copy.passwordModalTitle}
            />
        </>
    );
}
