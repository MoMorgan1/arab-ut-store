import { configure } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';

// `findBy*` and `waitFor` carry their own 1s budget, separate from Vitest's
// test timeout. Several jsdom suites run in parallel here, and under that
// contention a widget that opens through a fetch plus an animation frame can
// take longer than a second to settle — which failed whichever test was
// unlucky rather than any specific assertion. The queries are unchanged; they
// simply wait longer before giving up.
configure({ asyncUtilTimeout: 5000 });

// jsdom ships no ResizeObserver, and the account bottom bar measures its own
// items with one to place the sliding lens. Every browser we support has it,
// so the absence is a jsdom gap rather than a case the component must handle:
// a stub that never fires keeps the initial layout path under test.
if (!('ResizeObserver' in globalThis)) {
    globalThis.ResizeObserver = class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}
