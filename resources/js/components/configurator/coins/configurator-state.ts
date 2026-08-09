import type {
    CoinsDeliveryValue,
    CoinsPlatformValue,
    CoinsQuote,
    CoinsQuoteViewState,
} from '@/types/coins';

import type { CoinsStep } from './progress-rail';

export type CoinsConfiguratorState = {
    announcement: string;
    deliveryValue: CoinsDeliveryValue | null;
    platformValue: CoinsPlatformValue | null;
    quantityInput: string;
    quoteState: CoinsQuoteViewState;
    step: CoinsStep;
};

export type CoinsConfiguratorAction =
    | {
          type: 'platform-chosen';
          value: CoinsPlatformValue;
          maximum: number;
          selectionMessage: string;
          clampMessage: string;
      }
    | {
          type: 'delivery-chosen';
          value: CoinsDeliveryValue;
          maximum: number;
          selectionMessage: string;
          clampMessage: string;
      }
    | { type: 'navigated'; step: CoinsStep }
    | { type: 'restarted'; minimum: number }
    | { type: 'quantity-changed'; value: string; isValid: boolean }
    | { type: 'quote-loading' }
    | { type: 'quote-succeeded'; quote: CoinsQuote }
    | { type: 'quote-validation' }
    | { type: 'quote-unavailable' };

export function quantityFromInput(value: string): number | null {
    if (value.trim() === '') {
        return null;
    }

    const quantity = Number(value);

    return Number.isSafeInteger(quantity) ? quantity : null;
}

export function createInitialConfiguratorState(
    minimum: number,
): CoinsConfiguratorState {
    return {
        announcement: '',
        deliveryValue: null,
        platformValue: null,
        quantityInput: String(minimum),
        quoteState: { status: 'idle' },
        step: 'platform',
    };
}

function clampSelection(
    state: CoinsConfiguratorState,
    maximum: number,
    selectionMessage: string,
    clampMessage: string,
) {
    const quantity = quantityFromInput(state.quantityInput);
    const isClamped = quantity !== null && quantity > maximum;

    return {
        announcement: isClamped ? clampMessage : selectionMessage,
        quantityInput: isClamped ? String(maximum) : state.quantityInput,
    };
}

export function coinsConfiguratorReducer(
    state: CoinsConfiguratorState,
    action: CoinsConfiguratorAction,
): CoinsConfiguratorState {
    switch (action.type) {
        case 'platform-chosen': {
            const selection = clampSelection(
                state,
                action.maximum,
                action.selectionMessage,
                action.clampMessage,
            );

            return {
                ...state,
                ...selection,
                deliveryValue: null,
                platformValue: action.value,
                quoteState: { status: 'idle' },
            };
        }
        case 'delivery-chosen': {
            const selection = clampSelection(
                state,
                action.maximum,
                action.selectionMessage,
                action.clampMessage,
            );

            return {
                ...state,
                ...selection,
                deliveryValue: action.value,
                quoteState: { status: 'idle' },
            };
        }
        case 'navigated':
            return {
                ...state,
                quoteState: { status: 'idle' },
                step: action.step,
            };
        case 'restarted':
            return createInitialConfiguratorState(action.minimum);
        case 'quantity-changed':
            return {
                ...state,
                announcement: '',
                quantityInput: action.value,
                quoteState: action.isValid
                    ? { status: 'idle' }
                    : { status: 'validation' },
            };
        case 'quote-loading':
            return { ...state, quoteState: { status: 'loading' } };
        case 'quote-succeeded':
            return {
                ...state,
                quoteState: { quote: action.quote, status: 'success' },
            };
        case 'quote-validation':
            return { ...state, quoteState: { status: 'validation' } };
        case 'quote-unavailable':
            return { ...state, quoteState: { status: 'unavailable' } };
    }
}
