import { fileURLToPath, URL } from 'node:url';

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        dir: './resources/js/__tests__',
        environment: 'jsdom',
        setupFiles: ['./resources/js/test/setup.ts'],
        // Several jsdom suites run in parallel and the heaviest single file
        // takes tens of seconds on CI hardware, so individual tests were
        // exceeding the 5s default and failing whichever test was unlucky.
        // The assertions are unchanged; only the patience is.
        testTimeout: 20000,
        hookTimeout: 20000,
    },
});
