import { useState } from 'react';
import type { CSSProperties, Ref } from 'react';

import { formatCoins, formatCompactCoins } from '@/lib/money';
import type {
    CoinsAmountRules,
    CoinsProductSummary,
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
    onAdjust: (delta: number) => void;
    onBack: () => void;
    onCommit: (value: number) => void;
    onQuantityBlur: () => void;
    onQuantityChange: (value: string) => void;
    onRestart: () => void;
    product: CoinsProductSummary;
    quantity: number;
    quantityInput: string;
    quoteState: CoinsQuoteViewState;
    translations: CoinsStoreTranslations;
};

const DECREMENTS = [-1_000_000, -500_000, -100_000, -50_000];
const INCREMENTS = [50_000, 100_000, 500_000, 1_000_000];

function adjustmentLabel(delta: number): string {
    return `${delta > 0 ? '+' : '-'}${formatCompactCoins(Math.abs(delta))}`;
}

export function AmountStep({
    amount,
    focusRef,
    isValid,
    locale,
    maximum,
    onAdjust,
    onBack,
    onCommit,
    onQuantityBlur,
    onQuantityChange,
    onRestart,
    product,
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
                <label className="coins-amount-label" htmlFor="coins-amount">
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
                                ? quantityInput
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
                            {formatCompactCoins(preset)}
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
                    aria-label={`${translations.amount_copy.minimum_label}: ${formatCompactCoins(amount.minimum)}`}
                >
                    {formatCompactCoins(amount.minimum)}
                </span>
                <span
                    aria-label={`${translations.amount_copy.maximum_label}: ${formatCompactCoins(maximum)}`}
                >
                    {formatCompactCoins(maximum)}
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
                            {adjustmentLabel(delta)}
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
                            {adjustmentLabel(delta)}
                        </button>
                    ))}
                </div>
            </div>

            <QuotePanel
                locale={locale}
                onRestart={onRestart}
                state={quoteState}
                translations={translations}
            />

            <div className="coins-step__actions coins-step__actions--amount">
                <button
                    className="coins-secondary-action"
                    onClick={onBack}
                    type="button"
                >
                    {translations.actions.back}
                </button>
            </div>
            <p className="coins-product-reference">
                <img
                    alt=""
                    aria-hidden="true"
                    height="36"
                    src={product.imageUrl}
                    width="36"
                />
                <span>{product.name}</span>
            </p>
        </div>
    );
}
