import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatHandoffBanner } from '@/components/chat/chat-handoff-banner';
import { ChatMessageList } from '@/components/chat/chat-message-list';
import type { ChatConversationTicket, ChatMessage } from '@/types/chat';

describe('ChatHandoffBanner', () => {
    beforeEach(() => {
        // jsdom has no layout, so the message list's scroll sentinel has no
        // scrollIntoView unless the suite provides one.
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('renders the requested state with ticket number and Arabic copy', () => {
        const ticket: ChatConversationTicket = {
            number: 'TKT-4F9A2C',
            status: 'open',
        };

        render(
            <ChatHandoffBanner
                handoffState="requested"
                ticket={ticket}
                locale="ar"
            />,
        );

        expect(screen.getByText('طلبك وصل للفريق')).toBeInTheDocument();
        expect(screen.getByText('TKT-4F9A2C')).toBeInTheDocument();
        expect(screen.getByTestId('chat-handoff-banner')).toHaveAttribute(
            'data-handoff-state',
            'requested',
        );
    });

    it('renders the requested state in English', () => {
        const ticket: ChatConversationTicket = {
            number: 'TKT-4F9A2C',
            status: 'open',
        };

        render(
            <ChatHandoffBanner
                handoffState="requested"
                ticket={ticket}
                locale="en"
            />,
        );

        expect(
            screen.getByText('Your request reached the team'),
        ).toBeInTheDocument();
        expect(screen.getByText('TKT-4F9A2C')).toBeInTheDocument();
    });

    it('renders the active state naming the staff responder in Arabic and English', () => {
        const ticket: ChatConversationTicket = {
            number: 'TKT-4F9A2C',
            status: 'open',
            responderName: 'محمد',
        };

        const { rerender } = render(
            <ChatHandoffBanner
                handoffState="active"
                ticket={ticket}
                locale="ar"
            />,
        );

        expect(screen.getByText('محمد من الفريق يرد عليك')).toBeInTheDocument();
        // The avatar initial comes from the responder's own name, so an Arabic
        // responder gets an Arabic letter — not a transliterated 'M'.
        expect(screen.getByText('م')).toBeInTheDocument();
        expect(screen.getByText('TKT-4F9A2C')).toBeInTheDocument();

        rerender(
            <ChatHandoffBanner
                handoffState="active"
                ticket={{ ...ticket, responderName: 'Mohamed' }}
                locale="en"
            />,
        );

        expect(
            screen.getByText('Mohamed from the team is replying'),
        ).toBeInTheDocument();
        expect(screen.getByText('M')).toBeInTheDocument();
    });

    it('renders the resolved state with reopen button and handles click', () => {
        const onRequestNewTicket = vi.fn();
        const ticket: ChatConversationTicket = {
            number: 'TKT-4F9A2C',
            status: 'resolved',
        };

        render(
            <ChatHandoffBanner
                handoffState="resolved"
                ticket={ticket}
                locale="ar"
                onRequestNewTicket={onRequestNewTicket}
            />,
        );

        expect(screen.getByText('تم حل التذكرة')).toBeInTheDocument();
        expect(screen.getByText('TKT-4F9A2C')).toBeInTheDocument();

        const reopenBtn = screen.getByRole('button', {
            name: 'تحتاج مساعدة أكثر؟',
        });
        expect(reopenBtn).toBeInTheDocument();

        fireEvent.click(reopenBtn);
        expect(onRequestNewTicket).toHaveBeenCalledTimes(1);
    });

    it('renders nothing when handoff state is none or offered', () => {
        const ticket: ChatConversationTicket = {
            number: 'TKT-4F9A2C',
            status: 'open',
        };

        const { container, rerender } = render(
            <ChatHandoffBanner
                handoffState="none"
                ticket={ticket}
                locale="ar"
            />,
        );

        expect(container.firstChild).toBeNull();

        rerender(
            <ChatHandoffBanner
                handoffState="offered"
                ticket={ticket}
                locale="ar"
            />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('enforces the non-negotiable copy rule: no response time promises', () => {
        const ticket: ChatConversationTicket = {
            number: 'TKT-999999',
            status: 'open',
            responderName: 'Mohamed',
        };

        const prohibited = ['soon', 'shortly', 'within', 'قريبًا', 'خلال'];

        for (const state of ['requested', 'active', 'resolved'] as const) {
            for (const locale of ['ar', 'en'] as const) {
                const { container } = render(
                    <ChatHandoffBanner
                        handoffState={state}
                        ticket={ticket}
                        locale={locale}
                        onRequestNewTicket={vi.fn()}
                    />,
                );

                const text = container.textContent?.toLowerCase() ?? '';

                for (const word of prohibited) {
                    expect(text).not.toContain(word);
                }

                cleanup();
            }
        }
    });

    it('renders staff bubbles with distinct avatar row, name, and styling from assistant bubbles', () => {
        const messages: ChatMessage[] = [
            {
                publicId: 'msg-1',
                senderType: 'assistant',
                messageType: 'text',
                content: 'أهلًا بك، أنا نواف مساعدك الذكي',
                createdAt: '2026-08-24T10:00:00Z',
            },
            {
                publicId: 'msg-2',
                senderType: 'staff',
                messageType: 'text',
                content: 'أهلًا، أنا محمد من الدعم وسأساعدك الآن',
                staffName: 'محمد',
                createdAt: '2026-08-24T10:05:00Z',
            },
        ];

        render(
            <ChatMessageList
                messages={messages}
                isLoading={false}
                isAssistantTyping={false}
                hasMore={false}
                isLoadingOlder={false}
                locale="ar"
                handoffState="active"
                onLoadOlder={vi.fn()}
                onSelectSuggestion={vi.fn()}
                onRetry={vi.fn()}
            />,
        );

        // Staff author row
        expect(screen.getByText('محمد · فريق عرب التيميت')).toBeInTheDocument();

        // Paused pill
        expect(
            screen.getByText('نواف متوقف مؤقتًا — الفريق يتابع محادثتك'),
        ).toBeInTheDocument();

        // Staff bubble has distinct class
        const staffContent = screen.getByText(
            'أهلًا، أنا محمد من الدعم وسأساعدك الآن',
        );
        const staffBubble = staffContent.closest('.chat-staff-bubble');
        expect(staffBubble).not.toBeNull();
        expect(staffBubble).toHaveClass('border-[#d4a843]');
        expect(staffBubble).toHaveClass('bg-white');

        // Assistant bubble does NOT have staff bubble styling
        const assistantContent = screen.getByText(
            'أهلًا بك، أنا نواف مساعدك الذكي',
        );
        const assistantBubble = assistantContent.closest('.chat-staff-bubble');
        expect(assistantBubble).toBeNull();
    });
});
