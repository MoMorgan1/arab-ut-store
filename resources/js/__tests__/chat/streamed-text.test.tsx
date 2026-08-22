import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { StreamedText } from '@/components/chat/streamed-text';

describe('StreamedText', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders settled content as plain text', () => {
        const { container } = render(
            <StreamedText content="Final answer" isStreaming={false} />,
        );

        expect(container.textContent).toBe('Final answer');
        expect(container.querySelector('.chat-stream-run')).toBeNull();
    });

    it('animates only the newly appended run while streaming', () => {
        const { container, rerender } = render(
            <StreamedText content="Hel" isStreaming={true} />,
        );

        // The very first run is the whole content.
        expect(container.textContent).toBe('Hel');

        rerender(<StreamedText content="Hello wor" isStreaming={true} />);
        const run = container.querySelector('.chat-stream-run');
        expect(container.textContent).toBe('Hello wor');
        expect(run?.textContent).toBe('lo wor');

        rerender(<StreamedText content="Hello world" isStreaming={true} />);
        expect(container.querySelector('.chat-stream-run')?.textContent).toBe(
            'ld',
        );

        // Terminal completion drops the animated wrapper.
        rerender(<StreamedText content="Hello world" isStreaming={false} />);
        expect(container.querySelector('.chat-stream-run')).toBeNull();
        expect(container.textContent).toBe('Hello world');
    });
});
