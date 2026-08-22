import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatHome } from '@/components/chat/chat-home';

function renderHome(
    overrides: Partial<React.ComponentProps<typeof ChatHome>> = {},
) {
    const props = {
        locale: 'ar',
        hasConversation: false,
        lastMessage: null,
        disabled: false,
        onStart: vi.fn(),
        onContinue: vi.fn(),
        onSelectTopic: vi.fn(),
        onClose: vi.fn(),
        ...overrides,
    };

    render(<ChatHome {...props} />);

    return props;
}

describe('ChatHome', () => {
    afterEach(() => {
        cleanup();
    });

    it('greets in Arabic with rtl direction by default', () => {
        renderHome();

        const greeting = screen.getByRole('heading', { name: 'أهلًا بك' });
        expect(greeting).toBeInTheDocument();
        expect(greeting.closest('[dir]')).toHaveAttribute('dir', 'rtl');
        expect(screen.getByText('كيف نقدر نساعدك اليوم؟')).toBeInTheDocument();
    });

    it('greets in English with ltr direction', () => {
        renderHome({ locale: 'en' });

        const greeting = screen.getByRole('heading', { name: 'Hi there' });
        expect(greeting.closest('[dir]')).toHaveAttribute('dir', 'ltr');
        expect(
            screen.getByRole('button', { name: 'Start a conversation' }),
        ).toBeInTheDocument();
    });

    it('hides the continue card without a conversation and shows it with one', () => {
        renderHome({ locale: 'en' });
        expect(
            screen.queryByRole('button', {
                name: /Continue your conversation/,
            }),
        ).not.toBeInTheDocument();

        cleanup();

        const props = renderHome({
            locale: 'en',
            hasConversation: true,
            lastMessage: {
                preview: 'Order #4821 is being prepared…',
                createdAt: '2026-08-22T10:42:00Z',
            },
        });

        const card = screen.getByRole('button', {
            name: /Continue your conversation/,
        });
        expect(card).toHaveTextContent('Order #4821 is being prepared…');
        fireEvent.click(card);
        expect(props.onContinue).toHaveBeenCalledTimes(1);
    });

    it('starts a conversation and selects topics by label', () => {
        const props = renderHome({ locale: 'en' });

        fireEvent.click(
            screen.getByRole('button', { name: 'Start a conversation' }),
        );
        expect(props.onStart).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Track Order' }));
        expect(props.onSelectTopic).toHaveBeenCalledWith('Track Order');
    });

    it('disables all actions when disabled', () => {
        renderHome({ locale: 'en', disabled: true, hasConversation: true });

        for (const name of [
            'Start a conversation',
            'Prices',
            /Continue your conversation/,
        ]) {
            expect(screen.getByRole('button', { name })).toBeDisabled();
        }
    });

    it('applies staggered entrance classes to cards', () => {
        renderHome({ locale: 'en' });

        const start = screen.getByRole('button', {
            name: 'Start a conversation',
        });
        const card = start.closest('.chat-stagger-in');
        expect(card).not.toBeNull();
    });
});
