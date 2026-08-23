import { router, useHttp } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, RotateCcw } from 'lucide-react';
import React, { useCallback, useState } from 'react';

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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminOrderDetail, AdminTranslations } from '@/types/admin';

export type AdminOrderRefundControlProps = {
    order: AdminOrderDetail;
    refund: {
        eligible: boolean;
        amountMinor: string;
        currency: string;
    };
    refundUrl: string;
    adminUi: AdminTranslations;
    locale: 'ar' | 'en';
    direction: 'ltr' | 'rtl';
    confirmPasswordUrl?: string;
    variant?: 'card' | 'bar';
};

type RefundPayload = {
    amountHalalah: number;
    reason: string;
};

type RefundResponse = {
    data?: {
        refundId: string;
        status: string;
        amountHalalah: number;
    };
    error?: {
        code: string;
        message: string;
    };
};

export default function AdminOrderRefundControl({
    order,
    refund,
    refundUrl,
    adminUi,
    locale,
    direction,
    confirmPasswordUrl,
    variant = 'card',
}: AdminOrderRefundControlProps) {
    const copy = adminUi.orderDetail.refund;

    const [showDialog, setShowDialog] = useState(false);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState<string | null>(null);
    const [dialogError, setDialogError] = useState<string | null>(null);
    const [pendingPayload, setPendingPayload] = useState<RefundPayload | null>(
        null,
    );
    const [showPasswordModal, setShowPasswordModal] = useState(false);
    const [feedback, setFeedback] = useState<{
        type: 'success' | 'error';
        message: string;
    } | null>(null);

    const http = useHttp<RefundPayload, RefundResponse>('post', refundUrl, {
        amountHalalah: parseInt(refund.amountMinor, 10),
        reason: '',
    });

    const formattedAmount = formatAdminMoney(
        { amountMinor: refund.amountMinor, currency: refund.currency },
        locale,
    );

    const executeRefund = useCallback(
        async (payloadToSubmit?: RefundPayload) => {
            const submitPayload = payloadToSubmit ?? pendingPayload;

            if (!submitPayload) {
                return;
            }

            setFeedback(null);
            setDialogError(null);
            http.setData(submitPayload);

            let handled = false;

            try {
                await http.submit('post', refundUrl, {
                    headers: { Accept: 'application/json' },
                    onSuccess: () => {
                        handled = true;
                        setShowDialog(false);
                        setShowPasswordModal(false);
                        setFeedback({
                            message: copy.successMessage,
                            type: 'success',
                        });
                        setReason('');
                        setReasonError(null);
                        setDialogError(null);
                        setPendingPayload(null);

                        router.reload({
                            only: ['order', 'refund', 'allowedTransitions'],
                        });
                    },
                    onError: () => {
                        handled = true;
                        setDialogError(copy.fullRefundRequired);
                    },
                    onHttpException: (response) => {
                        handled = true;

                        if (response.status === 423) {
                            setShowDialog(false);
                            setShowPasswordModal(true);

                            return false;
                        }

                        if (response.status === 409) {
                            setDialogError(copy.unavailable);

                            return false;
                        }

                        if (response.status === 503) {
                            setDialogError(copy.providerUnavailable);

                            return false;
                        }

                        if (response.status === 429) {
                            const retryAfter =
                                (
                                    response.headers as
                                        Record<string, string> | undefined
                                )?.['retry-after'] ??
                                (
                                    response.headers as
                                        Record<string, string> | undefined
                                )?.['Retry-After'];

                            if (retryAfter) {
                                setDialogError(
                                    copy.rateLimited.replace(
                                        ':seconds',
                                        String(retryAfter),
                                    ),
                                );
                            } else {
                                setDialogError(copy.rateLimitedGeneric);
                            }

                            return false;
                        }

                        setDialogError(copy.genericError);

                        return false;
                    },
                    onNetworkError: () => {
                        handled = true;
                        setDialogError(copy.networkError);

                        return false;
                    },
                });
            } catch {
                // Handled in callbacks
            }

            if (!handled && !http.processing) {
                setDialogError(copy.genericError);
            }
        },
        [
            copy.fullRefundRequired,
            copy.genericError,
            copy.networkError,
            copy.providerUnavailable,
            copy.rateLimited,
            copy.rateLimitedGeneric,
            copy.successMessage,
            copy.unavailable,
            http,
            pendingPayload,
            refundUrl,
        ],
    );

    const handleSubmitRefund = () => {
        const trimmedReason = reason.trim();

        if (trimmedReason === '') {
            setReasonError(copy.reasonRequired);

            return;
        }

        if (trimmedReason.length > 500) {
            setReasonError(copy.reasonMaxLength);

            return;
        }

        setReasonError(null);
        setDialogError(null);

        const payload: RefundPayload = {
            amountHalalah: parseInt(refund.amountMinor, 10),
            reason: trimmedReason,
        };
        setPendingPayload(payload);
        void executeRefund(payload);
    };

    return (
        <div
            className={
                variant === 'bar'
                    ? 'flex flex-col gap-3'
                    : 'border-t border-border/60 pt-4'
            }
            dir={direction}
        >
            <div className="flex flex-col gap-3">
                <div
                    aria-atomic="true"
                    aria-live="polite"
                    className="empty:hidden"
                >
                    {feedback ? (
                        <Alert
                            className="text-xs"
                            variant={
                                feedback.type === 'success'
                                    ? 'default'
                                    : 'destructive'
                            }
                        >
                            {feedback.type === 'success' ? (
                                <CheckCircle2 className="size-4 text-emerald-500" />
                            ) : (
                                <AlertCircle className="size-4" />
                            )}
                            <AlertTitle className="text-xs font-semibold">
                                {feedback.type === 'success'
                                    ? copy.successTitle
                                    : copy.errorTitle}
                            </AlertTitle>
                            <AlertDescription>
                                {feedback.message}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                </div>

                <div>
                    <Button
                        className="min-h-11 gap-2 border-destructive/50 text-xs font-medium text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => {
                            setFeedback(null);
                            setReasonError(null);
                            setDialogError(null);
                            setShowDialog(true);
                        }}
                        type="button"
                        variant="outline"
                    >
                        <RotateCcw aria-hidden="true" className="size-4" />
                        <span>Refund order</span>
                    </Button>
                </div>
            </div>

            <Dialog
                onOpenChange={(open) => {
                    if (!open && !http.processing) {
                        setShowDialog(false);
                    }
                }}
                open={showDialog}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Issue Paylink refund</DialogTitle>
                        <DialogDescription>
                            Refunds the full captured payment back to the
                            customer via Paylink. This cannot be undone and
                            marks the order as refunded.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        aria-atomic="true"
                        aria-live="polite"
                        className="empty:hidden"
                    >
                        {dialogError ? (
                            <Alert className="text-xs" variant="destructive">
                                <AlertCircle className="size-4" />
                                <AlertTitle className="text-xs font-semibold">
                                    {copy.errorTitle}
                                </AlertTitle>
                                <AlertDescription>
                                    {dialogError}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-3 py-1">
                        <div className="flex flex-col gap-1">
                            <Label
                                className="text-xs font-semibold text-muted-foreground"
                                htmlFor={`refund-amount-${order.id}`}
                            >
                                {copy.amountLabel}
                            </Label>
                            <output
                                className="flex min-h-11 items-center rounded-md border border-border bg-muted/40 px-3 py-2 text-xs font-bold text-foreground tabular-nums"
                                id={`refund-amount-${order.id}`}
                            >
                                <bdi>{formattedAmount}</bdi>
                            </output>
                        </div>

                        <div className="flex flex-col gap-1">
                            <div className="flex items-center justify-between">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor={`refund-reason-${order.id}`}
                                >
                                    {copy.reasonLabel}
                                </Label>
                                <span className="text-[11px] text-muted-foreground tabular-nums">
                                    {reason.trim().length} / 500
                                </span>
                            </div>
                            <textarea
                                aria-describedby={
                                    reasonError
                                        ? `refund-reason-error-${order.id}`
                                        : undefined
                                }
                                aria-invalid={reasonError !== null}
                                className="flex min-h-20 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 motion-reduce:transition-none"
                                id={`refund-reason-${order.id}`}
                                maxLength={500}
                                onChange={(e) => {
                                    setReason(e.target.value);
                                    setReasonError(null);
                                    setDialogError(null);
                                }}
                                placeholder={copy.reasonPlaceholder}
                                rows={3}
                                value={reason}
                            />
                            {reasonError ? (
                                <p
                                    className="text-xs font-medium text-destructive"
                                    id={`refund-reason-error-${order.id}`}
                                    role="alert"
                                >
                                    {reasonError}
                                </p>
                            ) : null}
                        </div>
                    </div>

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
                            disabled={
                                http.processing ||
                                reason.trim() === '' ||
                                reason.trim().length > 500
                            }
                            onClick={() => void handleSubmitRefund()}
                            type="button"
                            variant="destructive"
                        >
                            {http.processing ? (
                                <>
                                    <Spinner />
                                    <span>{copy.processingButton}</span>
                                </>
                            ) : (
                                <span>Refund {formattedAmount}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AdminPasswordConfirmDialog
                cancelButtonText={copy.cancelButton}
                confirmButtonText={copy.confirmPasswordButton}
                confirmingButtonText={copy.confirmingPassword}
                confirmPasswordUrl={confirmPasswordUrl}
                description={copy.passwordModalDescription}
                genericErrorText={copy.genericError}
                inputId={`refund-password-confirm-${order.id}`}
                invalidPasswordText={copy.invalidPassword}
                networkErrorText={copy.networkError}
                onConfirmed={() => executeRefund(pendingPayload ?? undefined)}
                onOpenChange={setShowPasswordModal}
                open={showPasswordModal}
                passwordLabel={copy.passwordLabel}
                passwordPlaceholder={copy.passwordPlaceholder}
                title={copy.passwordModalTitle}
            />
        </div>
    );
}
