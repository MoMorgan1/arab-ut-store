import { useState } from 'react';
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
    onQuantityBlur,
    onQuantityChange,
    onSwitchToFast,
    quantity,
    quantityInput,
    quoteState,
    translations,
}: AmountStepProps) {
    const [isEditing, setIsEditing] = useState(false);
    const fillPercentage =
        maximum === amount.minimum
            ? 0
            : ((quantity - amount.minimum) / (maximum - amount.minimum)) * 100;
    const sliderStyle = {
        '--coins-slider-fill': `${Math.max(0, Math.min(100, fillPercentage)).toFixed(2)}%`,
    } as CSSProperties;

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
                        onChange={(event) =>
                            onQuantityChange(event.currentTarget.value)
                        }
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
                <div className="coins-step__actions">
                    <p className="coins-step__help">
                        {translations.amount_copy.normal_delivery_suggestion}
                    </p>
                    <button
                        className="coins-secondary-action"
                        onClick={onSwitchToFast}
                        type="button"
                    >
                        {translations.amount_copy.switch_to_fast}
                    </button>
                </div>
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
        </div>
    );
}
