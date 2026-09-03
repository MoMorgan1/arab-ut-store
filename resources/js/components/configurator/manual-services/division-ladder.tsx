import { formatInteger } from '@/lib/money';
import type {
    Division,
    RivalsServiceTranslations,
} from '@/types/manual-services';

import { ServiceSlider } from './service-slider';

export function DivisionLadder({
    from,
    ladder,
    locale,
    onFromChange,
    onToChange,
    price,
    service,
    to,
}: {
    from: Division;
    ladder: Division[];
    locale: 'ar' | 'en';
    onFromChange: (value: Division) => void;
    onToChange: (value: Division) => void;
    price: string;
    service: RivalsServiceTranslations;
    to: Division;
}) {
    const fromIndex = ladder.indexOf(from);
    const toIndex = ladder.indexOf(to);
    const direction = locale === 'ar' ? 'rtl' : 'ltr';
    const targets = ladder.slice(fromIndex + 1);
    const steps = Math.max(0, toIndex - fromIndex);

    function formatDivisionShort(value: Division) {
        return value === 'elite'
            ? service.elite
            : formatInteger(Number(value), locale);
    }

    function formatDivisionFull(value: Division) {
        return value === 'elite'
            ? service.elite
            : service.division.replace(
                  ':division',
                  formatInteger(Number(value), locale),
              );
    }

    const routeSummary = service.route_summary
        .replace(':from', formatDivisionFull(from))
        .replace(':to', formatDivisionFull(to));
    const stepsCount = service.steps_count.replace(
        ':count',
        formatInteger(steps, locale),
    );

    return (
        <div className="manual-division-ladder">
            {/* Current Division Slider */}
            <ServiceSlider
                direction={direction}
                inputName="from-division"
                legend={service.current_legend}
                maxValue={ladder.length - 2}
                minValue={0}
                onValueChange={(index) => {
                    const value = ladder[index];

                    if (value === undefined || value === 'elite') {
                        return;
                    }

                    onFromChange(value);
                    onToChange('elite');
                }}
                selectedValue={fromIndex}
                stopLabels={ladder
                    .slice(0, -1)
                    .map((val) => formatDivisionShort(val))}
                valueLabel={formatDivisionFull(from)}
            />

            {/* Compact Summary Row (plain text on surface, no track/dots) */}
            <div aria-hidden="true" className="manual-route-strip">
                <span className="manual-route-strip__route">
                    {routeSummary}
                </span>
                <span className="manual-route-strip__badge">{stepsCount}</span>
            </div>

            {/* Target Division Slider */}
            <ServiceSlider
                direction={direction}
                inputName="to-division"
                legend={service.target_legend}
                maxValue={ladder.length - 1}
                minValue={fromIndex + 1}
                onValueChange={(index) => {
                    const value = ladder[index];

                    if (value !== undefined) {
                        onToChange(value);
                    }
                }}
                price={price}
                selectedValue={toIndex}
                stopLabels={targets.map((val) => formatDivisionShort(val))}
                valueLabel={formatDivisionFull(to)}
            />
        </div>
    );
}
