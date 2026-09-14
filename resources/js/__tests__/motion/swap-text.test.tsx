import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { SwapText } from '@/components/motion/swap-text';

afterEach(() => {
    cleanup();
});

describe('SwapText', () => {
    it('renders the value', () => {
        render(<SwapText value="Add to cart" />);
        expect(screen.getByText('Add to cart')).toBeInTheDocument();
        expect(screen.getByText('Add to cart')).toHaveClass('t-text-swap');
    });

    it('reflects a changed prop in the DOM (jsdom path)', () => {
        const { rerender } = render(<SwapText value="Add to cart" />);
        expect(screen.getByText('Add to cart')).toBeInTheDocument();

        rerender(<SwapText value="Adding…" />);
        expect(screen.getByText('Adding…')).toBeInTheDocument();
    });

    it('does not throw when animate is absent', () => {
        expect(() => {
            const { rerender } = render(<SwapText value="Idle" />);
            rerender(<SwapText value="Busy" />);
        }).not.toThrow();
    });
});
