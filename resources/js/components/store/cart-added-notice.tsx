import { useEffect, useRef, useState } from 'react';

import { CART_ADDED_EVENT } from '@/lib/cart-added-event';
import type { CartAddedDetail } from '@/lib/cart-added-event';
import { formatInteger, formatMinorUnits } from '@/lib/money';
import type { StoreShellTranslations } from '@/types/store-shell';

const CART_NOTICE_DURATION_MS = 5_000;
const EXIT_MS = 180;
const SWIPE_UP_PX = 40;

export function CartAddedNotice({
    locale,
    translations,
}: {
    locale: 'ar' | 'en';
    translations: StoreShellTranslations['cart_added'];
}) {
    const [addition, setAddition] = useState<CartAddedDetail | null>(null);
    const [leaving, setLeaving] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const timerRef = useRef<number | null>(null);
    const exitRef = useRef<number | null>(null);
    const timerStartedAtRef = useRef<number | null>(null);
    const remainingMsRef = useRef(CART_NOTICE_DURATION_MS);
    const touchStartYRef = useRef<number | null>(null);

    useEffect(() => {
        const showAddition = (event: Event) => {
            if (exitRef.current !== null) {
                window.clearTimeout(exitRef.current);
                exitRef.current = null;
            }

            setAddition((event as CustomEvent<CartAddedDetail>).detail);
            setLeaving(false);
            setIsPaused(false);
        };

        window.addEventListener(CART_ADDED_EVENT, showAddition);

        return () => window.removeEventListener(CART_ADDED_EVENT, showAddition);
    }, []);

    // Escape closes the sheet from wherever focus is: after an add the
    // shopper's focus is still on the add button, never inside the sheet.
    useEffect(() => {
        if (addition === null) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                dismiss();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [addition]);

    // The exit timer outlives a navigation away (the cart links are the
    // common exit), so it is released on unmount.
    useEffect(
        () => () => {
            if (exitRef.current !== null) {
                window.clearTimeout(exitRef.current);
            }
        },
        [],
    );

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
            dismiss();
        }, CART_NOTICE_DURATION_MS);

        return () => {
            if (timerRef.current !== null) {
                window.clearTimeout(timerRef.current);
                timerRef.current = null;
            }

            timerStartedAtRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [addition]);

    function dismiss() {
        if (addition === null) {
            return;
        }

        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }

        timerStartedAtRef.current = null;
        setLeaving(true);

        if (exitRef.current !== null) {
            window.clearTimeout(exitRef.current);
        }

        exitRef.current = window.setTimeout(() => {
            exitRef.current = null;
            setAddition(null);
            setLeaving(false);
        }, EXIT_MS);
    }

    const pauseNoticeTimer = () => {
        if (leaving) {
            return;
        }

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
        if (addition === null || leaving || timerRef.current !== null) {
            return;
        }

        if (remainingMsRef.current <= 0) {
            dismiss();

            return;
        }

        timerStartedAtRef.current = Date.now();

        timerRef.current = window.setTimeout(() => {
            timerRef.current = null;
            timerStartedAtRef.current = null;
            remainingMsRef.current = 0;
            dismiss();
        }, remainingMsRef.current);
        setIsPaused(false);
    };

    if (addition === null) {
        return null;
    }

    const isDuplicate = addition.variant === 'duplicate';
    const pills =
        addition.selectionLabel === undefined
            ? []
            : addition.selectionLabel
                  .split(' · ')
                  .filter((pill) => pill !== '');
    const subLine = isDuplicate
        ? translations.duplicate_hint
        : (addition.cartCount === 1 && translations.in_cart_one !== undefined
              ? translations.in_cart_one
              : translations.in_cart
          )
              .replace(':count', formatInteger(addition.cartCount ?? 0, locale))
              .replace(
                  ':total',
                  addition.cartTotalHalalah === undefined
                      ? ''
                      : formatMinorUnits(
                            addition.cartTotalHalalah,
                            'SAR',
                            locale,
                        ),
              );

    return (
        <aside
            aria-atomic="true"
            className={[
                'store-cart-sheet',
                isDuplicate ? 'store-cart-sheet--duplicate' : '',
                leaving ? 'store-cart-sheet--leaving' : '',
            ]
                .filter(Boolean)
                .join(' ')}
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
                touchStartYRef.current = null;

                if (event.pointerType === 'touch') {
                    resumeNoticeTimer();
                }
            }}
            onPointerDown={(event) => {
                if (event.pointerType === 'touch') {
                    touchStartYRef.current =
                        typeof event.clientY === 'number'
                            ? event.clientY
                            : null;
                    pauseNoticeTimer();
                }
            }}
            onPointerUp={(event) => {
                if (event.pointerType !== 'touch') {
                    return;
                }

                const startY = touchStartYRef.current;
                touchStartYRef.current = null;

                if (startY !== null && startY - event.clientY > SWIPE_UP_PX) {
                    dismiss();

                    return;
                }

                resumeNoticeTimer();
            }}
            role="status"
        >
            <div className="store-cart-sheet__head">
                <span aria-hidden="true" className="store-cart-sheet__ring">
                    <svg viewBox="0 0 36 36">
                        <circle
                            className="store-cart-sheet__ring-circle"
                            cx="18"
                            cy="18"
                            r="15.5"
                        />
                        {isDuplicate ? (
                            <g className="store-cart-sheet__ring-glyph">
                                <path d="M18 10.5v8" />
                                <circle cx="18" cy="23.5" r="1.4" />
                            </g>
                        ) : (
                            <path
                                className="store-cart-sheet__ring-glyph"
                                d="m12 18.5 4.2 4.2 8.3-8.7"
                            />
                        )}
                    </svg>
                </span>
                <span className="store-cart-sheet__titles">
                    <strong>
                        {isDuplicate
                            ? translations.duplicate_title
                            : translations.title}
                    </strong>
                    <span>{subLine}</span>
                </span>
                <button
                    aria-label={translations.dismiss}
                    className="store-cart-sheet__close"
                    onClick={dismiss}
                    type="button"
                >
                    <svg aria-hidden="true" viewBox="0 0 16 16">
                        <path d="m4 4 8 8M12 4l-8 8" />
                    </svg>
                </button>
            </div>
            <div className="store-cart-sheet__item">
                <span
                    aria-hidden="true"
                    className={[
                        'store-cart-sheet__art',
                        addition.raisedArt === true
                            ? 'store-cart-sheet__art--raised'
                            : '',
                    ]
                        .filter(Boolean)
                        .join(' ')}
                >
                    <img
                        alt=""
                        height="64"
                        src={addition.imageUrl}
                        width="64"
                    />
                </span>
                <span className="store-cart-sheet__info">
                    <strong>{addition.itemLabel}</strong>
                    {pills.length > 0 ? (
                        <span className="store-cart-sheet__pills">
                            {pills.map((pill) => (
                                <span
                                    className="store-cart-sheet__pill"
                                    key={pill}
                                >
                                    {pill}
                                </span>
                            ))}
                        </span>
                    ) : null}
                </span>
                {addition.priceLabel !== undefined ? (
                    <strong className="store-cart-sheet__price">
                        {addition.priceLabel}
                    </strong>
                ) : null}
            </div>
            <div className="store-cart-sheet__footer">
                {isDuplicate ? (
                    <a
                        className="store-cart-sheet__cart store-cart-sheet__cart--full"
                        href={addition.cartUrl}
                    >
                        {translations.open_cart}
                    </a>
                ) : (
                    <>
                        <a
                            className="store-cart-sheet__checkout"
                            href={addition.cartUrl}
                        >
                            {translations.checkout}
                        </a>
                        <a
                            className="store-cart-sheet__cart"
                            href={addition.cartUrl}
                        >
                            {translations.cart}
                        </a>
                    </>
                )}
            </div>
            <span aria-hidden="true" className="store-cart-sheet__progress" />
        </aside>
    );
}
