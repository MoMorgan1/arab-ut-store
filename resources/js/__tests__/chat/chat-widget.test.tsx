import { readFileSync } from 'node:fs';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatWidget } from '@/components/chat/chat-widget';
import type { AgentTurnState } from '@/types/chat';

const appCss = readFileSync('resources/css/app.css', 'utf8');

function setViewportWidth(width: number) {
    Object.defineProperty(window, 'innerWidth', {
        configurable: true,
        value: width,
        writable: true,
    });
    window.dispatchEvent(new Event('resize'));
}

function declarationsFor(css: string, selector: string): string {
    const selectorIndex = css.indexOf(`${selector} {`);

    if (selectorIndex === -1) {
        throw new Error(`Missing CSS selector: ${selector}`);
    }

    const openBrace = css.indexOf('{', selectorIndex);
    const closeBrace = css.indexOf('}', openBrace);

    return css.slice(openBrace + 1, closeBrace);
}

describe('ChatWidget Component', () => {
    beforeEach(() => {
        setViewportWidth(1024);
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('renders nothing when enabled is false', () => {
        const { container } = render(
            <ChatWidget enabled={false} locale="ar" />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders launcher button in Arabic mode when locale is ar', () => {
        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        const launcherButton = screen.getByRole('button', {
            name: /فتح الشات/i,
        });
        expect(launcherButton).toBeInTheDocument();
        expect(launcherButton).toHaveAttribute('aria-expanded', 'false');
    });

    // Regression: owner mobile acceptance on 2026-08-20 found the gold orb
    // visually excessive after the first launcher polish.
    it('uses the approved quiet launcher geometry', () => {
        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        const launcherButton = screen.getByRole('button', {
            name: /فتح الشات/i,
        });

        expect(launcherButton).toHaveClass(
            'h-14',
            'w-14',
            'sm:h-[60px]',
            'sm:w-[60px]',
        );
        expect(launcherButton.className).toContain(
            'bg-[color:color-mix(in_srgb,var(--arabut-navy-raised)_88%,transparent)]',
        );
        expect(launcherButton).toHaveClass('backdrop-blur-md');
        expect(launcherButton.className).not.toContain('linear-gradient');
        expect(launcherButton.querySelector('.lucide-sparkles')).toBeNull();
    });

    it('anchors the desktop panel one spacing step above the launcher', () => {
        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toHaveClass('sm:right-6', 'sm:bottom-24', 'sm:origin-bottom-right');
    });

    it('marks the account root and keeps its dialog above account navigation', () => {
        const { container } = render(
            <ChatWidget
                initialView="chat"
                enabled={true}
                locale="ar"
                surface="account"
            />,
        );

        const root = container.querySelector('.chat-widget-root--account');
        expect(root).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toHaveClass('chat-widget-dialog', 'z-[70]');
    });

    it('keeps exact account safe-area, desktop reset, and layer geometry', () => {
        const mobileStart = appCss.indexOf('@media (max-width: 47.99rem)');
        const desktopStart = appCss.indexOf(
            '@media (min-width: 48rem)',
            mobileStart,
        );
        const mobileCss = appCss.slice(mobileStart, desktopStart);
        const desktopCss = appCss.slice(desktopStart);
        const mobileChat = declarationsFor(
            mobileCss,
            '.chat-widget-root--account',
        );
        const mobileDialog = declarationsFor(
            mobileCss,
            '.chat-widget-root--account .chat-widget-dialog',
        );
        const mobileNav = declarationsFor(
            mobileCss,
            '.account-mobile-bottom-nav',
        );
        const desktopChat = declarationsFor(
            desktopCss,
            '.chat-widget-root--account',
        );
        const chatLayer = Number(
            /z-index:\s*(\d+)/.exec(mobileChat)?.[1] ?? Number.NaN,
        );
        const navLayer = Number(
            /z-index:\s*(\d+)/.exec(mobileNav)?.[1] ?? Number.NaN,
        );

        expect(mobileStart).toBeGreaterThan(-1);
        expect(desktopStart).toBeGreaterThan(mobileStart);
        expect(mobileChat).toContain(
            'bottom: calc(112px + env(safe-area-inset-bottom));',
        );
        expect(mobileDialog).toContain('inset: 0;');
        expect(chatLayer).toBe(70);
        expect(navLayer).toBe(60);
        expect(chatLayer).toBeGreaterThan(navLayer);
        expect(desktopChat).toContain('bottom: 1.5rem;');
        expect(desktopChat).toContain('z-index: 50;');
    });

    it.each([320, 390])(
        'treats the %ipx account sheet as a focus-contained modal',
        async (width) => {
            setViewportWidth(width);
            vi.mocked(fetch).mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        publicId: 'conv-mobile-focus',
                        status: 'open',
                        locale: 'en',
                        subject: null,
                        lastMessageAt: '2026-08-20T10:00:00.000Z',
                        messages: [],
                        hasMore: false,
                        oldestCursor: null,
                    },
                }),
            } as Response);

            render(
                <ChatWidget
                    initialView="chat"
                    enabled={true}
                    locale="en"
                    surface="account"
                />,
            );

            const launcher = screen.getByRole('button', {
                name: /Open chat/i,
            });
            launcher.focus();
            fireEvent.click(launcher);

            const dialog = screen.getByRole('dialog', {
                name: /Arab UT Chat Assistant/i,
            });
            const close = within(dialog).getByRole('button', {
                name: /Close chat/i,
            });

            expect(dialog).toHaveAttribute('aria-modal', 'true');
            expect(close).toHaveFocus();

            const restart = within(dialog).getByRole('button', {
                name: /New conversation/i,
            });
            await waitFor(() => expect(restart).toBeEnabled());
            const composer = within(dialog).getByRole('textbox');

            const back = within(dialog).getByRole('button', {
                name: /^Back$/i,
            });

            composer.focus();
            fireEvent.keyDown(composer, { key: 'Tab' });
            expect(back).toHaveFocus();

            back.focus();
            fireEvent.keyDown(back, { key: 'Tab', shiftKey: true });
            expect(composer).toHaveFocus();

            fireEvent.keyDown(window, { key: 'Escape', code: 'Escape' });
            expect(launcher).toHaveFocus();
        },
    );

    it.each([768, 1440])(
        'keeps the %ipx account panel non-modal and launcher-focused',
        (width) => {
            setViewportWidth(width);
            render(
                <ChatWidget
                    initialView="chat"
                    enabled={true}
                    locale="en"
                    surface="account"
                />,
            );

            const launcher = screen.getByRole('button', {
                name: /Open chat/i,
            });
            launcher.focus();
            fireEvent.click(launcher);

            expect(screen.getByRole('dialog')).toHaveAttribute(
                'aria-modal',
                'false',
            );
            expect(launcher).toHaveFocus();
        },
    );

    it('supports legacy-only MediaQueryList registration, changes, and cleanup', () => {
        const listeners = new Set<(event: MediaQueryListEvent) => void>();
        const mediaState = { matches: false };
        const addListener = vi.fn(
            (listener: (event: MediaQueryListEvent) => void) => {
                listeners.add(listener);
            },
        );
        const removeListener = vi.fn(
            (listener: (event: MediaQueryListEvent) => void) => {
                listeners.delete(listener);
            },
        );
        const legacyAccountQuery = {
            get matches() {
                return mediaState.matches;
            },
            media: '(max-width: 47.99rem)',
            onchange: null,
            addListener,
            removeListener,
            dispatchEvent: vi.fn(() => true),
        } as unknown as MediaQueryList;
        const reducedMotionQuery = {
            matches: false,
            media: '(prefers-reduced-motion: reduce)',
        } as MediaQueryList;

        vi.stubGlobal(
            'matchMedia',
            vi.fn((query: string) =>
                query === '(max-width: 47.99rem)'
                    ? legacyAccountQuery
                    : reducedMotionQuery,
            ),
        );
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    publicId: 'conv-legacy-media-query',
                    status: 'open',
                    locale: 'en',
                    subject: null,
                    lastMessageAt: '2026-08-20T10:00:00.000Z',
                    messages: [],
                    hasMore: false,
                    oldestCursor: null,
                },
            }),
        } as Response);

        const { unmount } = render(
            <ChatWidget
                initialView="chat"
                enabled={true}
                locale="en"
                surface="account"
            />,
        );

        expect(addListener).toHaveBeenCalledTimes(1);
        const registeredListener = addListener.mock.calls[0]?.[0];
        expect(registeredListener).toBeTypeOf('function');

        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        expect(screen.getByRole('dialog')).toHaveAttribute(
            'aria-modal',
            'false',
        );

        mediaState.matches = true;
        act(() => {
            listeners.forEach((listener) =>
                listener({
                    matches: true,
                    media: legacyAccountQuery.media,
                } as MediaQueryListEvent),
            );
        });

        expect(screen.getByRole('dialog')).toHaveAttribute(
            'aria-modal',
            'true',
        );

        unmount();

        expect(removeListener).toHaveBeenCalledTimes(1);
        expect(removeListener).toHaveBeenCalledWith(registeredListener);
        expect(listeners.size).toBe(0);
    });

    it('renders one accessible 44px New conversation control', () => {
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        const restart = screen.getByRole('button', {
            name: /New conversation/i,
        });

        expect(restart).toHaveClass('h-11', 'w-11');
        expect(screen.getByRole('tooltip')).toHaveTextContent(
            'New conversation',
        );
    });

    it('gives the error dismissal control a 44px hit target', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: async () => ({ error: { code: 'chat_unavailable' } }),
        } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        expect(
            await screen.findByRole('button', { name: 'Dismiss' }),
        ).toHaveClass('min-h-11');
    });

    it('disables New conversation while a customer message is pending', async () => {
        const mockConversation = {
            publicId: 'conv-pending-send',
            status: 'open',
            locale: 'en',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };
        let resolveSend: ((response: Response) => void) | undefined;
        const pendingSend = new Promise<Response>((resolve) => {
            resolveSend = resolve;
        });

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockReturnValueOnce(pendingSend);

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: /New conversation/i }),
            ).toBeEnabled();
        });

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Pending message' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Send message/i }));

        expect(
            screen.getByRole('button', { name: /New conversation/i }),
        ).toBeDisabled();

        resolveSend?.({
            ok: true,
            status: 201,
            json: async () => ({
                data: {
                    message: {
                        publicId: 'sent-message',
                        conversationPublicId: 'conv-pending-send',
                        senderType: 'customer',
                        messageType: 'text',
                        content: 'Pending message',
                        createdAt: '2026-08-20T10:01:00.000Z',
                    },
                    demoReply: null,
                },
            }),
        } as Response);
    });

    it('disables New conversation while older messages are loading', async () => {
        const mockConversation = {
            publicId: 'conv-loading-older',
            status: 'open',
            locale: 'en',
            messages: [
                {
                    publicId: 'newest-message',
                    conversationPublicId: 'conv-loading-older',
                    senderType: 'assistant',
                    messageType: 'text',
                    content: 'Newest message',
                    createdAt: '2026-08-20T10:01:00.000Z',
                },
            ],
            hasMore: true,
            oldestCursor: 'newest-message',
        };
        let resolveOlder: ((response: Response) => void) | undefined;
        const pendingOlder = new Promise<Response>((resolve) => {
            resolveOlder = resolve;
        });

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockReturnValueOnce(pendingOlder);

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        await screen.findByText('Newest message');
        fireEvent.click(
            screen.getByRole('button', { name: /Load older messages/i }),
        );

        expect(
            screen.getByRole('button', { name: /New conversation/i }),
        ).toBeDisabled();

        resolveOlder?.({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    ...mockConversation,
                    messages: [],
                    hasMore: false,
                    oldestCursor: null,
                },
            }),
        } as Response);

        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: /New conversation/i }),
            ).toBeEnabled();
        });
    });

    it('restarts into the returned onboarding state and stays disabled during restart', async () => {
        const currentConversation = {
            publicId: 'conv-current',
            status: 'open',
            locale: 'en',
            messages: [
                {
                    publicId: 'current-message',
                    conversationPublicId: 'conv-current',
                    senderType: 'customer',
                    messageType: 'text',
                    content: 'Old conversation message',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: true,
            oldestCursor: 'current-message',
        };
        const restartedConversation = {
            publicId: 'conv-restarted',
            status: 'open',
            locale: 'en',
            messages: [
                {
                    publicId: 'new-onboarding',
                    conversationPublicId: 'conv-restarted',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'Welcome to your new conversation.',
                    createdAt: '2026-08-20T10:02:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };
        let resolveRestart: ((response: Response) => void) | undefined;
        const pendingRestart = new Promise<Response>((resolve) => {
            resolveRestart = resolve;
        });

        vi.mocked(fetch).mockImplementation((url, init) => {
            if (String(url) === '/chat/conversations/restart') {
                expect(init?.method).toBe('POST');
                expect(JSON.parse(String(init?.body))).toEqual({
                    locale: 'en',
                });

                return pendingRestart;
            }

            return Promise.resolve({
                ok: true,
                status: 200,
                json: async () => ({ data: currentConversation }),
            } as Response);
        });

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        expect(
            await screen.findByText('Old conversation message'),
        ).toBeInTheDocument();

        const restart = screen.getByRole('button', {
            name: /New conversation/i,
        });
        fireEvent.click(restart);

        expect(restart).toBeDisabled();
        expect(restart).toHaveAttribute('aria-busy', 'true');

        resolveRestart?.({
            ok: true,
            status: 200,
            json: async () => ({ data: restartedConversation }),
        } as Response);

        expect(
            await screen.findByText('Welcome to your new conversation.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Old conversation message'),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'New conversation started.',
        );
    });

    it('announces every restart failure while preserving the current conversation', async () => {
        const currentConversation = {
            publicId: 'conv-restart-failure',
            status: 'open',
            locale: 'en',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'preserved-message',
                    conversationPublicId: 'conv-restart-failure',
                    senderType: 'assistant',
                    messageType: 'text',
                    content: 'Keep this conversation visible.',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: currentConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: false,
                status: 500,
                json: async () => ({
                    error: { code: 'chat_unavailable' },
                }),
            } as Response)
            .mockResolvedValueOnce({
                ok: false,
                status: 500,
                json: async () => ({
                    error: { code: 'chat_unavailable' },
                }),
            } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        await screen.findByText('Keep this conversation visible.');
        fireEvent.click(
            screen.getByRole('button', { name: /New conversation/i }),
        );

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent(
            'Failed to confirm the new conversation. Your current chat is unchanged. Please try again.',
        );
        expect(
            screen.getByText('Keep this conversation visible.'),
        ).toBeInTheDocument();

        const restart = screen.getByRole('button', {
            name: /New conversation/i,
        });
        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.click(restart);

        await waitFor(() => {
            expect(screen.getByRole('alert')).not.toBe(alert);
        });
    });

    it('mutates the live region for consecutive identical restart success announcements', async () => {
        const conversation = (publicId: string) => ({
            publicId,
            status: 'open',
            locale: 'en',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        });

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: conversation('conv-initial') }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: conversation('conv-restart-1') }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: conversation('conv-restart-2') }),
            } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        const restart = screen.getByRole('button', {
            name: /New conversation/i,
        });
        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.click(restart);

        const status = screen.getByRole('status');
        await waitFor(() =>
            expect(status).toHaveTextContent('New conversation started.'),
        );
        const firstAnnouncement = status.firstElementChild;
        expect(firstAnnouncement).not.toBeNull();

        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.click(restart);

        await waitFor(() => {
            expect(status.firstElementChild).not.toBe(firstAnnouncement);
        });
        expect(status).toHaveTextContent('New conversation started.');
    });

    it('disables suggestion sends while a restart is pending', async () => {
        const mockConversation = {
            publicId: 'conv-suggestions',
            status: 'open',
            locale: 'en',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };
        let resolveRestart: ((response: Response) => void) | undefined;
        const pendingRestart = new Promise<Response>((resolve) => {
            resolveRestart = resolve;
        });

        vi.mocked(fetch).mockImplementation((url) => {
            if (String(url) === '/chat/conversations/restart') {
                return pendingRestart;
            }

            return Promise.resolve({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response);
        });

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        const restart = await screen.findByRole('button', {
            name: /New conversation/i,
        });
        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.click(restart);

        expect(screen.getByRole('textbox')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Prices' })).toBeDisabled();

        resolveRestart?.({
            ok: true,
            status: 200,
            json: async () => ({ data: mockConversation }),
        } as Response);
    });

    it('keeps the panel mounted only for the faster close transition', () => {
        vi.useFakeTimers();
        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));
        act(() => vi.runOnlyPendingTimers());

        fireEvent.keyDown(window, { key: 'Escape', code: 'Escape' });

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(180));

        expect(
            screen.queryByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).not.toBeInTheDocument();
    });

    it('renders launcher button and UI in English mode when locale is en', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'en',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'msg-sys-en-1',
                    conversationPublicId: '01JM0000000000000000000001',
                    senderType: 'system',
                    messageType: 'system',
                    content:
                        'Welcome to Arab UT! Ask anything about coins, services, or your order.',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ data: mockConversation }),
        } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);

        const launcherButton = screen.getByRole('button', {
            name: /Open chat/i,
        });
        expect(launcherButton).toBeInTheDocument();

        fireEvent.click(launcherButton);

        expect(
            screen.getByRole('dialog', { name: /Arab UT Chat Assistant/i }),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(
                screen.getByText(
                    /Welcome to Arab UT! Ask anything about coins/i,
                ),
            ).toBeInTheDocument();
        });

        // English suggestion chips
        expect(screen.getByText('Prices')).toBeInTheDocument();
        expect(screen.getByText('Services')).toBeInTheDocument();
        expect(screen.getByText('Track Order')).toBeInTheDocument();
        expect(screen.getByText('Support')).toBeInTheDocument();

        // English composer
        expect(
            screen.getByPlaceholderText(/Type a message/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Send message/i }),
        ).toBeInTheDocument();
    });

    it('opens chat panel on launcher click and lazily fetches active conversation in Arabic', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'msg-sys-1',
                    conversationPublicId: '01JM0000000000000000000001',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'هلا 👋 أنا مساعد عرب التيميت. اكتب رسالتك...',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ data: mockConversation }),
        } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        const launcherButton = screen.getByRole('button', {
            name: /فتح الشات/i,
        });
        fireEvent.click(launcherButton);

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(
                screen.getByText(/هلا 👋 أنا مساعد عرب التيميت/i),
            ).toBeInTheDocument();
        });

        expect(screen.getByText('الأسعار')).toBeInTheDocument();
        expect(screen.getByText('الخدمات')).toBeInTheDocument();
        expect(screen.getByText('متابعة الطلب')).toBeInTheDocument();
        expect(screen.getByText('الدعم')).toBeInTheDocument();
    });

    it('submits a customer message optimistically and calls POST message endpoint', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'msg-sys-1',
                    conversationPublicId: '01JM0000000000000000000001',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'هلا 👋 أنا مساعد عرب التيميت.',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                json: async () => ({
                    data: {
                        message: {
                            publicId: 'msg-cust-1',
                            conversationPublicId: '01JM0000000000000000000001',
                            senderType: 'customer',
                            messageType: 'text',
                            content: 'كم سعر 500 ألف كوينز؟',
                            createdAt: '2026-08-20T10:01:00.000Z',
                        },
                        demoReply: null,
                    },
                }),
            } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await waitFor(() => {
            expect(
                screen.getByText(/هلا 👋 أنا مساعد عرب التيميت/i),
            ).toBeInTheDocument();
        });

        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        fireEvent.change(textarea, {
            target: { value: 'كم سعر 500 ألف كوينز؟' },
        });

        const sendButton = screen.getByRole('button', {
            name: /إرسال الرسالة/i,
        });
        fireEvent.click(sendButton);

        expect(screen.getByText('كم سعر 500 ألف كوينز؟')).toBeInTheDocument();
        expect(screen.queryByText('الأسعار')).not.toBeInTheDocument();
    });

    it('clicking a suggestion chip sends that message immediately', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                json: async () => ({
                    data: {
                        message: {
                            publicId: 'msg-chip-1',
                            conversationPublicId: '01JM0000000000000000000001',
                            senderType: 'customer',
                            messageType: 'text',
                            content: 'الأسعار',
                            createdAt: '2026-08-20T10:01:00.000Z',
                        },
                        demoReply: null,
                    },
                }),
            } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await waitFor(() => {
            expect(screen.getByText('الأسعار')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('الأسعار'));

        expect(screen.getByText('الأسعار')).toBeInTheDocument();
    });

    it('closes on Escape key press and restores focus to launcher', async () => {
        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);

        const launcher = screen.getByRole('button', { name: /فتح الشات/i });
        fireEvent.click(launcher);

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();

        act(() => {
            fireEvent.keyDown(window, { key: 'Escape', code: 'Escape' });
        });

        expect(launcher).toHaveFocus();
    });

    it('reveals demo assistant reply after typing indicator delay when demoReply is returned', async () => {
        vi.useFakeTimers();

        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };

        const mockDemoReply = {
            publicId: 'msg-demo-1',
            conversationPublicId: '01JM0000000000000000000001',
            senderType: 'assistant' as const,
            messageType: 'text' as const,
            content: 'وصلتني رسالتك 👍 هذي نسخة تجريبية من الشات.',
            createdAt: '2026-08-20T10:02:00.000Z',
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                json: async () => ({
                    data: {
                        message: {
                            publicId: 'msg-cust-2',
                            conversationPublicId: '01JM0000000000000000000001',
                            senderType: 'customer',
                            messageType: 'text',
                            content: 'مرحبا',
                            createdAt: '2026-08-20T10:01:50.000Z',
                        },
                        demoReply: mockDemoReply,
                    },
                }),
            } as Response);

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await act(async () => {
            await Promise.resolve();
        });

        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        fireEvent.change(textarea, { target: { value: 'مرحبا' } });
        fireEvent.click(screen.getByRole('button', { name: /إرسال الرسالة/i }));

        await act(async () => {
            await Promise.resolve();
        });

        expect(screen.getByText('المساعد يكتب الآن...')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /محادثة جديدة/i }),
        ).toBeDisabled();

        act(() => {
            vi.advanceTimersByTime(1200);
        });

        expect(
            screen.getByText('وصلتني رسالتك 👍 هذي نسخة تجريبية من الشات.'),
        ).toBeInTheDocument();

        vi.useRealTimers();
    });

    it('renders streaming assistant bubble with data-stream-status and accessible sr-only text', async () => {
        vi.useFakeTimers();

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (
                path.includes('/chat/conversations') &&
                !path.includes('/messages') &&
                !path.includes('/agent-turns')
            ) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        data: {
                            publicId: 'conv-widget-stream-1',
                            status: 'open',
                            locale: 'ar',
                            assistantMode: 'agent',
                            messages: [],
                            hasMore: false,
                            oldestCursor: null,
                        },
                    }),
                } as Response;
            }

            if (path.includes('/messages')) {
                return {
                    ok: true,
                    status: 201,
                    json: async () => ({
                        data: {
                            message: {
                                publicId: 'msg-w-1',
                                conversationPublicId: 'conv-widget-stream-1',
                                clientMessageId: 'c-w-1',
                                senderType: 'customer',
                                messageType: 'text',
                                content: 'اختبار البث',
                                createdAt: new Date().toISOString(),
                            },
                            demoReply: null,
                        },
                    }),
                } as Response;
            }

            if (path.includes('/agent-turns')) {
                const stream = new ReadableStream<Uint8Array>({
                    start(controller) {
                        const turn: AgentTurnState = {
                            publicId: 'turn-w-stream',
                            status: 'running',
                            attemptCount: 1,
                            retryable: false,
                            hasPendingMessages: false,
                            errorCode: null,
                            message: null,
                        };
                        controller.enqueue(
                            new TextEncoder().encode(
                                `event: turn.created\ndata: ${JSON.stringify({ turn })}\n\n` +
                                    `event: response.delta\ndata: {"turnPublicId":"turn-w-stream","delta":"جاري التوليد"}\n\n`,
                            ),
                        );
                    },
                });

                return {
                    ok: true,
                    status: 200,
                    body: stream,
                } as unknown as Response;
            }

            return { ok: false, status: 404 } as Response;
        });

        render(<ChatWidget initialView="chat" enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await vi.advanceTimersByTimeAsync(10);
        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        const sendBtn = screen.getByRole('button', { name: /إرسال الرسالة/i });

        fireEvent.change(textarea, { target: { value: 'اختبار البث' } });
        fireEvent.click(sendBtn);

        // 1500ms quiet window triggers agent stream
        await vi.advanceTimersByTimeAsync(1550);

        // Streaming bubble is rendered
        const streamingBubble = document.querySelector(
            '[data-stream-status="streaming"]',
        );
        expect(streamingBubble).toBeInTheDocument();
        expect(streamingBubble).toHaveTextContent('جاري التوليد');
        expect(screen.getByText('المساعد يرد الآن')).toBeInTheDocument();
    });

    // Testing Library's findBy*/waitFor never advance Vitest fake timers, so
    // this test asserts synchronously after advanceTimersByTimeAsync.
    it('renders assistant retry button on retryable failed agent turn', async () => {
        vi.useFakeTimers();

        let retryCount = 0;

        vi.mocked(fetch).mockImplementation(async (url) => {
            const path = String(url);

            if (
                path.includes('/chat/conversations') &&
                !path.includes('/messages') &&
                !path.includes('/agent-turns')
            ) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        data: {
                            publicId: 'conv-widget-retry-1',
                            status: 'open',
                            locale: 'en',
                            assistantMode: 'agent',
                            messages: [],
                            hasMore: false,
                            oldestCursor: null,
                        },
                    }),
                } as Response;
            }

            if (path.includes('/messages')) {
                return {
                    ok: true,
                    status: 201,
                    json: async () => ({
                        data: {
                            message: {
                                publicId: 'msg-w-r',
                                conversationPublicId: 'conv-widget-retry-1',
                                clientMessageId: 'c-w-r',
                                senderType: 'customer',
                                messageType: 'text',
                                content: 'Retry me',
                                createdAt: new Date().toISOString(),
                            },
                            demoReply: null,
                        },
                    }),
                } as Response;
            }

            if (path.includes('/retry')) {
                retryCount++;
                const stream = new ReadableStream<Uint8Array>({
                    start(controller) {
                        const turn: AgentTurnState = {
                            publicId: 'turn-w-retry',
                            status: 'completed',
                            attemptCount: 2,
                            retryable: false,
                            hasPendingMessages: false,
                            errorCode: null,
                            message: null,
                        };
                        const message = {
                            publicId: 'msg-w-final',
                            conversationPublicId: 'conv-widget-retry-1',
                            senderType: 'assistant',
                            messageType: 'text',
                            content: 'Success after retry',
                            createdAt: new Date().toISOString(),
                        };
                        controller.enqueue(
                            new TextEncoder().encode(
                                `event: turn.created\ndata: ${JSON.stringify({ turn })}\n\n` +
                                    `event: response.completed\ndata: ${JSON.stringify({ turn, message })}\n\n`,
                            ),
                        );
                        controller.close();
                    },
                });

                return {
                    ok: true,
                    status: 200,
                    body: stream,
                } as unknown as Response;
            }

            if (path.includes('/agent-turns')) {
                // First turn fails with retryable = true
                const stream = new ReadableStream<Uint8Array>({
                    start(controller) {
                        const turn: AgentTurnState = {
                            publicId: 'turn-w-retry',
                            status: 'failed',
                            attemptCount: 1,
                            retryable: true,
                            hasPendingMessages: false,
                            errorCode: 'rate_limited',
                            message: null,
                        };
                        controller.enqueue(
                            new TextEncoder().encode(
                                `event: turn.created\ndata: ${JSON.stringify({ turn })}\n\n` +
                                    `event: response.failed\ndata: ${JSON.stringify({ turn, error: { code: 'rate_limited', message: 'Rate limit exceeded' } })}\n\n`,
                            ),
                        );
                        controller.close();
                    },
                });

                return {
                    ok: true,
                    status: 200,
                    body: stream,
                } as unknown as Response;
            }

            return { ok: false, status: 404 } as Response;
        });

        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        await vi.advanceTimersByTimeAsync(10);
        const textarea = screen.getByPlaceholderText(/Type a message/i);
        const sendBtn = screen.getByRole('button', { name: /Send message/i });

        fireEvent.change(textarea, { target: { value: 'Retry me' } });
        fireEvent.click(sendBtn);

        // Advance 1550ms -> stream starts and fails
        await vi.advanceTimersByTimeAsync(1550);

        // Verify Assistant retry button is displayed
        const retryBtn = screen.getByRole('button', { name: /Retry/i });
        expect(retryBtn).toBeInTheDocument();
        expect(
            screen.getByText('Assistant could not complete response'),
        ).toBeInTheDocument();

        // Click retry
        fireEvent.click(retryBtn);
        await vi.advanceTimersByTimeAsync(50);

        expect(retryCount).toBe(1);
        expect(screen.getByText('Success after retry')).toBeInTheDocument();
    });

    it('scopes light-surface chat tokens and motion to the dialog', () => {
        // The token block is the last bare `.chat-widget-dialog {` rule; an
        // earlier account-surface rule also ends with that selector.
        const tokenStart = appCss.lastIndexOf('\n.chat-widget-dialog {');
        const tokens = appCss.slice(
            tokenStart,
            appCss.indexOf('}', tokenStart),
        );

        expect(tokens).toContain('--chat-surface: #fbf8f2');
        expect(tokens).toContain('--chat-hero: var(--arabut-navy)');
        expect(tokens).toContain(
            '--chat-ease-out: cubic-bezier(0.16, 1, 0.3, 1)',
        );

        const motionStart = appCss.indexOf('.chat-view-enter {');
        const reducedMotionStart = appCss.lastIndexOf(
            '@media (prefers-reduced-motion: no-preference)',
            motionStart,
        );

        expect(motionStart).toBeGreaterThan(-1);
        expect(reducedMotionStart).toBeGreaterThan(-1);
    });

    describe('home view', () => {
        function mockConversationResponse(
            assistantMode: 'demo' | 'agent' = 'demo',
        ) {
            vi.mocked(fetch).mockResolvedValue({
                ok: true,
                status: 200,
                json: async () => ({
                    data: {
                        publicId: `conv-home-${assistantMode}`,
                        status: 'open',
                        locale: 'en',
                        subject: null,
                        lastMessageAt: null,
                        assistantMode,
                        messages: [],
                        hasMore: false,
                        oldestCursor: null,
                    },
                }),
            } as Response);
        }

        function mockEmptyConversation() {
            mockConversationResponse('demo');
        }

        /**
         * Home actions are disabled until the conversation finishes loading,
         * so a click fired during that window is silently dropped and the view
         * never changes. CI hit exactly that race. Wait for the control to be
         * enabled before clicking it.
         */
        async function clickWhenEnabled(name: string | RegExp) {
            const button = await screen.findByRole('button', { name });

            await waitFor(() => expect(button).toBeEnabled());
            fireEvent.click(button);

            return button;
        }

        it('lands on Home when opened and hides the composer', async () => {
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="ar" />);

            fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

            expect(
                await screen.findByRole('heading', { name: 'أهلًا بك' }),
            ).toBeInTheDocument();
            expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        });

        it('moves to chat on Start and back to Home on the back button', async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);

            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            await clickWhenEnabled('Start a conversation');

            expect(await screen.findByRole('textbox')).toBeInTheDocument();
            const dialog = screen.getByRole('dialog');
            expect(dialog).toHaveAttribute('data-view-direction', 'forward');

            fireEvent.click(screen.getByRole('button', { name: 'Back' }));
            expect(dialog).toHaveAttribute('data-view-direction', 'back');
            expect(
                await screen.findByRole('heading', { name: 'Hi there' }),
            ).toBeInTheDocument();

            await act(async () => {
                vi.advanceTimersByTime(300);
            });
            expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        });

        it('sends the topic and switches to chat when a topic is chosen', async () => {
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);

            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            await clickWhenEnabled('Prices');

            expect(await screen.findByRole('textbox')).toBeInTheDocument();
            expect(screen.getAllByText('Prices').length).toBeGreaterThan(0);
        });

        it('returns to Home after close and reopen', async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);

            const launcher = screen.getByRole('button', { name: /Open chat/i });
            fireEvent.click(launcher);
            await clickWhenEnabled('Start a conversation');
            await screen.findByRole('textbox');

            fireEvent.keyDown(window, { key: 'Escape' });
            await act(async () => {
                vi.advanceTimersByTime(300);
            });
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

            expect(
                await screen.findByRole('heading', { name: 'Hi there' }),
            ).toBeInTheDocument();
        });

        it('honours initialView="chat"', async () => {
            mockEmptyConversation();
            render(
                <ChatWidget initialView="chat" enabled={true} locale="en" />,
            );

            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

            expect(await screen.findByRole('textbox')).toBeInTheDocument();
            expect(
                screen.queryByRole('heading', { name: 'Hi there' }),
            ).not.toBeInTheDocument();
        });

        it('renders the chat header on the light card surface with a back control', async () => {
            mockEmptyConversation();
            render(
                <ChatWidget initialView="chat" enabled={true} locale="en" />,
            );
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            await screen.findByRole('textbox');

            const back = screen.getByRole('button', { name: 'Back' });
            expect(back).toHaveClass('h-11', 'w-11');
            expect(back.parentElement?.parentElement).toHaveClass(
                'bg-[var(--chat-card)]',
            );
        });

        it('shows the AI disclaimer only in agent mode', async () => {
            mockConversationResponse('agent');
            render(
                <ChatWidget initialView="chat" enabled={true} locale="en" />,
            );
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

            expect(
                await screen.findByText(/AI assistant — may make mistakes/),
            ).toBeInTheDocument();
        });

        it('hides the AI disclaimer in demo mode', async () => {
            mockEmptyConversation();
            render(
                <ChatWidget initialView="chat" enabled={true} locale="ar" />,
            );
            fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));
            await screen.findByRole('textbox');

            expect(screen.queryByText(/مساعد ذكي/)).not.toBeInTheDocument();
        });

        it('reveals the send button with a pop when text is typed', async () => {
            mockEmptyConversation();
            render(
                <ChatWidget initialView="chat" enabled={true} locale="en" />,
            );
            fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
            const textbox = await screen.findByRole('textbox');
            const send = screen.getByRole('button', { name: 'Send message' });

            expect(send).toHaveClass('scale-90', 'opacity-40');
            fireEvent.change(textbox, { target: { value: 'hello' } });
            expect(send).toHaveClass('scale-100', 'opacity-100');
        });

        it('pulses the launcher ring once when opened', async () => {
            mockEmptyConversation();
            render(<ChatWidget enabled={true} locale="en" />);
            const launcher = screen.getByRole('button', { name: /Open chat/i });

            expect(launcher).not.toHaveClass('chat-launcher-open');
            fireEvent.click(launcher);
            expect(
                screen.getByRole('button', {
                    name: /Close chat/i,
                    expanded: true,
                }),
            ).toHaveClass('chat-launcher-open');
        });
    });
});
