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
    lastValidQuantity: number;
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
    | {
          type: 'quantity-changed';
          value: string;
          validQuantity: number | null;
      }
    | { type: 'quantity-committed'; value: number }
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

export function clampAndSnapQuantity(
    value: number,
    minimum: number,
    maximum: number,
    increment: number,
): number {
    if (
        !Number.isSafeInteger(value) ||
        !Number.isSafeInteger(minimum) ||
        !Number.isSafeInteger(maximum) ||
        !Number.isSafeInteger(increment) ||
        increment <= 0 ||
        minimum > maximum
    ) {
        throw new RangeError('Invalid Coins quantity bounds.');
    }

    const clamped = Math.min(maximum, Math.max(minimum, value));
    const snapped = Math.round(clamped / increment) * increment;

    return Math.min(maximum, Math.max(minimum, snapped));
}

export function createInitialConfiguratorState(
    minimum: number,
): CoinsConfiguratorState {
    return {
        announcement: '',
        deliveryValue: null,
        lastValidQuantity: minimum,
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
    const isClamped = state.lastValidQuantity > maximum;

    return {
        announcement: isClamped ? clampMessage : selectionMessage,
        lastValidQuantity: isClamped ? maximum : state.lastValidQuantity,
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
                lastValidQuantity:
                    action.validQuantity ?? state.lastValidQuantity,
                quantityInput: action.value,
                quoteState:
                    action.validQuantity !== null
                        ? { status: 'idle' }
                        : { status: 'validation' },
            };
        case 'quantity-committed':
            return {
                ...state,
                announcement: '',
                lastValidQuantity: action.value,
                quantityInput: String(action.value),
                quoteState: { status: 'idle' },
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
