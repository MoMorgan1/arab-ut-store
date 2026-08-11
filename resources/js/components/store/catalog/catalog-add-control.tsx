import { useRef, useState } from 'react';

import {
    CatalogCartRequestError,
    submitCatalogCart,
} from '@/lib/catalog-cart-api';

function newAttemptKey(): string {
    return typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `catalog-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function CatalogAddControl({
    addUrl,
    errorLabel,
    idleLabel,
    loadingLabel,
    onSuccess,
    variantId,
}: {
    addUrl: string;
    errorLabel: string;
    idleLabel: string;
    loadingLabel: string;
    onSuccess: (cartUrl: string) => void;
    variantId: string;
}) {
    const keyRef = useRef(newAttemptKey());
    const statusRef = useRef<HTMLParagraphElement>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);

    const add = async () => {
        setLoading(true);
        setError(false);

        try {
            const result = await submitCatalogCart({
                cartUrl: addUrl,
                idempotencyKey: keyRef.current,
                variantId,
            });
            keyRef.current = newAttemptKey();
            onSuccess(result.cartUrl);
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
            <button disabled={loading} onClick={() => void add()} type="button">
                {loading ? loadingLabel : idleLabel}
            </button>
            {error ? (
                <p ref={statusRef} role="alert" tabIndex={-1}>
                    {errorLabel}
                </p>
            ) : null}
        </div>
    );
}
