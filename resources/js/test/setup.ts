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

// jsdom implements no Pointer Capture API and no scrolling, and Radix's Select
// calls both while it opens: it asks the trigger whether it has captured the
// pointer, and scrolls the highlighted option into view. Every browser we
// support answers both, so these are jsdom gaps rather than cases a component
// must handle - without them a select that works in a real browser throws on
// the first click in a test. `hasPointerCapture` answers false because nothing
// here captures a pointer.
if (!Element.prototype.hasPointerCapture) {
    Element.prototype.hasPointerCapture = (): boolean => false;
    Element.prototype.setPointerCapture = (): void => {};
    Element.prototype.releasePointerCapture = (): void => {};
}

if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = (): void => {};
}
