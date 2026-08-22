import { useHttp } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    Copy,
    Eye,
    EyeOff,
    Key,
    Lock,
    X,
} from 'lucide-react';
import React, { useCallback, useEffect, useState } from 'react';

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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminOrderDetailItem, AdminTranslations } from '@/types/admin';

export type AdminOrderItemSecretProps = {
    item: AdminOrderDetailItem;
    orderId: string;
    orderNumber: string;
    locale: 'ar' | 'en';
    direction: 'ltr' | 'rtl';
    adminUi: AdminTranslations;
    revealUrlTemplate?: string;
    confirmPasswordUrl?: string;
};

type PurposeCode =
    | 'fulfillment'
    | 'customer_support'
    | 'order_review'
    | 'incident_investigation';

const PURPOSE_CODES: PurposeCode[] = [
    'fulfillment',
    'customer_support',
    'order_review',
    'incident_investigation',
];

function parseResponseData(data: unknown): unknown {
    if (typeof data === 'string') {
        try {
            return JSON.parse(data);
        } catch {
            return data;
        }
    }

    return data;
}

export default function AdminOrderItemSecret({
    item,
    orderId,
    adminUi,
    revealUrlTemplate,
    confirmPasswordUrl,
}: AdminOrderItemSecretProps) {
    const copy = adminUi.orderDetail;
    const secretsCopy = copy.secrets;

    const [isFormOpen, setIsFormOpen] = useState(false);
    const [selectedPurpose, setSelectedPurpose] =
        useState<PurposeCode>('fulfillment');
    const [caseReference, setCaseReference] = useState('');
    const [caseReferenceError, setCaseReferenceError] = useState<string | null>(
        null,
    );
    const [decryptedPayload, setDecryptedPayload] = useState<Record<
        string,
        unknown
    > | null>(null);
    const [isPurged, setIsPurged] = useState(false);
    const [showPasswordModal, setShowPasswordModal] = useState(false);
    const [confirmPasswordInput, setConfirmPasswordInput] = useState('');
    const [passwordError, setPasswordError] = useState<string | null>(null);
    const [feedback, setFeedback] = useState<{
        type: 'error';
        message: string;
    } | null>(null);
    const [copiedField, setCopiedField] = useState<string | null>(null);
    const [revealedFields, setRevealedFields] = useState<
        Record<string, boolean>
    >({});

    // Ephemeral state cleanup on unmount
    useEffect(() => {
        return () => {
            setDecryptedPayload(null);
        };
    }, []);

    const revealUrl = revealUrlTemplate
        ? revealUrlTemplate.replace('__ITEM_ID__', item.id)
        : `/admin/api/orders/${orderId}/items/${item.id}/reveal`;

    const confirmUrl = confirmPasswordUrl || '/user/confirm-password';

    const revealHttp = useHttp<
        { purpose: string; case_reference?: string },
        { data: Record<string, unknown> }
    >('post', revealUrl, {
        purpose: 'fulfillment',
    });

    const passwordHttp = useHttp<{ password: string }, unknown>(
        'post',
        confirmUrl,
        {
            password: '',
        },
    );

    const executeReveal = useCallback(async () => {
        const trimmedCaseRef = caseReference.trim();

        if (trimmedCaseRef && !/^[A-Za-z0-9._:-]{1,64}$/.test(trimmedCaseRef)) {
            setCaseReferenceError(secretsCopy.caseReferenceHelp);

            return;
        }

        setCaseReferenceError(null);
        setFeedback(null);

        const submitData: { purpose: string; case_reference?: string } = {
            purpose: selectedPurpose,
        };

        if (trimmedCaseRef) {
            submitData.case_reference = trimmedCaseRef;
        }

        revealHttp.setData(submitData);

        let handled = false;

        try {
            await revealHttp.submit('post', revealUrl, {
                headers: { Accept: 'application/json' },
                onSuccess: (response) => {
                    handled = true;
                    const body = parseResponseData(response.data);
                    const payload =
                        body && typeof body === 'object' && 'data' in body
                            ? (body as { data: Record<string, unknown> }).data
                            : (body as Record<string, unknown>);

                    setDecryptedPayload(payload ?? {});
                    setIsFormOpen(false);
                    setFeedback(null);
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 410) {
                        setIsPurged(true);
                        setIsFormOpen(false);
                        setFeedback(null);

                        return false;
                    }

                    if (response.status === 423) {
                        setShowPasswordModal(true);

                        return false;
                    }

                    if (response.status === 403) {
                        setFeedback({
                            message: secretsCopy.forbiddenError,
                            type: 'error',
                        });

                        return false;
                    }

                    if (response.status === 422) {
                        const parsed = parseResponseData(response.data) as {
                            message?: string;
                            errors?: Record<string, string[]>;
                        };
                        const firstError =
                            parsed?.errors &&
                            Object.values(parsed.errors)[0]?.[0];
                        setFeedback({
                            message:
                                firstError ||
                                parsed?.message ||
                                secretsCopy.genericError,
                            type: 'error',
                        });

                        return false;
                    }

                    setFeedback({
                        message: secretsCopy.genericError,
                        type: 'error',
                    });

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFeedback({
                        message: secretsCopy.networkError,
                        type: 'error',
                    });

                    return false;
                },
            });
        } catch {
            // Handled in callbacks
        }

        if (!handled && !revealHttp.processing) {
            setFeedback({
                message: secretsCopy.genericError,
                type: 'error',
            });
        }
    }, [
        caseReference,
        revealHttp,
        revealUrl,
        secretsCopy.caseReferenceHelp,
        secretsCopy.forbiddenError,
        secretsCopy.genericError,
        secretsCopy.networkError,
        selectedPurpose,
    ]);

    const handlePasswordSubmit = async (e?: React.FormEvent) => {
        if (e) {
            e.preventDefault();
        }

        if (!confirmPasswordInput) {
            return;
        }

        setPasswordError(null);
        passwordHttp.setData({ password: confirmPasswordInput });

        let handled = false;

        try {
            await passwordHttp.submit('post', confirmUrl, {
                headers: { Accept: 'application/json' },
                onSuccess: () => {
                    handled = true;
                    setShowPasswordModal(false);
                    setConfirmPasswordInput('');
                    setPasswordError(null);
                    // Automatically replay the reveal request!
                    void executeReveal();
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 422) {
                        const parsed = parseResponseData(response.data) as {
                            errors?: { password?: string[] };
                        };
                        setPasswordError(
                            parsed?.errors?.password?.[0] ||
                                secretsCopy.invalidPassword,
                        );
                    } else {
                        setPasswordError(secretsCopy.invalidPassword);
                    }

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setPasswordError(secretsCopy.networkError);

                    return false;
                },
            });
        } catch {
            // Handled in callbacks
        }

        if (!handled && !passwordHttp.processing) {
            setPasswordError(secretsCopy.genericError);
        }
    };

    const handleCopy = async (text: string, fieldKey?: string) => {
        let success = false;

        if (navigator?.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(text);
                success = true;
            } catch {
                success = false;
            }
        }

        if (!success) {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                success = document.execCommand('copy');
                document.body.removeChild(textarea);
            } catch {
                success = false;
            }
        }

        if (success) {
            setCopiedField(fieldKey ?? 'all');
            setTimeout(() => {
                setCopiedField(null);
            }, 2000);
        }
    };

    const handleCloseCredentials = () => {
        setDecryptedPayload(null);
        setRevealedFields({});
        setFeedback(null);
    };

    const toggleFieldVisibility = (key: string) => {
        setRevealedFields((prev) => ({
            ...prev,
            [key]: !prev[key],
        }));
    };

    if (!item.hasSecret && !item.maskedSummary) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-col gap-2 rounded-md border border-border/70 bg-muted/30 p-3">
            {/* Masked summary chips */}
            {item.maskedSummary &&
            Object.keys(item.maskedSummary).length > 0 ? (
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="font-medium text-muted-foreground">
                        {secretsCopy.maskedSummaryTitle}:
                    </span>
                    {Object.entries(item.maskedSummary).map(([key, value]) => (
                        <span
                            className="inline-flex items-center gap-1 rounded border border-border/80 bg-background px-2 py-0.5 font-mono text-[11px] text-foreground"
                            key={key}
                        >
                            <span className="text-muted-foreground">
                                {key}:
                            </span>
                            <span className="font-semibold">
                                {typeof value === 'object'
                                    ? JSON.stringify(value)
                                    : String(value)}
                            </span>
                        </span>
                    ))}
                </div>
            ) : null}

            {/* Outcome and error feedback */}
            <div aria-atomic="true" aria-live="polite" className="empty:hidden">
                {feedback ? (
                    <Alert className="text-xs" variant="destructive">
                        <AlertCircle className="size-4" />
                        <AlertTitle className="text-xs font-semibold">
                            Error
                        </AlertTitle>
                        <AlertDescription>{feedback.message}</AlertDescription>
                    </Alert>
                ) : null}
            </div>

            {/* Purged state notice */}
            {isPurged ? (
                <div
                    aria-live="polite"
                    className="text-xs font-medium text-destructive"
                >
                    {secretsCopy.purgedNotice}
                </div>
            ) : decryptedPayload ? (
                /* Decrypted credentials state */
                <div className="flex flex-col gap-3 rounded-md border border-primary/20 bg-background p-3 shadow-xs">
                    <div className="flex items-center justify-between border-b border-border/60 pb-2">
                        <div className="flex items-center gap-2">
                            <Key className="size-4 text-primary" />
                            <h4 className="text-xs font-bold text-foreground">
                                {secretsCopy.revealedCredentialsTitle}
                            </h4>
                        </div>
                        <Button
                            className="min-h-11 gap-1 text-xs"
                            onClick={handleCloseCredentials}
                            type="button"
                            variant="ghost"
                        >
                            <X className="size-3.5" />
                            <span>{secretsCopy.closeButton}</span>
                        </Button>
                    </div>

                    <div className="flex flex-col divide-y divide-border/40 text-xs">
                        {Object.entries(decryptedPayload).map(([key, val]) => {
                            const isSensitive =
                                /password|code|secret|pin/i.test(key);
                            const isVisible = Boolean(revealedFields[key]);
                            const isCopied = copiedField === key;

                            if (Array.isArray(val)) {
                                return (
                                    <div
                                        className="flex flex-col gap-1 py-2 first:pt-0 last:pb-0"
                                        key={key}
                                    >
                                        <span className="font-semibold text-muted-foreground">
                                            {key}:
                                        </span>
                                        <div className="flex flex-wrap gap-1.5">
                                            {val.map((itemVal, idx) => (
                                                <div
                                                    className="inline-flex items-center gap-1.5 rounded border border-border bg-muted/40 px-2 py-1 font-mono text-[11px]"
                                                    key={idx}
                                                >
                                                    <span>
                                                        {String(itemVal)}
                                                    </span>
                                                    <Button
                                                        aria-label={`${secretsCopy.copyButton} ${key} #${idx + 1}`}
                                                        className="size-6 p-0"
                                                        onClick={() =>
                                                            handleCopy(
                                                                String(itemVal),
                                                                `${key}-${idx}`,
                                                            )
                                                        }
                                                        size="sm"
                                                        type="button"
                                                        variant="ghost"
                                                    >
                                                        {copiedField ===
                                                        `${key}-${idx}` ? (
                                                            <Check className="size-3 text-emerald-500" />
                                                        ) : (
                                                            <Copy className="size-3 text-muted-foreground" />
                                                        )}
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                );
                            }

                            const stringVal =
                                typeof val === 'object' && val !== null
                                    ? JSON.stringify(val)
                                    : String(val ?? '');

                            return (
                                <div
                                    className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                                    key={key}
                                >
                                    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                        <span className="font-semibold text-muted-foreground">
                                            {key}:
                                        </span>
                                        <span className="font-mono text-xs text-foreground">
                                            {isSensitive && !isVisible
                                                ? '••••••••'
                                                : stringVal}
                                        </span>
                                    </div>

                                    <div className="flex items-center gap-1">
                                        {isSensitive ? (
                                            <Button
                                                aria-label={
                                                    isVisible
                                                        ? secretsCopy.hideButton
                                                        : secretsCopy.showButton
                                                }
                                                className="min-h-11 min-w-11 gap-1 text-xs"
                                                onClick={() =>
                                                    toggleFieldVisibility(key)
                                                }
                                                type="button"
                                                variant="ghost"
                                            >
                                                {isVisible ? (
                                                    <EyeOff className="size-3.5" />
                                                ) : (
                                                    <Eye className="size-3.5" />
                                                )}
                                                <span className="hidden sm:inline">
                                                    {isVisible
                                                        ? secretsCopy.hideButton
                                                        : secretsCopy.showButton}
                                                </span>
                                            </Button>
                                        ) : null}

                                        <Button
                                            aria-label={`${secretsCopy.copyButton} ${key}`}
                                            className="min-h-11 min-w-11 gap-1 text-xs"
                                            onClick={() =>
                                                handleCopy(stringVal, key)
                                            }
                                            type="button"
                                            variant="outline"
                                        >
                                            {isCopied ? (
                                                <Check className="size-3.5 text-emerald-500" />
                                            ) : (
                                                <Copy className="size-3.5 text-muted-foreground" />
                                            )}
                                            <span>
                                                {isCopied
                                                    ? secretsCopy.copied
                                                    : secretsCopy.copyButton}
                                            </span>
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ) : !isFormOpen ? (
                /* Idle state — Reveal button */
                <div>
                    <Button
                        className="min-h-11 gap-2 text-xs font-medium"
                        disabled={revealHttp.processing}
                        onClick={() => {
                            setFeedback(null);
                            setIsFormOpen(true);
                        }}
                        type="button"
                        variant="outline"
                    >
                        <Lock aria-hidden="true" className="size-3.5" />
                        <span>{secretsCopy.revealButton}</span>
                    </Button>
                </div>
            ) : (
                /* Inline Purpose Selector & Case Reference Form */
                <div
                    className="flex flex-col gap-3 rounded-md border border-border bg-background p-3"
                    data-testid="reveal-panel"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label className="text-xs font-semibold">
                            {secretsCopy.purposeLabel}
                        </Label>
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {PURPOSE_CODES.map((code) => (
                                <label
                                    className={`flex min-h-11 cursor-pointer items-center gap-2 rounded-md border p-2.5 text-xs transition-colors ${
                                        selectedPurpose === code
                                            ? 'border-primary bg-primary/5 font-semibold text-foreground'
                                            : 'border-border bg-card text-muted-foreground hover:bg-muted/40'
                                    }`}
                                    key={code}
                                >
                                    <input
                                        checked={selectedPurpose === code}
                                        className="size-4 text-primary focus:ring-ring"
                                        name={`purpose-${item.id}`}
                                        onChange={() =>
                                            setSelectedPurpose(code)
                                        }
                                        type="radio"
                                        value={code}
                                    />
                                    <span>
                                        {secretsCopy.purposes[code] ?? code}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col gap-1">
                        <Label
                            className="text-xs font-semibold"
                            htmlFor={`case-ref-${item.id}`}
                        >
                            {secretsCopy.caseReferenceLabel}
                        </Label>
                        <Input
                            aria-describedby={`case-ref-help-${item.id}`}
                            className="min-h-11 text-xs"
                            id={`case-ref-${item.id}`}
                            maxLength={64}
                            onChange={(e) => {
                                setCaseReference(e.target.value);
                                setCaseReferenceError(null);
                            }}
                            placeholder={secretsCopy.caseReferencePlaceholder}
                            value={caseReference}
                        />
                        <p
                            className="text-[11px] text-muted-foreground"
                            id={`case-ref-help-${item.id}`}
                        >
                            {secretsCopy.caseReferenceHelp}
                        </p>
                        {caseReferenceError ? (
                            <p className="text-xs font-medium text-destructive">
                                {caseReferenceError}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <Button
                            className="min-h-11 gap-2 text-xs"
                            disabled={revealHttp.processing}
                            onClick={() => void executeReveal()}
                            type="button"
                            variant="default"
                        >
                            {revealHttp.processing ? (
                                <>
                                    <Spinner />
                                    <span>{secretsCopy.revealing}</span>
                                </>
                            ) : (
                                <>
                                    <Key className="size-3.5" />
                                    <span>{secretsCopy.confirmReveal}</span>
                                </>
                            )}
                        </Button>

                        <Button
                            className="min-h-11 text-xs"
                            disabled={revealHttp.processing}
                            onClick={() => {
                                setIsFormOpen(false);
                                setCaseReferenceError(null);
                            }}
                            type="button"
                            variant="outline"
                        >
                            {secretsCopy.cancelButton}
                        </Button>
                    </div>
                </div>
            )}

            {/* Password Confirmation Modal (Triggered by 423) */}
            <Dialog
                onOpenChange={(open) => {
                    if (!open && !passwordHttp.processing) {
                        setShowPasswordModal(false);
                        setPasswordError(null);
                        setConfirmPasswordInput('');
                    }
                }}
                open={showPasswordModal}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {secretsCopy.passwordModalTitle}
                        </DialogTitle>
                        <DialogDescription>
                            {secretsCopy.passwordModalDescription}
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(e) => void handlePasswordSubmit(e)}
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label
                                className="text-xs font-semibold"
                                htmlFor="reveal-password-confirm"
                            >
                                {secretsCopy.passwordLabel}
                            </Label>
                            <Input
                                autoFocus
                                className="min-h-11 text-xs"
                                id="reveal-password-confirm"
                                onChange={(e) => {
                                    setConfirmPasswordInput(e.target.value);
                                    setPasswordError(null);
                                }}
                                placeholder={secretsCopy.passwordPlaceholder}
                                type="password"
                                value={confirmPasswordInput}
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
                                    {secretsCopy.cancelButton}
                                </Button>
                            </DialogClose>
                            <Button
                                className="min-h-11 gap-2"
                                disabled={
                                    passwordHttp.processing ||
                                    !confirmPasswordInput
                                }
                                type="submit"
                                variant="default"
                            >
                                {passwordHttp.processing ? (
                                    <>
                                        <Spinner />
                                        <span>
                                            {secretsCopy.confirmingPassword}
                                        </span>
                                    </>
                                ) : (
                                    <span>
                                        {secretsCopy.confirmPasswordButton}
                                    </span>
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
