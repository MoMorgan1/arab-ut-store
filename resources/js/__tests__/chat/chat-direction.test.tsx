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

function messageCluster(content: string): HTMLElement | null {
    return messageFrame(content)?.parentElement ?? null;
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
        ['ar', /إرسال الرسالة/i, 'حقل كتابة الرسالة'],
        ['en', /Send message/i, 'Message input'],
    ])(
        'keeps the composer accessible and the send icon unmirrored in %s',
        (locale, sendLabel, inputLabel) => {
            render(<ChatComposer locale={locale} onSend={() => undefined} />);

            expect(screen.getByRole('textbox')).toHaveAttribute(
                'aria-label',
                inputLabel,
            );
            expect(screen.getByRole('textbox')).toHaveAttribute(
                'name',
                'chat_message',
            );
            expect(screen.getByRole('textbox')).toHaveAttribute(
                'autocomplete',
                'off',
            );
            expect(screen.getByRole('textbox').closest('form')).toHaveClass(
                'chat-composer',
            );

            const sendIcon = screen
                .getByRole('button', { name: sendLabel })
                .querySelector('svg');

            expect(sendIcon).not.toBeNull();
            expect(sendIcon).not.toHaveClass('rotate-180');
        },
    );

    it.each(['ar', 'en'])(
        'uses a mobile-safe composer font size in %s',
        (locale) => {
            render(<ChatComposer locale={locale} onSend={() => undefined} />);

            expect(screen.getByRole('textbox')).toHaveClass(
                'text-base',
                'lg:text-sm',
            );
        },
    );

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
            const customerCluster = messageCluster('Customer message');
            const assistantCluster = messageCluster('Assistant message');

            expect(customerCluster).toHaveAttribute('dir', 'ltr');
            expect(assistantCluster).toHaveAttribute('dir', 'ltr');
            expect(customerCluster).toHaveClass('items-end');
            expect(assistantCluster).toHaveClass('items-start');
            expect(customerFrame).toHaveAttribute('dir', 'auto');
            expect(assistantFrame).toHaveAttribute('dir', 'auto');
        },
    );

    it.each(['ar', 'en'])(
        'keeps the assistant typing indicator physically left in %s',
        (locale) => {
            const { container } = render(
                <div dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                    <ChatMessageList
                        messages={[]}
                        isLoading={false}
                        isAssistantTyping={true}
                        hasMore={false}
                        isLoadingOlder={false}
                        locale={locale}
                        onLoadOlder={() => undefined}
                        onSelectSuggestion={() => undefined}
                        onRetry={() => undefined}
                    />
                </div>,
            );

            const typingIndicator = container.querySelector(
                '[aria-hidden="true"]',
            );
            const typingFrame = typingIndicator?.parentElement;

            expect(typingFrame).toHaveAttribute('dir', 'ltr');
            expect(typingFrame).toHaveClass('w-full', 'justify-start');
        },
    );
});
