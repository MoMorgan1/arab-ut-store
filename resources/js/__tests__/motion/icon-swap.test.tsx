import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { IconSwap } from '@/components/motion/icon-swap';

afterEach(() => {
    cleanup();
});

describe('IconSwap', () => {
    it('renders both icons', () => {
        render(
            <IconSwap
                state="a"
                a={<span data-testid="icon-a">Icon A</span>}
                b={<span data-testid="icon-b">Icon B</span>}
            />,
        );

        expect(screen.getByTestId('icon-a')).toBeInTheDocument();
        expect(screen.getByTestId('icon-b')).toBeInTheDocument();
    });

    it('data-state follows the prop', () => {
        const { container, rerender } = render(
            <IconSwap
                state="a"
                a={<span data-testid="icon-a">Icon A</span>}
                b={<span data-testid="icon-b">Icon B</span>}
            />,
        );

        const slot = container.querySelector('.t-icon-swap');
        expect(slot).toHaveAttribute('data-state', 'a');

        rerender(
            <IconSwap
                state="b"
                a={<span data-testid="icon-a">Icon A</span>}
                b={<span data-testid="icon-b">Icon B</span>}
            />,
        );

        expect(slot).toHaveAttribute('data-state', 'b');
    });

    it('only the inactive icon is aria-hidden', () => {
        const { container, rerender } = render(
            <IconSwap
                state="a"
                a={<span data-testid="icon-a">Icon A</span>}
                b={<span data-testid="icon-b">Icon B</span>}
            />,
        );

        const iconA = container.querySelector('[data-icon="a"]');
        const iconB = container.querySelector('[data-icon="b"]');

        expect(iconA).not.toHaveAttribute('aria-hidden');
        expect(iconB).toHaveAttribute('aria-hidden', 'true');

        rerender(
            <IconSwap
                state="b"
                a={<span data-testid="icon-a">Icon A</span>}
                b={<span data-testid="icon-b">Icon B</span>}
            />,
        );

        expect(iconA).toHaveAttribute('aria-hidden', 'true');
        expect(iconB).not.toHaveAttribute('aria-hidden');
    });
});
