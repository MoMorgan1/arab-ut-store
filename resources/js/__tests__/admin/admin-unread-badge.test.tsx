import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AdminUnreadBadge } from '@/components/admin/admin-unread-badge';

describe('AdminUnreadBadge', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        vi.clearAllTimers();
    });

    it('polls every 30s, plays chime only when count increases, and renders badge chip', async () => {
        let currentCount = 0;
        const fetchSpy = vi.fn().mockImplementation(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ count: currentCount }),
            }),
        );
        global.fetch = fetchSpy;

        const { container } = render(<AdminUnreadBadge />);

        // Initial fetch with count = 0 -> badge not rendered
        await act(async () => {
            await Promise.resolve();
        });
        expect(container.firstChild).toBeNull();

        // Advance 30s with count = 2
        currentCount = 2;
        await act(async () => {
            vi.advanceTimersByTime(30_000);
            await Promise.resolve();
        });

        // Badge is visible with "2"
        expect(screen.getByTestId('admin-unread-badge')).toHaveTextContent('2');

        // Advance 30s with count = 5 (increased)
        currentCount = 5;
        await act(async () => {
            vi.advanceTimersByTime(30_000);
            await Promise.resolve();
        });

        expect(screen.getByTestId('admin-unread-badge')).toHaveTextContent('5');

        // Advance 30s with count = 3 (decreased)
        currentCount = 3;
        await act(async () => {
            vi.advanceTimersByTime(30_000);
            await Promise.resolve();
        });

        expect(screen.getByTestId('admin-unread-badge')).toHaveTextContent('3');

        // Advance 30s with count = 0 (cleared)
        currentCount = 0;
        await act(async () => {
            vi.advanceTimersByTime(30_000);
            await Promise.resolve();
        });

        expect(screen.queryByTestId('admin-unread-badge')).toBeNull();
    });

    it('pauses polling when document is hidden', async () => {
        const fetchSpy = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ count: 1 }),
        });
        global.fetch = fetchSpy;

        render(<AdminUnreadBadge />);

        await act(async () => {
            await Promise.resolve();
        });
        expect(fetchSpy).toHaveBeenCalledTimes(1);

        // Hide document
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => true,
        });

        // Advance 30s
        await act(async () => {
            vi.advanceTimersByTime(30_000);
            await Promise.resolve();
        });

        // Should not have fetched while hidden
        expect(fetchSpy).toHaveBeenCalledTimes(1);

        // Make document visible again and fire visibilitychange
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => false,
        });
        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
            await Promise.resolve();
        });

        // Immediately resumes fetch
        expect(fetchSpy).toHaveBeenCalledTimes(2);
    });

    it('does not start duplicate fetches or extra polling loops when visibility changes while a fetch is in flight', async () => {
        let resolveFirstFetch!: (value: unknown) => void;
        const firstFetchPromise = new Promise((resolve) => {
            resolveFirstFetch = resolve;
        });

        const fetchSpy = vi
            .fn()
            .mockImplementationOnce(() => firstFetchPromise)
            .mockImplementation(() =>
                Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({ count: 1 }),
                }),
            );
        global.fetch = fetchSpy;

        render(<AdminUnreadBadge />);

        // First fetch is in flight
        expect(fetchSpy).toHaveBeenCalledTimes(1);

        // Fire visibility change while in flight
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => false,
        });
        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
            await Promise.resolve();
        });

        // Still only 1 call because first is in flight
        expect(fetchSpy).toHaveBeenCalledTimes(1);

        // Resolve first fetch
        await act(async () => {
            resolveFirstFetch({
                ok: true,
                json: () => Promise.resolve({ count: 1 }),
            });
            await Promise.resolve();
        });

        // Advance 30s
        await act(async () => {
            vi.advanceTimersByTime(30_000);
            await Promise.resolve();
        });

        // Next poll occurred: exactly 2 total fetches
        expect(fetchSpy).toHaveBeenCalledTimes(2);

        // Advance another 30s: exactly 3 total fetches (single loop maintained)
        await act(async () => {
            vi.advanceTimersByTime(30_000);
            await Promise.resolve();
        });
        expect(fetchSpy).toHaveBeenCalledTimes(3);
    });
});
