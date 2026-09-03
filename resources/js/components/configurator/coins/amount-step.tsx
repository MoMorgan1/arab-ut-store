import { useLayoutEffect, useMemo, useRef, useState } from 'react';

import type { CSSProperties, Ref } from 'react';
import { nearestStopIndex, sliderStops } from '@/lib/coins-quantity';

import { formatCoins, formatCompactCoins } from '@/lib/money';
import type {
    CoinsAmountRules,
    CoinsDeliveryValue,
    CoinsQuoteViewState,
    CoinsStoreTranslations,
} from '@/types/coins';

import { QuotePanel } from './quote-panel';

type AmountStepProps = {
    amount: CoinsAmountRules;
    focusRef: Ref<HTMLHeadingElement>;
    isValid: boolean;
    locale: 'ar' | 'en';
    maximum: number;
    delivery: CoinsDeliveryValue | null;
    onAdjust: (delta: number) => void;
    onBack: () => void;
    onCommit: (value: number) => void;
    onQuantityBlur: () => void;
    onQuantityChange: (value: string) => void;
    onSwitchToFast: () => void;
    onContinue: () => void;
    quantity: number;
    quantityInput: string;
    quoteState: CoinsQuoteViewState;
    translations: CoinsStoreTranslations;
};

const DECREMENTS = [-1_000_000, -500_000, -100_000, -50_000];
const INCREMENTS = [50_000, 100_000, 500_000, 1_000_000];

function adjustmentLabel(delta: number, locale: 'ar' | 'en'): string {
    return `${delta > 0 ? '+' : '-'}${formatCompactCoins(Math.abs(delta), locale)}`;
}

export function AmountStep({
    amount,
    delivery,
    focusRef,
    isValid,
    locale,
    maximum,
    onAdjust,
    onBack,
    onCommit,
    onContinue,
    onQuantityBlur,
    onQuantityChange,
    onSwitchToFast,
    quantity,
    quantityInput,
    quoteState,
    translations,
}: AmountStepProps) {
    const [isEditing, setIsEditing] = useState(false);
    const amountInputRef = useRef<HTMLInputElement | null>(null);
    const pendingSelection = useRef<{ end: number; start: number } | null>(
        null,
    );
    const sliderQuantities = useMemo(
        () => sliderStops(amount.minimum, amount.tiers, maximum),
        [amount.minimum, amount.tiers, maximum],
    );
    // The rail ends at the highest buyable stop. That is the delivery maximum
    // whenever the pricing tiers cover it; if an edited schedule stops short,
    // the rail shrinks with it instead of leaving an unreachable dead zone.
    const sliderMaximum =
        sliderQuantities[sliderQuantities.length - 1] ?? maximum;
    const fillPercentage =
        sliderMaximum === amount.minimum
            ? 0
            : ((quantity - amount.minimum) / (sliderMaximum - amount.minimum)) *
              100;
    const sliderStyle = {
        '--coins-slider-fill': `${Math.max(0, Math.min(100, fillPercentage)).toFixed(2)}%`,
    } as CSSProperties;

    useLayoutEffect(() => {
        const input = amountInputRef.current;
        const selection = pendingSelection.current;

        if (input === null || selection === null) {
            return;
        }

        input.setSelectionRange(
            positionAfterDigits(input.value, selection.start),
            positionAfterDigits(input.value, selection.end),
        );
        pendingSelection.current = null;
    }, [isEditing, locale, quantityInput]);

    function commitDirectly(value: number) {
        setIsEditing(true);
        onCommit(value);
    }

    function adjustDirectly(delta: number) {
        setIsEditing(true);
        onAdjust(delta);
    }

    return (
        <div className="coins-step">
            <h2 className="coins-step__title" ref={focusRef} tabIndex={-1}>
                {translations.amount_copy.title}
            </h2>
            <p className="coins-step__help">{translations.amount_copy.help}</p>

            <div className="coins-amount-field">
                <label className="sr-only" htmlFor="coins-amount">
                    {translations.amount_copy.label}
                </label>
                <div className="coins-amount-field__control">
                    <input
                        aria-invalid={!isValid}
                        className="coins-amount-input"
                        id="coins-amount"
                        inputMode="numeric"
                        onBlur={() => {
                            setIsEditing(false);
                            onQuantityBlur();
                        }}
                        onChange={(event) => {
                            const input = event.currentTarget;

                            pendingSelection.current = {
                                end: digitsBefore(
                                    input.value,
                                    input.selectionEnd,
                                ),
                                start: digitsBefore(
                                    input.value,
                                    input.selectionStart,
                                ),
                            };
                            onQuantityChange(input.value);
                        }}
                        onFocus={(event) => {
                            setIsEditing(true);
                            event.currentTarget.select();
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                event.currentTarget.blur();
                            }
                        }}
                        type="text"
                        ref={amountInputRef}
                        value={
                            isEditing
                                ? quantityInput === ''
                                    ? ''
                                    : formatCoins(Number(quantityInput), locale)
                                : formatCoins(quantity, locale)
                        }
                    />
                    <span>{translations.units.coins}</span>
                </div>
            </div>

            <div
                aria-label={translations.amount_copy.preset_label}
                className="coins-quick-amounts"
                role="group"
            >
                {amount.presets
                    .filter((preset) => preset <= maximum)
                    .map((preset) => (
                        <button
                            aria-pressed={isValid && quantity === preset}
                            key={preset}
                            onClick={() => {
                                if (!isValid || quantity !== preset) {
                                    commitDirectly(preset);
                                }
                            }}
                            type="button"
                        >
                            {formatCompactCoins(preset, locale)}
                        </button>
                    ))}
            </div>

            {/*
             * The thumb sits proportional to the quantity itself — halfway
             * along the rail is halfway to the maximum — while drags still
             * snap to the pre-priced schedule stops, so dragging never waits
             * on the network. Arrow keys are handled directly and move one
             * buyable stop: the pointer and the keyboard cannot be told
             * apart from the change delta alone, because on a narrow range
             * one pixel of drag is smaller than one rounding unit.
             */}
            <input
                aria-label={translations.amount_copy.slider_label}
                aria-valuetext={`${formatCoins(quantity, locale)} ${translations.units.coins}`}
                className="coins-amount-slider"
                max={sliderMaximum}
                min={amount.minimum}
                onChange={(event) => {
                    const raw = Number(event.currentTarget.value);
                    const next =
                        sliderQuantities[
                            nearestStopIndex(raw, sliderQuantities)
                        ];

                    if (next !== undefined && next !== quantity) {
                        commitDirectly(next);
                    }
                }}
                onKeyDown={(event) => {
                    const next = stopForKey(
                        event.key,
                        sliderQuantities,
                        quantity,
                    );

                    if (next === null) {
                        return;
                    }

                    event.preventDefault();

                    if (next !== undefined && next !== quantity) {
                        commitDirectly(next);
                    }
                }}
                step={amount.roundingUnit}
                style={sliderStyle}
                type="range"
                value={quantity}
            />

            <div className="coins-slider-labels">
                <span
                    aria-label={`${translations.amount_copy.minimum_label}: ${formatCompactCoins(amount.minimum, locale)}`}
                >
                    {formatCompactCoins(amount.minimum, locale)}
                </span>
                <span
                    aria-label={`${translations.amount_copy.maximum_label}: ${formatCompactCoins(maximum, locale)}`}
                >
                    {formatCompactCoins(maximum, locale)}
                </span>
            </div>

            <div className="coins-adjustments">
                <div>
                    {DECREMENTS.map((delta) => (
                        <button
                            className="coins-adjustment coins-adjustment--minus"
                            key={delta}
                            onClick={() => adjustDirectly(delta)}
                            type="button"
                        >
                            {adjustmentLabel(delta, locale)}
                        </button>
                    ))}
                </div>
                <div>
                    {INCREMENTS.map((delta) => (
                        <button
                            className="coins-adjustment coins-adjustment--plus"
                            key={delta}
                            onClick={() => adjustDirectly(delta)}
                            type="button"
                        >
                            {adjustmentLabel(delta, locale)}
                        </button>
                    ))}
                </div>
            </div>

            {delivery === 'normal' && quantity >= 1_500_000 ? (
                <aside
                    aria-label={
                        translations.amount_copy.normal_delivery_suggestion
                    }
                    className="coins-fast-suggestion"
                >
                    <span
                        aria-hidden="true"
                        className="coins-fast-suggestion__icon"
                    >
                        <svg
                            fill="none"
                            height="18"
                            viewBox="0 0 24 24"
                            width="18"
                        >
                            <path
                                d="m13 2-8 12h6l-1 8 9-13h-6V2Z"
                                stroke="currentColor"
                                strokeLinejoin="round"
                                strokeWidth="1.8"
                            />
                        </svg>
                    </span>
                    <p className="coins-fast-suggestion__copy">
                        {translations.amount_copy.normal_delivery_suggestion}
                    </p>
                    <button
                        className="coins-fast-suggestion__action"
                        onClick={onSwitchToFast}
                        type="button"
                    >
                        {translations.amount_copy.switch_to_fast}
                    </button>
                </aside>
            ) : null}

            <QuotePanel
                locale={locale}
                state={quoteState}
                translations={translations}
            />
            <div className="coins-step__actions">
                <button
                    className="coins-secondary-action"
                    onClick={onBack}
                    type="button"
                >
                    {translations.actions.back}
                </button>
                <button
                    className="coins-primary-action"
                    disabled={!isValid || quoteState.status !== 'success'}
                    onClick={onContinue}
                    type="button"
                >
                    {translations.actions.continue}
                </button>
            </div>
        </div>
    );
}

