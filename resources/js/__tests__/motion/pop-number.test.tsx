import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { PopNumber } from '@/components/motion/pop-number';

afterEach(() => {
    cleanup();
});

describe('PopNumber', () => {
    it('renders one span per digit, keeps the runs between digits whole, and reads the full value once', () => {
        const value = '1,250.00 SAR';
        const { container } = render(<PopNumber value={value} />);
        const wrapper = container.querySelector('.t-digits');

        expect(wrapper).not.toBeNull();
        // One full reading for assistive tech, the characters are decorative.
        expect(wrapper?.querySelector('.sr-only')).toHaveTextContent(value);

        const digitSpans = container.querySelectorAll('.t-digit');
        const expected = ['1', ',', '2', '5', '0', '.', '0', '0', ' SAR'];

        expect(digitSpans).toHaveLength(expected.length);
        digitSpans.forEach((span, i) => {
            expect(span.textContent).toBe(expected[i]);
            expect(span).toHaveAttribute('aria-hidden', 'true');
        });
    });

    it('keeps the Arabic riyal letters in one span so the font can ligate them', () => {
        const { container } = render(
            <PopNumber value={'\u2066ر.س\u00a012.50\u2069'} />,
        );
        const spans = [...container.querySelectorAll('.t-digit')].map(
            (span) => span.textContent,
        );

        expect(spans).toEqual([
            '\u2066ر.س\u00a0',
            '1',
            '2',
            '.',
            '5',
            '0',
            '\u2069',
        ]);
    });

    it('nothing is data-changed on first render', () => {
        const value = '1,250.00 SAR';
        const { container } = render(<PopNumber value={value} />);
        const changedSpans = container.querySelectorAll(
            '[data-changed="true"]',
        );

        expect(changedSpans).toHaveLength(0);
    });

    it('after a re-render with 1,250.00 SAR → 1,125.00 SAR exactly the characters at the changed positions carry data-changed="true"', () => {
        const { container, rerender } = render(
            <PopNumber value="1,250.00 SAR" />,
        );

        expect(
            container.querySelectorAll('[data-changed="true"]'),
        ).toHaveLength(0);

        rerender(<PopNumber value="1,125.00 SAR" />);

        const digitSpans = container.querySelectorAll('.t-digit');

        // 1 , 1 2 5 . 0 0 " SAR" — nine tokens
        expect(digitSpans).toHaveLength(9);

        const changedIndices = [2, 3, 4];

        digitSpans.forEach((span, i) => {
            if (changedIndices.includes(i)) {
                expect(span).toHaveAttribute('data-changed', 'true');
            } else {
                expect(span).not.toHaveAttribute('data-changed');
            }
        });
    });

    it('a length change marks every character', () => {
        const { container, rerender } = render(
            <PopNumber value="1,250.00 SAR" />,
        );

        rerender(<PopNumber value="950.00 SAR" />);

        const digitSpans = container.querySelectorAll('.t-digit');

        // 9 5 0 . 0 0 " SAR" — seven tokens
        expect(digitSpans).toHaveLength(7);
        digitSpans.forEach((span) => {
            expect(span).toHaveAttribute('data-changed', 'true');
        });

        // The two fraction digits ride stagger 1 and 2
        // In '950.00 SAR', the last two digits are indices 4 ('0') and 5 ('0')
        expect(
            (digitSpans[4] as HTMLElement).style.getPropertyValue(
                '--digit-stagger-n',
            ),
        ).toBe('1');
        expect(
            (digitSpans[5] as HTMLElement).style.getPropertyValue(
                '--digit-stagger-n',
            ),
        ).toBe('2');
    });
});
