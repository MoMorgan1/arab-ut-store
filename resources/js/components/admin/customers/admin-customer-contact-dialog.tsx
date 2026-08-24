'use no memo';

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
import type { AdminCustomerDetail, AdminTranslations } from '@/types/admin';

export type AdminCustomerContactDialogProps = {
    adminUi: AdminTranslations;
    contactUrl: string;
    customer: AdminCustomerDetail;
    onConflict: () => void;
    onOpenChange: (open: boolean) => void;
    onSuccess: (result: {
        firstName: string;
        lastName: string;
        email: string;
        phone: string | null;
        updatedAt: string;
    }) => void;
    open: boolean;
};

type ContactExpectation = {
    email: string;
    first_name: string;
    last_name: string;
    phone: string | null;
};

type ContactPayload = ContactExpectation & {
    expected: ContactExpectation;
};

type ContactResponse = {
    data: {
        email: string;
        firstName: string;
        lastName: string;
        phone: string | null;
        updatedAt: string;
    };
};

type FieldErrors = {
    email?: string;
    expected?: string;
    first_name?: string;
    general?: string;
    last_name?: string;
    phone?: string;
    unexpected_fields?: string;
};

export default function AdminCustomerContactDialog({
    adminUi,
    contactUrl,
    customer,
    onConflict,
    onOpenChange,
    onSuccess,
    open,
}: AdminCustomerContactDialogProps) {
    const copy = adminUi.customerDetail;
    const [firstName, setFirstName] = useState(customer.firstName);
    const [lastName, setLastName] = useState(customer.lastName);
    const [email, setEmail] = useState(customer.email);
    const [phone, setPhone] = useState(customer.phone ?? '');
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

    // Seed the form from the customer each time the dialog opens. Deliberately
    // keyed on the open transition alone: a partial reload landing while the
    // dialog is up would otherwise discard whatever the admin has typed.
    const [prevOpen, setPrevOpen] = useState(open);

    if (open !== prevOpen) {
        setPrevOpen(open);

        if (open) {
            setFirstName(customer.firstName);
            setLastName(customer.lastName);
            setEmail(customer.email);
            setPhone(customer.phone ?? '');
            setFieldErrors({});
        }
    }

    const expectation = (): ContactExpectation => ({
        email: customer.email,
        first_name: customer.firstName,
        last_name: customer.lastName,
        phone: customer.phone,
    });

    const http = useHttp<ContactPayload, ContactResponse>('post', contactUrl, {
        ...expectation(),
        expected: expectation(),
    });

    const isChanged =
        firstName.trim() !== customer.firstName ||
        lastName.trim() !== customer.lastName ||
        email.trim() !== customer.email ||
        (phone.trim() === '' ? null : phone.trim()) !== customer.phone;

    const executeContactUpdate = async () => {
        const trimmedFirstName = firstName.trim();
        const trimmedLastName = lastName.trim();
        const trimmedEmail = email.trim();
        const trimmedPhone = phone.trim() === '' ? null : phone.trim();

        const errors: FieldErrors = {};

        if (!trimmedFirstName) {
            errors.first_name = 'First name is required.';
        }

        if (!trimmedLastName) {
            errors.last_name = 'Last name is required.';
        }

        if (!trimmedEmail) {
            errors.email = 'Email address is required.';
        }

        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);

            return;
        }

        setFieldErrors({});
        const payload: ContactPayload = {
            email: trimmedEmail,
            expected: expectation(),
            first_name: trimmedFirstName,
            last_name: trimmedLastName,
            phone: trimmedPhone,
        };

        http.setData(payload);

        let handled = false;

        try {
            await http.submit('post', contactUrl, {
                headers: { Accept: 'application/json' },
                onError: (validationErrors) => {
                    handled = true;
                    setFieldErrors({
                        email: validationErrors.email,
                        expected: validationErrors.expected,
                        first_name: validationErrors.first_name,
                        general:
                            validationErrors.unexpected_fields ||
                            validationErrors.expected ||
                            copy.updateContactFailed,
                        last_name: validationErrors.last_name,
                        phone: validationErrors.phone,
                    });
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 409) {
                        onOpenChange(false);
                        onConflict();

                        return false;
                    }

                    if (response.status === 403) {
                        setFieldErrors({ general: copy.forbiddenContactError });

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
                            email: resErrors.email,
                            expected: resErrors.expected,
                            first_name: resErrors.first_name,
                            general:
                                resErrors.unexpected_fields ||
                                resErrors.expected ||
                                copy.updateContactFailed,
                            last_name: resErrors.last_name,
                            phone: resErrors.phone,
                        });

                        return false;
                    }

                    setFieldErrors({ general: copy.updateContactFailed });

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFieldErrors({ general: copy.networkError });

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
            setFieldErrors({ general: copy.updateContactFailed });
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        void executeContactUpdate();
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen && !http.processing) {
            setFirstName(customer.firstName);
            setLastName(customer.lastName);
            setEmail(customer.email);
            setPhone(customer.phone ?? '');
            setFieldErrors({});
            onOpenChange(false);
        }
    };

    return (
        <>
            <Dialog onOpenChange={handleOpenChange} open={open}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{copy.editContactTitle}</DialogTitle>
                        <DialogDescription>
                            {copy.editContactDescription}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="flex flex-col gap-4"
                        onSubmit={handleSubmit}
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="admin-customer-first-name"
                                >
                                    {copy.firstNameLabel}{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    aria-describedby={
                                        fieldErrors.first_name
                                            ? 'admin-customer-first-name-error'
                                            : undefined
                                    }
                                    aria-invalid={!!fieldErrors.first_name}
                                    className="min-h-11 text-xs"
                                    disabled={http.processing}
                                    id="admin-customer-first-name"
                                    maxLength={255}
                                    onChange={(e) => {
                                        setFirstName(e.target.value);
                                        setFieldErrors((prev) => ({
                                            ...prev,
                                            first_name: undefined,
                                        }));
                                    }}
                                    required
                                    value={firstName}
                                />
                                {fieldErrors.first_name ? (
                                    <p
                                        className="text-xs font-medium text-destructive"
                                        id="admin-customer-first-name-error"
                                        role="alert"
                                    >
                                        {fieldErrors.first_name}
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="admin-customer-last-name"
                                >
                                    {copy.lastNameLabel}{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    aria-describedby={
                                        fieldErrors.last_name
                                            ? 'admin-customer-last-name-error'
                                            : undefined
                                    }
                                    aria-invalid={!!fieldErrors.last_name}
                                    className="min-h-11 text-xs"
                                    disabled={http.processing}
                                    id="admin-customer-last-name"
                                    maxLength={255}
                                    onChange={(e) => {
                                        setLastName(e.target.value);
                                        setFieldErrors((prev) => ({
                                            ...prev,
                                            last_name: undefined,
                                        }));
                                    }}
                                    required
                                    value={lastName}
                                />
                                {fieldErrors.last_name ? (
                                    <p
                                        className="text-xs font-medium text-destructive"
                                        id="admin-customer-last-name-error"
                                        role="alert"
                                    >
                                        {fieldErrors.last_name}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="admin-customer-email"
                            >
                                {copy.emailLabel}{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                aria-describedby={
                                    fieldErrors.email
                                        ? 'admin-customer-email-error'
                                        : undefined
                                }
                                aria-invalid={!!fieldErrors.email}
                                className="min-h-11 text-xs"
                                disabled={http.processing}
                                id="admin-customer-email"
                                maxLength={255}
                                onChange={(e) => {
                                    setEmail(e.target.value);
                                    setFieldErrors((prev) => ({
                                        ...prev,
                                        email: undefined,
                                    }));
                                }}
                                required
                                type="email"
                                value={email}
                            />
                            {fieldErrors.email ? (
                                <p
                                    className="text-xs font-medium text-destructive"
                                    id="admin-customer-email-error"
                                    role="alert"
                                >
                                    {fieldErrors.email}
                                </p>
                            ) : null}
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="admin-customer-phone"
                            >
                                {copy.phoneLabel}
                            </Label>
                            <Input
                                aria-describedby={
                                    fieldErrors.phone
                                        ? 'admin-customer-phone-error'
                                        : 'admin-customer-phone-help'
                                }
                                aria-invalid={!!fieldErrors.phone}
                                className="min-h-11 text-xs tabular-nums"
                                disabled={http.processing}
                                id="admin-customer-phone"
                                maxLength={20}
                                onChange={(e) => {
                                    setPhone(e.target.value);
                                    setFieldErrors((prev) => ({
                                        ...prev,
                                        phone: undefined,
                                    }));
                                }}
                                placeholder="+966501234567"
                                type="tel"
                                value={phone}
                            />
                            {fieldErrors.phone ? (
                                <p
                                    className="text-xs font-medium text-destructive"
                                    id="admin-customer-phone-error"
                                    role="alert"
                                >
                                    {fieldErrors.phone}
                                </p>
                            ) : (
                                <p
                                    className="text-[11px] text-muted-foreground"
                                    id="admin-customer-phone-help"
                                >
                                    {copy.phoneHelp}
                                </p>
                            )}
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
                                disabled={http.processing || !isChanged}
                                type="submit"
                                variant="default"
                            >
                                {http.processing ? (
                                    <>
                                        <Spinner />
                                        <span>{copy.savingButton}</span>
                                    </>
                                ) : (
                                    <span>{copy.saveButton}</span>
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
