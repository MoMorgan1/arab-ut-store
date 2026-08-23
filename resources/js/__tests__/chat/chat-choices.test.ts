import { describe, expect, it } from 'vitest';
import { chatChoices } from '@/lib/chat-choices';
import type { ChatMessage } from '@/types/chat';

function message(metadata: unknown, senderType = 'assistant'): ChatMessage {
    return {
        publicId: 'msg-1',
        senderType: senderType as ChatMessage['senderType'],
        messageType: 'text',
        content: 'reply',
        metadata: metadata as ChatMessage['metadata'],
        createdAt: '2026-08-23T00:00:00Z',
    };
}

const choices = {
    version: 'choices.v1',
    prompt: 'على أي منصة؟',
    items: [
        {
            id: 'coins-platform:playstation',
            label: 'بلايستيشن',
            message: 'بلايستيشن',
        },
        { id: 'coins-platform:pc', label: 'كمبيوتر PC', message: 'بي سي' },
    ],
};

describe('chatChoices', () => {
    it('reads a well-formed payload', () => {
        const result = chatChoices(message({ choices }));

        expect(result?.prompt).toBe('على أي منصة؟');
        expect(result?.items).toHaveLength(2);
    });

    it('ignores a payload from a newer server version', () => {
        expect(
            chatChoices(
                message({ choices: { ...choices, version: 'choices.v2' } }),
            ),
        ).toBeNull();
    });

    it('ignores chips on a customer message', () => {
        expect(chatChoices(message({ choices }, 'customer'))).toBeNull();
    });

    it.each([
        null,
        undefined,
        'nonsense',
        42,
        {},
        { choices: null },
        { choices: {} },
    ])('returns null for malformed metadata %s', (metadata) => {
        expect(chatChoices(message(metadata))).toBeNull();
    });

    it('drops items that are missing text', () => {
        const result = chatChoices(
            message({
                choices: {
                    ...choices,
                    items: [
                        choices.items[0],
                        { id: 'x', label: '', message: 'y' },
                    ],
                },
            }),
        );

        expect(result?.items).toHaveLength(1);
    });

    it('rejects a chip whose message is not one short typed line', () => {
        // The message is sent verbatim as the customer's own text, so a
        // multi-line or oversized payload is a malformed record, not a question.
        const result = chatChoices(
            message({
                choices: {
                    ...choices,
                    items: [
                        { id: 'a', label: 'a', message: 'line one\nline two' },
                        { id: 'b', label: 'b', message: 'x'.repeat(200) },
                    ],
                },
            }),
        );

        expect(result).toBeNull();
    });

    it('caps the number of chips so a question stays tappable', () => {
        const many = Array.from({ length: 12 }, (_, i) => ({
            id: `r${i}`,
            label: `rank ${i}`,
            message: `rank ${i}`,
        }));

        // Seven, the width of the Rivals ladder — the widest real question.
        expect(
            chatChoices(message({ choices: { ...choices, items: many } }))
                ?.items,
        ).toHaveLength(7);
    });

    it('returns null when nothing survives validation', () => {
        expect(
            chatChoices(message({ choices: { ...choices, items: [{}] } })),
        ).toBeNull();
    });
});
