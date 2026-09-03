import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import {
    announceCartAddition,
    announceCartDuplicate,
} from '@/lib/cart-added-event';
import { loadManualCartCredentials } from '@/lib/cart-credentials-api';
import type { StoredManualCartCredentials } from '@/lib/cart-credentials-api';
import {
    ManualServiceCartError,
    submitManualServiceCart,
} from '@/lib/manual-service-cart-api';
import { formatInteger, formatMinorUnits } from '@/lib/money';
import {
    getInitialManualLauncher,
    getInitialManualPlatform,
    getInitialReplaceId,
    getInitialRivalsMode,
    getInitialRivalsRoute,
} from '@/lib/query-params';
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

function toManualDraft(
    stored: StoredManualCartCredentials,
): ManualCredentialsDraft {
    return {
        eaEmail: stored.eaEmail,
        eaPassword: stored.eaPassword,
        eaCodes: [...stored.eaCodes],
        playstationEmail: stored.playstationEmail,
        playstationPassword: stored.playstationPassword,
        playstationCodes: [...stored.playstationCodes],
        steamUsername: stored.steamUsername,
        steamPassword: stored.steamPassword,
    };
}

export function RivalsConfigurator({
    addUrl,
    common,
    locale,
    pricing,
    product,
    replaceCredentialsUrl = null,
    scheduleVersion,
    service,
    tutorials,
    variantIds,
}: {
    addUrl: string;
    common: ManualServiceCommonTranslations;
    locale: 'ar' | 'en';
    pricing: RivalsPricing;
    product: ManualServicePageProps['manualService']['product'];
    replaceCredentialsUrl?: string | null;
    scheduleVersion: number;
    service: RivalsServiceTranslations;
    tutorials: { ea: string; playstation: string };
    variantIds: Record<ManualServicePlatform, string | null>;
}) {
    const formRef = useRef<HTMLFormElement>(null);
    const keyRef = useRef(newManualAttemptKey());
    const fieldRefs = useRef<Record<string, HTMLInputElement | null>>({});
    const squadInputRef = useRef<HTMLInputElement | null>(null);

    const [platform, setPlatform] = useState<ManualServicePlatform>(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialManualPlatform(search) ?? 'playstation';
    });
    const [launcher, setLauncher] = useState<PcLauncher | null>(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialManualLauncher(search) ?? null;
    });
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
    const [mode, setMode] = useState<'promotion' | 'weekly_matches'>(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';
        const requested = getInitialRivalsMode(search);

        return requested === 'weekly_matches' && pricing.weeklyMatches !== null
            ? 'weekly_matches'
            : 'promotion';
    });
    // Editing a cart line: `replace` names the line, and the server passes
    // its credentials URL through the page props — it is never built here.
    const [replaceCartItemId, setReplaceCartItemId] = useState<string | null>(
        () => {
            const search =
                typeof window !== 'undefined' ? window.location.search : '';

            return getInitialReplaceId(search);
        },
    );
    const replacing = replaceCartItemId !== null;
    const [credentials, setCredentials] = useState(emptyManualCredentials);
    const [image, setImage] = useState<File | null>(null);
    const [errors, setErrors] = useState<ManualFormErrors>({});
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const [addedVariantIds, setAddedVariantIds] = useState<string[]>([]);
    const pageProps = usePage<ManualServicePageProps>().props;
    const cartVariantIds = pageProps.cartVariantIds ?? [];
    const selectedVariantId = variantIds[platform] ?? null;
    // While replacing, the old line is the one being replaced — it must
    // not block the edit as "in cart".
    const inCart =
        !replacing &&
        selectedVariantId !== null &&
        (cartVariantIds.includes(selectedVariantId) ||
            addedVariantIds.includes(selectedVariantId));

    // Prefill the credentials from the line being edited. A failed fetch
    // leaves the fields empty — and never leaks — while the note still
    // shows.
    useEffect(() => {
        if (!replacing || replaceCredentialsUrl === null) {
            return;
        }

        const controller = new AbortController();

        loadManualCartCredentials(replaceCredentialsUrl, controller.signal)
            .then((stored) => {
                setCredentials(toManualDraft(stored));
            })
            .catch(() => {
                // Fields stay empty; the editing note still shows.
            });

        return () => controller.abort();
        // Once per configurator: the line being edited never changes mid-flow.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
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

        // The flight starts from the button the visitor actually pressed
        // (phone dock or inline bar); currentTarget nulls out once the
        // handler yields, so both are captured before the first await.
        const submitter = (event.nativeEvent as SubmitEvent).submitter;
        const submitButton =
            submitter instanceof HTMLElement
                ? submitter
                : event.currentTarget.querySelector(
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
        // A replacement without a new upload keeps the old squad image, so
        // the dropzone stays optional until one is picked.
        const imageIssue =
            replacing && image === null ? null : validSquadImage(image);
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

        if (replaceCartItemId !== null) {
            form.set('replaceCartItemId', replaceCartItemId);
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
            // The old line is gone now; a second add from this page is a
            // plain add, not another replacement.
            setReplaceCartItemId(null);

            if (selectedVariantId !== null) {
                setAddedVariantIds((current) =>
                    current.includes(selectedVariantId)
                        ? current
                        : [...current, selectedVariantId],
                );
            }

            const selectionLabel = [
                common.platforms[platform],
                isWeekly
                    ? (service.weekly_summary ?? service.mode_weekly)
                    : service.route_summary
                          .replace(':from', divisionLabel(from))
                          .replace(':to', divisionLabel(to)),
            ].join(' · ');
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
                cartCount: result.cartCount,
                cartTotalHalalah: result.cartTotalHalalah,
                cartUrl: result.cartUrl,
                ...(submitButton instanceof HTMLElement
                    ? { from: submitButton }
                    : {}),
                imageAlt: product.image.alt,
                imageUrl: product.image.url,
                itemLabel: product.name,
                priceLabel: formattedPrice === '' ? undefined : formattedPrice,
                selectionLabel,
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

            if (
                failure instanceof ManualServiceCartError &&
                failure.code === 'already_in_cart'
            ) {
                setStatus('idle');

                if (selectedVariantId !== null) {
                    setAddedVariantIds((current) =>
                        current.includes(selectedVariantId)
                            ? current
                            : [...current, selectedVariantId],
                    );
                }

                announceCartDuplicate({
                    cartUrl:
                        failure.cartUrl ??
                        (locale === 'en' ? '/en/cart' : '/cart'),
                    imageAlt: product.image.alt,
                    imageUrl: product.image.url,
                    itemLabel: product.name,
                });

                return;
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
            {replacing ? (
                <p className="manual-configurator__editing" role="note">
                    {common.editing_replace}
                </p>
            ) : null}
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
                        keptNotice={
                            replacing && image === null
                                ? common.squad_image_kept
                                : null
                        }
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
                cartUrl={pageProps.storeShell.cartUrl}
                eta={service.standard_eta}
                facts={facts}
                image={product.image}
                inCart={inCart}
                inCartLabel={common.in_cart}
                locale={locale}
                openCartLabel={common.open_cart}
                price={price}
                status={status}
                submitDisabled={platform === 'pc' && launcher === null}
                title={product.name}
                translations={common}
            />
        </form>
    );
}
