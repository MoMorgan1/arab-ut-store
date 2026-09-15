import type { HTMLAttributes, JSX, ReactNode } from 'react';

/*
 * Panel reveal — wraps a conditional block in a grid row that grows from
 * 0fr on mount while the block itself rises and sharpens. The block keeps
 * its own tag, class and attributes, so the CSS that lays out its children
 * (a flex row, a grid with a gap) keeps matching; only the outer wrapper
 * is new. Mounting is the trigger; the resting state is fully open.
 */
export function PanelReveal({
    as = 'div',
    className,
    children,
    ...rest
}: {
    as?: 'div' | 'section' | 'p';
    className?: string;
    children: ReactNode;
} & HTMLAttributes<HTMLElement>): JSX.Element {
    const Component = as;

    return (
        <div className="t-panel" data-state="in">
            <Component className={className} {...rest}>
                {children}
            </Component>
        </div>
    );
}
