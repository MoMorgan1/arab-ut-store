import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Mock } from 'vitest';

const observerHarness = vi.hoisted(() => {
    const instances: Array<{
        observe: Mock;
        unobserve: Mock;
    }> = [];
    let callback: IntersectionObserverCallback | undefined;

    class FakeIntersectionObserver {
        observe: Mock = vi.fn();

        unobserve: Mock = vi.fn();

        constructor(seen: IntersectionObserverCallback) {
            callback = seen;
            instances.push(this);
        }

        disconnect() {}
    }

    return {
        instances,
        takeCallback: () => callback,
        FakeIntersectionObserver,
    };
});

function stubMatchMedia(matches: boolean) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn().mockReturnValue({
            matches,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        }),
    );
}

async function loadScanner() {
    const module = await import('@/hooks/use-scroll-reveal');

    return module.scanScrollReveal;
}

beforeEach(() => {
    vi.resetModules();
    document.body.innerHTML = '';
    observerHarness.instances.length = 0;
    vi.stubGlobal(
        'IntersectionObserver',
        observerHarness.FakeIntersectionObserver,
    );
    stubMatchMedia(false);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('scanScrollReveal', () => {
    it('marks the shell ready, observes opt-ins, and reveals them once', async () => {
        const scanScrollReveal = await loadScanner();
        document.body.innerHTML =
            '<div class="store-shell"><section data-reveal id="one"></section><section data-reveal data-reveal-delay="140" id="two"></section></div>';

        scanScrollReveal(document);

        expect(document.querySelector('.store-shell')).toHaveClass(
            'store-shell--reveal-ready',
        );

        const [observer] = observerHarness.instances;
        const one = document.getElementById('one') as HTMLElement;
        const two = document.getElementById('two') as HTMLElement;

        expect(observer?.observe).toHaveBeenCalledWith(one);
        expect(observer?.observe).toHaveBeenCalledWith(two);
        expect(two.style.getPropertyValue('--reveal-delay')).toBe('140ms');
        expect(one).not.toHaveClass('is-revealed');

        const callback = observerHarness.takeCallback();

        expect(callback).toBeDefined();
        callback?.(
            [
                { target: one, isIntersecting: true },
                { target: two, isIntersecting: false },
            ] as unknown as IntersectionObserverEntry[],
            observer as unknown as IntersectionObserver,
        );

        expect(one).toHaveClass('is-revealed');
        expect(two).not.toHaveClass('is-revealed');
        expect(observer?.unobserve).toHaveBeenCalledWith(one);
        expect(observer?.unobserve).not.toHaveBeenCalledWith(two);
    });

    it('does not observe an element twice across re-scans', async () => {
        const scanScrollReveal = await loadScanner();
        document.body.innerHTML =
            '<div class="store-shell"><section data-reveal id="one"></section></div>';

        scanScrollReveal(document);
        scanScrollReveal(document);

        const [observer] = observerHarness.instances;
        const one = document.getElementById('one') as HTMLElement;

        expect(observer?.observe).toHaveBeenCalledTimes(1);

        observerHarness.takeCallback()?.(
            [
                { target: one, isIntersecting: true },
            ] as unknown as IntersectionObserverEntry[],
            observer as unknown as IntersectionObserver,
        );

        scanScrollReveal(document);

        expect(observer?.observe).toHaveBeenCalledTimes(1);
        expect(one).toHaveClass('is-revealed');
    });

    it('reveals everything immediately under reduced motion', async () => {
        stubMatchMedia(true);
        const scanScrollReveal = await loadScanner();
        document.body.innerHTML =
            '<div class="store-shell"><section data-reveal id="one"></section></div>';

        scanScrollReveal(document);

        expect(document.querySelector('.store-shell')).toHaveClass(
            'store-shell--reveal-ready',
        );
        expect(document.getElementById('one')).toHaveClass('is-revealed');
        expect(observerHarness.instances).toHaveLength(0);
    });
});
