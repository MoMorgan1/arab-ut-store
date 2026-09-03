import { useEffect, useMemo, useReducer, useRef, useState } from 'react';

import { newAttemptKey } from '@/lib/attempt-key';
import {
    announceCartAddition,
    announceCartDuplicate,
} from '@/lib/cart-added-event';
import { loadCartCredentials } from '@/lib/cart-credentials-api';
import type { StoredCartCredentials } from '@/lib/cart-credentials-api';
import { CoinsCartRequestError, submitCoinsCart } from '@/lib/coins-cart-api';
import { acceptsQuantity } from '@/lib/coins-quantity';
import { quoteFromSchedule } from '@/lib/coins-quote-schedule';
import { formatCoins, formatMinorUnits } from '@/lib/money';
import { parseQueryParams } from '@/lib/query-params';
import type {
    CoinsAmountRules,
    CoinsCartConfig,
    CoinsCredentialField,
    CoinsCredentials,
    CoinsDeliveryValue,
    CoinsPlatformOption,
    CoinsPlatformValue,
    CoinsQuoteSchedules,
    CoinsQuoteViewState,
    CoinsStoreTranslations,
} from '@/types/coins';

import { AmountStep } from './amount-step';
import { interpolate } from './configurator-copy';
import {
    clampAndSnapQuantity,
    coinsConfiguratorReducer,
    createInitialConfiguratorState,
    quantityFromInput,
} from './configurator-state';
import { CredentialsStep, emptyCoinsCredentials } from './credentials-step';
import { DeliveryStep } from './delivery-step';
import { PlatformStep } from './platform-step';
import { ProgressRail } from './progress-rail';
import type { CoinsStep } from './progress-rail';
import { SummaryStep } from './summary-step';
import { useCoinsQuoteRequest } from './use-coins-quote-request';

function toCoinsCredentials(stored: StoredCartCredentials): CoinsCredentials {
    return {
        backupCodes: [...stored.backupCodes],
        companionMarketOpen: stored.companionMarketOpen,
        currentBalance:
            stored.currentBalance === null ? '' : String(stored.currentBalance),
        eaEmail: stored.eaEmail,
        eaPassword: stored.eaPassword,
        policyAccepted: stored.policyAccepted,
    };
}

type CoinsConfiguratorProps = {
    amount: CoinsAmountRules;
    cart: CoinsCartConfig;
    cartUrl?: string;
    cartVariantIds?: string[];
    displayCurrency: string;
    locale: 'ar' | 'en';
    platforms: CoinsPlatformOption[];
    quoteSchedules: CoinsQuoteSchedules;
    quoteUrl: string;
    requiresCurrentBalance: boolean;
    termsUrl: string;
    translations: CoinsStoreTranslations;
    warrantyUrl: string;
};

