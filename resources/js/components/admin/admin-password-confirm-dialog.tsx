import { useHttp } from '@inertiajs/react';
import React, { useState } from 'react';

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

export type AdminPasswordConfirmDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirmed: () => void | Promise<void>;
    confirmPasswordUrl?: string;
    title: string;
    description: string;
    passwordLabel?: string;
    passwordPlaceholder?: string;
    confirmButtonText?: string;
    confirmingButtonText?: string;
    cancelButtonText?: string;
    invalidPasswordText?: string;
    genericErrorText?: string;
    networkErrorText?: string;
    inputId?: string;
};

export default function AdminPasswordConfirmDialog({
    open,
    onOpenChange,
    onConfirmed,
    confirmPasswordUrl,
    title,
    description,
    passwordLabel = 'Password',
    passwordPlaceholder = 'Enter your current password',
    confirmButtonText = 'Confirm password',
    confirmingButtonText = 'Verifying password…',
    cancelButtonText = 'Cancel',
    invalidPasswordText = 'The provided password was incorrect.',
    genericErrorText = 'An error occurred. Please try again.',
    networkErrorText = 'Network error. Please check your connection and try again.',
    inputId = 'admin-password-confirm',
}: AdminPasswordConfirmDialogProps) {
    const [passwordInput, setPasswordInput] = useState('');
    const [passwordError, setPasswordError] = useState<string | null>(null);

    const confirmUrl = confirmPasswordUrl || '/user/confirm-password';

    const passwordHttp = useHttp<{ password: string }, unknown>(
        'post',
        confirmUrl,
        {
            password: '',
        },
    );

    const handlePasswordSubmit = async (e?: React.FormEvent) => {
        if (e) {
            e.preventDefault();
        }

        if (!passwordInput) {
            return;
        }

        setPasswordError(null);
        passwordHttp.setData({ password: passwordInput });

        let handled = false;

        try {
            await passwordHttp.submit('post', confirmUrl, {
                headers: { Accept: 'application/json' },
                onSuccess: () => {
                    handled = true;
                    onOpenChange(false);
                    setPasswordInput('');
                    setPasswordError(null);
                    void onConfirmed();
                },
                onError: (errors) => {
                    handled = true;

                    setPasswordError(errors.password || invalidPasswordText);
                },
                onHttpException: () => {
                    handled = true;
                    setPasswordError(invalidPasswordText);

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setPasswordError(networkErrorText);

                    return false;
                },
            });
        } catch {
            // Handled in callbacks
        }

        if (!handled && !passwordHttp.processing) {
            setPasswordError(genericErrorText);
        }
    };

    const handleDialogOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && !passwordHttp.processing) {
            setPasswordError(null);
            setPasswordInput('');
            onOpenChange(false);
        }
    };

    return (
        <Dialog onOpenChange={handleDialogOpenChange} open={open}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <form
                    className="flex flex-col gap-4"
                    onSubmit={(e) => void handlePasswordSubmit(e)}
                >
                    <div className="flex flex-col gap-1.5">
                        <Label
                            className="text-xs font-semibold"
                            htmlFor={inputId}
                        >
                            {passwordLabel}
                        </Label>
                        <Input
                            autoFocus
                            className="min-h-11 text-xs"
                            id={inputId}
                            onChange={(e) => {
                                setPasswordInput(e.target.value);
                                setPasswordError(null);
                            }}
                            placeholder={passwordPlaceholder}
                            type="password"
                            value={passwordInput}
                        />
                        {passwordError ? (
                            <p
                                className="text-xs font-medium text-destructive"
                                role="alert"
                            >
                                {passwordError}
                            </p>
                        ) : null}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                className="min-h-11"
                                disabled={passwordHttp.processing}
                                type="button"
                                variant="outline"
                            >
                                {cancelButtonText}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11 gap-2"
                            disabled={passwordHttp.processing || !passwordInput}
                            type="submit"
                            variant="default"
                        >
                            {passwordHttp.processing ? (
                                <>
                                    <Spinner />
                                    <span>{confirmingButtonText}</span>
                                </>
                            ) : (
                                <span>{confirmButtonText}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
