import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { TypingIndicator } from '@/components/chat/typing-indicator';

describe('TypingIndicator component', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders aria-hidden visual bouncing dots container', () => {
        const { container } = render(<TypingIndicator locale="ar" />);

        const indicator = container.querySelector('[aria-hidden="true"]');
        expect(indicator).not.toBeNull();
        expect(indicator?.querySelectorAll('span')).toHaveLength(3);
    });

    it('uses the light card palette and accent dots', () => {
        const { container } = render(<TypingIndicator locale="en" />);

        expect(container.firstChild).toHaveClass('bg-[var(--chat-card)]');
        expect(
            container.querySelectorAll('.bg-\\[var\\(--chat-accent\\)\\]'),
        ).toHaveLength(3);
    });
});
