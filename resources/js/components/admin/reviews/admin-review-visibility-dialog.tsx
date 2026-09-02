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
import type { AdminReviewRow, AdminTranslations } from '@/types/admin';

export type AdminReviewVisibilityDialogProps = {
    adminUi: AdminTranslations;
    onConflict: (currentVisible: boolean) => void;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: { review: string; visible: boolean }) => void;
    open: boolean;
    review: AdminReviewRow | null;
    visibilityUrlTemplate: string;
};

type VisibilityPayload = {
    expectedVisible: boolean;
    visible: boolean;
};

type VisibilityResponse = {
    data: {
        review: string;
        visible: boolean;
    };
};

export default function AdminReviewVisibilityDialog({
    adminUi,
    onConflict,
    onOpenChange,
    onSuccess,
    open,
    review,
    visibilityUrlTemplate,
}: AdminReviewVisibilityDialogProps) {
    const copy = adminUi.reviews;
    const isCurrentlyVisible = Boolean(review?.isVisible);
    const targetVisible = !isCurrentlyVisible;

    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const visibilityUrl = review
        ? visibilityUrlTemplate.replace('__ID__', review.id)
        : '';

    const http = useHttp<VisibilityPayload, VisibilityResponse>(
        'post',
        visibilityUrl,
        {
            expectedVisible: isCurrentlyVisible,
            visible: targetVisible,
        },
    );

    if (!review) {
        return null;
    }

    const title = targetVisible ? copy.showDialogTitle : copy.hideDialogTitle;
    const description = targetVisible
        ? copy.showDialogDescription
        : copy.hideDialogDescription;
    const confirmButtonText = targetVisible
        ? copy.showInStore
        : copy.hideFromStore;
    const processingButtonText = targetVisible
        ? copy.showingInStore
        : copy.hidingFromStore;

    const executeVisibilityChange = async () => {
        if (!visibilityUrl) {
            return;
        }

        setErrorMessage(null);
        http.setData({
            expectedVisible: isCurrentlyVisible,
            visible: targetVisible,
        });

        let handled = false;

        try {
            await http.submit('post', visibilityUrl, {
                headers: { Accept: 'application/json' },
                onError: (errors) => {
                    handled = true;
                    setErrorMessage(
                        errors.visible ||
                            errors.expectedVisible ||
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
                                      current?: { visible?: boolean };
                                  })
                                : (response.data as {
                                      current?: { visible?: boolean };
                                  });
                        onOpenChange(false);
                        onConflict(
                            body?.current?.visible ?? !isCurrentlyVisible,
                        );

                        return false;
                    }

                    if (response.status === 422) {
                        const body = response.data as {
                            errors?: Record<string, string>;
                            message?: string;
                        };
                        setErrorMessage(
                            body?.errors?.visible ||
                                body?.errors?.expectedVisible ||
                                body?.message ||
                                copy.visibilityUpdateFailed,
                        );

                        return false;
                    }

                    setErrorMessage(copy.visibilityUpdateFailed);

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setErrorMessage(copy.loadFailed);

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
        <Dialog onOpenChange={onOpenChange} open={open}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-[480px]"
                onCloseAutoFocus={(event) => event.preventDefault()}
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
                        <AlertTitle>{copy.errorTitle}</AlertTitle>
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
                        variant={targetVisible ? 'default' : 'destructive'}
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
    );
}
