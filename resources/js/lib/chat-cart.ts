import type { ChatMessage } from '@/types/chat';
import type { CoinsDeliveryValue, CoinsPlatformValue } from '@/types/coins';

export type ChatCoinsCartOffer = {
    service: 'coins';
    platform: CoinsPlatformValue;
    delivery: CoinsDeliveryValue | null;
    quantity: number;
};

/** Payload shape this client understands. A newer server version is ignored. */
const SUPPORTED_VERSION = 'cart.v1';

/**
 * The coins configurator sells nothing above five million, and the cart
 * endpoint enforces its own bounds. This is only here so a malformed payload
 * cannot render a panel offering an absurd amount before the server says no.
 */
const MAX_QUANTITY = 20_000_000;

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * A console order is priced per delivery speed, so it is only cart-ready with
 * one chosen. PC is sold at a single speed and carries none — a delivery on a
 * PC selection means the payload disagrees with the catalogue, so it is
 * rejected rather than quietly dropped.
 */
function coinsSelection(
    payload: Record<string, unknown>,
): ChatCoinsCartOffer | null {
    const selection = payload.selection;

    if (!isRecord(selection)) {
        return null;
    }

    const { platform, delivery, quantity } = selection;

    if (
        !Number.isSafeInteger(quantity) ||
        Number(quantity) < 1 ||
        Number(quantity) > MAX_QUANTITY
    ) {
        return null;
    }

    if (platform === 'pc') {
        return delivery === undefined
            ? {
                  service: 'coins',
                  platform: 'pc',
                  delivery: null,
                  quantity: Number(quantity),
              }
            : null;
    }

    if (
        platform !== 'playstation' ||
        (delivery !== 'normal' && delivery !== 'fast')
    ) {
        return null;
    }

    return {
        service: 'coins',
        platform: 'playstation',
        delivery,
        quantity: Number(quantity),
    };
}

/**
 * Reads the add-to-cart offer attached to an assistant message.
 *
 * Message metadata is persisted JSON, so it is validated rather than trusted:
 * anything unexpected yields no offer and the reply still renders as text. The
 * offer never carries a price or a credential — the panel resolves the price
 * live and collects the credentials itself.
 */
export function chatCartOffer(message: ChatMessage): ChatCoinsCartOffer | null {
    if (message.senderType !== 'assistant') {
        return null;
    }

    const metadata = message.metadata;

    if (!isRecord(metadata)) {
        return null;
    }

    const cart = metadata.cart;

    if (!isRecord(cart) || cart.version !== SUPPORTED_VERSION) {
        return null;
    }

    return cart.service === 'coins' ? coinsSelection(cart) : null;
}
