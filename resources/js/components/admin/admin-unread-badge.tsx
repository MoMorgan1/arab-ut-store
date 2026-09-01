import React, { useEffect, useRef, useState } from 'react';

export function playAdminChime(): void {
    try {
        if (typeof window === 'undefined') {
            return;
        }

        const AudioCtx =
            window.AudioContext ||
            (
                window as unknown as {
                    webkitAudioContext: typeof AudioContext;
                }
            ).webkitAudioContext;

        if (!AudioCtx) {
            return;
        }

        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
        osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1); // A5

        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start();
        osc.stop(ctx.currentTime + 0.4);
    } catch {
        // Audio playback failure safely ignored
    }
}

export const AdminUnreadBadge: React.FC = () => {
    const [count, setCount] = useState<number>(0);
    const previousCountRef = useRef<number | null>(null);
    const isMountedRef = useRef<boolean>(true);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const inFlightRef = useRef<boolean>(false);

    const fetchCount = async () => {
        if (!isMountedRef.current || inFlightRef.current) {
            return;
        }

        if (typeof document !== 'undefined' && document.hidden) {
            return;
        }

        inFlightRef.current = true;

        try {
            const response = await fetch('/admin/support/unread-count', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok || !isMountedRef.current) {
                return;
            }

            const data = (await response.json()) as { count: number };
            const newCount = typeof data.count === 'number' ? data.count : 0;

            if (
                previousCountRef.current !== null &&
                newCount > previousCountRef.current
            ) {
                playAdminChime();
            }

            previousCountRef.current = newCount;
            setCount(newCount);
        } catch {
            // Ignore transient network errors
        } finally {
            inFlightRef.current = false;

            if (isMountedRef.current) {
                timerRef.current = setTimeout(fetchCount, 30_000);
            }
        }
    };

    useEffect(() => {
        isMountedRef.current = true;
        void fetchCount();

        const handleVisibilityChange = () => {
            if (typeof document !== 'undefined' && !document.hidden) {
                if (timerRef.current !== null) {
                    clearTimeout(timerRef.current);
                    timerRef.current = null;
                }

                void fetchCount();
            }
        };

        if (typeof document !== 'undefined') {
            document.addEventListener(
                'visibilitychange',
                handleVisibilityChange,
            );
        }

        return () => {
            isMountedRef.current = false;

            if (timerRef.current !== null) {
                clearTimeout(timerRef.current);
                timerRef.current = null;
            }

            if (typeof document !== 'undefined') {
                document.removeEventListener(
                    'visibilitychange',
                    handleVisibilityChange,
                );
            }
        };
    }, []);

    if (count <= 0) {
        return null;
    }

    return (
        <span
            data-testid="admin-unread-badge"
            className="ms-auto flex h-5 min-w-[20px] items-center justify-center rounded-full bg-[#d4a843] px-1.5 text-[11px] font-bold text-white shadow-xs"
        >
            {count > 99 ? '99+' : count}
        </span>
    );
};
