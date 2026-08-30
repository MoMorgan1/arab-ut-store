import type { Ref } from 'react';

import { formatCompactCoins, formatInteger } from '@/lib/money';
import type {
    CoinsDeliveryValue,
    CoinsPlatformOption,
    CoinsStoreTranslations,
} from '@/types/coins';

import { interpolate } from './configurator-copy';
import { SelectionCard } from './selection-card';

type DeliveryStepProps = {
    focusRef: Ref<HTMLLegendElement>;
    locale: 'ar' | 'en';
    onBack: () => void;
    onChoose: (value: CoinsDeliveryValue) => void;
    onContinue: () => void;
    platform: CoinsPlatformOption;
    selectedValue: CoinsDeliveryValue | null;
    translations: CoinsStoreTranslations;
};

export function DeliveryStep({
    focusRef,
    locale,
    onBack,
    onChoose,
    onContinue,
    platform,
    selectedValue,
    translations,
}: DeliveryStepProps) {
    const deliveries = [...platform.deliveries].sort(
        (first, second) =>
            Number(second.value === 'fast') - Number(first.value === 'fast'),
    );

    return (
        <fieldset className="coins-step">
            <legend className="coins-step__title" ref={focusRef} tabIndex={-1}>
                {translations.delivery.title}
            </legend>
            <p className="coins-step__help">{translations.delivery.help}</p>
            <div className="coins-choice-grid coins-choice-grid--delivery">
                {deliveries.map((delivery) => {
                    const label = translations.delivery.options[delivery.value];

                    return (
                        <SelectionCard
                            checked={delivery.value === selectedValue}
                            key={delivery.value}
                            label={label}
                            name="coins-delivery"
                            onChange={() => onChoose(delivery.value)}
                            value={delivery.value}
                        >
                            <span
                                className={`coins-delivery-badge coins-delivery-badge--${delivery.value}`}
                            >
                                {translations.delivery.badges[delivery.value]}
                            </span>
                            <strong>{label}</strong>
                            <small>
                                {interpolate(translations.delivery.eta, {
                                    minutes: formatInteger(
                                        delivery.minutesPerMillion,
                                        locale,
                                    ),
                                })}
                            </small>
                            <span className="coins-delivery-maximum">
                                {interpolate(translations.delivery.maximum, {
                                    maximum: formatCompactCoins(
                                        delivery.maximum,
                                        locale,
                                    ),
                                })}
                            </span>
                        </SelectionCard>
                    );
                })}
            </div>
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
                    disabled={selectedValue === null}
                    onClick={onContinue}
                    type="button"
                >
                    {translations.actions.continue}
                </button>
            </div>
        </fieldset>
    );
}
