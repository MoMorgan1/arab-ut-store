import type { JSX } from 'react';
import { useState } from 'react';

import { cn } from '@/lib/utils';

export function PopNumber({
    value,
    as = 'span',
    className,
}: {
    value: string;
    as?: 'span' | 'strong';
    className?: string;
}): JSX.Element {
    const [state, setState] = useState({
        current: value,
        prev: value,
        runId: 0,
    });

    if (value !== state.current) {
        setState({
            current: value,
            prev: state.current,
            runId: state.runId + 1,
        });
    }

    const isFirstRender = state.runId === 0 && state.current === value;
    const prevValue = state.current === value ? state.prev : state.current;
    const runId = state.current === value ? state.runId : state.runId + 1;

    const chars = Array.from(value);
    const prevChars = Array.from(prevValue);
    const isLengthChanged = chars.length !== prevChars.length;

    // Identify indices of the last two digit characters (the fraction digits)
    const digitIndices: number[] = [];

    for (let i = 0; i < chars.length; i++) {
        if (/\d/.test(chars[i])) {
            digitIndices.push(i);
        }
    }

    const frac1Idx =
        digitIndices.length >= 2 ? digitIndices[digitIndices.length - 2] : -1;
    const frac2Idx =
        digitIndices.length >= 1 ? digitIndices[digitIndices.length - 1] : -1;

    const Component = as;

    return (
        <Component className={cn('t-digits', className)}>
            {/* One reading for assistive tech; the characters below are
                decorative so a screen reader never gets a digit soup. */}
            <span className="sr-only">{value}</span>
            {chars.map((char, i) => {
                const isChanged =
                    !isFirstRender &&
                    (isLengthChanged || char !== prevChars[i]);

                let staggerN: 1 | 2 | undefined;

                if (isChanged) {
                    if (i === frac1Idx) {
                        staggerN = 1;
                    } else if (i === frac2Idx) {
                        staggerN = 2;
                    }
                }

                const key = isChanged ? `${i}-${runId}` : `${i}`;

                return (
                    <span
                        aria-hidden="true"
                        className="t-digit"
                        data-changed={isChanged ? 'true' : undefined}
                        key={key}
                        style={
                            staggerN !== undefined
                                ? ({
                                      '--digit-stagger-n': staggerN,
                                  } as React.CSSProperties)
                                : undefined
                        }
                    >
                        {char}
                    </span>
                );
            })}
        </Component>
    );
}
