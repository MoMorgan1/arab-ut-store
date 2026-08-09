import { useEffect, useMemo, useReducer, useRef } from 'react';

import type {
    CoinsAmountRules,
    CoinsDeliveryValue,
    CoinsPlatformOption,
    CoinsPlatformValue,
    CoinsProductSummary,
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
import { DeliveryStep } from './delivery-step';
import { PlatformStep } from './platform-step';
import { ProgressRail } from './progress-rail';
import type { CoinsStep } from './progress-rail';
import { useCoinsQuoteRequest } from './use-coins-quote-request';

type CoinsConfiguratorProps = {
    amount: CoinsAmountRules;
    locale: 'ar' | 'en';
    platforms: CoinsPlatformOption[];
    product: CoinsProductSummary;
    quoteUrl: string;
    translations: CoinsStoreTranslations;
};

export function CoinsConfigurator({
    amount,
    locale,
    platforms,
    product,
    quoteUrl,
    translations,
}: CoinsConfiguratorProps) {
    const [state, dispatch] = useReducer(
        coinsConfiguratorReducer,
        amount.minimum,
        createInitialConfiguratorState,
    );
    const pendingFocus = useRef<CoinsStep | null>(null);
    const platformHeading = useRef<HTMLLegendElement | null>(null);
    const deliveryHeading = useRef<HTMLLegendElement | null>(null);
    const amountHeading = useRef<HTMLHeadingElement | null>(null);

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
            state.step === 'amount' &&
            selectedPlatform !== null &&
            deliveryIsValid &&
            quantityIsValid,
        delivery: requestDelivery,
        dispatch,
        platform: selectedPlatform?.value ?? null,
        quantity,
        quoteUrl,
    });

    useEffect(() => {
        if (pendingFocus.current !== state.step) {
            return;
        }

        const target =
            state.step === 'platform'
                ? platformHeading.current
                : state.step === 'delivery'
                  ? deliveryHeading.current
                  : amountHeading.current;

        target?.focus({ preventScroll: true });
        pendingFocus.current = null;
    }, [state.step]);

    function selectionAnnouncement(value: string) {
        return interpolate(translations.accessibility.selection, { value });
    }

    function navigateTo(step: CoinsStep) {
        invalidateQuoteRequest();
        pendingFocus.current = step;
        dispatch({ step, type: 'navigated' });
    }

    function choosePlatform(value: CoinsPlatformValue) {
        const platform = platforms.find((option) => option.value === value);

        if (platform === undefined) {
            return;
        }

        invalidateQuoteRequest();
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
        navigateTo(state.step === 'amount' && !isPc ? 'delivery' : 'platform');
    }

    function restart() {
        invalidateQuoteRequest();
        pendingFocus.current = 'platform';
        dispatch({ minimum: amount.minimum, type: 'restarted' });
    }

    function updateQuantity(value: string) {
        const sanitizedValue = value.replace(/[^0-9]/g, '');
        const nextQuantity = quantityFromInput(sanitizedValue);
        const isValid =
            nextQuantity !== null &&
            nextQuantity >= amount.minimum &&
            nextQuantity <= maximum &&
            nextQuantity % amount.increment === 0;

        invalidateQuoteRequest();
        dispatch({
            type: 'quantity-changed',
            validQuantity: isValid ? nextQuantity : null,
            value: sanitizedValue,
        });
    }

    function commitQuantity(value: number) {
        invalidateQuoteRequest();
        dispatch({
            type: 'quantity-committed',
            value: clampAndSnapQuantity(
                value,
                amount.minimum,
                maximum,
                amount.increment,
            ),
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
                translations={translations}
            />

            {liveMessage !== '' ? (
                <p className="coins-live" role="status">
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
                    focusRef={amountHeading}
                    isValid={quantityIsValid}
                    locale={locale}
                    maximum={maximum}
                    onAdjust={adjustQuantity}
                    onBack={goBack}
                    onCommit={commitQuantity}
                    onQuantityBlur={commitTypedQuantity}
                    onQuantityChange={updateQuantity}
                    onRestart={restart}
                    product={product}
                    quantity={state.lastValidQuantity}
                    quantityInput={state.quantityInput}
                    quoteState={state.quoteState}
                    translations={translations}
                />
            ) : null}
        </div>
    );
}