function adjacentStop(
    stops: number[],
    quantity: number,
    direction: 1 | -1,
): number | undefined {
    if (direction > 0) {
        return stops.find((stop) => stop > quantity);
    }

    for (let index = stops.length - 1; index >= 0; index -= 1) {
        if (stops[index] < quantity) {
            return stops[index];
        }
    }

    return undefined;
}

/**
 * `null` means the key is not ours and the browser keeps it; `undefined`
 * means the key is handled but there is no stop to move to (already at an
 * end of the schedule), which swallows the keystroke as a quiet no-op.
 */
function stopForKey(
    key: string,
    stops: number[],
    quantity: number,
): number | null | undefined {
    switch (key) {
        case 'ArrowRight':
        case 'ArrowUp':
            return adjacentStop(stops, quantity, 1);
        case 'ArrowLeft':
        case 'ArrowDown':
            return adjacentStop(stops, quantity, -1);
        case 'Home':
            return stops[0];
        case 'End':
            return stops[stops.length - 1];
        default:
            return null;
    }
}

function digitsBefore(value: string, position: number | null): number {
    if (position === null) {
        return value.replace(/[^0-9]/g, '').length;
    }

    return value.slice(0, position).replace(/[^0-9]/g, '').length;
}

function positionAfterDigits(value: string, digitCount: number): number {
    if (digitCount === 0) {
        return 0;
    }

    let seenDigits = 0;

    for (let index = 0; index < value.length; index += 1) {
        if (/[0-9]/.test(value[index])) {
            seenDigits += 1;

            if (seenDigits === digitCount) {
                return index + 1;
            }
        }
    }

    return value.length;
}
