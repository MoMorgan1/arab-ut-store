import { useEffect, useRef, useState } from 'react';

import { newAttemptKey } from '@/lib/attempt-key';
import { announceCartAddition } from '@/lib/cart-added-event';
import type { CartAddedAnalytics } from '@/lib/cart-added-event';
import {
    CatalogCartRequestError,
    submitCatalogCart,
} from '@/lib/catalog-cart-api';

export function CatalogAddControl({
    addUrl,
    analytics,
    errorLabel,
    idleLabel,
    loadingLabel,
    imageAlt,
    imageUrl,
    itemLabel,
    successLabel,
    variantId,
}: {
    addUrl: string;
    analytics?: CartAddedAnalytics;
    errorLabel: string;
    idleLabel: string;
    loadingLabel: string;
    imageAlt: string;
    imageUrl: string;
    itemLabel: string;
    successLabel?: string;
    variantId: string;
}) {
    const keyRef = useRef(newAttemptKey());
    const statusRef = useRef<HTMLParagraphElement>(null);
    const [loading, setLoading] = useState(false);
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!success) {
            return;
        }

        const timer = window.setTimeout(() => setSuccess(false), 2000);

        return () => window.clearTimeout(timer);
    }, [success]);

    const add = async (button: HTMLButtonElement) => {
        setLoading(true);
        setError(false);
        setSuccess(false);

        try {
            const result = await submitCatalogCart({
                cartUrl: addUrl,
                idempotencyKey: keyRef.current,
                variantId,
            });
            keyRef.current = newAttemptKey();
            setSuccess(true);
            // The item is in the cart: release the button before the flight
            // so the success look gets its full two seconds.
            setLoading(false);
            await announceCartAddition({
                analytics,
                cartUrl: result.cartUrl,
                from: button,
                imageAlt,
                imageUrl,
                itemLabel,
            });
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: result.cartCount,
                }),
            );
        } catch (failure) {
            if (
                failure instanceof CatalogCartRequestError &&
                failure.conclusive
            ) {
                keyRef.current = newAttemptKey();
            }

            setError(true);
            queueMicrotask(() => statusRef.current?.focus());
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="store-catalog-add">
            <button
                data-state={loading ? 'loading' : success ? 'success' : 'idle'}
                disabled={loading}
                onClick={(event) => void add(event.currentTarget)}
                type="button"
            >
                {loading
                    ? loadingLabel
                    : success
                      ? (successLabel ?? idleLabel)
                      : idleLabel}
            </button>
            {error ? (
                <p ref={statusRef} role="alert" tabIndex={-1}>
                    {errorLabel}
                </p>
            ) : null}
        </div>
    );
}
