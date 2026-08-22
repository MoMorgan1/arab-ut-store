import { router, useHttp } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    Play,
    UserCheck,
    XCircle,
} from 'lucide-react';
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
import type { AdminOrderDetail, AdminTranslations } from '@/types/admin';

export type AdminOrderTransitionControlsProps = {
    adminUi: AdminTranslations;
    order: AdminOrderDetail;
    allowedTransitions: string[];
    transitionUrl: string;
    permissions: string[];
    onStatusUpdated?: (freshOrder: AdminOrderDetail) => void;
};

const transitionIcons: Record<
    string,
    React.ComponentType<{ className?: string }>
> = {
    cancelled: XCircle,
    completed: CheckCircle2,
    in_progress: Play,
    waiting_for_customer: Clock,
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
    const [pendingTarget, setPendingTarget] = useState<string | null>(null);
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

    const handleConfirm = useCallback(async () => {
        if (!pendingTarget) {
return;
}

        setFeedback(null);
        http.setData({
            expected_status: order.status,
            target_status: pendingTarget,
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
                        setPendingTarget(null);
                        router.reload({ only: ['order'] });

                        return false;
                    }

                    if (response.status === 403) {
                        setFeedback({
                            message: copy.forbiddenTransition,
                            type: 'error',
                        });
                        setPendingTarget(null);

                        return false;
                    }

                    setFeedback({
                        message: copy.transitionFailed,
                        type: 'error',
                    });
                    setPendingTarget(null);

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFeedback({
                        message: copy.transitionFailed,
                        type: 'error',
                    });
                    setPendingTarget(null);

                    return false;
                },
                onSuccess: (response) => {
                    handled = true;
                    setFeedback({
                        message: copy.statusUpdated,
                        type: 'success',
                    });
                    setPendingTarget(null);

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

        if (!handled) {
            setFeedback({
                message: copy.transitionFailed,
                type: 'error',
            });
            setPendingTarget(null);
        }
    }, [
        copy.conflictError,
        copy.forbiddenTransition,
        copy.statusUpdated,
        copy.transitionFailed,
        http,
        order.status,
        pendingTarget,
        statuses,
        transitionUrl,
        onStatusUpdated,
    ]);

    const getDialogDescription = (target: string) => {
        if (target === 'cancelled') {
            return copy.confirmCancelDescription.replace(
                ':number',
                order.orderNumber,
            );
        }

        if (target === 'completed') {
            return copy.confirmCompleteDescription.replace(
                ':number',
                order.orderNumber,
            );
        }

        return copy.confirmModalDescription
            .replace(':number', order.orderNumber)
            .replace(':status', statuses[target] ?? target);
    };

    if (allowedTransitions.length === 0) {
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

            <div className="flex flex-wrap gap-2">
                {allowedTransitions.map((target) => {
                    const Icon = transitionIcons[target] ?? UserCheck;
                    const isCancelled = target === 'cancelled';
                    const isAllowedByRole = isCancelled
                        ? canUpdate && canCancel
                        : canUpdate;
                    const label = copy.changeStatusTo.replace(
                        ':status',
                        statuses[target] ?? target,
                    );

                    return (
                        <Button
                            className="min-h-11 gap-2 text-xs font-medium"
                            disabled={http.processing || !isAllowedByRole}
                            key={target}
                            onClick={() => {
                                setFeedback(null);
                                setPendingTarget(target);
                            }}
                            type="button"
                            variant={isCancelled ? 'destructive' : 'outline'}
                        >
                            <Icon aria-hidden="true" className="size-4" />
                            <span>{label}</span>
                        </Button>
                    );
                })}
            </div>

            <Dialog
                onOpenChange={(open) => {
                    if (!open && !http.processing) {
                        setPendingTarget(null);
                    }
                }}
                open={pendingTarget !== null}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{copy.confirmModalTitle}</DialogTitle>
                        <DialogDescription>
                            {pendingTarget
                                ? getDialogDescription(pendingTarget)
                                : ''}
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
                            onClick={handleConfirm}
                            type="button"
                            variant={
                                pendingTarget === 'cancelled'
                                    ? 'destructive'
                                    : 'default'
                            }
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
