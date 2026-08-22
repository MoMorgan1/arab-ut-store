import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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
        expect(dialog.style.getPropertyValue('--chat-vv-height')).toBe('844px');

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
        expect(dialog.style.getPropertyValue('--chat-vv-top')).toBe('0px');
        vi.advanceTimersByTime(850);
        expect(dialog.style.getPropertyValue('--chat-vv-top')).toBe('120px');
        expect(dialog.style.getPropertyValue('--chat-vv-height')).toBe('430px');
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
});
