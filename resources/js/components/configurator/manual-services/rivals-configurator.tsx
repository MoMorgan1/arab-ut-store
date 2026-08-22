import { useRef, useState } from 'react';

import { announceCartAddition } from '@/lib/cart-added-event';
import { submitManualServiceCart } from '@/lib/manual-service-cart-api';
import { formatInteger, formatMinorUnits } from '@/lib/money';
import type {
    Division,
    ManualServiceCommonTranslations,
    ManualServicePageProps,
    ManualServicePlatform,
    PcLauncher,
    RivalsServiceTranslations,
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

type RivalsPricing = Extract<
    NonNullable<ManualServicePageProps['manualService']['pricing']>,
    { ladder: unknown }
>;

export function RivalsConfigurator({
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
    pricing: RivalsPricing;
    product: ManualServicePageProps['manualService']['product'];
    scheduleVersion: number;
    service: RivalsServiceTranslations;
    tutorials: { ea: string; playstation: string };
}) {
    const formRef = useRef<HTMLFormElement>(null);
    const keyRef = useRef(newManualAttemptKey());
    const [platform, setPlatform] =
        useState<ManualServicePlatform>('playstation');
    const [launcher, setLauncher] = useState<PcLauncher | null>(null);
    const [from, setFrom] = useState<Division>('5');
    const [to, setTo] = useState<Division>('elite');
    const [credentials, setCredentials] = useState(emptyManualCredentials);
    const [image, setImage] = useState<File | null>(null);
    const [imageError, setImageError] = useState<string>();
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const fromIndex = pricing.ladder.indexOf(from);
    const targets = pricing.ladder.slice(fromIndex + 1);
    const toIndex = pricing.ladder.indexOf(to);
    const amount =
        toIndex <= fromIndex
            ? null
            : pricing.stepOptions
                  .slice(fromIndex, toIndex)
                  .reduce((sum, step) => sum + step.price.amountMinor, 0);
    const price =
        amount === null
            ? null
            : {
                  amountMinor: amount,
                  currency: pricing.stepOptions[0]?.price.currency ?? 'SAR',
              };
    const formattedPrice =
        price === null
            ? ''
            : formatMinorUnits(price.amountMinor, price.currency, locale);

    function divisionLabel(value: Division) {
        return value === 'elite'
            ? service.elite
            : service.division.replace(
                  ':division',
                  formatInteger(Number(value), locale),
              );
    }

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

        if (imageIssue !== null || image === null || amount === null) {
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
        form.set('currentDivision', from);
        form.set('targetDivision', to);
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
            <div className="manual-rivals-route">
                <ServiceSlider
                    direction={locale === 'ar' ? 'rtl' : 'ltr'}
                    inputName="from-division"
                    legend={service.current_legend}
                    maxValue={pricing.ladder.length - 2}
                    minValue={0}
                    onValueChange={(index) => {
                        const value = pricing.ladder[index];

                        if (value === undefined || value === 'elite') {
                            return;
                        }

                        setFrom(value);
                        setTo('elite');
                    }}
                    selectedValue={fromIndex}
                    stopLabels={pricing.ladder
                        .slice(0, -1)
                        .map((value) => formatInteger(Number(value), locale))}
                    valueLabel={divisionLabel(from)}
                />
                <ServiceSlider
                    direction={locale === 'ar' ? 'rtl' : 'ltr'}
                    inputName="to-division"
                    legend={service.target_legend}
                    maxValue={pricing.ladder.length - 1}
                    minValue={fromIndex + 1}
                    onValueChange={(index) => {
                        const value = pricing.ladder[index];

                        if (value !== undefined) {
                            setTo(value);
                        }
                    }}
                    price={formattedPrice}
                    selectedValue={toIndex}
                    stopLabels={targets.map((value) =>
                        value === 'elite'
                            ? service.elite
                            : formatInteger(Number(value), locale),
                    )}
                    valueLabel={divisionLabel(to)}
                />
            </div>
            <p className="manual-service-eta">{service.standard_eta}</p>
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
                        label: service.current_legend,
                        value: divisionLabel(from),
                    },
                    { label: service.target_legend, value: divisionLabel(to) },
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
