import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Disclosure } from '@/components/motion/disclosure';

describe('Disclosure', () => {
    afterEach(cleanup);

    it('renders nothing until the first open, then stays mounted and folds shut', () => {
        const { container, rerender } = render(
            <Disclosure as="ul" className="lines" open={false}>
                <li>one</li>
            </Disclosure>,
        );

        expect(container.querySelector('.t-disclosure')).toBeNull();

        rerender(
            <Disclosure as="ul" className="lines" open>
                <li>one</li>
            </Disclosure>,
        );

        const fold = container.querySelector('.t-disclosure');
        expect(fold).toHaveAttribute('data-state', 'open');
        expect(fold).not.toHaveAttribute('inert');
        // The block keeps its own tag and class inside the new wrapper.
        expect(fold?.firstElementChild?.tagName).toBe('UL');
        expect(fold?.firstElementChild).toHaveClass('lines');

        rerender(
            <Disclosure as="ul" className="lines" open={false}>
                <li>one</li>
            </Disclosure>,
        );

        expect(container.querySelector('.t-disclosure')).toHaveAttribute(
            'data-state',
            'closed',
        );
        expect(container.querySelector('.t-disclosure')).toHaveAttribute(
            'inert',
        );
        expect(container.textContent).toBe('one');
    });

    it('defers function children until the first open', () => {
        const build = vi.fn(() => <span>panel</span>);
        const { container, rerender } = render(
            <Disclosure anchored className="panel" open={false} role="dialog">
                {build}
            </Disclosure>,
        );

        expect(build).not.toHaveBeenCalled();

        rerender(
            <Disclosure anchored className="panel" open role="dialog">
                {build}
            </Disclosure>,
        );

        expect(build).toHaveBeenCalled();
        // An anchored popover carries the state itself: no grid wrapper.
        const panel = container.querySelector('.panel');
        expect(panel).toHaveClass('t-popover');
        expect(panel).toHaveAttribute('data-state', 'open');
        expect(panel).toHaveAttribute('role', 'dialog');
        expect(container.querySelector('.t-disclosure')).toBeNull();
    });
});
