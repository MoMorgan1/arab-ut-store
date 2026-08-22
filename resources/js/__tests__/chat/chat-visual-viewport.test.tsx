import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatWidget } from '@/components/chat/chat-widget';

type Listener = () => void;

function installFakeVisualViewport(offsetTop: number, height: number) {
    const listeners: Record<string, Listener[]> = { resize: [], scroll: [] };
    const viewport = {
        offsetTop,
        height,
        addEventListener: (type: string, listener: Listener) => {
            listeners[type]?.push(listener);
        },
        removeEventListener: (type: string, listener: Listener) => {
            listeners[type] = (listeners[type] ?? []).filter(
                (l) => l !== listener,
            );
        },
        emit(type: string) {
            for (const listener of listeners[type] ?? []) {
                listener();
            }
        },
        listenerCount: () => listeners.resize.length + listeners.scroll.length,
    };
    Object.defineProperty(window, 'visualViewport', {
        configurable: true,
        value: viewport,
    });

    return viewport;
}

describe('mobile sheet follows the visual viewport', () => {
    beforeEach(() => {
        vi.stubGlobal('scrollTo', vi.fn());
        Object.defineProperty(window, 'innerHeight', {
            configurable: true,
            value: 844,
            writable: true,
        });
        Object.defineProperty(window, 'innerWidth', {
            configurable: true,
            value: 390,
            writable: true,
        });
        vi.stubGlobal('fetch', vi.fn());
        vi.mocked(fetch).mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    publicId: 'conv-vv',
                    status: 'open',
                    locale: 'en',
                    messages: [],
                    hasMore: false,
                    oldestCursor: null,
                },
            }),
        } as Response);
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        Object.defineProperty(window, 'visualViewport', {
            configurable: true,
            value: undefined,
        });
    });

    it('pins the open sheet to the visual viewport and releases it on close', async () => {
        const viewport = installFakeVisualViewport(0, 844);
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);

        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        const dialog = await screen.findByRole('dialog');

        expect(dialog).toHaveClass('chat-widget-dialog--viewport-tracked');
        expect(dialog).toHaveClass('chat-widget-dialog--sheet');
        // 88% of the viewport, sitting on the bottom edge.
        expect(dialog.style.getPropertyValue('--chat-vv-height')).toBe('743px');
        expect(dialog.style.getPropertyValue('--chat-vv-top')).toBe('101px');

        // Keyboard opens: the visual viewport shrinks and scrolls.
        viewport.height = 430;
        viewport.offsetTop = 120;
        viewport.emit('resize');

        expect(dialog.style.getPropertyValue('--chat-vv-top')).toBe('120px');
        expect(dialog.style.getPropertyValue('--chat-vv-height')).toBe('430px');

        fireEvent.keyDown(window, { key: 'Escape' });
        expect(viewport.listenerCount()).toBe(0);
    });

    it('locks page scroll while the sheet is open and re-syncs after focus', async () => {
        const viewport = installFakeVisualViewport(0, 844);
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);

        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        const dialog = await screen.findByRole('dialog');

        expect(document.documentElement).toHaveClass('chat-scroll-lock');

        // iOS settles the keyboard without firing a viewport event.
        vi.useFakeTimers();
        viewport.height = 430;
        viewport.offsetTop = 120;
        fireEvent.focusIn(dialog);
        expect(dialog.style.getPropertyValue('--chat-vv-top')).toBe('101px');
        vi.advanceTimersByTime(850);
        expect(dialog.style.getPropertyValue('--chat-vv-top')).toBe('120px');
        expect(dialog.style.getPropertyValue('--chat-vv-height')).toBe('430px');

        // Keyboard closes but iOS leaves the viewport scrolled by 120px.
        viewport.height = 844;
        fireEvent.focusOut(dialog);
        vi.advanceTimersByTime(850);
        expect(scrollTo).toHaveBeenCalledWith(0, 0);
        expect(dialog.style.getPropertyValue('--chat-vv-top')).toBe('101px');
        expect(dialog.style.getPropertyValue('--chat-vv-height')).toBe('743px');
        vi.useRealTimers();

        fireEvent.keyDown(window, { key: 'Escape' });
        expect(document.documentElement).not.toHaveClass('chat-scroll-lock');
        expect(scrollTo).toHaveBeenCalledWith(0, 0);
    });

    it('does not track on the desktop panel', async () => {
        Object.defineProperty(window, 'innerWidth', {
            configurable: true,
            value: 1280,
            writable: true,
        });
        const viewport = installFakeVisualViewport(0, 900);
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);

        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        const dialog = await screen.findByRole('dialog');

        expect(dialog).not.toHaveClass('chat-widget-dialog--viewport-tracked');
        expect(viewport.listenerCount()).toBe(0);
    });

    it('closes on backdrop tap and on a swipe down', async () => {
        installFakeVisualViewport(0, 844);
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);

        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        const dialog = await screen.findByRole('dialog');

        fireEvent.click(screen.getByTestId('chat-widget-backdrop'));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(dialog).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        const reopened = await screen.findByRole('dialog');
        await waitFor(() =>
            expect(reopened).toHaveClass('pointer-events-auto'),
        );

        const touch = (clientY: number) => [{ clientX: 10, clientY }];
        fireEvent.touchStart(reopened, { touches: touch(100) });
        fireEvent.touchMove(reopened, { touches: touch(140) });
        expect(reopened.style.transform).toBe('translateY(40px)');
        fireEvent.touchMove(reopened, { touches: touch(260) });
        fireEvent.touchEnd(reopened, { changedTouches: touch(260) });

        // The sheet keeps sliding down instead of snapping back to the top.
        expect(reopened.style.transform).toBe('translateY(100%)');
        expect(reopened).toHaveClass('chat-widget-dialog--dismissing');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('does not start a swipe from a scrolled message list', async () => {
        installFakeVisualViewport(0, 844);
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);

        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        const dialog = await screen.findByRole('dialog');
        await waitFor(() => expect(dialog).toHaveClass('pointer-events-auto'));
        const list = dialog.querySelector('.overflow-y-auto') as HTMLElement;
        Object.defineProperty(list, 'scrollTop', {
            value: 80,
            configurable: true,
        });

        const touch = (clientY: number) => [{ clientX: 10, clientY }];
        fireEvent.touchStart(list, { touches: touch(100) });
        fireEvent.touchMove(list, { touches: touch(300) });
        fireEvent.touchEnd(list, { changedTouches: touch(300) });

        expect(dialog.style.transform).toBe('');
        expect(dialog).toHaveClass('pointer-events-auto');
    });
});
