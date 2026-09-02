'use no memo';

import { useHttp } from '@inertiajs/react';
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
import type { AdminFaqRow, AdminTranslations } from '@/types/admin';

export type AdminFaqDeleteDialogProps = {
    copy: NonNullable<AdminTranslations['faq']>;
    deleteUrlTemplate: string;
    entry: AdminFaqRow | null;
    onOpenChange: (open: boolean) => void;
    onSuccess: (message: string) => void;
    open: boolean;
};

type DeleteResponse = {
    data: {
        deleted: boolean;
        id: string;
    };
};

export default function AdminFaqDeleteDialog({
    copy,
    deleteUrlTemplate,
    entry,
    onOpenChange,
    onSuccess,
    open,
}: AdminFaqDeleteDialogProps) {
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const deleteUrl = entry
        ? deleteUrlTemplate.replace('__ID__', entry.id)
        : '';

    const http = useHttp<Record<string, never>, DeleteResponse>(
        'delete',
        deleteUrl,
        {},
    );

    if (!entry) {
        return null;
    }

    const description = copy.deleteDialogDescription.replace(
        ':question',
        entry.questionAr,
    );

    const handleDelete = async () => {
        if (!deleteUrl) {
            return;
        }

        setErrorMessage(null);
        let handled = false;

        try {
            await http.submit('delete', deleteUrl, {
                headers: { Accept: 'application/json' },
                onHttpException: () => {
                    handled = true;
                    setErrorMessage(copy.errorTitle);

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setErrorMessage(copy.errorTitle);

                    return false;
                },
                onSuccess: () => {
                    handled = true;
                    onOpenChange(false);
                    onSuccess(copy.deletedMessage);
                },
            });
        } catch {
            if (!handled) {
                setErrorMessage(copy.errorTitle);
            }
        }
    };

    return (
        <Dialog onOpenChange={onOpenChange} open={open}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{copy.deleteDialogTitle}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                {errorMessage ? (
                    <Alert variant="destructive">
                        <AlertTitle>{copy.errorTitle}</AlertTitle>
                        <AlertDescription>{errorMessage}</AlertDescription>
                    </Alert>
                ) : null}

                <DialogFooter className="gap-2 sm:gap-0">
                    <DialogClose asChild>
                        <Button
                            disabled={http.processing}
                            type="button"
                            variant="outline"
                        >
                            {copy.cancelButton}
                        </Button>
                    </DialogClose>
                    <Button
                        disabled={http.processing}
                        onClick={handleDelete}
                        type="button"
                        variant="destructive"
                    >
                        {http.processing ? (
                            <>
                                <Spinner className="size-4" />
                                <span>{copy.deleting}</span>
                            </>
                        ) : (
                            <span>{copy.confirmDelete}</span>
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
