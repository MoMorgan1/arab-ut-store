import { useState } from 'react';
import type { HTMLAttributes, JSX, ReactNode } from 'react';

/*
 * Disclosure — a block that a control opens and closes in place. Nothing is
 * rendered until the first open, so a block nobody reaches costs nothing;
 * from then on it stays mounted, so it can fold shut as smoothly as it grew.
 * While closed it is invisible and inert, so readers and the tab order never
 * reach it. The wrapper is the only new element: the block keeps its own
 * tag, class and attributes, and the CSS that lays out its children keeps
 * matching.
 *
 * `anchored` is for a popover that positions itself absolutely: a grid row
 * cannot size such a block, so it fades and settles instead of growing.
 */
export function Disclosure({
    open,
    anchored = false,
    as = 'div',
    className,
    children,
    ...rest
}: {
    open: boolean;
    anchored?: boolean;
    as?: 'div' | 'ul' | 'section';
    className?: string;
    /** A function defers the block's markup until the first open. */
    children: ReactNode | (() => ReactNode);
} & Omit<HTMLAttributes<HTMLElement>, 'children'>): JSX.Element | null {
    const [everOpened, setEverOpened] = useState(open);

    if (open && !everOpened) {
        setEverOpened(true);
    }

    if (!open && !everOpened) {
        return null;
    }

    const Component = as;
    const state = open ? 'open' : 'closed';
    const content = typeof children === 'function' ? children() : children;

    if (anchored) {
        return (
            <Component
                className={[className, 't-popover'].filter(Boolean).join(' ')}
                data-state={state}
                inert={!open}
                {...rest}
            >
                {content}
            </Component>
        );
    }

    return (
        <div className="t-disclosure" data-state={state} inert={!open}>
            <Component className={className} {...rest}>
                {content}
            </Component>
        </div>
    );
}
