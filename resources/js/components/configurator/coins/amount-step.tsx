import { useLayoutEffect, useRef, useState } from 'react';
import type { CSSProperties, Ref } from 'react';

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
    continueHref: string | null;
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
    continueHref,
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
    const fillPercentage =
        maximum === amount.minimum
            ? 0
            : ((quantity - amount.minimum) / (maximum - amount.minimum)) * 100;
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

            <input
                aria-label={translations.amount_copy.slider_label}
                className="coins-amount-slider"
                max={maximum}
                min={amount.minimum}
                onChange={(event) =>
                    commitDirectly(Number(event.currentTarget.value))
                }
                step={amount.increment}
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

            <div className="coins-step__actions coins-step__actions--amount">
                <button
                    className="coins-secondary-action"
                    onClick={onBack}
                    type="button"
                >
                    {translations.actions.back}
                </button>
            </div>
            <QuotePanel
                locale={locale}
                state={quoteState}
                translations={translations}
            />
            {quoteState.status === 'success' ? (
                continueHref === null ? (
                    <button
                        className="coins-primary-action coins-primary-action--full"
                        onClick={onContinue}
                        type="button"
                    >
                        {translations.actions.continue}
                    </button>
                ) : (
                    <a
                        className="coins-primary-action coins-primary-action--full"
                        href={continueHref}
                    >
                        {translations.actions.continue}
                    </a>
                )
            ) : null}
        </div>
    );
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
