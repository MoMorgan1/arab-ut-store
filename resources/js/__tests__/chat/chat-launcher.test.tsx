import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatLauncher } from '@/components/chat/chat-launcher';

describe('ChatLauncher Component', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        sessionStorage.clear();
    });

    afterEach(() => {
        cleanup();
        vi.useRealTimers();
        vi.restoreAllMocks();
        sessionStorage.clear();
    });

    it('renders launcher button in Arabic mode with proper accessibility attributes', () => {
        const onToggle = vi.fn();
        render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        const button = screen.getByRole('button', { name: /فتح الشات/i });
        expect(button).toBeInTheDocument();
        expect(button).toHaveAttribute('aria-expanded', 'false');
        expect(button).toHaveAttribute('aria-haspopup', 'dialog');
        expect(screen.getByTestId('chat-online-dot')).toBeInTheDocument();
    });

    it('renders launcher button in English mode with proper accessibility attributes', () => {
        const onToggle = vi.fn();
        render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="en"
                onToggle={onToggle}
            />,
        );

        const button = screen.getByRole('button', { name: /Open chat/i });
        expect(button).toBeInTheDocument();
        expect(button).toHaveAttribute('aria-expanded', 'false');
        expect(button).toHaveAttribute('aria-haspopup', 'dialog');
    });

    it('updates label and aria-expanded when open', () => {
        const onToggle = vi.fn();
        const { rerender } = render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        expect(
            screen.getByRole('button', { name: /فتح الشات/i }),
        ).toHaveAttribute('aria-expanded', 'false');

        rerender(
            <ChatLauncher
                isOpen={true}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        const openButton = screen.getByRole('button', { name: /إغلاق الشات/i });
        expect(openButton).toHaveAttribute('aria-expanded', 'true');
        expect(openButton).toHaveClass('chat-launcher-open');
        expect(screen.queryByTestId('chat-online-dot')).toBeNull();
    });

    it('shows Arabic greeting bubble after 3-second delay on wide viewport', () => {
        const onToggle = vi.fn();
        render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        // Before 3 seconds: bubble is not visible
        expect(screen.queryByTestId('chat-greeting-bubble')).toBeNull();

        act(() => {
            vi.advanceTimersByTime(2999);
        });
        expect(screen.queryByTestId('chat-greeting-bubble')).toBeNull();

        // At 3 seconds: bubble appears with Arabic invitation
        act(() => {
            vi.advanceTimersByTime(1);
        });

        const bubble = screen.getByTestId('chat-greeting-bubble');
        expect(bubble).toBeInTheDocument();
        expect(bubble).toHaveTextContent('محتاج مساعدة؟ اسألني');
    });

    it('shows English greeting bubble after 3-second delay when locale is en', () => {
        const onToggle = vi.fn();
        render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="en"
                onToggle={onToggle}
            />,
        );

        act(() => {
            vi.advanceTimersByTime(3000);
        });

        const bubble = screen.getByTestId('chat-greeting-bubble');
        expect(bubble).toBeInTheDocument();
        expect(bubble).toHaveTextContent('Need help? Ask me');
    });

    it('does not show greeting bubble when chat is already open', () => {
        const onToggle = vi.fn();
        render(
            <ChatLauncher
                isOpen={true}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        act(() => {
            vi.advanceTimersByTime(5000);
        });

        expect(screen.queryByTestId('chat-greeting-bubble')).toBeNull();
    });

    it('disappears immediately when chat is opened', () => {
        const onToggle = vi.fn();
        const { rerender } = render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        act(() => {
            vi.advanceTimersByTime(3000);
        });
        expect(screen.getByTestId('chat-greeting-bubble')).toBeInTheDocument();

        // Customer opens chat
        rerender(
            <ChatLauncher
                isOpen={true}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        expect(screen.queryByTestId('chat-greeting-bubble')).toBeNull();
    });

    it('clicking greeting bubble invokes onToggle to open chat', () => {
        const onToggle = vi.fn();
        render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        act(() => {
            vi.advanceTimersByTime(3000);
        });

        const bubble = screen.getByTestId('chat-greeting-bubble');
        fireEvent.click(bubble);

        expect(onToggle).toHaveBeenCalledTimes(1);
    });

    it('dismissing greeting bubble persists in sessionStorage and never nags again in the session', () => {
        const onToggle = vi.fn();
        const { unmount } = render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        act(() => {
            vi.advanceTimersByTime(3000);
        });

        const dismissBtn = screen.getByTestId('chat-greeting-dismiss');
        fireEvent.click(dismissBtn);

        expect(screen.queryByTestId('chat-greeting-bubble')).toBeNull();
        expect(onToggle).not.toHaveBeenCalled();
        expect(sessionStorage.getItem('arabut_chat_greeting_dismissed')).toBe(
            '1',
        );

        // Unmount and remount (simulating page navigation within same session)
        unmount();

        render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        act(() => {
            vi.advanceTimersByTime(5000);
        });

        expect(screen.queryByTestId('chat-greeting-bubble')).toBeNull();
    });

    it('renders attention beacon when unopened and stops permanently once chat is opened', () => {
        const onToggle = vi.fn();
        const { rerender } = render(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        expect(screen.getByTestId('chat-beacon-ring')).toBeInTheDocument();

        // Customer opens chat
        rerender(
            <ChatLauncher
                isOpen={true}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        expect(screen.queryByTestId('chat-beacon-ring')).toBeNull();
        expect(sessionStorage.getItem('arabut_chat_opened')).toBe('1');

        // Customer closes chat: beacon does NOT return
        rerender(
            <ChatLauncher
                isOpen={false}
                unreadCount={0}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        expect(screen.queryByTestId('chat-beacon-ring')).toBeNull();
    });

    it('renders unread badge when unreadCount > 0 and closed', () => {
        const onToggle = vi.fn();
        const { rerender } = render(
            <ChatLauncher
                isOpen={false}
                unreadCount={4}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        expect(screen.getByText('4')).toBeInTheDocument();
        expect(screen.getByLabelText('4 رسائل غير مقروءة')).toBeInTheDocument();

        // When open, badge is hidden
        rerender(
            <ChatLauncher
                isOpen={true}
                unreadCount={4}
                locale="ar"
                onToggle={onToggle}
            />,
        );

        expect(screen.queryByText('4')).toBeNull();
    });

    it('renders +9 badge when unreadCount exceeds 9', () => {
        const onToggle = vi.fn();
        render(
            <ChatLauncher
                isOpen={false}
                unreadCount={15}
                locale="en"
                onToggle={onToggle}
            />,
        );

        expect(screen.getByText('+9')).toBeInTheDocument();
        expect(screen.getByLabelText('15 unread messages')).toBeInTheDocument();
    });
});
