import { useEffect, useState } from 'react';

import { CART_ADDED_EVENT } from '@/lib/cart-added-event';
import type { CartAddedDetail } from '@/lib/cart-added-event';
import type { StoreShellTranslations } from '@/types/store-shell';

export function CartAddedNotice({
    translations,
}: {
    translations: StoreShellTranslations['cart_added'];
}) {
    const [addition, setAddition] = useState<CartAddedDetail | null>(null);
    const [isPaused, setIsPaused] = useState(false);

    useEffect(() => {
        const showAddition = (event: Event) => {
            setAddition((event as CustomEvent<CartAddedDetail>).detail);
            setIsPaused(false);
        };

        window.addEventListener(CART_ADDED_EVENT, showAddition);

        return () => window.removeEventListener(CART_ADDED_EVENT, showAddition);
    }, []);

    useEffect(() => {
        if (addition === null || isPaused) {
            return;
        }

        const timeout = window.setTimeout(() => setAddition(null), 5_000);

        return () => window.clearTimeout(timeout);
    }, [addition, isPaused]);

    if (addition === null) {
        return null;
    }

    return (
        <aside
            aria-atomic="true"
            className="store-cart-added"
            onBlur={(event) => {
                if (
                    !event.currentTarget.contains(
                        event.relatedTarget as Node | null,
                    )
                ) {
                    setIsPaused(false);
                }
            }}
            onFocus={() => setIsPaused(true)}
            onMouseEnter={() => setIsPaused(true)}
            onMouseLeave={() => setIsPaused(false)}
            role="status"
        >
            <div className="store-cart-added__visual">
                <img
                    alt={addition.imageAlt}
                    height="64"
                    src={addition.imageUrl}
                    width="64"
                />
                <span aria-hidden="true" className="store-cart-added__mark">
                    <CartCheckIcon />
                </span>
            </div>
            <div className="store-cart-added__copy">
                <strong>{translations.title}</strong>
                <p>
                    {translations.message.replace(':item', addition.itemLabel)}
                </p>
                {addition.selectionLabel ? (
                    <span className="store-cart-added__selection">
                        {addition.selectionLabel}
                    </span>
                ) : null}
            </div>
            <div className="store-cart-added__actions">
                <a href={addition.cartUrl}>{translations.buy_now}</a>
                <button onClick={() => setAddition(null)} type="button">
                    {translations.continue_shopping}
                </button>
            </div>
            <span aria-hidden="true" className="store-cart-added__progress" />
        </aside>
    );
}

function CartCheckIcon() {
    return (
        <svg height="24" viewBox="0 0 24 24" width="24">
            <path
                d="m5 12.5 4.1 4.1L19 6.8"
                fill="none"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2.2"
            />
        </svg>
    );
}
