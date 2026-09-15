import type { JSX, ReactNode } from 'react';

import { cn } from '@/lib/utils';

export function IconSwap({
    a,
    b,
    className,
    label,
    state,
}: {
    state: 'a' | 'b';
    a: ReactNode;
    b: ReactNode;
    className?: string;
    label?: string;
}): JSX.Element {
    return (
        <span
            aria-label={label}
            className={cn('t-icon-swap', className)}
            data-state={state}
        >
            <span
                aria-hidden={state !== 'a' ? 'true' : undefined}
                className="t-icon"
                data-icon="a"
            >
                {a}
            </span>
            <span
                aria-hidden={state !== 'b' ? 'true' : undefined}
                className="t-icon"
                data-icon="b"
            >
                {b}
            </span>
        </span>
    );
}
