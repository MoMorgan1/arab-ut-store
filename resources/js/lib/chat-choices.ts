import type { ChatMessage } from '@/types/chat';

export type ChatChoiceItem = {
    id: string;
    label: string;
    message: string;
};

export type ChatChoices = {
    prompt: string;
    items: ChatChoiceItem[];
};

/** Payload shape this client understands. A newer server version is ignored. */
const SUPPORTED_VERSION = 'choices.v1';

/**
 * Seven is the widest real question: the Rivals ladder, which a customer can
 * start from at any of divisions 7 through 1. Silently slicing it would drop
 * division 1 and leave a customer unable to answer.
 */
const MAX_ITEMS = 7;

/** A chip's text is sent verbatim as the customer's next message. */
const MAX_MESSAGE_LENGTH = 120;

function isChoice(value: unknown): value is ChatChoiceItem {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const item = value as Record<string, unknown>;

    const hasText = (['id', 'label', 'message'] as const).every(
        (key) => typeof item[key] === 'string' && item[key] !== '',
    );

    if (!hasText) {
        return false;
    }

    // Tapping a chip sends its message as if the customer typed it, so the text
    // has to look like something a person would type: one short line. A
    // multi-line or oversized payload is a malformed record, not a question.
    const message = item.message as string;

    return message.length <= MAX_MESSAGE_LENGTH && !/[\r\n]/.test(message);
}

/**
 * Reads the quick-choice chips attached to an assistant message.
 *
 * Message metadata is persisted JSON, so it is validated rather than trusted:
 * anything unexpected yields no chips and the reply still renders as text.
 */
export function chatChoices(message: ChatMessage): ChatChoices | null {
    if (message.senderType !== 'assistant') {
        return null;
    }

    const metadata = message.metadata;

    if (typeof metadata !== 'object' || metadata === null) {
        return null;
    }

    const choices = (metadata as Record<string, unknown>).choices;

    if (typeof choices !== 'object' || choices === null) {
        return null;
    }

    const payload = choices as Record<string, unknown>;

    if (
        payload.version !== SUPPORTED_VERSION ||
        typeof payload.prompt !== 'string' ||
        payload.prompt === '' ||
        !Array.isArray(payload.items)
    ) {
        return null;
    }

    const items = payload.items.filter(isChoice).slice(0, MAX_ITEMS);

    return items.length === 0 ? null : { prompt: payload.prompt, items };
}
