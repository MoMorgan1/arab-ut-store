import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatComposer } from '@/components/chat/chat-composer';
import { ChatMessageList } from '@/components/chat/chat-message-list';
import type { ChatMessage } from '@/types/chat';

const messages: ChatMessage[] = [
    {
        publicId: 'customer-message',
        senderType: 'customer',
        messageType: 'text',
        content: 'Customer message',
        createdAt: '2026-08-20T12:00:00.000Z',
    },
    {
        publicId: 'assistant-message',
        senderType: 'assistant',
        messageType: 'text',
        content: 'Assistant message',
        createdAt: '2026-08-20T12:01:00.000Z',
    },
];

function messageFrame(content: string): HTMLElement | null {
    return screen.getByText(content).parentElement?.parentElement ?? null;
}

// Regression from Mohamed's 2026-08-20 mobile acceptance screenshot.
describe('chat direction contracts', () => {
    beforeEach(() => {
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it.each([
        ['ar', /إرسال الرسالة/i],
        ['en', /Send message/i],
    ])('keeps the send icon unmirrored in %s', (locale, sendLabel) => {
        render(<ChatComposer locale={locale} onSend={() => undefined} />);

        const sendIcon = screen
            .getByRole('button', { name: sendLabel })
            .querySelector('svg');

        expect(sendIcon).not.toBeNull();
        expect(sendIcon).not.toHaveClass('rotate-180');
    });

    it.each(['ar', 'en'])(
        'keeps customer messages physically right and assistant messages physically left in %s',
        (locale) => {
            render(
                <div dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                    <ChatMessageList
                        messages={messages}
                        isLoading={false}
                        isAssistantTyping={false}
                        hasMore={false}
                        isLoadingOlder={false}
                        locale={locale}
                        onLoadOlder={() => undefined}
                        onSelectSuggestion={() => undefined}
                        onRetry={() => undefined}
                    />
                </div>,
            );

            const customerFrame = messageFrame('Customer message');
            const assistantFrame = messageFrame('Assistant message');

            expect(customerFrame).toHaveClass('ml-auto');
            expect(assistantFrame).toHaveClass('mr-auto');
            expect(customerFrame).toHaveAttribute('dir', 'auto');
            expect(assistantFrame).toHaveAttribute('dir', 'auto');
        },
    );
});
