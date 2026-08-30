import { formatInteger } from '@/lib/money';
import type { CoinsStoreTranslations } from '@/types/coins';

import { interpolate } from './configurator-copy';

export type CoinsStep =
    'platform' | 'delivery' | 'amount' | 'credentials' | 'summary';

type ProgressRailProps = {
    current: CoinsStep;
    includesDelivery: boolean;
    locale: 'ar' | 'en';
    onNavigate: (step: CoinsStep) => void;
    translations: Pick<CoinsStoreTranslations, 'accessibility' | 'progress'>;
};

export function ProgressRail({
    current,
    includesDelivery,
    locale,
    onNavigate,
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
        { value: 'credentials', label: translations.progress.credentials },
        { value: 'summary', label: translations.progress.summary },
    ];
    const currentIndex = steps.findIndex((step) => step.value === current);
    const ariaLabel = interpolate(translations.accessibility.steps, {
        current: formatInteger(currentIndex + 1, locale),
        total: formatInteger(steps.length, locale),
    });

    return (
        <ol aria-label={ariaLabel} className="coins-progress">
            {steps.map((step, index) => {
                const isComplete = index < currentIndex;
                const content = (
                    <>
                        <span
                            aria-hidden="true"
                            className="coins-progress__number"
                        >
                            {isComplete ? (
                                <svg
                                    className="coins-progress__check"
                                    fill="none"
                                    height="14"
                                    viewBox="0 0 24 24"
                                    width="14"
                                >
                                    <path
                                        d="m4.5 12.5 5 5L19.5 7"
                                        stroke="currentColor"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="3"
                                    />
                                </svg>
                            ) : (
                                formatInteger(index + 1, locale)
                            )}
                        </span>
                        <span>{step.label}</span>
                    </>
                );

                return (
                    <li
                        aria-current={
                            step.value === current ? 'step' : undefined
                        }
                        className="coins-progress__item"
                        data-complete={isComplete ? '' : undefined}
                        key={step.value}
                    >
                        {isComplete ? (
                            <button
                                className="coins-progress__step"
                                onClick={() => onNavigate(step.value)}
                                type="button"
                            >
                                {content}
                            </button>
                        ) : (
                            <span className="coins-progress__step">
                                {content}
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
