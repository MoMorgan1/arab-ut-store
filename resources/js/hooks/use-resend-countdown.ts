import { useCallback, useEffect, useState } from 'react';

export type UseResendCountdownReturn = {
    countdown: number;
    isActive: boolean;
    start: (customSeconds?: number) => void;
    reset: () => void;
};

export function useResendCountdown(
    defaultSeconds = 60,
): UseResendCountdownReturn {
    const [resendAt, setResendAt] = useState<number | null>(null);
    const [countdown, setCountdown] = useState(0);

    const start = useCallback(
        (customSeconds?: number) => {
            const duration = customSeconds ?? defaultSeconds;
            setResendAt(Date.now() + duration * 1000);
            setCountdown(duration);
        },
        [defaultSeconds],
    );

    const reset = useCallback(() => {
        setResendAt(null);
        setCountdown(0);
    }, []);

    useEffect(() => {
        if (!resendAt) {
            return;
        }

        const tick = () => {
            const remaining = Math.max(
                0,
                Math.ceil((resendAt - Date.now()) / 1000),
            );
            setCountdown(remaining);

            if (remaining === 0) {
                setResendAt(null);
            }
        };

        tick();
        const timer = setInterval(tick, 1000);

        return () => {
            clearInterval(timer);
        };
    }, [resendAt]);

    return {
        countdown,
        isActive: countdown > 0,
        start,
        reset,
    };
}
