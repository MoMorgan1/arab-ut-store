import { useRef, useState } from 'react';

import { announceCartAddition } from '@/lib/cart-added-event';
import {
    ManualServiceCartError,
    submitManualServiceCart,
} from '@/lib/manual-service-cart-api';
import { formatInteger } from '@/lib/money';
import { getInitialFutChampionsConfig } from '@/lib/query-params';
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
    const fieldRefs = useRef<Record<string, HTMLInputElement | null>>({});
    const squadInputRef = useRef<HTMLInputElement | null>(null);
    const matchesInputRef = useRef<HTMLInputElement | null>(null);

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
    const [errors, setErrors] = useState<ManualFormErrors>({});
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
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
                eta={urgent ? service.urgent_eta : service.standard_eta}
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
