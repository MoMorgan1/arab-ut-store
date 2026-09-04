import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                // Every `lucide-react` icon is its own ES module and every
                // `@inertiajs` sub-package its own entry, so the default
                // splitting handed the storefront home page fifty preloaded
                // scripts — thirty of them under half a kilobyte. The browser
                // registered each one in its module graph before it could
                // paint. Grouping the three vendors that every page loads takes
                // that to nineteen requests for the same bytes: measured over
                // the home page graph, 184.0 KB brotli before, 183.6 KB after.
                //
                // Radix is deliberately left alone. Its packages split per
                // component, and only the admin screens pull the heavy ones
                // (select, dropdown-menu, table); one `vendor-radix` chunk
                // would have pushed 82 KB of admin-only code onto the store.
                advancedChunks: {
                    // Folds the leftover sub-kilobyte chunks into whichever
                    // chunk imports them instead of emitting a file per module.
                    minSize: 12000,
                    groups: [
                        {
                            name: 'vendor-react',
                            test: /[\\/]node_modules[\\/](react|react-dom|scheduler|use-sync-external-store)[\\/]/,
                            priority: 30,
                        },
                        {
                            name: 'vendor-inertia',
                            test: /[\\/]node_modules[\\/]@inertiajs[\\/]/,
                            priority: 25,
                        },
                        {
                            name: 'vendor-icons',
                            test: /[\\/]node_modules[\\/]lucide-react[\\/]/,
                            priority: 20,
                        },
                    ],
                },
            },
        },
    },
});
