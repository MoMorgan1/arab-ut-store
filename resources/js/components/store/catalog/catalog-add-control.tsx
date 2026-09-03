import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { newAttemptKey } from '@/lib/attempt-key';
import {
    announceCartAddition,
    announceCartDuplicate,
} from '@/lib/cart-added-event';
import type { CartAddedAnalytics } from '@/lib/cart-added-event';
import {
    CatalogCartRequestError,
    submitCatalogCart,
} from '@/lib/catalog-cart-api';
import { formatMinorUnits } from '@/lib/money';
import type { StoreBasePageProps } from '@/types/store-content';

export function CatalogAddControl({
    addUrl,
    analytics,
    currency,
    amountMinor,
    errorLabel,
    idleLabel,
    inCartLabel,
    loadingLabel,
    imageAlt,
    imageUrl,
    itemLabel,
    locale,
    openCartLabel,
    selectionLabel,
    successLabel,
    variantId,
}: {
    addUrl: string;
    analytics?: CartAddedAnalytics;
    /** Display-currency price parts for the sheet's line price, if known. */
    currency?: string;
    amountMinor?: number;
    errorLabel: string;
    idleLabel: string;
    inCartLabel: string;
    loadingLabel: string;
    imageAlt: string;
    imageUrl: string;
    itemLabel: string;
    locale: 'ar' | 'en';
    openCartLabel: string;
    selectionLabel?: string;
    successLabel?: string;
    variantId: string;
}) {
    const keyRef = useRef(newAttemptKey());
    const statusRef = useRef<HTMLParagraphElement>(null);
    const pageProps = usePage<StoreBasePageProps>().props;
    const cartVariantIds = pageProps.cartVariantIds ?? [];
    const cartUrl = pageProps.storeShell.cartUrl;
    const [addedVariantIds, setAddedVariantIds] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!success) {
            return;
        }

        // The in-cart look takes over once the two-second success look ends.
        const timer = window.setTimeout(() => {
            setSuccess(false);
            setAddedVariantIds((current) =>
                current.includes(variantId) ? current : [...current, variantId],
            );
        }, 2000);

        return () => window.clearTimeout(timer);
    }, [success, variantId]);

    const inCart =
        cartVariantIds.includes(variantId) ||
        addedVariantIds.includes(variantId);

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
                cartCount: result.cartCount,
                cartTotalHalalah: result.cartTotalHalalah,
                cartUrl: result.cartUrl,
                from: button,
                imageAlt,
                imageUrl,
                itemLabel,
                priceLabel:
                    amountMinor === undefined || currency === undefined
                        ? undefined
                        : formatMinorUnits(amountMinor, currency, locale),
                selectionLabel,
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

            if (
                failure instanceof CatalogCartRequestError &&
                failure.code === 'already_in_cart'
            ) {
                setAddedVariantIds((current) =>
                    current.includes(variantId)
                        ? current
                        : [...current, variantId],
                );
                setLoading(false);
                announceCartDuplicate({
                    cartUrl: failure.cartUrl ?? cartUrl,
                    imageAlt,
                    imageUrl,
                    itemLabel,
                    selectionLabel,
                });

                return;
            }

            setError(true);
            queueMicrotask(() => statusRef.current?.focus());
        } finally {
            setLoading(false);
        }
    };

    if (inCart && !loading && !success) {
        return (
            <div className="store-catalog-add store-catalog-add--in-cart">
                <button data-state="in-cart" disabled type="button">
                    {inCartLabel}
                </button>
                <a href={cartUrl}>{openCartLabel}</a>
            </div>
        );
    }

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
