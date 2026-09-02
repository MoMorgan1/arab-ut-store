import type { CSSProperties } from 'react';

export function ServiceSlider({
    direction,
    inputName,
    legend,
    maxValue,
    minValue,
    onValueChange,
    price,
    selectedValue,
    stopLabels,
    valueLabel,
}: {
    direction: 'ltr' | 'rtl';
    inputName: string;
    legend: string;
    maxValue: number;
    minValue: number;
    onValueChange: (selectedValue: number) => void;
    price?: string;
    selectedValue: number;
    stopLabels: string[];
    valueLabel: string;
}) {
    const progress =
        maxValue === minValue
            ? '100%'
            : `${((selectedValue - minValue) / (maxValue - minValue)) * 100}%`;
    const accessibleValue =
        price === undefined ? valueLabel : `${valueLabel} · ${price}`;

    return (
        <fieldset className="manual-service-slider">
            <legend className="manual-service-slider__legend">{legend}</legend>
            <div className="manual-service-slider__surface">
                <div className="manual-service-slider__header">
                    <div className="manual-service-slider__cluster">
                        <span
                            className="manual-service-slider__value"
                            key={`label-${selectedValue}-${valueLabel}`}
                        >
                            {valueLabel}
                        </span>
                        {price === undefined ? null : (
                            <strong
                                aria-live="polite"
                                className="manual-service-slider__price manual-service-slider__value"
                                key={`price-${selectedValue}-${price}`}
                            >
                                {price}
                            </strong>
                        )}
                    </div>
                </div>
                <div className="manual-service-slider__track-wrap">
                    <input
                        aria-label={legend}
                        aria-valuetext={accessibleValue}
                        dir={direction}
                        max={maxValue}
                        min={minValue}
                        name={inputName}
                        onChange={(event) =>
                            onValueChange(Number(event.currentTarget.value))
                        }
                        step="1"
                        style={
                            {
                                '--manual-slider-progress': progress,
                            } as CSSProperties
                        }
                        type="range"
                        value={selectedValue}
                    />
                </div>
                <div
                    className="manual-service-slider__labels"
                    style={
                        {
                            '--stop-count': stopLabels.length,
                        } as CSSProperties
                    }
                >
                    {stopLabels.map((label, index) => {
                        const stopVal = minValue + index;
                        const isSelected = selectedValue === stopVal;
                        const isPassed = stopVal <= selectedValue;

                        return (
                            <button
                                aria-label={`${legend}: ${label}`}
                                className="manual-service-slider__tick-btn"
                                data-active={isSelected}
                                data-passed={isPassed}
                                key={`${index}-${label}`}
                                onClick={() => onValueChange(stopVal)}
                                type="button"
                            >
                                <span
                                    aria-hidden="true"
                                    className="manual-service-slider__tick-dot"
                                />
                                <span className="manual-service-slider__tick-label">
                                    {label}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </fieldset>
    );
}
