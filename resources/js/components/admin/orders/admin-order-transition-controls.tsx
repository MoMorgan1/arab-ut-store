import { router } from '@inertiajs/react';
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

function getCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

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
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [feedback, setFeedback] = useState<{
        type: 'success' | 'error' | 'conflict';
        message: string;
    } | null>(null);

    const canUpdate = permissions.includes('orders.update');
    const canCancel = permissions.includes('orders.cancel');

    const handleConfirm = useCallback(async () => {
        if (!pendingTarget) {
            return;
        }

        setIsSubmitting(true);
        setFeedback(null);

        try {
            const response = await fetch(transitionUrl, {
                body: JSON.stringify({
                    expected_status: order.status,
                    target_status: pendingTarget,
                }),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                method: 'POST',
            });

            if (response.status === 409) {
                const conflictData = (await response.json()) as {
                    order: string;
                    status: string;
                };
                const canonicalStatus = conflictData.status ?? 'unknown';
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

                return;
            }

            if (response.status === 403) {
                setFeedback({
                    message: copy.forbiddenTransition,
                    type: 'error',
                });
                setPendingTarget(null);

                return;
            }

            if (!response.ok) {
                setFeedback({
                    message: copy.transitionFailed,
                    type: 'error',
                });
                setPendingTarget(null);

                return;
            }

            const successData = (await response.json()) as {
                order: AdminOrderDetail;
                status: string;
            };

            setFeedback({
                message: copy.statusUpdated,
                type: 'success',
            });
            setPendingTarget(null);

            if (onStatusUpdated && successData.order) {
                onStatusUpdated(successData.order);
            } else {
                router.reload({ only: ['order'] });
            }
        } catch {
            setFeedback({
                message: copy.transitionFailed,
                type: 'error',
            });
            setPendingTarget(null);
        } finally {
            setIsSubmitting(false);
        }
    }, [
        copy.conflictError,
        copy.forbiddenTransition,
        copy.statusUpdated,
        copy.transitionFailed,
        onStatusUpdated,
        order.status,
        pendingTarget,
        statuses,
        transitionUrl,
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
                            disabled={isSubmitting || !isAllowedByRole}
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
                    if (!open && !isSubmitting) {
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
                                disabled={isSubmitting}
                                type="button"
                                variant="outline"
                            >
                                {copy.cancelButton}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11"
                            disabled={isSubmitting}
                            onClick={handleConfirm}
                            type="button"
                            variant={
                                pendingTarget === 'cancelled'
                                    ? 'destructive'
                                    : 'default'
                            }
                        >
                            {isSubmitting ? copy.updating : copy.confirmButton}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