export function CoinsConfigurator({
    amount,
    cart,
    cartUrl,
    cartVariantIds = [],
    displayCurrency,
    locale,
    platforms,
    quoteSchedules,
    quoteUrl,
    requiresCurrentBalance,
    termsUrl,
    translations,
    warrantyUrl,
}: CoinsConfiguratorProps) {
    const [state, dispatch] = useReducer(
        coinsConfiguratorReducer,
        undefined,
        () => {
            const search =
                typeof window !== 'undefined' ? window.location.search : '';

            return createInitialConfiguratorState(
                amount,
                cart.initialSelection,
                platforms,
                search,
            );
        },
    );
    const [credentials, setCredentials] = useState<CoinsCredentials>(
        emptyCoinsCredentials,
    );
    // Editing a cart line: `replace` names the line, and the server passes
    // its credentials URL through the page props — it is never built here.
    const [replaceCartItemId, setReplaceCartItemId] = useState<string | null>(
        () => {
            if (typeof window === 'undefined') {
                return null;
            }

            const replace = parseQueryParams(window.location.search).get(
                'replace',
            );

            return replace !== null &&
                /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i.test(replace)
                ? replace
                : null;
        },
    );
    const replaceCredentialsUrl =
        typeof cart.replaceCredentialsUrl === 'string' &&
        cart.replaceCredentialsUrl !== ''
            ? cart.replaceCredentialsUrl
            : null;
    const replacing = replaceCartItemId !== null;

    const [pending, setPending] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [rejectedCredentialFields, setRejectedCredentialFields] = useState<
        CoinsCredentialField[]
    >([]);
    const [addedVariantIds, setAddedVariantIds] = useState<string[]>([]);
    const credentialsRef = useRef(credentials);
    const idempotencyKey = useRef<string | null>(null);
    const pendingSubmission = useRef(false);
    const pendingFocus = useRef<CoinsStep | null>(null);
    const configuratorRoot = useRef<HTMLDivElement | null>(null);
    const platformHeading = useRef<HTMLLegendElement | null>(null);
    const deliveryHeading = useRef<HTMLLegendElement | null>(null);
    const amountHeading = useRef<HTMLHeadingElement | null>(null);
    const credentialsHeading = useRef<HTMLHeadingElement | null>(null);
    const summaryHeading = useRef<HTMLHeadingElement | null>(null);

    const selectedPlatform = useMemo(
        () =>
            platforms.find(
                (platform) => platform.value === state.platformValue,
            ) ?? null,
        [platforms, state.platformValue],
    );
    const selectedDelivery = useMemo(
        () =>
            selectedPlatform?.deliveries.find(
                (delivery) => delivery.value === state.deliveryValue,
            ) ?? null,
        [selectedPlatform, state.deliveryValue],
    );
    const quantity = quantityFromInput(state.quantityInput);
    const maximum = selectedDelivery?.maximum ?? selectedPlatform?.maximum ?? 0;
    const quantityIsValid =
        quantity !== null &&
        acceptsQuantity(quantity, amount.minimum, maximum, amount.roundingUnit);
    const isPc = selectedPlatform?.value === 'pc';
    const deliveryIsValid = isPc || selectedDelivery !== null;
    const requestDelivery = isPc ? null : (selectedDelivery?.value ?? null);
    const selectedSchedule = useMemo(() => {
        if (selectedPlatform?.value === 'pc') {
            return quoteSchedules.pc;
        }

        if (
            selectedPlatform?.value !== 'playstation' ||
            requestDelivery === null
        ) {
            return null;
        }

        return quoteSchedules[`playstation:${requestDelivery}`];
    }, [quoteSchedules, requestDelivery, selectedPlatform?.value]);
    const selectedQuantity = quantityIsValid
        ? quantity
        : state.lastValidQuantity;
    // The slider stops are priced ahead of time, so dragging never waits on the
    // network. A typed amount between two stops is not in that list, and asking
    // the server for it is what lets a customer buy the amount they meant.
    const scheduleQuote = useMemo(
        () =>
            selectedSchedule === null
                ? null
                : quoteFromSchedule(selectedSchedule, selectedQuantity),
        [selectedQuantity, selectedSchedule],
    );
    const needsLiveQuote =
        selectedPlatform !== null &&
        deliveryIsValid &&
        selectedSchedule !== null &&
        scheduleQuote === null;

    useCoinsQuoteRequest({
        active: needsLiveQuote,
        delivery: requestDelivery,
        dispatch,
        expectedDisplayCurrency: displayCurrency,
        platform: selectedPlatform?.value ?? null,
        quantity: needsLiveQuote ? selectedQuantity : null,
        quoteUrl,
    });

    const quoteState = useMemo<CoinsQuoteViewState>(() => {
        if (selectedPlatform === null || !deliveryIsValid) {
            return { status: 'idle' };
        }

        if (selectedSchedule === null) {
            return { status: 'unavailable' };
        }

        return scheduleQuote === null
            ? state.quoteState
            : { quote: scheduleQuote, status: 'success' };
    }, [
        deliveryIsValid,
        scheduleQuote,
        selectedPlatform,
        selectedSchedule,
        state.quoteState,
    ]);

    useEffect(() => {
        if (pendingFocus.current !== state.step) {
            return;
        }

        const targets: Record<CoinsStep, HTMLElement | null> = {
            amount: amountHeading.current,
            credentials: credentialsHeading.current,
            delivery: deliveryHeading.current,
            platform: platformHeading.current,
            summary: summaryHeading.current,
        };

        targets[state.step]?.focus({ preventScroll: true });
        // Steps differ in height, so without re-anchoring, leaving a long
        // step can strand the customer past the end of a shorter one. The
        // optional call keeps jsdom (no scrollIntoView) quiet in tests.
        configuratorRoot.current?.scrollIntoView?.({
            behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
            block: 'start',
        });
        pendingFocus.current = null;
    }, [state.step]);

    useEffect(() => {
        return () => {
            credentialsRef.current = emptyCoinsCredentials();
            idempotencyKey.current = null;
        };
    }, []);

    function selectionAnnouncement(value: string) {
        return interpolate(translations.accessibility.selection, { value });
    }

    function navigateTo(step: CoinsStep) {
        pendingFocus.current = step;
        dispatch({ step, type: 'navigated' });
    }

    function beginNewSubmission() {
        idempotencyKey.current = null;
        setRejectedCredentialFields([]);
        setRetrying(false);
        setSubmitError(null);
    }

    function choosePlatform(value: CoinsPlatformValue) {
        const platform = platforms.find((option) => option.value === value);

        if (platform === undefined) {
            return;
        }

        beginNewSubmission();
        dispatch({
            clampMessage: translations.amount_copy.clamped,
            maximum: platform.maximum,
            selectionMessage: selectionAnnouncement(
                translations.platform.options[value],
            ),
            type: 'platform-chosen',
            value,
        });
    }

    function chooseDelivery(value: CoinsDeliveryValue) {
        const delivery = selectedPlatform?.deliveries.find(
            (option) => option.value === value,
        );

        if (delivery === undefined) {
            return;
        }

        beginNewSubmission();
        dispatch({
            clampMessage: translations.amount_copy.clamped,
            maximum: delivery.maximum,
            selectionMessage: selectionAnnouncement(
                translations.delivery.options[value],
            ),
            type: 'delivery-chosen',
            value,
        });
    }

    function continueFromPlatform() {
        if (selectedPlatform === null) {
            return;
        }

        navigateTo(selectedPlatform.value === 'pc' ? 'amount' : 'delivery');
    }

    function continueFromDelivery() {
        if (selectedDelivery !== null) {
            navigateTo('amount');
        }
    }

    function goBack() {
        if (state.step === 'summary') {
            navigateTo('credentials');
        } else if (state.step === 'credentials') {
            navigateTo('amount');
        } else {
            navigateTo(
                state.step === 'amount' && !isPc ? 'delivery' : 'platform',
            );
        }
    }

    function switchToFast() {
        chooseDelivery('fast');
        navigateTo('delivery');
    }

    function updateQuantity(value: string) {
        const sanitizedValue = value.replace(/[^0-9]/g, '');
        const nextQuantity = quantityFromInput(sanitizedValue);
        const isValid =
            nextQuantity !== null &&
            nextQuantity >= amount.minimum &&
            nextQuantity <= maximum &&
            acceptsQuantity(
                nextQuantity,
                amount.minimum,
                maximum,
                amount.roundingUnit,
            );

        if (isValid && nextQuantity === state.lastValidQuantity) {
            const normalizedValue = String(nextQuantity);

            if (state.quantityInput !== normalizedValue) {
                dispatch({
                    type: 'quantity-normalized',
                    value: normalizedValue,
                });
            }

            return;
        }

        beginNewSubmission();
        dispatch({
            type: 'quantity-changed',
            validQuantity: isValid ? nextQuantity : null,
            value: sanitizedValue,
        });
    }

    function commitQuantity(value: number) {
        const committedQuantity = clampAndSnapQuantity(
            value,
            amount.minimum,
            maximum,
            amount.roundingUnit,
        );
        const quantityInputAlreadyMatches =
            quantityFromInput(state.quantityInput) === committedQuantity;

        if (
            committedQuantity === state.lastValidQuantity &&
            quantityInputAlreadyMatches
        ) {
            return;
        }

        beginNewSubmission();
        dispatch({
            type: 'quantity-committed',
            value: committedQuantity,
        });
    }

    function adjustQuantity(delta: number) {
        commitQuantity(state.lastValidQuantity + delta);
    }

    function commitTypedQuantity() {
        commitQuantity(
            quantityFromInput(state.quantityInput) ?? state.lastValidQuantity,
        );
    }

    function updateCredentials(nextCredentials: CoinsCredentials) {
        credentialsRef.current = nextCredentials;
        setCredentials(nextCredentials);
        beginNewSubmission();
    }

    function clearCredentials() {
        const emptyCredentials = emptyCoinsCredentials();

        credentialsRef.current = emptyCredentials;
        setCredentials(emptyCredentials);
        beginNewSubmission();
        navigateTo('amount');
    }

    // Prefill the credentials step from the line being edited. A failed fetch
    // leaves the fields empty — and never leaks — while the note still shows.
    // Placed after the credential mutators: writing the ref from an earlier
    // effect would forbid every later write to it.
    useEffect(() => {
        if (!replacing || replaceCredentialsUrl === null) {
            return;
        }

        const controller = new AbortController();

        loadCartCredentials(replaceCredentialsUrl, controller.signal)
            .then((stored) => {
                const prefilled = toCoinsCredentials(stored);
                credentialsRef.current = prefilled;
                setCredentials(prefilled);
            })
            .catch(() => {
                // Fields stay empty; the editing note still shows.
            });

        return () => controller.abort();
        // Once per configurator: the line being edited never changes mid-flow.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function submitErrorMessage(error: CoinsCartRequestError): string {
        if (error.code === 'transport_error') {
            return translations.summary.transport_error;
        }

        if (error.status === 422) {
            return translations.summary.validation_error;
        }

        if (error.status === 409) {
            return translations.summary.conflict_error;
        }

        if (error.status === 503) {
            return translations.summary.unavailable_error;
        }

        return translations.summary.generic_error;
    }

    async function addToCart(button: HTMLButtonElement) {
        const quote = quoteState.status === 'success' ? quoteState.quote : null;

        if (
            pendingSubmission.current ||
            selectedPlatform === null ||
            quote === null
        ) {
            return;
        }

        pendingSubmission.current = true;
        setPending(true);
        setSubmitError(null);
        idempotencyKey.current ??= newAttemptKey('coins');

        try {
            const addition = await submitCoinsCart({
                cartUrl: cart.addUrl,
                credentials: credentialsRef.current,
                delivery: requestDelivery,
                idempotencyKey: idempotencyKey.current,
                platform: selectedPlatform.value,
                quantity: state.lastValidQuantity,
                ...(replaceCartItemId === null ? {} : { replaceCartItemId }),
            });
            idempotencyKey.current = null;
            // The old line is gone now; a second add from this page is a
            // plain add, not another replacement.
            setReplaceCartItemId(null);
            setAddedVariantIds((current) =>
                current.includes(quote.variantId)
                    ? current
                    : [...current, quote.variantId],
            );
            const priceLabel = formatMinorUnits(
                quote.total.amountHalalah,
                quote.total.currency,
                locale,
            );
            const selectionLabel = [
                translations.platform.options[selectedPlatform.value],
                requestDelivery === null
                    ? translations.summary.delivery_pc
                    : translations.delivery.options[requestDelivery],
                `${formatCoins(state.lastValidQuantity, locale)} ${translations.units.coins}`,
            ].join(' · ');
            await announceCartAddition({
                analytics: {
                    id: quote.variantId,
                    name: translations.summary.service_value,
                    priceMinorSar: quote.total.amountHalalah,
                    quantity: 1,
                    serviceType: 'coins',
                },
                cartCount: addition.cartCount,
                cartTotalHalalah: addition.cartTotalHalalah,
                cartUrl: addition.cartUrl,
                from: button,
                imageAlt: translations.summary.service_value,
                imageUrl: '/images/store/coins/ut-coin-160.webp',
                itemLabel: translations.summary.service_value,
                priceLabel,
                selectionLabel,
            });
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: addition.cartCount,
                }),
            );
            clearCredentials();
        } catch (error) {
            if (!(error instanceof CoinsCartRequestError)) {
                throw error;
            }

            if (error.conclusive) {
                idempotencyKey.current = null;
            }

            if (error.code === 'already_in_cart') {
                setRetrying(false);
                setSubmitError(null);

                if (quote !== null) {
                    setAddedVariantIds((current) =>
                        current.includes(quote.variantId)
                            ? current
                            : [...current, quote.variantId],
                    );
                }

                announceCartDuplicate({
                    cartUrl:
                        error.cartUrl ??
                        (locale === 'en' ? '/en/cart' : '/cart'),
                    imageAlt: translations.summary.service_value,
                    imageUrl: '/images/store/coins/ut-coin-160.webp',
                    itemLabel: translations.summary.service_value,
                });

                return;
            }

            if (error.status === 422 && error.validationFields.length > 0) {
                setRejectedCredentialFields(error.validationFields);
                setRetrying(false);
                setSubmitError(null);
                dispatch({ step: 'credentials', type: 'navigated' });

                return;
            }

            setRetrying(!error.conclusive || error.status === 409);
            setSubmitError(submitErrorMessage(error));
        } finally {
            pendingSubmission.current = false;
            setPending(false);
        }
    }

    const liveMessage =
        state.announcement === ''
            ? ''
            : interpolate(translations.accessibility.live, {
                  message: state.announcement,
              });

    return (
        <div className="coins-configurator" ref={configuratorRoot}>
            {replacing ? (
                <p className="coins-configurator__editing" role="note">
                    {translations.summary.editing_replace}
                </p>
            ) : null}
            <ProgressRail
                current={state.step}
                includesDelivery={!isPc}
                locale={locale}
                onNavigate={navigateTo}
                translations={translations}
            />

            {liveMessage !== '' ? (
                <p className="sr-only" role="status" style={{ opacity: 0 }}>
                    {liveMessage}
                </p>
            ) : null}

            {state.step === 'platform' ? (
                <PlatformStep
                    focusRef={platformHeading}
                    onChoose={choosePlatform}
                    onContinue={continueFromPlatform}
                    platforms={platforms}
                    selectedValue={state.platformValue}
                    translations={translations}
                />
            ) : null}

            {state.step === 'delivery' && selectedPlatform !== null ? (
                <DeliveryStep
                    focusRef={deliveryHeading}
                    locale={locale}
                    onBack={goBack}
                    onChoose={chooseDelivery}
                    onContinue={continueFromDelivery}
                    platform={selectedPlatform}
                    selectedValue={state.deliveryValue}
                    translations={translations}
                />
            ) : null}

            {state.step === 'amount' && selectedPlatform !== null ? (
                <AmountStep
                    amount={amount}
                    delivery={requestDelivery}
                    focusRef={amountHeading}
                    isValid={quantityIsValid}
                    locale={locale}
                    maximum={maximum}
                    onAdjust={adjustQuantity}
                    onBack={goBack}
                    onCommit={commitQuantity}
                    onContinue={() => navigateTo('credentials')}
                    onQuantityBlur={commitTypedQuantity}
                    onQuantityChange={updateQuantity}
                    onSwitchToFast={switchToFast}
                    quantity={state.lastValidQuantity}
                    quantityInput={state.quantityInput}
                    quoteState={quoteState}
                    translations={translations}
                />
            ) : null}

            {state.step === 'credentials' && selectedPlatform !== null ? (
                <CredentialsStep
                    credentials={credentials}
                    delivery={requestDelivery}
                    focusRef={credentialsHeading}
                    locale={locale}
                    onBack={goBack}
                    onCancel={clearCredentials}
                    onChange={updateCredentials}
                    onContinue={() => {
                        if (quoteState.status === 'success') {
                            navigateTo('summary');
                        }
                    }}
                    quoteState={quoteState}
                    rejectedFields={rejectedCredentialFields}
                    requiresCurrentBalance={requiresCurrentBalance}
                    platform={selectedPlatform.value}
                    termsUrl={termsUrl}
                    translations={translations}
                    warrantyUrl={warrantyUrl}
                />
            ) : null}

            {state.step === 'summary' &&
            selectedPlatform !== null &&
            quoteState.status === 'success' ? (
                <SummaryStep
                    cartUrl={
                        cartUrl ?? (locale === 'en' ? '/en/cart' : '/cart')
                    }
                    delivery={requestDelivery}
                    error={submitError}
                    focusRef={summaryHeading}
                    inCart={
                        // While replacing, the old line is the one being
                        // replaced — it must not block the edit as "in cart".
                        !replacing &&
                        (cartVariantIds.includes(quoteState.quote.variantId) ||
                            addedVariantIds.includes(
                                quoteState.quote.variantId,
                            ))
                    }
                    locale={locale}
                    onAdd={addToCart}
                    onBack={goBack}
                    onCancel={clearCredentials}
                    pending={pending}
                    platform={selectedPlatform.value}
                    quote={quoteState.quote}
                    retrying={retrying}
                    translations={translations}
                />
            ) : null}
        </div>
    );
}
