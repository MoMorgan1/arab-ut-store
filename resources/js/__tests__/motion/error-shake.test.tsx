import { cleanup, render } from '@testing-library/react';
import { useRef } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { shake, useErrorShake } from '@/components/motion/error-shake';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('error-shake', () => {
    it('does not throw when shake(null) is called', () => {
        expect(() => shake(null)).not.toThrow();
    });

    it('does not throw when shake is called on an element without animate', () => {
        const el = document.createElement('div');
        Object.defineProperty(el, 'animate', {
            value: undefined,
            configurable: true,
        });

        expect(() => shake(el)).not.toThrow();
    });

    it('calls animate when an error appears and again when the message changes', () => {
        const animateMock = vi.fn().mockReturnValue({ cancel: vi.fn() });
        const getAnimationsMock = vi.fn().mockReturnValue([]);

        function TestComponent({
            error,
        }: {
            error: string | undefined | null;
        }) {
            const ref = useRef<HTMLDivElement | null>(null);
            useErrorShake(ref, error);

            return (
                <div
                    ref={(node) => {
                        if (node) {
                            node.animate = animateMock;
                            node.getAnimations = getAnimationsMock;
                        }

                        ref.current = node;
                    }}
                >
                    {error}
                </div>
            );
        }

        const { rerender } = render(<TestComponent error={undefined} />);
        expect(animateMock).not.toHaveBeenCalled();

        rerender(<TestComponent error="First error" />);
        expect(animateMock).toHaveBeenCalled();
        const callCountAfterFirst = animateMock.mock.calls.length;

        rerender(<TestComponent error="Second error" />);
        expect(animateMock.mock.calls.length).toBeGreaterThan(
            callCountAfterFirst,
        );
    });

    it('does not call animate when an error clears', () => {
        const animateMock = vi.fn().mockReturnValue({ cancel: vi.fn() });
        const getAnimationsMock = vi.fn().mockReturnValue([]);

        function TestComponent({
            error,
        }: {
            error: string | undefined | null;
        }) {
            const ref = useRef<HTMLDivElement | null>(null);
            useErrorShake(ref, error);

            return (
                <div
                    ref={(node) => {
                        if (node) {
                            node.animate = animateMock;
                            node.getAnimations = getAnimationsMock;
                        }

                        ref.current = node;
                    }}
                >
                    {error}
                </div>
            );
        }

        const { rerender } = render(<TestComponent error="Initial error" />);
        expect(animateMock).toHaveBeenCalled();
        const callCountBeforeClear = animateMock.mock.calls.length;

        rerender(<TestComponent error={undefined} />);
        expect(animateMock.mock.calls.length).toBe(callCountBeforeClear);

        rerender(<TestComponent error={null} />);
        expect(animateMock.mock.calls.length).toBe(callCountBeforeClear);
    });
});
