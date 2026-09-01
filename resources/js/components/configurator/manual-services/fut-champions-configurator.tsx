import { useRef, useState } from 'react';

import { announceCartAddition } from '@/lib/cart-added-event';
import {
    ManualServiceCartError,
    submitManualServiceCart,
} from '@/lib/manual-service-cart-api';
import { formatInteger, formatMinorUnits } from '@/lib/money';
import { getInitialFutChampionsConfig } from '@/lib/query-params';
import type {
    FutServiceTranslations,
    ManualServiceCommonTranslations,
    ManualServicePageProps,
    ManualServicePlatform,
    PcLauncher,
} from '@/types/manual-services';
import { emptyManualCredentials } from '@/types/manual-services';

import { CredentialsFields } from './credentials-fields';
import {
    appendCredentials,
    newManualAttemptKey,
    validManualCredentials,
    validSquadImage,
} from './form-utils';
import { ManualOrderSummary } from './order-summary';
import { SelectionCard } from './selection-card';
import { ServiceSlider } from './service-slider';
import { SquadUpload } from './squad-upload';

type FutPricing = Extract<
    NonNullable<ManualServicePageProps['manualService']['pricing']>,
    { rankOptions: unknown }
>;

export function FutChampionsConfigurator({
    addUrl,
    common,
    locale,
    pricing,
    product,
    scheduleVersion,
    service,
    tutorials,
}: {
    addUrl: string;
    common: ManualServiceCommonTranslations;
    locale: 'ar' | 'en';
    pricing: FutPricing;
    product: ManualServicePageProps['manualService']['product'];
    scheduleVersion: number;
    service: FutServiceTranslations;
    tutorials: { ea: string; playstation: string };
}) {
    const formRef = useRef<HTMLFormElement>(null);
    const keyRef = useRef(newManualAttemptKey());
    const [platform, setPlatform] =
        useState<ManualServicePlatform>('playstation');
    const [launcher, setLauncher] = useState<PcLauncher | null>(null);
    const [rank, setRank] = useState(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialFutChampionsConfig(search, pricing.rankOptions).rank;
    });
    const [urgent, setUrgent] = useState(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialFutChampionsConfig(search, pricing.rankOptions).urgent;
    });
    const [hasPlayed, setHasPlayed] = useState(false);
    const [matchesPlayed, setMatchesPlayed] = useState(0);
    const [credentials, setCredentials] = useState(emptyManualCredentials);
    const [image, setImage] = useState<File | null>(null);
    const [imageError, setImageError] = useState<string>();
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const option = pricing.rankOptions.find((entry) => entry.rank === rank);
    const rankLabel = service.rank.replace(
        ':rank',
        formatInteger(rank, locale),
    );
    const rankPrice =
        option === undefined
            ? ''
            : formatMinorUnits(
                  option.price.amountMinor,
                  option.price.currency,
                  locale,
              );
    const price =
        option === undefined
            ? null
            : {
                  amountMinor:
                      option.price.amountMinor +
                      (urgent ? pricing.urgentSurcharge.amountMinor : 0),
                  currency: option.price.currency,
              };

    function choosePlatform(value: ManualServicePlatform) {
        setPlatform(value);
        setLauncher(null);
        setCredentials(emptyManualCredentials());
    }

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (status === 'loading') {
            return;
        }

        if (!validManualCredentials(platform, launcher, credentials)) {
            setStatus('error');
            queueMicrotask(() =>
                formRef.current
                    ?.querySelector<HTMLInputElement>('input:invalid')
                    ?.focus(),
            );

            return;
        }

        const imageIssue = validSquadImage(image);

        if (imageIssue !== null || image === null) {
            setImageError(
                imageIssue === 'size'
                    ? common.image_too_large
                    : imageIssue === 'type'
                      ? common.image_invalid
                      : common.image_required,
            );

            return;
        }

        const form = new FormData();
        form.set('scheduleVersion', String(scheduleVersion));
        form.set('platform', platform);
        form.set('rank', String(rank));
        form.set('urgent', urgent ? '1' : '0');
        form.set('matchesPlayed', String(matchesPlayed));
        form.set('squadImage', image);

        if (platform === 'pc' && launcher !== null) {
            form.set('pcStore', launcher);
        }

        appendCredentials(form, platform, launcher, credentials);
        setStatus('loading');

        try {
            const result = await submitManualServiceCart(
                addUrl,
                form,
                keyRef.current,
            );
            keyRef.current = newManualAttemptKey();
            setStatus('success');
            announceCartAddition({
                cartUrl: result.cartUrl,
                imageAlt: product.image.alt,
                imageUrl: product.image.url,
                itemLabel: product.name,
            });
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: result.cartCount,
                }),
            );
        } catch (failure) {
            if (
                failure instanceof ManualServiceCartError &&
                failure.conclusive
            ) {
                keyRef.current = newManualAttemptKey();
            }

            setStatus('error');
        }
    }

    return (
        <form className="manual-configurator" onSubmit={submit} ref={formRef}>
            <fieldset>
                <legend>{common.platform_legend}</legend>
                <div className="manual-selection-grid">
                    {(['playstation', 'pc'] as const).map((value) => (
                        <SelectionCard
                            checked={platform === value}
                            key={value}
                            name="platform"
                            onChange={() => choosePlatform(value)}
                            value={value}
                        >
                            {common.platforms[value]}
                        </SelectionCard>
                    ))}
                </div>
            </fieldset>
            {platform === 'pc' ? (
                <fieldset>
                    <legend>{common.pc_store_legend}</legend>
                    <div className="manual-selection-grid">
                        {(['ea_app', 'steam'] as const).map((value) => (
                            <SelectionCard
                                checked={launcher === value}
                                key={value}
                                name="launcher"
                                onChange={() => {
                                    setLauncher(value);

                                    if (value === 'ea_app') {
                                        setCredentials((current) => ({
                                            ...current,
                                            steamUsername: '',
                                            steamPassword: '',
                                        }));
                                    }
                                }}
                                value={value}
                            >
                                {common.pc_stores[value]}
                            </SelectionCard>
                        ))}
                    </div>
                </fieldset>
            ) : null}
            <ServiceSlider
                direction={locale === 'ar' ? 'rtl' : 'ltr'}
                inputName="rank"
                legend={service.target_legend}
                maxValue={pricing.rankOptions.at(-1)?.rank ?? 6}
                minValue={pricing.rankOptions[0]?.rank ?? 1}
                onValueChange={setRank}
                price={rankPrice}
                selectedValue={rank}
                stopLabels={pricing.rankOptions.map((entry) =>
                    formatInteger(entry.rank, locale),
                )}
                valueLabel={rankLabel}
            />
            <div className="manual-fut-options">
                <label
                    className="manual-fut-urgent-card"
                    data-selected={urgent}
                >
                    <div className="manual-fut-urgent-card__header">
                        <div className="manual-fut-urgent-card__control">
                            <input
                                checked={urgent}
                                name="urgent"
                                onChange={(event) =>
                                    setUrgent(event.currentTarget.checked)
                                }
                                type="checkbox"
                            />
                            <strong>{service.urgent}</strong>
                        </div>
                        <span className="manual-fut-urgent-card__chip">
                            <small>{service.urgent_price}</small>
                        </span>
                    </div>
                    <small className="manual-service-eta--urgent-option">
                        {urgent ? service.urgent_eta : service.standard_eta}
                    </small>
                </label>
                <fieldset className="manual-played-matches">
                    <legend>{service.matches_question}</legend>
                    <div className="manual-played-matches__choices">
                        <label>
                            <input
                                checked={!hasPlayed}
                                name="matches-played-choice"
                                onChange={() => {
                                    setHasPlayed(false);
                                    setMatchesPlayed(0);
                                }}
                                type="radio"
                                value="no"
                            />
                            <span>{service.matches_no}</span>
                        </label>
                        <label>
                            <input
                                checked={hasPlayed}
                                name="matches-played-choice"
                                onChange={() => {
                                    setHasPlayed(true);
                                    setMatchesPlayed((current) =>
                                        Math.max(1, current),
                                    );
                                }}
                                type="radio"
                                value="yes"
                            />
                            <span>{service.matches_yes}</span>
                        </label>
                    </div>
                    {hasPlayed ? (
                        <label className="manual-played-matches__count">
                            <span>{service.matches_played}</span>
                            <input
                                max="100"
                                min="1"
                                name="matches-played"
                                onChange={(event) =>
                                    setMatchesPlayed(
                                        Number(event.currentTarget.value),
                                    )
                                }
                                required
                                type="number"
                                value={matchesPlayed}
                            />
                        </label>
                    ) : null}
                </fieldset>
            </div>
            <CredentialsFields
                credentials={credentials}
                launcher={launcher}
                onChange={setCredentials}
                platform={platform}
                translations={common}
                tutorials={tutorials}
            />
            <SquadUpload
                error={imageError}
                file={image}
                onChange={(file) => {
                    setImage(file);
                    setImageError(undefined);
                }}
                translations={common}
            />
            <ManualOrderSummary
                facts={[
                    { label: common.review_service, value: product.name },
                    {
                        label: common.review_platform,
                        value: common.platforms[platform],
                    },
                    ...(platform === 'pc' && launcher !== null
                        ? [
                              {
                                  label: common.review_launcher,
                                  value: common.pc_stores[launcher],
                              },
                          ]
                        : []),
                    {
                        label: service.target_legend,
                        value: service.rank.replace(
                            ':rank',
                            formatInteger(rank, locale),
                        ),
                    },
                ]}
                locale={locale}
                price={price}
                translations={common}
            />
            <div className="manual-configurator__action-bar">
                <div className="manual-configurator__live-summary">
                    <div className="manual-configurator__summary-pills">
                        <span className="manual-configurator__summary-pill">
                            {rankLabel}
                        </span>
                        <span className="manual-configurator__summary-pill">
                            {platform === 'pc' && launcher !== null
                                ? `${common.platforms.pc} (${common.pc_stores[launcher]})`
                                : common.platforms[platform]}
                        </span>
                        {urgent ? (
                            <span className="manual-configurator__summary-pill manual-configurator__summary-pill--urgent">
                                {service.urgent}
                            </span>
                        ) : null}
                    </div>
                    <div className="manual-configurator__summary-total">
                        <span className="manual-configurator__summary-total-label">
                            {common.review_total}
                        </span>
                        <strong className="manual-configurator__summary-total-amount">
                            {price === null
                                ? '—'
                                : formatMinorUnits(
                                      price.amountMinor,
                                      price.currency,
                                      locale,
                                  )}
                        </strong>
                    </div>
                </div>
                <button
                    className="manual-configurator__submit"
                    disabled={
                        status === 'loading' ||
                        (platform === 'pc' && launcher === null)
                    }
                    type="submit"
                >
                    {status === 'loading' ? common.adding : common.add_to_cart}
                </button>
                {status === 'success' ? (
                    <p role="status">{common.added}</p>
                ) : null}
                {status === 'error' ? (
                    <p role="alert">{common.add_error}</p>
                ) : null}
            </div>
        </form>
    );
}
