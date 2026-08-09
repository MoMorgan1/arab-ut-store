import type { Ref } from 'react';

import { formatCoins } from '@/lib/money';
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
    onBack: () => void;
    onPreset: (preset: number) => void;
    onQuantityChange: (value: string) => void;
    onRestart: () => void;
    product: CoinsProductSummary;
    quantity: number | null;
    quantityInput: string;
    quoteState: CoinsQuoteViewState;
    translations: CoinsStoreTranslations;
};

export function AmountStep({
    amount,
    focusRef,
    isValid,
    locale,
    maximum,
    onBack,
    onPreset,
    onQuantityChange,
    onRestart,
    product,
    quantity,
    quantityInput,
    quoteState,
    translations,
}: AmountStepProps) {
    return (
        <div className="coins-step">
            <h2 className="coins-step__title" ref={focusRef} tabIndex={-1}>
                {translations.amount_copy.title}
            </h2>
            <p className="coins-step__help">{translations.amount_copy.help}</p>
            <label className="coins-amount-label" htmlFor="coins-amount">
                {translations.amount_copy.label}
            </label>
            <div className="coins-amount-field">
                <input
                    aria-invalid={!isValid}
                    className="coins-amount-input"
                    id="coins-amount"
                    inputMode="numeric"
                    max={maximum}
                    min={amount.minimum}
                    onChange={(event) =>
                        onQuantityChange(event.currentTarget.value)
                    }
                    step={amount.increment}
                    type="number"
                    value={quantityInput}
                />
                <span>{translations.units.coins}</span>
            </div>
            <fieldset className="coins-presets">
                <legend>{translations.amount_copy.preset_label}</legend>
                <div className="coins-presets__grid">
                    {amount.presets
                        .filter((preset) => preset <= maximum)
                        .map((preset) => (
                            <button
                                aria-pressed={quantity === preset}
                                key={preset}
                                onClick={() => onPreset(preset)}
                                type="button"
                            >
                                {formatCoins(preset, locale)}
                            </button>
                        ))}
                </div>
            </fieldset>
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
                onRestart={onRestart}
                state={quoteState}
                translations={translations}
            />
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
