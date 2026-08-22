'use no memo';

import { useHttp } from '@inertiajs/react';
import React, { useState } from 'react';

import AdminPasswordConfirmDialog from '@/components/admin/admin-password-confirm-dialog';
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
import type { AdminCustomerDetail, AdminTranslations } from '@/types/admin';

export type AdminCustomerStatusDialogProps = {
    action: 'suspend' | 'reactivate';
    adminUi: AdminTranslations;
    confirmPasswordUrl?: string;
    customer: AdminCustomerDetail;
    onConflict: (currentActive: boolean) => void;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: { isActive: boolean; updatedAt: string }) => void;
    open: boolean;
    statusUrl: string;
};

type StatusPayload = {
    action: 'suspend' | 'reactivate';
    case_reference: string | null;
    expected_active: boolean;
    reason_code: string;
};

type StatusResponse = {
    data: {
        isActive: boolean;
        updatedAt: string;
    };
};

const REASON_CODES = [
    'fraud_suspected',
    'chargeback',
    'abuse',
    'customer_request',
    'account_recovery',
    'other_reviewed',
] as const;

export default function AdminCustomerStatusDialog({
    action,
    adminUi,
    confirmPasswordUrl,
    customer,
    onConflict,
    onOpenChange,
    onSuccess,
    open,
    statusUrl,
}: AdminCustomerStatusDialogProps) {
    const copy = adminUi.customerDetail;
    const [reasonCode, setReasonCode] = useState<string>('');
    const [caseReference, setCaseReference] = useState<string>('');
    const [fieldError, setFieldError] = useState<string | null>(null);
    const [passwordConfirmOpen, setPasswordConfirmOpen] = useState(false);

    const http = useHttp<StatusPayload, StatusResponse>('post', statusUrl, {
        action,
        case_reference: null,
        expected_active: customer.isActive,
        reason_code: '',
    });

    const isSuspend = action === 'suspend';
    const title = isSuspend ? copy.suspendTitle : copy.reactivateTitle;
    const description = isSuspend
        ? copy.suspendConsequence
        : copy.reactivateConsequence;
    const confirmButtonText = isSuspend
        ? copy.confirmSuspend
        : copy.confirmReactivate;
    const processingText = isSuspend ? copy.suspending : copy.reactivating;

    const executeStatusUpdate = async () => {
        if (!reasonCode) {
            setFieldError(copy.reasonRequired);

            return;
        }

        const trimmedCaseRef = caseReference.trim() || null;

        if (trimmedCaseRef && !/^[A-Za-z0-9._:-]{1,64}$/.test(trimmedCaseRef)) {
            setFieldError(copy.caseReferenceHelp);

            return;
        }

        setFieldError(null);
        http.setData({
            action,
            case_reference: trimmedCaseRef,
            expected_active: customer.isActive,
            reason_code: reasonCode,
        });

        let handled = false;

        try {
            await http.submit('post', statusUrl, {
                headers: { Accept: 'application/json' },
                onError: (errors) => {
                    handled = true;
                    setFieldError(
                        errors.reason_code ||
                            errors.case_reference ||
                            errors.unexpected_fields ||
                            copy.updateFailed,
                    );
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
                                      isActive?: boolean;
                                  })
                                : (response.data as { isActive?: boolean });
                        onOpenChange(false);
                        onConflict(body.isActive ?? !customer.isActive);

                        return false;
                    }

                    if (response.status === 403) {
                        setFieldError(copy.forbiddenError);

                        return false;
                    }

                    setFieldError(copy.updateFailed);

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFieldError(copy.networkError);

                    return false;
                },
                onSuccess: (response) => {
                    handled = true;
                    onOpenChange(false);
                    setReasonCode('');
                    setCaseReference('');
                    setFieldError(null);
                    onSuccess(response.data);
                },
            });
        } catch {
            // Handled in callbacks
        }

        if (!handled && !http.processing) {
            setFieldError(copy.updateFailed);
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        void executeStatusUpdate();
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && !http.processing) {
            setReasonCode('');
            setCaseReference('');
            setFieldError(null);
            onOpenChange(false);
        }
    };

    return (
        <>
            <Dialog onOpenChange={handleOpenChange} open={open}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    <form
                        className="flex flex-col gap-4"
                        onSubmit={handleSubmit}
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="admin-customer-status-reason"
                            >
                                {copy.reasonLabel}{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <select
                                aria-label={copy.reasonLabel}
                                className="flex min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive dark:bg-input/30 dark:hover:bg-input/50"
                                disabled={http.processing}
                                id="admin-customer-status-reason"
                                onChange={(e) => {
                                    setReasonCode(e.target.value);
                                    setFieldError(null);
                                }}
                                required
                                value={reasonCode}
                            >
                                <option
                                    className="bg-popover text-popover-foreground"
                                    value=""
                                >
                                    Choose reason…
                                </option>
                                {REASON_CODES.map((code) => (
                                    <option
                                        className="bg-popover text-popover-foreground"
                                        key={code}
                                        value={code}
                                    >
                                        {copy.reasons[code] ?? code}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="admin-customer-status-case-ref"
                            >
                                {copy.caseReferenceLabel}
                            </Label>
                            <Input
                                className="min-h-11 text-xs"
                                disabled={http.processing}
                                id="admin-customer-status-case-ref"
                                maxLength={64}
                                onChange={(e) => {
                                    setCaseReference(e.target.value);
                                    setFieldError(null);
                                }}
                                placeholder={copy.caseReferencePlaceholder}
                                value={caseReference}
                            />
                            <p className="text-[11px] text-muted-foreground">
                                {copy.caseReferenceHelp}
                            </p>
                        </div>

                        {fieldError ? (
                            <p
                                className="text-xs font-medium text-destructive"
                                role="alert"
                            >
                                {fieldError}
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
                                disabled={http.processing || !reasonCode}
                                type="submit"
                                variant={isSuspend ? 'destructive' : 'default'}
                            >
                                {http.processing ? (
                                    <>
                                        <Spinner />
                                        <span>{processingText}</span>
                                    </>
                                ) : (
                                    <span>{confirmButtonText}</span>
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AdminPasswordConfirmDialog
                confirmButtonText={copy.confirmPasswordButton}
                confirmPasswordUrl={confirmPasswordUrl}
                confirmingButtonText={copy.confirmingPassword}
                description={copy.passwordModalDescription}
                invalidPasswordText={copy.invalidPassword}
                onConfirmed={() => {
                    void executeStatusUpdate();
                }}
                onOpenChange={setPasswordConfirmOpen}
                open={passwordConfirmOpen}
                passwordLabel={copy.passwordLabel}
                passwordPlaceholder={copy.passwordPlaceholder}
                title={copy.passwordModalTitle}
            />
        </>
    );
}
