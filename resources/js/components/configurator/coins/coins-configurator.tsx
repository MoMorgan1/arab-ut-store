import { useEffect, useMemo, useReducer, useRef, useState } from 'react';

import { announceCartAddition } from '@/lib/cart-added-event';
import { CoinsCartRequestError, submitCoinsCart } from '@/lib/coins-cart-api';
import { quoteFromSchedule } from '@/lib/coins-quote-schedule';
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

type CoinsConfiguratorProps = {
    amount: CoinsAmountRules;
    cart: CoinsCartConfig;
    locale: 'ar' | 'en';
    platforms: CoinsPlatformOption[];
    quoteSchedules: CoinsQuoteSchedules;
    termsUrl: string;
    translations: CoinsStoreTranslations;
    warrantyUrl: string;
};

export function CoinsConfigurator({
    amount,
    cart,
    locale,
    platforms,
    quoteSchedules,
    termsUrl,
    translations,
    warrantyUrl,
}: CoinsConfiguratorProps) {
    const [state, dispatch] = useReducer(
        coinsConfiguratorReducer,
        createInitialConfiguratorState(amount.minimum, cart.initialSelection),
    );
    const [credentials, setCredentials] = useState<CoinsCredentials>(
        emptyCoinsCredentials,
    );
    const [pending, setPending] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [rejectedCredentialFields, setRejectedCredentialFields] = useState<
        CoinsCredentialField[]
    >([]);
    const credentialsRef = useRef(credentials);
    const idempotencyKey = useRef<string | null>(null);
    const pendingSubmission = useRef(false);
    const pendingFocus = useRef<CoinsStep | null>(null);
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
        quantity >= amount.minimum &&
        quantity <= maximum &&
        quantity % amount.increment === 0;
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
    const quoteState = useMemo<CoinsQuoteViewState>(() => {
        if (selectedPlatform === null || !deliveryIsValid) {
            return { status: 'idle' };
        }

        if (selectedSchedule === null) {
            return { status: 'unavailable' };
        }

        const selectedQuantity = quantityIsValid
            ? quantity
            : state.lastValidQuantity;
        const quote = quoteFromSchedule(selectedSchedule, selectedQuantity);

        return quote === null
            ? { status: 'unavailable' }
            : { quote, status: 'success' };
    }, [
        deliveryIsValid,
        quantity,
        quantityIsValid,
        selectedPlatform,
        selectedSchedule,
        state.lastValidQuantity,
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
            nextQuantity % amount.increment === 0;

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
            amount.increment,
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

    async function addToCart() {
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
        idempotencyKey.current ??= crypto.randomUUID();

        try {
            const addition = await submitCoinsCart({
                cartUrl: cart.addUrl,
                credentials: credentialsRef.current,
                delivery: requestDelivery,
                idempotencyKey: idempotencyKey.current,
                platform: selectedPlatform.value,
                quantity: state.lastValidQuantity,
            });
            idempotencyKey.current = null;
            announceCartAddition({
                cartUrl: addition.cartUrl,
                imageAlt: translations.summary.service_value,
                imageUrl: '/images/store/coins/ut-coin-160.webp',
                itemLabel: translations.summary.service_value,
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
        <div className="coins-configurator">
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
                    delivery={requestDelivery}
                    error={submitError}
                    focusRef={summaryHeading}
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
