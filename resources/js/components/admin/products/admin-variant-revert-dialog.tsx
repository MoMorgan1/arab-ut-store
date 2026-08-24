'use no memo';

import { useHttp } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import React, { useState } from 'react';

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
import { Spinner } from '@/components/ui/spinner';
import type { AdminProductVariant, AdminTranslations } from '@/types/admin';

export type AdminVariantRevertDialogProps = {
    adminUi: AdminTranslations;
    onConflict: (
        variantId: string,
        current: { effectivePriceHalalah: number; priceVersion: number },
    ) => void;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: {
        adminCompletionPricing: null;
        adminPriceHalalah: null;
        effectivePriceHalalah: number;
        hasOverride: boolean;
        priceVersion: number;
        variant: string;
    }) => void;
    open: boolean;
    priceUrl: string;
    variant: AdminProductVariant;
};

type RevertPayload = {
    completion_pricing: null;
    expected_price_version: number;
    price_halalah: null;
};

type RevertResponse = {
    data: {
        effectivePriceHalalah: number;
        hasOverride: boolean;
        priceVersion: number;
        variant: string;
    };
};

export default function AdminVariantRevertDialog({
    adminUi,
    onConflict,
    onOpenChange,
    onSuccess,
    open,
    priceUrl,
    variant,
}: AdminVariantRevertDialogProps) {
    const copy = adminUi.productDetail;
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const http = useHttp<RevertPayload, RevertResponse>('post', priceUrl, {
        completion_pricing: null,
        expected_price_version: variant.priceVersion,
        price_halalah: null,
    });

    const executeRevert = async () => {
        setErrorMessage(null);
        http.setData({
            completion_pricing: null,
            expected_price_version: variant.priceVersion,
            price_halalah: null,
        });

        let handled = false;

        try {
            await http.submit('post', priceUrl, {
                headers: { Accept: 'application/json' },
                onError: (errors) => {
                    handled = true;
                    setErrorMessage(
                        errors.price_halalah ||
                            errors.expected_price_version ||
                            errors.payload ||
                            copy.revertFailed,
                    );
                },
                onHttpException: (response) => {
                    handled = true;

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
                                parseInt(variant.price.amountMinor, 10),
                            priceVersion:
                                body?.current?.priceVersion ??
                                variant.priceVersion + 1,
                        });

                        return false;
                    }

                    if (response.status === 403) {
                        setErrorMessage(copy.forbiddenError);

                        return false;
                    }

                    if (response.status === 422) {
                        const body = response.data as {
                            errors?: Record<string, string>;
                            message?: string;
                        };
                        setErrorMessage(
                            body?.errors?.price_halalah ||
                                body?.message ||
                                copy.revertFailed,
                        );

                        return false;
                    }

                    setErrorMessage(copy.revertFailed);

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setErrorMessage(copy.networkError);

                    return false;
                },
                onSuccess: (response) => {
                    handled = true;
                    onOpenChange(false);
                    setErrorMessage(null);
                    onSuccess({
                        adminCompletionPricing: null,
                        adminPriceHalalah: null,
                        effectivePriceHalalah:
                            response.data.effectivePriceHalalah ??
                            parseInt(variant.price.amountMinor, 10),
                        hasOverride: false,
                        priceVersion:
                            response.data.priceVersion ??
                            variant.priceVersion + 1,
                        variant: response.data.variant || variant.id,
                    });
                },
            });
        } catch {
            if (!handled) {
                setErrorMessage(copy.revertFailed);
            }
        }
    };

    return (
        <>
            <Dialog onOpenChange={onOpenChange} open={open}>
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto sm:max-w-[480px]"
                    onCloseAutoFocus={(e) => e.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>{copy.revertDialogTitle}</DialogTitle>
                        <DialogDescription className="leading-relaxed">
                            {copy.revertDialogDescription.replace(
                                ':sku',
                                variant.sku,
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {errorMessage ? (
                        <Alert role="alert" variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>{copy.revertFailed}</AlertTitle>
                            <AlertDescription>{errorMessage}</AlertDescription>
                        </Alert>
                    ) : null}

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
                            onClick={() => void executeRevert()}
                            type="button"
                            variant="destructive"
                        >
                            {http.processing ? (
                                <>
                                    <Spinner className="size-3.5" />
                                    <span>{copy.revertingToAutomation}</span>
                                </>
                            ) : (
                                <span>{copy.confirmRevertButton}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
