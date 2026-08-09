import type { CoinsStoreTranslations } from '@/types/coins';

import { interpolate } from './configurator-copy';

export type CoinsStep = 'platform' | 'delivery' | 'amount';

type ProgressRailProps = {
    current: CoinsStep;
    includesDelivery: boolean;
    translations: Pick<CoinsStoreTranslations, 'accessibility' | 'progress'>;
};

export function ProgressRail({
    current,
    includesDelivery,
    translations,
}: ProgressRailProps) {
    const steps: Array<{ value: CoinsStep; label: string }> = [
        { value: 'platform', label: translations.progress.platform },
        ...(includesDelivery
            ? [
                  {
                      value: 'delivery' as const,
                      label: translations.progress.delivery,
                  },
              ]
            : []),
        { value: 'amount', label: translations.progress.amount },
    ];
    const currentIndex = steps.findIndex((step) => step.value === current);
    const ariaLabel = interpolate(translations.accessibility.steps, {
        current: currentIndex + 1,
        total: steps.length,
    });

    return (
        <ol aria-label={ariaLabel} className="coins-progress">
            {steps.map((step, index) => (
                <li
                    aria-current={step.value === current ? 'step' : undefined}
                    className="coins-progress__item"
                    data-complete={index < currentIndex ? '' : undefined}
                    key={step.value}
                >
                    <span aria-hidden="true" className="coins-progress__number">
                        {index + 1}
                    </span>
                    <span>{step.label}</span>
                </li>
            ))}
        </ol>
    );
}
