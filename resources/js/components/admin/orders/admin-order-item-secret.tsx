import { useHttp } from '@inertiajs/react';
import { AlertCircle, Check, Copy, Key, Lock } from 'lucide-react';
import React, { useCallback, useEffect, useRef, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
};

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
}: AdminOrderItemSecretProps) {
    const copy = adminUi.orderDetail;
    const secretsCopy = copy.secrets;

    const [decryptedPayload, setDecryptedPayload] = useState<Record<
        string,
        unknown
    > | null>(null);
    const [isPurged, setIsPurged] = useState(false);
    const [feedback, setFeedback] = useState<{
        canRetry?: boolean;
        message: string;
    } | null>(null);
    const [copiedField, setCopiedField] = useState<string | null>(null);

    const requestedItemIdRef = useRef<string | null>(null);

    const revealUrl = revealUrlTemplate
        ? revealUrlTemplate.replace('__ITEM_ID__', item.id)
        : `/admin/api/orders/${orderId}/items/${item.id}/reveal`;

    const revealHttp = useHttp<
        { purpose?: string },
        { data: Record<string, unknown> }
    >('post', revealUrl, {
        purpose: 'fulfillment',
    });

    const executeReveal = useCallback(async () => {
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
                    setFeedback(null);
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 410) {
                        setIsPurged(true);
                        setFeedback(null);

                        return false;
                    }

                    if (response.status === 403) {
                        setFeedback({
                            canRetry: false,
                            message: secretsCopy.forbiddenError,
                        });

                        return false;
                    }

                    setFeedback({
                        canRetry: true,
                        message: secretsCopy.genericError,
                    });

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFeedback({
                        canRetry: true,
                        message: secretsCopy.networkError,
                    });

                    return false;
                },
            });
        } catch {
            // Handled in callbacks
        }

        if (!handled && !revealHttp.processing) {
            setFeedback({
                canRetry: true,
                message: secretsCopy.genericError,
            });
        }
    }, [
        revealHttp,
        revealUrl,
        secretsCopy.forbiddenError,
        secretsCopy.genericError,
        secretsCopy.networkError,
    ]);

    // Keep the latest reveal callback reachable without re-running the
    // fetch effect (useHttp returns a fresh object every render).
    const executeRevealRef = useRef(executeReveal);

    useEffect(() => {
        executeRevealRef.current = executeReveal;
    }, [executeReveal]);

    // Auto-fetch credentials once per item, guarding against StrictMode's
    // double invocation with a ref.
    useEffect(() => {
        if (!item.hasSecret || requestedItemIdRef.current === item.id) {
            return;
        }

        requestedItemIdRef.current = item.id;
        void executeRevealRef.current();
    }, [item.hasSecret, item.id]);

    // Forget the decrypted payload only when the component really unmounts.
    useEffect(() => {
        return () => {
            setDecryptedPayload(null);
        };
    }, []);

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

    if (!item.hasSecret) {
        return null;
    }

    return (
        <div className="mt-3 flex flex-col gap-2 border-t border-border/60 pt-3">
            <div className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                <Lock
                    aria-hidden="true"
                    className="size-3.5 text-muted-foreground"
                />
                <span>{secretsCopy.title}</span>
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
                    <div className="flex items-center gap-2 border-b border-border/60 pb-2">
                        <Key className="size-4 text-primary" />
                        <h4 className="text-xs font-bold text-foreground">
                            {secretsCopy.revealedCredentialsTitle}
                        </h4>
                    </div>

                    <div className="flex flex-col divide-y divide-border/40 text-xs">
                        {Object.entries(decryptedPayload).map(([key, val]) => {
                            const isArray = Array.isArray(val);
                            const displayVal = isArray
                                ? val.map(String).join(' · ')
                                : typeof val === 'object' && val !== null
                                  ? JSON.stringify(val)
                                  : String(val ?? '');
                            const copyVal = isArray
                                ? val.map(String).join('\n')
                                : displayVal;
                            const isCopied = copiedField === key;

                            return (
                                <div
                                    className="flex min-h-11 flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                                    key={key}
                                >
                                    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                        <span className="font-semibold text-muted-foreground">
                                            {key}:
                                        </span>
                                        <span className="font-mono text-xs text-foreground">
                                            {displayVal}
                                        </span>
                                    </div>

                                    <Button
                                        aria-label={`${secretsCopy.copyButton} ${key}`}
                                        className="min-h-11 min-w-11 gap-1 text-xs"
                                        onClick={() =>
                                            void handleCopy(copyVal, key)
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
                            );
                        })}
                    </div>
                </div>
            ) : feedback ? (
                /* Error / Forbidden state with optional retry button */
                <div
                    aria-atomic="true"
                    aria-live="polite"
                    className="flex flex-col gap-2"
                >
                    <Alert className="text-xs" variant="destructive">
                        <AlertCircle className="size-4" />
                        <AlertTitle className="text-xs font-semibold">
                            Error
                        </AlertTitle>
                        <AlertDescription>{feedback.message}</AlertDescription>
                    </Alert>
                    {feedback.canRetry ? (
                        <div>
                            <Button
                                className="min-h-11 gap-1.5 text-xs"
                                disabled={revealHttp.processing}
                                onClick={() => {
                                    setFeedback(null);
                                    void executeReveal();
                                }}
                                type="button"
                                variant="outline"
                            >
                                {revealHttp.processing ? (
                                    <>
                                        <Spinner className="size-3.5" />
                                        <span>{secretsCopy.loading}</span>
                                    </>
                                ) : (
                                    <span>{secretsCopy.retryButton}</span>
                                )}
                            </Button>
                        </div>
                    ) : null}
                </div>
            ) : (
                /* Loading state */
                <div
                    aria-live="polite"
                    className="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Spinner className="size-3.5" />
                    <span>{secretsCopy.loading}</span>
                </div>
            )}
        </div>
    );
}
