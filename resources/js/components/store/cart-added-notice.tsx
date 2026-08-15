import { useEffect, useRef, useState } from 'react';

import { CART_ADDED_EVENT } from '@/lib/cart-added-event';
import type { CartAddedDetail } from '@/lib/cart-added-event';
import type { StoreShellTranslations } from '@/types/store-shell';

const CART_NOTICE_DURATION_MS = 5_000;

export function CartAddedNotice({
    translations,
}: {
    translations: StoreShellTranslations['cart_added'];
}) {
    const [addition, setAddition] = useState<CartAddedDetail | null>(null);
    const [isPaused, setIsPaused] = useState(false);
    const timerRef = useRef<number | null>(null);
    const timerStartedAtRef = useRef<number | null>(null);
    const remainingMsRef = useRef(CART_NOTICE_DURATION_MS);

    useEffect(() => {
        const showAddition = (event: Event) => {
            setAddition((event as CustomEvent<CartAddedDetail>).detail);
            setIsPaused(false);
        };

        window.addEventListener(CART_ADDED_EVENT, showAddition);

        return () => window.removeEventListener(CART_ADDED_EVENT, showAddition);
    }, []);

    useEffect(() => {
        if (addition === null) {
            return;
        }

        remainingMsRef.current = CART_NOTICE_DURATION_MS;
        timerStartedAtRef.current = Date.now();
        timerRef.current = window.setTimeout(() => {
            timerRef.current = null;
            timerStartedAtRef.current = null;
            remainingMsRef.current = 0;
            setAddition(null);
        }, CART_NOTICE_DURATION_MS);

        return () => {
            if (timerRef.current !== null) {
                window.clearTimeout(timerRef.current);
                timerRef.current = null;
            }

            timerStartedAtRef.current = null;
        };
    }, [addition]);

    const pauseNoticeTimer = () => {
        if (timerRef.current !== null) {
            const elapsed =
                Date.now() - (timerStartedAtRef.current ?? Date.now());

            remainingMsRef.current = Math.max(
                0,
                remainingMsRef.current - elapsed,
            );

            window.clearTimeout(timerRef.current);
            timerRef.current = null;
            timerStartedAtRef.current = null;
        }

        setIsPaused(true);
    };

    const resumeNoticeTimer = () => {
        if (addition === null || timerRef.current !== null) {
            return;
        }

        if (remainingMsRef.current <= 0) {
            setAddition(null);

            return;
        }

        timerStartedAtRef.current = Date.now();

        timerRef.current = window.setTimeout(() => {
            timerRef.current = null;
            timerStartedAtRef.current = null;
            remainingMsRef.current = 0;
            setAddition(null);
        }, remainingMsRef.current);
        setIsPaused(false);
    };

    if (addition === null) {
        return null;
    }

    return (
        <aside
            aria-atomic="true"
            className="store-cart-added"
            data-paused={isPaused}
            onBlur={(event) => {
                if (
                    !event.currentTarget.contains(
                        event.relatedTarget as Node | null,
                    )
                ) {
                    resumeNoticeTimer();
                }
            }}
            onFocus={pauseNoticeTimer}
            onMouseEnter={pauseNoticeTimer}
            onMouseLeave={resumeNoticeTimer}
            onPointerCancel={(event) => {
                if (event.pointerType === 'touch') {
                    resumeNoticeTimer();
                }
            }}
            onPointerDown={(event) => {
                if (event.pointerType === 'touch') {
                    pauseNoticeTimer();
                }
            }}
            onPointerUp={(event) => {
                if (event.pointerType === 'touch') {
                    resumeNoticeTimer();
                }
            }}
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
