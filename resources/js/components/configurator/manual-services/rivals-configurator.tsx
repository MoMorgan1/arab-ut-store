import { useRef, useState } from 'react';

import { announceCartAddition } from '@/lib/cart-added-event';
import {
    ManualServiceCartError,
    submitManualServiceCart,
} from '@/lib/manual-service-cart-api';
import { formatInteger, formatMinorUnits } from '@/lib/money';
import { getInitialRivalsRoute } from '@/lib/query-params';
import type {
    Division,
    ManualCredentialsDraft,
    ManualServiceCommonTranslations,
    ManualServicePageProps,
    ManualServicePlatform,
    PcLauncher,
    RivalsServiceTranslations,
} from '@/types/manual-services';
import { emptyManualCredentials } from '@/types/manual-services';

import { CredentialsFields } from './credentials-fields';
import { DivisionLadder } from './division-ladder';
import { FieldError } from './field-error';
import {
    appendCredentials,
    manualCredentialErrors,
    newManualAttemptKey,
    validateManualFieldOnBlur,
    validManualCredentials,
    validSquadImage,
} from './form-utils';
import type { ManualFormErrors } from './form-utils';
import { MANUAL_PLATFORM_ARTWORK } from './platform-artwork';
import { ManualSection } from './section';
import { SelectionCard } from './selection-card';
import { ManualServicePanel } from './service-panel';
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
    const fieldRefs = useRef<Record<string, HTMLInputElement | null>>({});
    const squadInputRef = useRef<HTMLInputElement | null>(null);

    const [platform, setPlatform] =
        useState<ManualServicePlatform>('playstation');
    const [launcher, setLauncher] = useState<PcLauncher | null>(null);
    const [from, setFrom] = useState<Division>(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialRivalsRoute(search, pricing.ladder).from;
    });
    const [to, setTo] = useState<Division>(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialRivalsRoute(search, pricing.ladder).to;
    });
    const [mode, setMode] = useState<'promotion' | 'weekly_matches'>(
        'promotion',
    );
    const [credentials, setCredentials] = useState(emptyManualCredentials);
    const [image, setImage] = useState<File | null>(null);
    const [errors, setErrors] = useState<ManualFormErrors>({});
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const fromIndex = pricing.ladder.indexOf(from);
    const toIndex = pricing.ladder.indexOf(to);
    const amount =
        toIndex <= fromIndex
            ? null
            : pricing.stepOptions
                  .slice(fromIndex, toIndex)
                  .reduce((sum, step) => sum + step.price.amountMinor, 0);
    const weekly = pricing.weeklyMatches;
    const isWeekly = mode === 'weekly_matches' && weekly !== null;
    const price = isWeekly
        ? weekly.price
        : amount === null
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
        setErrors({});
    }

    function handleBlurField(field: string, value: string) {
        const error = validateManualFieldOnBlur(
            field,
            value,
            platform,
            launcher,
            credentials,
            common,
        );

        setErrors((prev) => {
            if (error) {
                return { ...prev, [field]: error };
            }

            const next = { ...prev };

            delete next[field];

            return next;
        });
    }

    function handleCredentialsChange(nextCreds: ManualCredentialsDraft) {
        setCredentials(nextCreds);
    }

    async function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        // currentTarget nulls out once the handler yields, so the flight
        // origin is captured before the first await.
        const submitButton = event.currentTarget.querySelector(
            '.manual-configurator__submit',
        );

        if (status === 'loading') {
            return;
        }

        const credErrors = manualCredentialErrors(
            platform,
            launcher,
            credentials,
            common,
        );
        const imageIssue = validSquadImage(image);
        const imageErrorMessage =
            imageIssue === 'size'
                ? common.image_too_large
                : imageIssue === 'type'
                  ? common.image_invalid
                  : imageIssue === 'required'
                    ? common.image_required
                    : undefined;

        const nextErrors: ManualFormErrors = { ...credErrors };

        if (imageErrorMessage) {
            nextErrors.squadImage = imageErrorMessage;
        }

        const hasErrors =
            Object.keys(nextErrors).length > 0 ||
            (!isWeekly && amount === null) ||
            !validManualCredentials(platform, launcher, credentials) ||
            imageIssue !== null;

        if (hasErrors) {
            setErrors(nextErrors);
            setStatus('error');

            queueMicrotask(() => {
                if (platform === 'pc' && launcher === null) {
                    formRef.current
                        ?.querySelector<HTMLInputElement>(
                            'input[name="pc-store"]',
                        )
                        ?.focus();

                    return;
                }

                const firstCredKey = Object.keys(credErrors)[0];

                if (firstCredKey && fieldRefs.current[firstCredKey]) {
                    fieldRefs.current[firstCredKey]?.focus();

                    return;
                }

                if (imageErrorMessage) {
                    squadInputRef.current?.focus();

                    return;
                }

                formRef.current
                    ?.querySelector<HTMLInputElement>('input:invalid')
                    ?.focus();
            });

            return;
        }

        const form = new FormData();
        form.set('scheduleVersion', String(scheduleVersion));
        form.set('platform', platform);
        form.set('mode', isWeekly ? 'weekly_matches' : 'promotion');

        if (!isWeekly) {
            form.set('currentDivision', from);
            form.set('targetDivision', to);
        }

        if (image !== null) {
            form.set('squadImage', image);
        }

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
            await announceCartAddition({
                analytics: {
                    id: product.slug,
                    name: product.name,
                    ...(price !== null && price.currency === 'SAR'
                        ? { priceMinorSar: price.amountMinor }
                        : {}),
                    quantity: 1,
                    serviceType: 'rivals',
                },
                cartUrl: result.cartUrl,
                ...(submitButton instanceof HTMLElement
                    ? { from: submitButton }
                    : {}),
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

    const facts = [
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
        ...(isWeekly
            ? [
                  {
                      label: service.mode_legend,
                      value: service.weekly_summary ?? service.mode_weekly,
                  },
              ]
            : [
                  {
                      label: service.current_legend,
                      value: divisionLabel(from),
                  },
                  {
                      label: service.target_legend,
                      value: divisionLabel(to),
                  },
              ]),
    ];

    return (
        <form
            className="manual-configurator"
            noValidate
            onSubmit={submit}
            ref={formRef}
        >
            <div className="manual-configurator__main">
                {/* Step 1: Platform */}
                <ManualSection
                    id="rivals-step-platform"
                    locale={locale}
                    number={1}
                    title={common.step_platform}
                >
                    <fieldset className="manual-fieldset">
                        <legend className="sr-only">
                            {common.platform_legend}
                        </legend>
                        <div
                            aria-label={common.platform_legend}
                            className="coins-choice-grid coins-choice-grid--platforms"
                            role="radiogroup"
                        >
                            {(['playstation', 'pc'] as const).map((value) => (
                                <SelectionCard
                                    caption={common.platform_captions[value]}
                                    checked={platform === value}
                                    iconUrls={MANUAL_PLATFORM_ARTWORK[value]}
                                    key={value}
                                    label={common.platforms[value]}
                                    name="platform"
                                    onChange={() => choosePlatform(value)}
                                    value={value}
                                    variant="platform"
                                >
                                    {common.platforms[value]}
                                </SelectionCard>
                            ))}
                        </div>
                    </fieldset>

                    {platform === 'pc' ? (
                        <div className="manual-pc-launcher">
                            <p className="manual-pc-launcher__label">
                                {common.pc_store_legend}
                            </p>
                            <div
                                aria-label={common.pc_store_legend}
                                className="manual-segmented"
                                role="radiogroup"
                            >
                                {(['ea_app', 'steam'] as const).map((value) => (
                                    <SelectionCard
                                        checked={launcher === value}
                                        key={value}
                                        name="pc-store"
                                        onChange={() => {
                                            setLauncher(value);
                                            setErrors((prev) => {
                                                const next = { ...prev };
                                                delete next.launcher;

                                                return next;
                                            });

                                            if (value === 'ea_app') {
                                                setCredentials((current) => ({
                                                    ...current,
                                                    steamUsername: '',
                                                    steamPassword: '',
                                                }));
                                            }
                                        }}
                                        value={value}
                                        variant="segment"
                                    >
                                        {common.pc_stores[value]}
                                    </SelectionCard>
                                ))}
                            </div>
                            <FieldError
                                error={errors.launcher}
                                id="manual-pc-launcher-error"
                            />
                        </div>
                    ) : null}
                </ManualSection>

                {/* Step 2: Options */}
                <ManualSection
                    id="rivals-step-options"
                    locale={locale}
                    number={2}
                    title={common.step_options}
                >
                    {weekly !== null ? (
                        <fieldset className="manual-fieldset manual-mode-selection">
                            <legend className="manual-mode-legend">
                                {service.mode_legend}
                            </legend>
                            <div
                                aria-label={service.mode_legend}
                                className="manual-segmented"
                                role="radiogroup"
                            >
                                <SelectionCard
                                    checked={!isWeekly}
                                    name="mode"
                                    onChange={() => setMode('promotion')}
                                    value="promotion"
                                    variant="segment"
                                >
                                    {service.mode_promotion}
                                </SelectionCard>
                                <SelectionCard
                                    checked={isWeekly}
                                    name="mode"
                                    onChange={() => setMode('weekly_matches')}
                                    value="weekly_matches"
                                    variant="segment"
                                >
                                    {service.mode_weekly}
                                </SelectionCard>
                            </div>
                            <p className="manual-configurator__hint">
                                {isWeekly
                                    ? service.mode_weekly_hint.replace(
                                          ':wins',
                                          formatInteger(
                                              weekly.includedWins,
                                              locale,
                                          ),
                                      )
                                    : service.mode_promotion_hint}
                            </p>
                        </fieldset>
                    ) : null}

                    {!isWeekly ? (
                        <DivisionLadder
                            from={from}
                            ladder={pricing.ladder}
                            locale={locale}
                            onFromChange={setFrom}
                            onToChange={setTo}
                            price={formattedPrice}
                            service={service}
                            to={to}
                        />
                    ) : null}
                </ManualSection>

                {/* Step 3: Account */}
                <ManualSection
                    id="rivals-step-account"
                    locale={locale}
                    number={3}
                    title={common.step_account}
                >
                    <CredentialsFields
                        credentials={credentials}
                        errors={errors}
                        launcher={launcher}
                        onBlurField={handleBlurField}
                        onChange={handleCredentialsChange}
                        platform={platform}
                        registerFieldRef={(field, node) => {
                            fieldRefs.current[field] = node;
                        }}
                        translations={common}
                        tutorials={tutorials}
                    />
                </ManualSection>

                {/* Step 4: Image */}
                <ManualSection
                    id="rivals-step-image"
                    locale={locale}
                    number={4}
                    title={common.step_image}
                >
                    <SquadUpload
                        error={errors.squadImage}
                        file={image}
                        inputRef={(node) => {
                            squadInputRef.current = node;
                        }}
                        onChange={(file) => {
                            setImage(file);
                            setErrors((prev) => {
                                const next = { ...prev };
                                delete next.squadImage;

                                return next;
                            });
                        }}
                        translations={common}
                    />
                </ManualSection>
            </div>

            {/* Sticky Order Panel (Desktop) / Glassmorphic Action Bar (Mobile) */}
            <ManualServicePanel
                eta={service.standard_eta}
                facts={facts}
                image={product.image}
                locale={locale}
                price={price}
                status={status}
                submitDisabled={platform === 'pc' && launcher === null}
                title={product.name}
                translations={common}
            />
        </form>
    );
}
