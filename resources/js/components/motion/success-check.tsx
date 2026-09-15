import type { JSX } from 'react';

import { cn } from '@/lib/utils';

export function SuccessCheck({
    variant = 'check',
    className,
    label,
}: {
    variant?: 'check' | 'circle';
    className?: string;
    label?: string;
}): JSX.Element {
    const isLabeled = Boolean(label);

    return (
        <span
            aria-hidden={isLabeled ? undefined : 'true'}
            aria-label={label}
            className={cn('t-success-check', className)}
            data-state="in"
            role={isLabeled ? 'img' : undefined}
        >
            <svg
                aria-hidden="true"
                fill="none"
                height="1em"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2.5"
                viewBox="0 0 24 24"
                width="1em"
            >
                {variant === 'circle' ? (
                    <>
                        <circle cx="12" cy="12" pathLength="1" r="10" />
                        <path d="m9 12 2 2 4-4" pathLength="1" />
                    </>
                ) : (
                    <path d="M5 13l4 4L19 7" pathLength="1" />
                )}
            </svg>
        </span>
    );
}
