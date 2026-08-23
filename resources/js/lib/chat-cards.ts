import type { ChatMessage } from '@/types/chat';

export type ChatServiceCard = {
    id: string;
    title: string;
    subtitle: string;
    cta: string;
    url: string;
};

/** Payload shape this client understands. A newer server version is ignored. */
const SUPPORTED_VERSION = 'cards.v1';

/** Cards are decoration on an answer; more than a couple would bury the reply. */
const MAX_CARDS = 2;

function isCard(value: unknown): value is ChatServiceCard {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const card = value as Record<string, unknown>;

    const hasText = (['id', 'title', 'subtitle', 'cta', 'url'] as const).every(
        (key) => typeof card[key] === 'string' && card[key] !== '',
    );

    if (!hasText) {
        return false;
    }

    // Same-origin storefront paths only: a card is rendered as a link the
    // customer taps, so an absolute or scheme-relative URL from a malformed
    // payload must never become an off-site navigation.
    const url = card.url as string;

    return url.startsWith('/') && !url.startsWith('//');
}

/**
 * Reads the service cards attached to an assistant message.
 *
 * Message metadata is persisted JSON, so it is validated rather than trusted:
 * anything unexpected yields no cards and the reply still renders as text.
 */
export function chatServiceCards(message: ChatMessage): ChatServiceCard[] {
    if (message.senderType !== 'assistant') {
        return [];
    }

    const metadata = message.metadata;

    if (typeof metadata !== 'object' || metadata === null) {
        return [];
    }

    const cards = (metadata as Record<string, unknown>).cards;

    if (typeof cards !== 'object' || cards === null) {
        return [];
    }

    const payload = cards as Record<string, unknown>;

    if (
        payload.version !== SUPPORTED_VERSION ||
        !Array.isArray(payload.items)
    ) {
        return [];
    }

    return payload.items.filter(isCard).slice(0, MAX_CARDS);
}
