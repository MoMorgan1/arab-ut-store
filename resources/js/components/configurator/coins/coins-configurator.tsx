import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useReducer, useRef, useState } from 'react';

import { CoinsCartRequestError, submitCoinsCart } from '@/lib/coins-cart-api';
import type {
    CoinsAmountRules,
    CoinsCartConfig,
    CoinsCredentials,
    CoinsDeliveryValue,
    CoinsPlatformOption,
    CoinsPlatformValue,
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

type CoinsConfiguratorProps = {
    amount: CoinsAmountRules;
    authenticated: boolean;
    cart: CoinsCartConfig;
    locale: 'ar' | 'en';
    platforms: CoinsPlatformOption[];
    quoteUrl: string;
    translations: CoinsStoreTranslations;
};

export function CoinsConfigurator({
    amount,
    authenticated,
    cart,
    locale,
    platforms,
    quoteUrl,
    translations,
}: CoinsConfiguratorProps) {
    const { displayCurrency } = usePage<{ displayCurrency: string }>().props;
    const [state, dispatch] = useReducer(
        coinsConfiguratorReducer,
        createInitialConfiguratorState(amount.minimum, cart.initialSelection),
    );
    const [credentials, setCredentials] = useState<CoinsCredentials>(
        emptyCoinsCredentials,
    );
    const [pending, setPending] = useState(false);
    const [redirectUrl, setRedirectUrl] = useState<string | null>(null);
    const [retrying, setRetrying] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
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
    const invalidateQuoteRequest = useCoinsQuoteRequest({
        active:
            (state.step === 'amount' || state.step === 'credentials') &&
            selectedPlatform !== null &&
            deliveryIsValid &&
            quantityIsValid,
        delivery: requestDelivery,
        dispatch,
        expectedDisplayCurrency: displayCurrency,
        platform: selectedPlatform?.value ?? null,
        quantity,
        quoteUrl,
    });

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
        if (redirectUrl !== null) {
            router.visit(redirectUrl);
        }
    }, [redirectUrl]);

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
        invalidateQuoteRequest();
        pendingFocus.current = step;
        dispatch({ step, type: 'navigated' });
    }

    function beginNewSubmission() {
        idempotencyKey.current = null;
        setRetrying(false);
        setSubmitError(null);
    }

    function choosePlatform(value: CoinsPlatformValue) {
        const platform = platforms.find((option) => option.value === value);

        if (platform === undefined) {
            return;
        }

        invalidateQuoteRequest();
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

        invalidateQuoteRequest();
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

        invalidateQuoteRequest();
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

        invalidateQuoteRequest();
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

    function resumeUrl(): string | null {
        if (authenticated || selectedPlatform === null) {
            return null;
        }

        const url = new URL(cart.resumeUrl, window.location.origin);
        url.searchParams.set('platform', selectedPlatform.value);
        url.searchParams.set('quantity', String(state.lastValidQuantity));

        if (requestDelivery !== null) {
            url.searchParams.set('delivery', requestDelivery);
        }

        return `${url.pathname}${url.search}`;
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
        const quote =
            state.quoteState.status === 'success'
                ? state.quoteState.quote
                : null;

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
            const emptyCredentials = emptyCoinsCredentials();

            credentialsRef.current = emptyCredentials;
            idempotencyKey.current = null;
            setCredentials(emptyCredentials);
            setRedirectUrl(addition.cartUrl);
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: addition.cartCount,
                }),
            );
        } catch (error) {
            if (!(error instanceof CoinsCartRequestError)) {
                throw error;
            }

            if (error.conclusive) {
                idempotencyKey.current = null;
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
                    continueHref={resumeUrl()}
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
                    quoteState={state.quoteState}
                    translations={translations}
                />
            ) : null}

            {state.step === 'credentials' && selectedPlatform !== null ? (
                <CredentialsStep
                    credentials={credentials}
                    focusRef={credentialsHeading}
                    locale={locale}
                    onBack={goBack}
                    onCancel={clearCredentials}
                    onChange={updateCredentials}
                    onContinue={() => {
                        if (state.quoteState.status === 'success') {
                            navigateTo('summary');
                        }
                    }}
                    quoteState={state.quoteState}
                    translations={translations}
                />
            ) : null}

            {state.step === 'summary' &&
            selectedPlatform !== null &&
            state.quoteState.status === 'success' &&
            redirectUrl === null ? (
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
                    quote={state.quoteState.quote}
                    retrying={retrying}
                    translations={translations}
                />
            ) : null}

            {redirectUrl === null ? null : (
                <p className="coins-redirecting" role="status">
                    {translations.summary.adding}
                </p>
            )}
        </div>
    );
}
