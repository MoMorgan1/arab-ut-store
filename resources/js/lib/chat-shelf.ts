import type { ChatMessage } from '@/types/chat';

export type ChatShelfItem = {
    id: string;
    title: string;
    url: string;
    image: string;
};

/** Payload shape this client understands. A newer server version is ignored. */
const SUPPORTED_VERSION = 'shelf.v1';

/** Enough to feel like a choice, few enough to swipe through. */
const MAX_ITEMS = 5;

function isItem(value: unknown): value is ChatShelfItem {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const item = value as Record<string, unknown>;

    const hasText = (['id', 'title', 'url', 'image'] as const).every(
        (key) => typeof item[key] === 'string' && item[key] !== '',
    );

    if (!hasText) {
        return false;
    }

    // Same-origin storefront paths only. A shelf card is a link the customer
    // taps and an image the page loads, so an absolute or scheme-relative URL
    // from a malformed payload must never become an off-site request.
    const url = item.url as string;
    const image = item.image as string;

    return (
        url.startsWith('/') &&
        !url.startsWith('//') &&
        image.startsWith('/') &&
        !image.startsWith('//')
    );
}

/**
 * Reads the product shelf attached to an assistant message.
 *
 * Message metadata is persisted JSON, so it is validated rather than trusted:
 * anything unexpected yields no shelf and the reply still renders as text.
 */
export function chatShelfItems(message: ChatMessage): ChatShelfItem[] {
    if (message.senderType !== 'assistant') {
        return [];
    }

    const metadata = message.metadata;

    if (typeof metadata !== 'object' || metadata === null) {
        return [];
    }

    const shelf = (metadata as Record<string, unknown>).shelf;

    if (typeof shelf !== 'object' || shelf === null) {
        return [];
    }

    const payload = shelf as Record<string, unknown>;

    if (
        payload.version !== SUPPORTED_VERSION ||
        !Array.isArray(payload.items)
    ) {
        return [];
    }

    return payload.items.filter(isItem).slice(0, MAX_ITEMS);
}
