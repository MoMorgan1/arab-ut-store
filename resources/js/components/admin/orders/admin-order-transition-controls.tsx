import { router, useHttp } from '@inertiajs/react';
import { AlertCircle, CheckCircle2 } from 'lucide-react';
import React, { useCallback, useState } from 'react';

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
import type { AdminOrderDetail, AdminTranslations } from '@/types/admin';

export type AdminOrderTransitionControlsProps = {
    adminUi: AdminTranslations;
    order: AdminOrderDetail;
    allowedTransitions: string[];
    transitionUrl: string;
    permissions: string[];
    onStatusUpdated?: (freshOrder: AdminOrderDetail) => void;
};

type TransitionPayload = {
    target_status: string;
    expected_status: string;
};

type TransitionResponse = {
    order: AdminOrderDetail;
    status: string;
};

export default function AdminOrderTransitionControls({
    adminUi,
    order,
    allowedTransitions,
    transitionUrl,
    permissions,
    onStatusUpdated,
}: AdminOrderTransitionControlsProps) {
    const copy = adminUi.orderDetail;
    const statuses = adminUi.statuses;
    const [selectedStatus, setSelectedStatus] = useState<string>('');
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [feedback, setFeedback] = useState<{
        type: 'success' | 'error' | 'conflict';
        message: string;
    } | null>(null);
    const http = useHttp<TransitionPayload, TransitionResponse>(
        'post',
        transitionUrl,
        {
            expected_status: '',
            target_status: '',
        },
    );

    const canUpdate = permissions.includes('orders.update');
    const canCancel = permissions.includes('orders.cancel');

    const availableTransitions = allowedTransitions.filter((target) =>
        target === 'cancelled' ? canUpdate && canCancel : canUpdate,
    );

    const handleTransition = useCallback(
        async (target: string) => {
            setFeedback(null);
            http.setData({
                expected_status: order.status,
                target_status: target,
            });

            let handled = false;

            try {
                await http.submit('post', transitionUrl, {
                    headers: { Accept: 'application/json' },
                    onHttpException: (response) => {
                        handled = true;

                        if (response.status === 409) {
                            const body =
                                typeof response.data === 'string'
                                    ? (JSON.parse(response.data) as {
                                          status?: string;
                                      })
                                    : (response.data as { status?: string });
                            const canonicalStatus = body.status ?? 'unknown';
                            const readableStatus =
                                statuses[canonicalStatus] ?? canonicalStatus;
                            setFeedback({
                                message: copy.conflictError.replace(
                                    ':status',
                                    readableStatus,
                                ),
                                type: 'conflict',
                            });
                            setSelectedStatus('');
                            setShowCancelDialog(false);
                            router.reload({ only: ['order'] });

                            return false;
                        }

                        if (response.status === 403) {
                            setFeedback({
                                message: copy.forbiddenTransition,
                                type: 'error',
                            });
                            setSelectedStatus('');
                            setShowCancelDialog(false);

                            return false;
                        }

                        setFeedback({
                            message: copy.transitionFailed,
                            type: 'error',
                        });
                        setSelectedStatus('');
                        setShowCancelDialog(false);

                        return false;
                    },
                    onNetworkError: () => {
                        handled = true;
                        setFeedback({
                            message: copy.transitionFailed,
                            type: 'error',
                        });
                        setSelectedStatus('');
                        setShowCancelDialog(false);

                        return false;
                    },
                    onSuccess: (response) => {
                        handled = true;
                        setFeedback({
                            message: copy.statusUpdated,
                            type: 'success',
                        });
                        setSelectedStatus('');
                        setShowCancelDialog(false);

                        if (onStatusUpdated && response.order) {
                            onStatusUpdated(response.order);
                        } else {
                            router.reload({ only: ['order'] });
                        }
                    },
                });
            } catch {
                // Rejections are already surfaced through the callbacks above.
            }

            if (!handled && !http.processing) {
                setFeedback({
                    message: copy.transitionFailed,
                    type: 'error',
                });
                setSelectedStatus('');
                setShowCancelDialog(false);
            }
        },
        [
            copy.conflictError,
            copy.forbiddenTransition,
            copy.statusUpdated,
            copy.transitionFailed,
            http,
            onStatusUpdated,
            order.status,
            statuses,
            transitionUrl,
        ],
    );

    const handleApply = () => {
        if (!selectedStatus) {
            return;
        }

        if (selectedStatus === 'cancelled') {
            setShowCancelDialog(true);
        } else {
            void handleTransition(selectedStatus);
        }
    };

    if (availableTransitions.length === 0) {
        return (
            <div className="rounded-lg border border-border bg-card p-4 text-card-foreground">
                <h3 className="text-sm font-semibold text-foreground">
                    {copy.transitionsTitle}
                </h3>
                <p className="mt-1 text-xs text-muted-foreground">
                    {copy.noTransitionsAvailable}
                </p>
            </div>
        );
    }

    return (
        <section
            aria-labelledby="transition-controls-heading"
            className="flex flex-col gap-4 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
        >
            <div className="flex flex-col gap-1">
                <h3
                    className="text-base font-semibold text-foreground"
                    id="transition-controls-heading"
                >
                    {copy.transitionsTitle}
                </h3>
                <p className="text-xs text-muted-foreground">
                    {copy.transitionsDescription}
                </p>
            </div>

            <div aria-atomic="true" aria-live="polite" className="empty:hidden">
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
                                ? copy.statusUpdated
                                : feedback.type === 'conflict'
                                  ? (statuses[order.status] ?? 'Conflict')
                                  : 'Error'}
                        </AlertTitle>
                        <AlertDescription>{feedback.message}</AlertDescription>
                    </Alert>
                ) : null}
            </div>

            <div className="flex flex-col gap-2">
                <Label
                    className="text-xs font-semibold text-foreground"
                    htmlFor="admin-order-next-status"
                >
                    Next status
                </Label>
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label="Next status"
                        className="flex min-h-11 min-w-0 flex-1 items-center justify-between rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive dark:bg-input/30 dark:hover:bg-input/50"
                        disabled={http.processing}
                        id="admin-order-next-status"
                        onChange={(e) => {
                            setFeedback(null);
                            setSelectedStatus(e.target.value);
                        }}
                        value={selectedStatus}
                    >
                        <option
                            className="bg-popover text-popover-foreground"
                            value=""
                        >
                            Choose next status…
                        </option>
                        {availableTransitions.map((target) => (
                            <option
                                className="bg-popover text-popover-foreground"
                                key={target}
                                value={target}
                            >
                                {target === 'cancelled'
                                    ? 'Cancelled'
                                    : (statuses[target] ?? target)}
                            </option>
                        ))}
                    </select>

                    <Button
                        className="min-h-11 text-xs font-medium"
                        disabled={http.processing || !selectedStatus}
                        onClick={handleApply}
                        type="button"
                    >
                        Apply status
                    </Button>
                </div>
            </div>

            <Dialog
                onOpenChange={(open) => {
                    if (!open && !http.processing) {
                        setShowCancelDialog(false);
                    }
                }}
                open={showCancelDialog}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{copy.confirmModalTitle}</DialogTitle>
                        <DialogDescription>
                            {copy.confirmCancelDescription.replace(
                                ':number',
                                order.orderNumber,
                            )}
                        </DialogDescription>
                    </DialogHeader>
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
                            className="min-h-11"
                            disabled={http.processing}
                            onClick={() => void handleTransition('cancelled')}
                            type="button"
                            variant="destructive"
                        >
                            {http.processing
                                ? copy.updating
                                : copy.confirmButton}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
