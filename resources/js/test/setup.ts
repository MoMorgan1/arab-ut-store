import { configure } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';

// `findBy*` and `waitFor` carry their own 1s budget, separate from Vitest's
// test timeout. Several jsdom suites run in parallel here, and under that
// contention a widget that opens through a fetch plus an animation frame can
// take longer than a second to settle — which failed whichever test was
// unlucky rather than any specific assertion. The queries are unchanged; they
// simply wait longer before giving up.
configure({ asyncUtilTimeout: 5000 });
