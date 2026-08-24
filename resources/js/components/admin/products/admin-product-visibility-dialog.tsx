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
import type { AdminProductDetail, AdminTranslations } from '@/types/admin';

export type AdminProductVisibilityDialogProps = {
    adminUi: AdminTranslations;
    onConflict: (currentHidden: boolean) => void;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: { adminHidden: boolean }) => void;
    open: boolean;
    product: AdminProductDetail;
    visibilityUrl: string;
};

type VisibilityPayload = {
    expected_hidden: boolean;
    hidden: boolean;
};

type VisibilityResponse = {
    data: {
        adminHidden: boolean;
        product: string;
    };
};

export default function AdminProductVisibilityDialog({
    adminUi,
    onConflict,
    onOpenChange,
    onSuccess,
    open,
    product,
    visibilityUrl,
}: AdminProductVisibilityDialogProps) {
    const copy = adminUi.productDetail;
    const isCurrentlyHidden = Boolean(product.adminHidden);
    const targetHidden = !isCurrentlyHidden;

    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const http = useHttp<VisibilityPayload, VisibilityResponse>(
        'post',
        visibilityUrl,
        {
            expected_hidden: isCurrentlyHidden,
            hidden: targetHidden,
        },
    );

    const title = targetHidden ? copy.hideDialogTitle : copy.restoreDialogTitle;
    const description = targetHidden
        ? copy.hideDialogDescription
        : copy.restoreDialogDescription;
    const confirmButtonText = targetHidden
        ? copy.confirmHideButton
        : copy.confirmRestoreButton;
    const processingButtonText = targetHidden
        ? copy.hidingFromStore
        : copy.restoringToStore;

    const executeVisibilityChange = async () => {
        setErrorMessage(null);
        http.setData({
            expected_hidden: isCurrentlyHidden,
            hidden: targetHidden,
        });

        let handled = false;

        try {
            await http.submit('post', visibilityUrl, {
                headers: { Accept: 'application/json' },
                onError: (errors) => {
                    handled = true;
                    setErrorMessage(
                        errors.hidden ||
                            errors.expected_hidden ||
                            errors.payload ||
                            copy.visibilityUpdateFailed,
                    );
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 409) {
                        const body =
                            typeof response.data === 'string'
                                ? (JSON.parse(response.data) as {
                                      current?: { adminHidden?: boolean };
                                  })
                                : (response.data as {
                                      current?: { adminHidden?: boolean };
                                  });
                        onOpenChange(false);
                        onConflict(
                            body?.current?.adminHidden ?? !isCurrentlyHidden,
                        );

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
                        const firstError =
                            body?.errors?.hidden ||
                            body?.errors?.expected_hidden ||
                            body?.message ||
                            copy.visibilityUpdateFailed;
                        setErrorMessage(firstError);

                        return false;
                    }

                    setErrorMessage(copy.visibilityUpdateFailed);

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
                    onSuccess(response.data);
                },
            });
        } catch {
            if (!handled) {
                setErrorMessage(copy.visibilityUpdateFailed);
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
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription className="leading-relaxed">
                            {description}
                        </DialogDescription>
                    </DialogHeader>

                    {errorMessage ? (
                        <Alert role="alert" variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>
                                {copy.visibilityUpdateFailed}
                            </AlertTitle>
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
                            onClick={() => void executeVisibilityChange()}
                            type="button"
                            variant={targetHidden ? 'destructive' : 'default'}
                        >
                            {http.processing ? (
                                <>
                                    <Spinner className="size-3.5" />
                                    <span>{processingButtonText}</span>
                                </>
                            ) : (
                                <span>{confirmButtonText}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
