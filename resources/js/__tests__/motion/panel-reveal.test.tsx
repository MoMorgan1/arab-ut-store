import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { PanelReveal } from '@/components/motion/panel-reveal';

afterEach(() => {
    cleanup();
});

describe('PanelReveal', () => {
    it('keeps the class, id and role on the block inside the animating wrapper', () => {
        render(
            <PanelReveal
                className="store-cart-confirm"
                id="test-panel"
                role="alert"
            >
                <span>Alert content</span>
            </PanelReveal>,
        );

        const block = screen.getByRole('alert');
        expect(block).toHaveClass('store-cart-confirm');
        expect(block).toHaveAttribute('id', 'test-panel');
        expect(block).not.toHaveClass('t-panel');

        const wrapper = block.parentElement;
        expect(wrapper).toHaveClass('t-panel');
        expect(wrapper).toHaveAttribute('data-state', 'in');
    });

    it('renders the children as direct children of the block', () => {
        const { container } = render(
            <PanelReveal className="row">
                <span data-testid="a" />
                <span data-testid="b" />
            </PanelReveal>,
        );

        const block = container.querySelector('.t-panel > .row');
        expect(block).not.toBeNull();
        expect(block?.children).toHaveLength(2);
        expect(screen.getByTestId('a').parentElement).toBe(block);
    });

    it('as="section" and as="p" render that tag inside the wrapper', () => {
        const { container } = render(
            <>
                <PanelReveal as="section" className="s">
                    Section
                </PanelReveal>
                <PanelReveal as="p" className="p">
                    Paragraph
                </PanelReveal>
            </>,
        );

        expect(
            container.querySelector('div.t-panel > section.s'),
        ).not.toBeNull();
        expect(container.querySelector('div.t-panel > p.p')).not.toBeNull();
    });
});
