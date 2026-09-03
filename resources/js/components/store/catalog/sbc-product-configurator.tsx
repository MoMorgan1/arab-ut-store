import { usePage } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { CodeFields } from '@/components/configurator/manual-services/credentials-fields';
import { FieldError } from '@/components/configurator/manual-services/field-error';
import type { ManualFormErrors } from '@/components/configurator/manual-services/form-utils';
import { ManualSection } from '@/components/configurator/manual-services/section';
import { SelectionCard } from '@/components/configurator/manual-services/selection-card';
import { ManualServicePanel } from '@/components/configurator/manual-services/service-panel';
import { ServiceSlider } from '@/components/configurator/manual-services/service-slider';
import { newAttemptKey } from '@/lib/attempt-key';
import {
    announceCartAddition,
    announceCartDuplicate,
} from '@/lib/cart-added-event';
import { catalogPlatformName } from '@/lib/catalog-platform-name';
import { formatMinorUnits } from '@/lib/money';
import { SbcCartRequestError, submitSbcCart } from '@/lib/sbc-cart-api';
import { sbcPlatformIconUrls } from '@/lib/sbc-platform-artwork';
import type { CoinsCredentialField, CoinsCredentials } from '@/types/coins';
import type { ManualServiceCommonTranslations } from '@/types/manual-services';
import type {
    CatalogProduct,
    ProductTranslations,
    StoreCatalogProductPageProps,
} from '@/types/store-content';

type CredentialErrors = Partial<Record<CoinsCredentialField, string>>;
const CODE_FIELDS = ['code-0', 'code-1', 'code-2'] as const;
const EMPTY_CREDENTIALS: CoinsCredentials = {
    eaEmail: '',
    eaPassword: '',
    backupCodes: ['', '', ''],
};

function initialVariant(product: CatalogProduct, currentUrl: string): string {
    const requested = new URL(
        currentUrl,
        'https://store.arab-ut.com',
    ).searchParams.get('variant');

    const requestedVariant = product.variants.find(
        (variant) => variant.id === requested && variant.price !== null,
    );

    if (requestedVariant !== undefined) {
        return requestedVariant.id;
    }

    return (
        product.variants.find(
            (variant) =>
                variant.platform === 'playstation' && variant.price !== null,
        )?.id ??
        product.variants.find((variant) => variant.price !== null)?.id ??
        ''
    );
}

function initialCompletionCount(
    product: CatalogProduct,
    variantId: string,
): number {
    return (
        product.variants.find((variant) => variant.id === variantId)
            ?.completionTiers[0]?.completions ?? 1
    );
}

function completionLabel(
    copy: Pick<
        ProductTranslations['sbc'],
        'completion_option' | 'completion_option_one'
    >,
    count: number,
): string {
    if (count === 1 && copy.completion_option_one !== undefined) {
        return copy.completion_option_one;
    }

    return copy.completion_option.replace(':count', String(count));
}

function validate(
    credentials: CoinsCredentials,
    copy: ProductTranslations['sbc'],
): CredentialErrors {
    const errors: CredentialErrors = {};

    if (
        credentials.eaEmail.length > 254 ||
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(credentials.eaEmail)
    ) {
        errors.email = copy.required_email;
    }

    if (
        credentials.eaPassword.length < 1 ||
        credentials.eaPassword.length > 128
    ) {
        errors.password = copy.required_password;
    }

    CODE_FIELDS.forEach((field, index) => {
        const code = credentials.backupCodes[index];

        if (!/^[0-9]{8}$/.test(code)) {
            errors[field] = copy.required_code;
        } else if (credentials.backupCodes.indexOf(code) !== index) {
            errors[field] = copy.duplicate_code;
        }
    });

    return errors;
}

