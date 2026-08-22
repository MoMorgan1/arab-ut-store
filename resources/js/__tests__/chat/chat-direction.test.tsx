import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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

function customer(id: string, content: string): ChatMessage {
    return {
        publicId: id,
        senderType: 'customer',
        messageType: 'text',
        content,
        createdAt: '2026-08-22T10:00:00Z',
    };
}

function assistant(id: string, content: string): ChatMessage {
    return { ...customer(id, content), senderType: 'assistant' };
}

function renderList(messages: ChatMessage[]) {
    return render(
        <ChatMessageList
            messages={messages}
            isLoading={false}
            isAssistantTyping={false}
            hasMore={false}
            isLoadingOlder={false}
            locale="ar"
            onLoadOlder={() => {}}
            onSelectSuggestion={() => {}}
            onRetry={() => {}}
        />,
    );
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
        'uses a mobile-safe composer font size in %s',
        (locale) => {
            render(<ChatComposer locale={locale} onSend={() => undefined} />);

            expect(screen.getByRole('textbox')).toHaveClass(
                'text-base',
                'lg:text-sm',
            );
        },
    );

    it.each([
        ['ar', 'اكتب رسالتك'],
        ['en', 'Type your message'],
    ])(
        'gives the composer an explicit accessible name in %s',
        (locale, name) => {
            render(<ChatComposer locale={locale} onSend={() => undefined} />);

            expect(screen.getByRole('textbox')).toHaveAccessibleName(name);
            expect(screen.getByRole('textbox').closest('form')).toHaveClass(
                'chat-composer--mobile-safe',
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

    it.each(['ar', 'en'])(
        'lets mixed-language system messages choose their own direction in %s',
        (locale) => {
            render(
                <div dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                    <ChatMessageList
                        messages={[
                            {
                                publicId: 'mixed-system-message',
                                senderType: 'system',
                                messageType: 'system',
                                content: 'Welcome مرحبًا بك',
                                createdAt: '2026-08-20T12:00:00.000Z',
                            },
                        ]}
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

            expect(
                screen.getByText('Welcome مرحبًا بك').parentElement,
            ).toHaveAttribute('dir', 'auto');
        },
    );

    it.each(['ar', 'en'])(
        'centers the scroll control physically without RTL clipping in %s',
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

            const log = screen.getByRole('log');
            Object.defineProperties(log, {
                scrollHeight: { configurable: true, value: 500 },
                scrollTop: { configurable: true, value: 0 },
                clientHeight: { configurable: true, value: 100 },
            });
            fireEvent.scroll(log);

            const scrollControl = screen.getByRole('button', {
                name: locale === 'ar' ? 'الانتقال لأسفل' : 'Scroll to bottom',
            });
            expect(scrollControl).toHaveClass('left-1/2', '-translate-x-1/2');
            expect(scrollControl).not.toHaveClass('start-1/2');
        },
    );

    it('gives load, retry, and scroll controls 44px hit targets', () => {
        const failedMessage: ChatMessage = {
            publicId: 'failed-message',
            tempId: 'failed-message',
            senderType: 'customer',
            messageType: 'text',
            content: 'Failed message',
            createdAt: '2026-08-20T12:00:00.000Z',
            clientStatus: 'error',
        };

        render(
            <ChatMessageList
                messages={[failedMessage]}
                isLoading={false}
                isAssistantTyping={false}
                hasMore={true}
                isLoadingOlder={false}
                locale="en"
                onLoadOlder={() => undefined}
                onSelectSuggestion={() => undefined}
                onRetry={() => undefined}
            />,
        );

        const log = screen.getByRole('log');
        Object.defineProperties(log, {
            scrollHeight: { configurable: true, value: 500 },
            scrollTop: { configurable: true, value: 0 },
            clientHeight: { configurable: true, value: 100 },
        });
        fireEvent.scroll(log);

        expect(
            screen.getByRole('button', { name: /Load older messages/i }),
        ).toHaveClass('min-h-11');
        expect(screen.getByRole('button', { name: /Retry/i })).toHaveClass(
            'min-h-11',
        );
        expect(
            screen.getByRole('button', { name: /Scroll to bottom/i }),
        ).toHaveClass('min-h-11');
    });

    it('gives suggestion controls 44px hit targets', () => {
        render(
            <ChatMessageList
                messages={[]}
                isLoading={false}
                isAssistantTyping={false}
                hasMore={false}
                isLoadingOlder={false}
                locale="en"
                onLoadOlder={() => undefined}
                onSelectSuggestion={() => undefined}
                onRetry={() => undefined}
            />,
        );

        expect(screen.getByRole('button', { name: 'Prices' })).toHaveClass(
            'min-h-11',
        );
    });

    it('uses the light bubble palette', () => {
        renderList([customer('c1', 'مرحبا'), assistant('a1', 'أهلًا')]);

        const customerBubble = screen.getByText('مرحبا').parentElement;
        const assistantBubble = screen.getByText('أهلًا').parentElement;

        expect(customerBubble).toHaveClass('bg-[var(--chat-hero)]');
        expect(assistantBubble).toHaveClass('bg-[var(--chat-card)]');
        expect(customerBubble?.parentElement).toHaveClass('chat-bubble-enter');
    });

    it('renders quick replies as gold pills with stagger', () => {
        renderList([assistant('a1', 'أهلًا')]);

        const pill = screen.getByRole('button', { name: 'الأسعار' });
        expect(pill).toHaveClass('rounded-full', 'chat-stagger-in');
        expect(pill).toHaveClass('border-[var(--chat-accent)]');
    });
});
