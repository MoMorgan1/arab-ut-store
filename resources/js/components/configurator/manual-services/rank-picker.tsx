import { formatInteger, formatMinorUnits } from '@/lib/money';
import type {
    FutServiceTranslations,
    ManualServiceMoney,
} from '@/types/manual-services';

import { FieldError } from './field-error';
import { ServiceSlider } from './service-slider';

export function RankPicker({
    hasPlayed,
    locale,
    matchesError,
    matchesInputRef,
    matchesPlayed,
    onBlurMatches,
    onHasPlayedChange,
    onMatchesPlayedChange,
    onRankChange,
    onUrgentChange,
    pricing,
    rank,
    service,
    urgent,
}: {
    hasPlayed: boolean;
    locale: 'ar' | 'en';
    matchesError?: string;
    matchesInputRef?: (node: HTMLInputElement | null) => void;
    matchesPlayed: number;
    onBlurMatches?: () => void;
    onHasPlayedChange: (hasPlayed: boolean) => void;
    onMatchesPlayedChange: (matches: number) => void;
    onRankChange: (rank: number) => void;
    onUrgentChange: (urgent: boolean) => void;
    pricing: {
        currency: string;
        rankOptions: Array<{
            rank: number;
            price: ManualServiceMoney;
        }>;
        urgentSurcharge: ManualServiceMoney;
    };
    rank: number;
    service: FutServiceTranslations;
    urgent: boolean;
}) {
    const direction = locale === 'ar' ? 'rtl' : 'ltr';
    const minValue = pricing.rankOptions[0]?.rank ?? 1;
    const maxValue = pricing.rankOptions.at(-1)?.rank ?? 6;
    const rankEntry = pricing.rankOptions.find((entry) => entry.rank === rank);
    const rankPrice = rankEntry
        ? formatMinorUnits(
              rankEntry.price.amountMinor,
              rankEntry.price.currency,
              locale,
          )
        : undefined;
    const rankLabel = service.rank.replace(
        ':rank',
        formatInteger(rank, locale),
    );

    const stopLabels = pricing.rankOptions.map((entry) =>
        formatInteger(entry.rank, locale),
    );

    return (
        <div className="manual-rank-picker">
            <ServiceSlider
                direction={direction}
                inputName="rank"
                legend={service.target_legend}
                maxValue={maxValue}
                minValue={minValue}
                onValueChange={onRankChange}
                price={rankPrice}
                selectedValue={rank}
                stopLabels={stopLabels}
                valueLabel={rankLabel}
            />

            <label className="manual-toggle-row" data-checked={urgent}>
                <div className="manual-toggle-row__header">
                    <div className="manual-toggle-row__switch-wrap">
                        <input
                            checked={urgent}
                            name="urgent"
                            onChange={(event) =>
                                onUrgentChange(event.target.checked)
                            }
                            type="checkbox"
                        />
                        <span className="manual-toggle-switch" />
                        <strong className="manual-toggle-row__title">
                            {service.urgent}
                        </strong>
                    </div>
                    <span className="manual-toggle-row__chip">
                        {service.urgent_price}
                    </span>
                </div>
                <p className="manual-toggle-row__eta">
                    {urgent ? service.urgent_eta : service.standard_eta}
                </p>
            </label>

            <fieldset className="manual-played-matches">
                <legend>{service.matches_question}</legend>
                <div className="manual-played-matches__row">
                    <div className="manual-played-matches__choices">
                        <label
                            className="manual-played-chip"
                            data-selected={!hasPlayed}
                        >
                            <input
                                checked={!hasPlayed}
                                className="sr-only"
                                name="matches-played-choice"
                                onChange={() => {
                                    onHasPlayedChange(false);
                                    onMatchesPlayedChange(0);
                                }}
                                type="radio"
                                value="no"
                            />
                            <span>{service.matches_no}</span>
                        </label>
                        <label
                            className="manual-played-chip"
                            data-selected={hasPlayed}
                        >
                            <input
                                checked={hasPlayed}
                                className="sr-only"
                                name="matches-played-choice"
                                onChange={() => {
                                    onHasPlayedChange(true);
                                    onMatchesPlayedChange(
                                        Math.max(1, matchesPlayed),
                                    );
                                }}
                                type="radio"
                                value="yes"
                            />
                            <span>{service.matches_yes}</span>
                        </label>
                    </div>

                    {hasPlayed ? (
                        <div className="manual-played-matches__field-wrap">
                            <label className="manual-played-matches__count">
                                <span>{service.matches_played}</span>
                                <input
                                    aria-describedby={
                                        matchesError
                                            ? 'manual-matches-played-error'
                                            : undefined
                                    }
                                    aria-invalid={matchesError !== undefined}
                                    max="100"
                                    min="1"
                                    name="matches-played"
                                    onBlur={onBlurMatches}
                                    onChange={(event) =>
                                        onMatchesPlayedChange(
                                            Number(event.currentTarget.value),
                                        )
                                    }
                                    ref={matchesInputRef}
                                    required
                                    type="number"
                                    value={matchesPlayed || ''}
                                />
                            </label>
                            <FieldError
                                error={matchesError}
                                id="manual-matches-played-error"
                            />
                        </div>
                    ) : null}
                </div>
            </fieldset>
        </div>
    );
}
