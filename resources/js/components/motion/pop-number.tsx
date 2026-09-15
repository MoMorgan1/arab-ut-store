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

    // Each digit is its own token; the characters between digits stay
    // together as one run. A run is what a font shapes: the riyal label
    // «ر.س» only becomes the riyal sign when its letters share a span.
    const tokens = tokenise(value);
    const prevTokens = tokenise(prevValue);
    const isLengthChanged = tokens.length !== prevTokens.length;

    // The last two digit tokens are the fraction; they ride the stagger.
    const digitIndices: number[] = [];

    for (let i = 0; i < tokens.length; i++) {
        if (/^\d$/.test(tokens[i])) {
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
            {tokens.map((token, i) => {
                const isChanged =
                    !isFirstRender &&
                    (isLengthChanged || token !== prevTokens[i]);

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
                        {token}
                    </span>
                );
            })}
        </Component>
    );
}

function tokenise(value: string): string[] {
    const tokens: string[] = [];

    for (const char of value) {
        const last = tokens.length - 1;

        if (/\d/.test(char) || last < 0 || /\d/.test(tokens[last])) {
            tokens.push(char);
        } else {
            tokens[last] += char;
        }
    }

    return tokens;
}
