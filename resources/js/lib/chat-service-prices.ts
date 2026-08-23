import type { ChatServicePrices } from '@/types/chat';

/**
 * Fetches the starting prices shown on service cards.
 *
 * Prices deliberately do not ride along in the page props: the storefront keeps
 * a tight query budget per page render, and this data is only needed once the
 * assistant has actually offered a card. A failure is not worth surfacing — the
 * card simply renders without a price.
 */
export async function fetchChatServicePrices(): Promise<ChatServicePrices> {
    let response: Response;

    try {
        response = await fetch('/chat/service-prices', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
    } catch {
        return {};
    }

    if (!response.ok) {
        return {};
    }

    let payload: unknown;

    try {
        payload = await response.json();
    } catch {
        return {};
    }

    if (typeof payload !== 'object' || payload === null) {
        return {};
    }

    const prices = (payload as Record<string, unknown>).prices;

    if (typeof prices !== 'object' || prices === null) {
        return {};
    }

    const validated: ChatServicePrices = {};

    for (const [id, entry] of Object.entries(
        prices as Record<string, unknown>,
    )) {
        if (typeof entry !== 'object' || entry === null) {
            continue;
        }

        const price = entry as Record<string, unknown>;

        if (
            Number.isSafeInteger(price.amountMinor) &&
            (price.amountMinor as number) >= 0 &&
            typeof price.currency === 'string' &&
            price.currency !== '' &&
            typeof price.unit === 'string'
        ) {
            validated[id] = {
                amountMinor: price.amountMinor as number,
                currency: price.currency,
                unit: price.unit,
            };
        }
    }

    return validated;
}