export function SbcProductConfigurator({
    addUrl,
    currentUrl,
    direction,
    locale,
    manualCommon,
    product,
    translations,
    tutorials,
}: {
    addUrl: string;
    currentUrl: string;
    direction: 'rtl' | 'ltr';
    locale: 'ar' | 'en';
    manualCommon: ManualServiceCommonTranslations;
    product: CatalogProduct;
    translations: ProductTranslations;
    tutorials: { ea: string };
}) {
    const initialVariantId = initialVariant(product, currentUrl);
    const [variantId, setVariantId] = useState(initialVariantId);
    const [completionCount, setCompletionCount] = useState(() =>
        initialCompletionCount(product, initialVariantId),
    );
    const [credentials, setCredentials] =
        useState<CoinsCredentials>(EMPTY_CREDENTIALS);
    const [errors, setErrors] = useState<CredentialErrors>({});
    const [passwordVisible, setPasswordVisible] = useState(false);
    const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle');
    const [addedVariantIds, setAddedVariantIds] = useState<string[]>([]);
    const pageProps = usePage<StoreCatalogProductPageProps>().props;
    const cartVariantIds = pageProps.cartVariantIds ?? [];
    const attemptKey = useRef(newAttemptKey());
    const fieldRefs = useRef<
        Partial<Record<CoinsCredentialField, HTMLInputElement | null>>
    >({});
    const pendingFocus = useRef<CoinsCredentialField | null>(null);
    const variant = product.variants.find((option) => option.id === variantId);
    const completionTiers = variant?.completionTiers ?? [];
    const selectedCompletionIndex = Math.max(
        0,
        completionTiers.findIndex(
            (tier) => tier.completions === completionCount,
        ),
    );
    const selectedCompletionTier = completionTiers[selectedCompletionIndex];
    const completionTier = variant?.completionTiers.find(
        (tier) => tier.completions === completionCount,
    );
    const locked = state === 'loading';

    useEffect(() => {
        if (state !== 'error' || pendingFocus.current === null) {
            return;
        }

        fieldRefs.current[pendingFocus.current]?.focus();
        pendingFocus.current = null;
    }, [state]);

    function updateCredential<Key extends keyof CoinsCredentials>(
        key: Key,
        value: CoinsCredentials[Key],
        field: CoinsCredentialField,
    ) {
        if (locked) {
            return;
        }

        setCredentials((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [field]: undefined }));
        setState('idle');
    }

    function selectVariant(nextVariantId: string) {
        if (locked) {
            return;
        }

        const nextVariant = product.variants.find(
            (option) => option.id === nextVariantId,
        );

        if (nextVariant === undefined) {
            return;
        }

        setVariantId(nextVariantId);

        if (
            !nextVariant.completionTiers.some(
                (tier) => tier.completions === completionCount,
            )
        ) {
            setCompletionCount(
                nextVariant.completionTiers[0]?.completions ?? 1,
            );
        }
    }

    function handleCodesChange(nextCodes: [string, string, string]) {
        const changed =
            ([0, 1, 2] as const).find(
                (index) => nextCodes[index] !== credentials.backupCodes[index],
            ) ?? 0;
        updateCredential('backupCodes', nextCodes, `code-${changed}`);
    }

    function registerCodeRef(field: string, node: HTMLInputElement | null) {
        const match = /^eaCode-([012])$/.exec(field);

        if (match !== null) {
            fieldRefs.current[`code-${match[1]}` as CoinsCredentialField] =
                node;
        }
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

        const nextErrors = validate(credentials, translations.sbc);
        const firstError = Object.keys(nextErrors)[0] as
            CoinsCredentialField | undefined;
        setErrors(nextErrors);

        if (firstError !== undefined) {
            fieldRefs.current[firstError]?.focus();

            return;
        }

        if (
            variant === undefined ||
            completionTier === undefined ||
            state === 'loading'
        ) {
            return;
        }

        setState('loading');

        try {
            const result = await submitSbcCart({
                cartUrl: addUrl,
                completionCount,
                credentials,
                idempotencyKey: attemptKey.current,
                variantId: variant.id,
            });
            attemptKey.current = newAttemptKey();
            setAddedVariantIds((current) =>
                current.includes(variant.id)
                    ? current
                    : [...current, variant.id],
            );
            setCredentials(EMPTY_CREDENTIALS);
            setState('idle');
            await announceCartAddition({
                analytics: {
                    id: product.id,
                    name: product.name,
                    ...(selectedCompletionTier?.price.currency === 'SAR'
                        ? {
                              priceMinorSar:
                                  selectedCompletionTier.price.amountMinor,
                          }
                        : {}),
                    quantity: 1,
                    serviceType: 'sbc',
                },
                cartCount: result.cartCount,
                cartTotalHalalah: result.cartTotalHalalah,
                cartUrl: result.cartUrl,
                ...(submitButton instanceof HTMLElement
                    ? { from: submitButton }
                    : {}),
                imageAlt: product.image?.alt || product.name,
                imageUrl:
                    product.image?.url ??
                    '/images/store/navigation/logo-sbc-256.webp',
                itemLabel: product.name,
                priceLabel:
                    selectedCompletionTier === undefined
                        ? undefined
                        : formatMinorUnits(
                              selectedCompletionTier.price.amountMinor,
                              selectedCompletionTier.price.currency,
                              locale,
                          ),
                raisedArt: true,
                selectionLabel: `${completionLabel(
                    translations.sbc,
                    completionCount,
                )} · ${catalogPlatformName(
                    variant.platform,
                    variant.name,
                    locale,
                )}`,
            });
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: result.cartCount,
                }),
            );
        } catch (failure) {
            if (failure instanceof SbcCartRequestError) {
                if (failure.conclusive) {
                    attemptKey.current = newAttemptKey();
                }

                if (failure.code === 'already_in_cart') {
                    setAddedVariantIds((current) =>
                        current.includes(variant.id)
                            ? current
                            : [...current, variant.id],
                    );
                    setCredentials(EMPTY_CREDENTIALS);
                    setState('idle');
                    announceCartDuplicate({
                        cartUrl:
                            failure.cartUrl ??
                            `/${locale === 'en' ? 'en/' : ''}cart`,
                        imageAlt: product.image?.alt || product.name,
                        imageUrl:
                            product.image?.url ??
                            '/images/store/navigation/logo-sbc-256.webp',
                        itemLabel: product.name,
                        raisedArt: true,
                        selectionLabel: `${completionLabel(
                            translations.sbc,
                            completionCount,
                        )} · ${catalogPlatformName(
                            variant.platform,
                            variant.name,
                            locale,
                        )}`,
                    });

                    return;
                }

                const firstRejected = failure.validationFields[0];

                if (firstRejected !== undefined) {
                    pendingFocus.current = firstRejected;
                }
            }

            setState('error');
        }
    }

    const codeErrors: ManualFormErrors = {};

    CODE_FIELDS.forEach((field, index) => {
        const message = errors[field];

        if (message !== undefined) {
            codeErrors[`eaCode-${index}`] = message;
        }
    });

    const platformName =
        variant === undefined
            ? '—'
            : catalogPlatformName(variant.platform, variant.name, locale);

    return (
        <form
            className="sbc-product-configurator manual-configurator"
            noValidate
            onSubmit={(event) => void submit(event)}
        >
            <div className="manual-configurator__main">
                <ManualSection
                    id="sbc-step-platform"
                    locale={locale}
                    number={1}
                    title={manualCommon.step_platform}
                >
                    <fieldset className="manual-fieldset" disabled={locked}>
                        <legend className="sr-only">
                            {translations.sbc.platform_legend}
                        </legend>
                        <div
                            aria-label={translations.sbc.platform_legend}
                            className="coins-choice-grid coins-choice-grid--platforms"
                            role="radiogroup"
                        >
                            {product.variants.map((option) => {
                                const optionName = catalogPlatformName(
                                    option.platform,
                                    option.name,
                                    locale,
                                );
                                const priceText =
                                    option.price === null
                                        ? translations.unavailable_price
                                        : formatMinorUnits(
                                              option.price.amountMinor,
                                              option.price.currency,
                                              locale,
                                          );

                                return (
                                    <SelectionCard
                                        checked={option.id === variantId}
                                        disabled={option.price === null}
                                        iconUrls={sbcPlatformIconUrls(
                                            option.platform,
                                        )}
                                        key={option.id}
                                        label={`${optionName} · ${priceText}`}
                                        name="sbc-platform"
                                        onChange={() =>
                                            selectVariant(option.id)
                                        }
                                        value={option.id}
                                        variant="platform"
                                    >
                                        <span>{optionName}</span>
                                        <span className="sbc-platform-price">
                                            {priceText}
                                        </span>
                                    </SelectionCard>
                                );
                            })}
                        </div>
                    </fieldset>
                </ManualSection>

                {completionTiers.length > 1 &&
                selectedCompletionTier !== undefined ? (
                    <ManualSection
                        id="sbc-step-completions"
                        locale={locale}
                        number={2}
                        title={translations.sbc.completion_legend}
                    >
                        <fieldset className="manual-fieldset" disabled={locked}>
                            <ServiceSlider
                                direction={direction}
                                inputName="sbc-completion"
                                legend={translations.sbc.completion_legend}
                                maxValue={completionTiers.length - 1}
                                minValue={0}
                                onValueChange={(index) => {
                                    const tier = completionTiers[index];

                                    if (tier !== undefined) {
                                        setCompletionCount(tier.completions);
                                    }
                                }}
                                price={formatMinorUnits(
                                    selectedCompletionTier.price.amountMinor,
                                    selectedCompletionTier.price.currency,
                                    locale,
                                )}
                                selectedValue={selectedCompletionIndex}
                                stopLabels={completionTiers.map((tier) =>
                                    String(tier.completions),
                                )}
                                valueLabel={completionLabel(
                                    translations.sbc,
                                    selectedCompletionTier.completions,
                                )}
                            />
                        </fieldset>
                    </ManualSection>
                ) : null}

                <ManualSection
                    id="sbc-step-account"
                    locale={locale}
                    number={3}
                    title={translations.sbc.credentials_title}
                >
                    <div className="coins-credentials-form manual-credentials-form">
                        <div className="manual-credentials__grid">
                            <div className="coins-credential-field">
                                <label htmlFor="sbc-ea-email">
                                    {translations.sbc.email}
                                </label>
                                <input
                                    aria-describedby={
                                        errors.email === undefined
                                            ? undefined
                                            : 'sbc-ea-email-error'
                                    }
                                    aria-invalid={errors.email !== undefined}
                                    autoComplete="off"
                                    data-1p-ignore="true"
                                    data-lpignore="true"
                                    dir="ltr"
                                    disabled={locked}
                                    id="sbc-ea-email"
                                    name="sbc-ea-email"
                                    onChange={(event) =>
                                        updateCredential(
                                            'eaEmail',
                                            event.currentTarget.value,
                                            'email',
                                        )
                                    }
                                    ref={(node) => {
                                        fieldRefs.current.email = node;
                                    }}
                                    spellCheck={false}
                                    type="email"
                                    value={credentials.eaEmail}
                                />
                                <FieldError
                                    error={errors.email}
                                    id="sbc-ea-email-error"
                                />
                            </div>

                            <div className="coins-credential-field">
                                <label htmlFor="sbc-ea-password">
                                    {translations.sbc.password}
                                </label>
                                <div className="coins-password-control">
                                    <input
                                        aria-describedby={
                                            errors.password === undefined
                                                ? undefined
                                                : 'sbc-ea-password-error'
                                        }
                                        aria-invalid={
                                            errors.password !== undefined
                                        }
                                        autoComplete="off"
                                        data-1p-ignore="true"
                                        data-lpignore="true"
                                        dir="ltr"
                                        disabled={locked}
                                        id="sbc-ea-password"
                                        name="sbc-ea-password"
                                        onChange={(event) =>
                                            updateCredential(
                                                'eaPassword',
                                                event.currentTarget.value,
                                                'password',
                                            )
                                        }
                                        ref={(node) => {
                                            fieldRefs.current.password = node;
                                        }}
                                        spellCheck={false}
                                        type={
                                            passwordVisible
                                                ? 'text'
                                                : 'password'
                                        }
                                        value={credentials.eaPassword}
                                    />
                                    <button
                                        aria-label={
                                            passwordVisible
                                                ? translations.sbc.hide_password
                                                : translations.sbc.show_password
                                        }
                                        disabled={locked}
                                        onClick={() =>
                                            setPasswordVisible(
                                                (visible) => !visible,
                                            )
                                        }
                                        type="button"
                                    >
                                        {passwordVisible ? (
                                            <EyeOff aria-hidden="true" />
                                        ) : (
                                            <Eye aria-hidden="true" />
                                        )}
                                    </button>
                                </div>
                                <FieldError
                                    error={errors.password}
                                    id="sbc-ea-password-error"
                                />
                            </div>
                        </div>

                        <fieldset className="manual-fieldset" disabled={locked}>
                            <CodeFields
                                codes={credentials.backupCodes}
                                errors={codeErrors}
                                fieldPrefix="eaCode"
                                label={translations.sbc.backup_codes}
                                namePrefix="sbc-backup"
                                numeric
                                onChange={handleCodesChange}
                                registerFieldRef={registerCodeRef}
                                translations={manualCommon}
                                tutorialHref={tutorials.ea}
                                tutorialLabel={manualCommon.ea_tutorial}
                            />
                        </fieldset>
                        <p className="sbc-backup-help">
                            {translations.sbc.backup_help}
                        </p>
                    </div>
                </ManualSection>
            </div>

            <ManualServicePanel
                facts={[
                    { label: translations.sbc.selected, value: platformName },
                    {
                        label: translations.sbc.completion_summary,
                        value: String(completionCount),
                    },
                ]}
                cartUrl={pageProps.storeShell.cartUrl}
                image={
                    product.image ?? {
                        alt: product.name,
                        url: '/images/store/navigation/logo-sbc-256.webp',
                    }
                }
                inCart={
                    variant !== undefined &&
                    (cartVariantIds.includes(variant.id) ||
                        addedVariantIds.includes(variant.id))
                }
                inCartLabel={manualCommon.in_cart}
                inline
                locale={locale}
                openCartLabel={manualCommon.open_cart}
                price={completionTier?.price ?? null}
                status={state}
                submitDisabled={completionTier === undefined}
                submitLabel={translations.add_to_cart}
                title={product.name}
                translations={manualCommon}
                trustLabel={translations.sbc.credentials_ready}
            />
        </form>
    );
}
