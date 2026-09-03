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
    getInitialMatchesPlayed,
    getInitialReplaceId,
    getInitialFutChampionsConfig,
} from '@/lib/query-params';
import type {
    FutServiceTranslations,
    ManualCredentialsDraft,
    ManualServiceCommonTranslations,
    ManualServicePageProps,
    ManualServicePlatform,
    PcLauncher,
} from '@/types/manual-services';
import { emptyManualCredentials } from '@/types/manual-services';

import { CredentialsFields } from './credentials-fields';
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
import { RankPicker } from './rank-picker';
import { ManualSection } from './section';
import { SelectionCard } from './selection-card';
import { ManualServicePanel } from './service-panel';
import { SquadUpload } from './squad-upload';

type FutPricing = Extract<
    NonNullable<ManualServicePageProps['manualService']['pricing']>,
    { rankOptions: unknown }
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

export function FutChampionsConfigurator({
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
    pricing: FutPricing;
    product: ManualServicePageProps['manualService']['product'];
    replaceCredentialsUrl?: string | null;
    scheduleVersion: number;
    service: FutServiceTranslations;
    tutorials: { ea: string; playstation: string };
    variantIds: Record<ManualServicePlatform, string | null>;
}) {
    const formRef = useRef<HTMLFormElement>(null);
    const keyRef = useRef(newManualAttemptKey());
    const fieldRefs = useRef<Record<string, HTMLInputElement | null>>({});
    const squadInputRef = useRef<HTMLInputElement | null>(null);
    const matchesInputRef = useRef<HTMLInputElement | null>(null);

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
    const [hasPlayed, setHasPlayed] = useState(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialMatchesPlayed(search) > 0;
    });
    const [matchesPlayed, setMatchesPlayed] = useState(() => {
        const search =
            typeof window !== 'undefined' ? window.location.search : '';

        return getInitialMatchesPlayed(search);
    });
    const [credentials, setCredentials] = useState(emptyManualCredentials);
    const [image, setImage] = useState<File | null>(null);
    const [errors, setErrors] = useState<ManualFormErrors>({});
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
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
    const option = pricing.rankOptions.find((entry) => entry.rank === rank);
    const rankLabel = service.rank.replace(
        ':rank',
        formatInteger(rank, locale),
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

    function handleBlurMatches() {
        if (hasPlayed && (!matchesPlayed || matchesPlayed < 1)) {
            setErrors((prev) => ({
                ...prev,
                matchesPlayed: common.required_field,
            }));
        } else {
            setErrors((prev) => {
                const next = { ...prev };

                delete next.matchesPlayed;

                return next;
            });
        }
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

        if (hasPlayed && (!matchesPlayed || matchesPlayed < 1)) {
            nextErrors.matchesPlayed = common.required_field;
        }

        const hasErrors =
            Object.keys(nextErrors).length > 0 ||
            !validManualCredentials(platform, launcher, credentials) ||
            imageIssue !== null ||
            (hasPlayed && matchesPlayed < 1);

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

                if (hasPlayed && (!matchesPlayed || matchesPlayed < 1)) {
                    matchesInputRef.current?.focus();

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
        form.set('rank', String(rank));
        form.set('urgent', urgent ? '1' : '0');
        form.set('matchesPlayed', String(matchesPlayed));

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
                rankLabel,
                ...(urgent ? [service.urgent] : []),
            ].join(' · ');
            await announceCartAddition({
                analytics: {
                    id: product.slug,
                    name: product.name,
                    ...(price !== null && price.currency === 'SAR'
                        ? { priceMinorSar: price.amountMinor }
                        : {}),
                    quantity: 1,
                    serviceType: 'fut_champions',
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
                priceLabel:
                    price === null
                        ? undefined
                        : formatMinorUnits(
                              price.amountMinor,
                              price.currency,
                              locale,
                          ),
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
        { label: service.target_legend, value: rankLabel },
        ...(urgent
            ? [
                  {
                      label: service.urgent,
                      value: service.urgent_eta,
                  },
              ]
            : []),
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
                    id="fut-step-platform"
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
                    id="fut-step-options"
                    locale={locale}
                    number={2}
                    title={common.step_options}
                >
                    <RankPicker
                        hasPlayed={hasPlayed}
                        locale={locale}
                        matchesError={errors.matchesPlayed}
                        matchesInputRef={(node) => {
                            matchesInputRef.current = node;
                        }}
                        matchesPlayed={matchesPlayed}
                        onBlurMatches={handleBlurMatches}
                        onHasPlayedChange={(played) => {
                            setHasPlayed(played);
                            setMatchesPlayed(
                                played ? Math.max(1, matchesPlayed) : 0,
                            );

                            if (!played) {
                                setErrors((prev) => {
                                    const next = { ...prev };
                                    delete next.matchesPlayed;

                                    return next;
                                });
                            }
                        }}
                        onMatchesPlayedChange={(matches) => {
                            setMatchesPlayed(matches);

                            if (matches >= 1) {
                                setErrors((prev) => {
                                    const next = { ...prev };
                                    delete next.matchesPlayed;

                                    return next;
                                });
                            }
                        }}
                        onRankChange={setRank}
                        onUrgentChange={setUrgent}
                        pricing={pricing}
                        rank={rank}
                        service={service}
                        urgent={urgent}
                    />
                </ManualSection>

                {/* Step 3: Account */}
                <ManualSection
                    id="fut-step-account"
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

                {/* Step 4: Squad Image */}
                <ManualSection
                    id="fut-step-image"
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
                eta={urgent ? service.urgent_eta : service.standard_eta}
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
