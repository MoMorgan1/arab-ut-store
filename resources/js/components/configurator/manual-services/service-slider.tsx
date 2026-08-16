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
            <legend>{legend}</legend>
            <div className="manual-service-slider__surface">
                <div className="manual-service-slider__header">
                    <span
                        className="manual-service-slider__value"
                        key={`label-${selectedValue}-${valueLabel}`}
                    >
                        {valueLabel}
                    </span>
                    {price === undefined ? null : (
                        <strong
                            className="manual-service-slider__value"
                            key={`price-${selectedValue}-${price}`}
                        >
                            {price}
                        </strong>
                    )}
                </div>
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
                <div
                    aria-hidden="true"
                    className="manual-service-slider__labels"
                >
                    {stopLabels.map((label, index) => (
                        <span key={`${index}-${label}`}>{label}</span>
                    ))}
                </div>
            </div>
        </fieldset>
    );
}
