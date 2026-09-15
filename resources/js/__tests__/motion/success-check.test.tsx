import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { SuccessCheck } from '@/components/motion/success-check';

afterEach(() => {
    cleanup();
});

describe('SuccessCheck', () => {
    it('renders data-state="in"', () => {
        const { container } = render(<SuccessCheck />);
        const element = container.querySelector('.t-success-check');

        expect(element).not.toBeNull();
        expect(element).toHaveAttribute('data-state', 'in');
    });

    it('every drawn element carries pathLength="1"', () => {
        const { container: checkContainer } = render(
            <SuccessCheck variant="check" />,
        );
        const checkElements = checkContainer.querySelectorAll('path, circle');

        expect(checkElements.length).toBeGreaterThan(0);
        checkElements.forEach((el) => {
            expect(el).toHaveAttribute('pathLength', '1');
        });

        const { container: circleContainer } = render(
            <SuccessCheck variant="circle" />,
        );
        const circleElements = circleContainer.querySelectorAll('path, circle');

        expect(circleElements.length).toBe(2);
        circleElements.forEach((el) => {
            expect(el).toHaveAttribute('pathLength', '1');
        });
    });

    it('label makes it exposed (role="img" + aria-label) while the default is aria-hidden="true"', () => {
        const { container: defaultContainer } = render(<SuccessCheck />);
        const defaultElement =
            defaultContainer.querySelector('.t-success-check');

        expect(defaultElement).toHaveAttribute('aria-hidden', 'true');
        expect(defaultElement).not.toHaveAttribute('role');
        expect(defaultElement).not.toHaveAttribute('aria-label');
        expect(screen.queryByRole('img')).toBeNull();

        const { container: labeledContainer } = render(
            <SuccessCheck label="Saved successfully" />,
        );
        const labeledElement =
            labeledContainer.querySelector('.t-success-check');

        expect(labeledElement).toHaveAttribute('role', 'img');
        expect(labeledElement).toHaveAttribute(
            'aria-label',
            'Saved successfully',
        );
        expect(labeledElement).not.toHaveAttribute('aria-hidden');
        expect(
            screen.getByRole('img', { name: 'Saved successfully' }),
        ).toBeInTheDocument();
    });
});
