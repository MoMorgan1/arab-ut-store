import type { Ref } from 'react';

import type {
    CoinsPlatformOption,
    CoinsPlatformValue,
    CoinsStoreTranslations,
} from '@/types/coins';

import { SelectionCard } from './selection-card';

type PlatformStepProps = {
    focusRef: Ref<HTMLLegendElement>;
    onChoose: (value: CoinsPlatformValue) => void;
    onContinue: () => void;
    platforms: CoinsPlatformOption[];
    selectedValue: CoinsPlatformValue | null;
    translations: CoinsStoreTranslations;
};

export function PlatformStep({
    focusRef,
    onChoose,
    onContinue,
    platforms,
    selectedValue,
    translations,
}: PlatformStepProps) {
    return (
        <fieldset className="coins-step">
            <legend className="coins-step__title" ref={focusRef} tabIndex={-1}>
                {translations.platform.title}
            </legend>
            <div className="coins-choice-grid coins-choice-grid--platforms">
                {platforms.map((platform) => {
                    const primaryLabel =
                        translations.platform.options[platform.value];

                    return (
                        <SelectionCard
                            checked={platform.value === selectedValue}
                            iconUrls={platform.iconUrls}
                            key={platform.value}
                            label={primaryLabel}
                            name="coins-platform"
                            onChange={() => onChoose(platform.value)}
                            value={platform.value}
                        >
                            <strong>{primaryLabel}</strong>
                            <small>{platform.label}</small>
                        </SelectionCard>
                    );
                })}
            </div>
            <button
                className="coins-primary-action"
                disabled={selectedValue === null}
                onClick={onContinue}
                type="button"
            >
                {translations.actions.continue}
            </button>
        </fieldset>
    );
}
