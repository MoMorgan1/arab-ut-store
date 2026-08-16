import { useRef, useState } from 'react';

import { announceCartAddition } from '@/lib/cart-added-event';
import { submitManualServiceCart } from '@/lib/manual-service-cart-api';
import { formatInteger, formatMinorUnits } from '@/lib/money';
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
    const [rank, setRank] = useState(3);
    const [urgent, setUrgent] = useState(false);
    const [matchesPlayed, setMatchesPlayed] = useState(0);
    const [credentials, setCredentials] = useState(emptyManualCredentials);
    const [image, setImage] = useState<File | null>(null);
    const [imageError, setImageError] = useState<string>();
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const option = pricing.rankOptions.find((entry) => entry.rank === rank);
    const price =
        option === undefined
            ? null
            : {
                  amountMinor:
                      option.price.amountMinor +
                      (urgent ? pricing.urgentSurcharge.amountMinor : 0),
                  currency: 'SAR' as const,
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
        } catch {
            keyRef.current = newManualAttemptKey();
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
            <fieldset>
                <legend>{service.target_legend}</legend>
                <div className="manual-selection-grid manual-selection-grid--ranks">
                    {pricing.rankOptions.map((entry) => (
                        <SelectionCard
                            badge={formatMinorUnits(
                                entry.price.amountMinor,
                                entry.price.currency,
                                locale,
                            )}
                            checked={rank === entry.rank}
                            key={entry.rank}
                            name="rank"
                            onChange={() => setRank(entry.rank)}
                            value={String(entry.rank)}
                        >
                            {service.rank.replace(
                                ':rank',
                                formatInteger(entry.rank, locale),
                            )}
                        </SelectionCard>
                    ))}
                </div>
            </fieldset>
            <div className="manual-fut-options">
                <label>
                    <input
                        checked={urgent}
                        name="urgent"
                        onChange={(event) =>
                            setUrgent(event.currentTarget.checked)
                        }
                        type="checkbox"
                    />
                    <span>
                        <strong>{service.urgent}</strong>
                        <small>{service.urgent_price}</small>
                    </span>
                </label>
                <label>
                    <span>{service.matches_played}</span>
                    <input
                        max="100"
                        min="0"
                        name="matches-played"
                        onChange={(event) =>
                            setMatchesPlayed(Number(event.currentTarget.value))
                        }
                        required
                        type="number"
                        value={matchesPlayed}
                    />
                </label>
            </div>
            <p className="manual-service-eta">
                {urgent ? service.urgent_eta : service.standard_eta}
            </p>
            <p className="manual-service-note">{service.already_played}</p>
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
            {status === 'success' ? <p role="status">{common.added}</p> : null}
            {status === 'error' ? <p role="alert">{common.add_error}</p> : null}
        </form>
    );
}
