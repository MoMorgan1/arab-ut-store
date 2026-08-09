import type { Ref } from 'react';

import type {
    CoinsDeliveryValue,
    CoinsPlatformOption,
    CoinsStoreTranslations,
} from '@/types/coins';

import { interpolate } from './configurator-copy';
import { SelectionCard } from './selection-card';

type DeliveryStepProps = {
    focusRef: Ref<HTMLLegendElement>;
    onBack: () => void;
    onChoose: (value: CoinsDeliveryValue) => void;
    onContinue: () => void;
    platform: CoinsPlatformOption;
    selectedValue: CoinsDeliveryValue | null;
    translations: CoinsStoreTranslations;
};

export function DeliveryStep({
    focusRef,
    onBack,
    onChoose,
    onContinue,
    platform,
    selectedValue,
    translations,
}: DeliveryStepProps) {
    return (
        <fieldset className="coins-step">
            <legend className="coins-step__title" ref={focusRef} tabIndex={-1}>
                {translations.delivery.title}
            </legend>
            <p className="coins-step__help">{translations.delivery.help}</p>
            <div className="coins-choice-grid coins-choice-grid--delivery">
                {platform.deliveries.map((delivery) => {
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
                            <strong>{label}</strong>
                            <small>
                                {interpolate(translations.delivery.eta, {
                                    minutes: delivery.minutesPerMillion,
                                })}
                            </small>
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
