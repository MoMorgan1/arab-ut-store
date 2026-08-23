'use no memo';

import { useHttp } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
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
import type {
    AdminCustomerDetail,
    AdminCustomerWalletEntry,
    AdminMoney,
    AdminTranslations,
} from '@/types/admin';

export type AdminCustomerWalletAdjustDialogProps = {
    adminUi: AdminTranslations;
    confirmPasswordUrl?: string;
    customer: AdminCustomerDetail;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: {
        balance: AdminMoney<'SAR'>;
        entry: AdminCustomerWalletEntry;
    }) => void;
    open: boolean;
    walletAdjustUrl: string;
};

type AdjustPayload = {
    amount_halalah: number;
    reason: string;
};

type AdjustResponse = {
    data: {
        balance: AdminMoney<'SAR'>;
        entry: AdminCustomerWalletEntry;
    };
};

type FieldErrors = {
    amount_halalah?: string;
    general?: string;
    reason?: string;
    unexpected_fields?: string;
};

export default function AdminCustomerWalletAdjustDialog({
    adminUi,
    confirmPasswordUrl,
    customer,
    onOpenChange,
    onSuccess,
    open,
    walletAdjustUrl,
}: AdminCustomerWalletAdjustDialogProps) {
    const copy = adminUi.customerDetail;

    const [direction, setDirection] = useState<'credit' | 'debit'>('credit');
    const [amountSar, setAmountSar] = useState('');
    const [reason, setReason] = useState('');
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    const [passwordConfirmOpen, setPasswordConfirmOpen] = useState(false);

    const [prevOpen, setPrevOpen] = useState(open);

    if (open !== prevOpen) {
        setPrevOpen(open);

        if (open) {
            setDirection('credit');
            setAmountSar('');
            setReason('');
            setFieldErrors({});
        }
    }

    const http = useHttp<AdjustPayload, AdjustResponse>(
        'post',
        walletAdjustUrl,
        {
            amount_halalah: 0,
            reason: '',
        },
    );

    const executeAdjustment = async () => {
        const parsedAmount = parseFloat(amountSar);
        const trimmedReason = reason.trim();
        const errors: FieldErrors = {};

        if (isNaN(parsedAmount) || parsedAmount <= 0) {
            errors.amount_halalah = 'Amount must be greater than 0 SAR.';
        } else if (parsedAmount > 1000) {
            errors.amount_halalah =
                'Maximum adjustment amount is 1,000.00 SAR (100,000 Halalah).';
        }

        if (
            !trimmedReason ||
            trimmedReason.length < 5 ||
            trimmedReason.length > 200
        ) {
            errors.reason = 'Reason must be between 5 and 200 characters.';
        }

        const halalah = Math.round(parsedAmount * 100);
        const signedHalalah = direction === 'credit' ? halalah : -halalah;

        const currentBalanceHalalah = parseInt(
            customer.walletSummary.balance.amountMinor,
            10,
        );

        if (direction === 'debit' && halalah > currentBalanceHalalah) {
            errors.amount_halalah = copy.walletInsufficientBalance;
        }

        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);

            return;
        }

        setFieldErrors({});

        const payload: AdjustPayload = {
            amount_halalah: signedHalalah,
            reason: trimmedReason,
        };

        http.setData(payload);

        let handled = false;

        try {
            await http.submit('post', walletAdjustUrl, {
                headers: { Accept: 'application/json' },
                onError: (validationErrors) => {
                    handled = true;
                    setFieldErrors({
                        amount_halalah: validationErrors.amount_halalah,
                        general:
                            validationErrors.unexpected_fields ||
                            copy.walletAdjustFailed,
                        reason: validationErrors.reason,
                    });
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 423) {
                        setPasswordConfirmOpen(true);

                        return false;
                    }

                    if (response.status === 422) {
                        const resErrors =
                            (
                                response.data as {
                                    errors?: Record<string, string>;
                                }
                            )?.errors ?? {};

                        setFieldErrors({
                            amount_halalah: resErrors.amount_halalah,
                            general:
                                resErrors.unexpected_fields ||
                                copy.walletAdjustFailed,
                            reason: resErrors.reason,
                        });

                        return false;
                    }

                    setFieldErrors({ general: copy.walletAdjustFailed });

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFieldErrors({ general: copy.walletAdjustFailed });

                    return false;
                },
                onSuccess: (response) => {
                    handled = true;
                    onOpenChange(false);
                    setFieldErrors({});
                    onSuccess(response.data);
                },
            });
        } catch {
            // Handled in callbacks
        }

        if (!handled && !http.processing) {
            setFieldErrors({ general: copy.walletAdjustFailed });
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        void executeAdjustment();
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && !http.processing) {
            setFieldErrors({});
            onOpenChange(false);
        }
    };

    return (
        <>
            <Dialog onOpenChange={handleOpenChange} open={open}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{copy.adjustBalanceTitle}</DialogTitle>
                        <DialogDescription>
                            {copy.adjustBalanceDescription}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="flex flex-col gap-4"
                        onSubmit={handleSubmit}
                    >
                        {/* Type Toggle */}
                        <div className="flex flex-col gap-1.5">
                            <Label className="text-xs font-semibold">
                                {copy.adjustTypeLabel}
                            </Label>
                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    className={`inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md border p-2 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-ring ${
                                        direction === 'credit'
                                            ? 'border-emerald-500 bg-emerald-500/10 text-emerald-500'
                                            : 'border-border bg-card text-muted-foreground hover:bg-accent'
                                    }`}
                                    disabled={http.processing}
                                    onClick={() => setDirection('credit')}
                                    type="button"
                                >
                                    <ArrowDownLeft
                                        aria-hidden="true"
                                        className="size-4 text-emerald-500"
                                    />
                                    <span>{copy.credit}</span>
                                </button>
                                <button
                                    className={`inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md border p-2 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-ring ${
                                        direction === 'debit'
                                            ? 'border-destructive bg-destructive/10 text-destructive'
                                            : 'border-border bg-card text-muted-foreground hover:bg-accent'
                                    }`}
                                    disabled={http.processing}
                                    onClick={() => setDirection('debit')}
                                    type="button"
                                >
                                    <ArrowUpRight
                                        aria-hidden="true"
                                        className="size-4 text-destructive"
                                    />
                                    <span>{copy.debit}</span>
                                </button>
                            </div>
                        </div>

                        {/* Amount */}
                        <div className="flex flex-col gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="admin-wallet-adjust-amount"
                            >
                                {copy.amountSarLabel}{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                aria-describedby={
                                    fieldErrors.amount_halalah
                                        ? 'admin-wallet-adjust-amount-error'
                                        : 'admin-wallet-adjust-amount-help'
                                }
                                aria-invalid={!!fieldErrors.amount_halalah}
                                className="min-h-11 text-xs tabular-nums"
                                disabled={http.processing}
                                id="admin-wallet-adjust-amount"
                                max="1000"
                                min="0.01"
                                onChange={(e) => {
                                    setAmountSar(e.target.value);
                                    setFieldErrors((prev) => ({
                                        ...prev,
                                        amount_halalah: undefined,
                                    }));
                                }}
                                placeholder="0.00"
                                required
                                step="0.01"
                                type="number"
                                value={amountSar}
                            />
                            {fieldErrors.amount_halalah ? (
                                <p
                                    className="text-xs font-medium text-destructive"
                                    id="admin-wallet-adjust-amount-error"
                                    role="alert"
                                >
                                    {fieldErrors.amount_halalah}
                                </p>
                            ) : (
                                <p
                                    className="text-[11px] text-muted-foreground"
                                    id="admin-wallet-adjust-amount-help"
                                >
                                    {copy.amountHalalahHelp.replace(
                                        ':halalah',
                                        isNaN(parseFloat(amountSar))
                                            ? '0'
                                            : Math.round(
                                                  parseFloat(amountSar) * 100,
                                              ).toString(),
                                    )}
                                </p>
                            )}
                        </div>

                        {/* Reason */}
                        <div className="flex flex-col gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="admin-wallet-adjust-reason"
                            >
                                {copy.adjustmentReasonLabel}{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                aria-describedby={
                                    fieldErrors.reason
                                        ? 'admin-wallet-adjust-reason-error'
                                        : undefined
                                }
                                aria-invalid={!!fieldErrors.reason}
                                className="min-h-11 text-xs"
                                disabled={http.processing}
                                id="admin-wallet-adjust-reason"
                                maxLength={200}
                                minLength={5}
                                onChange={(e) => {
                                    setReason(e.target.value);
                                    setFieldErrors((prev) => ({
                                        ...prev,
                                        reason: undefined,
                                    }));
                                }}
                                placeholder={copy.adjustmentReasonPlaceholder}
                                required
                                value={reason}
                            />
                            {fieldErrors.reason ? (
                                <p
                                    className="text-xs font-medium text-destructive"
                                    id="admin-wallet-adjust-reason-error"
                                    role="alert"
                                >
                                    {fieldErrors.reason}
                                </p>
                            ) : null}
                        </div>

                        {fieldErrors.general ? (
                            <p
                                className="text-xs font-medium text-destructive"
                                role="alert"
                            >
                                {fieldErrors.general}
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
                                disabled={http.processing}
                                type="submit"
                                variant="default"
                            >
                                {http.processing ? (
                                    <>
                                        <Spinner />
                                        <span>{copy.adjustingBalance}</span>
                                    </>
                                ) : (
                                    <span>{copy.submitAdjustment}</span>
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
                description={copy.walletPasswordModalDescription}
                invalidPasswordText={copy.invalidPassword}
                onConfirmed={() => {
                    void executeAdjustment();
                }}
                onOpenChange={setPasswordConfirmOpen}
                open={passwordConfirmOpen}
                passwordLabel={copy.passwordLabel}
                passwordPlaceholder={copy.passwordPlaceholder}
                title={copy.walletPasswordModalTitle}
            />
        </>
    );
}
