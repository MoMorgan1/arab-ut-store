import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import {
    DEFAULT_APPLICATION_NAME,
    formatDocumentTitle,
} from '@/lib/document-title';
import { resolveApplicationLayout } from '@/lib/page-layout';

const appName = import.meta.env.VITE_APP_NAME || DEFAULT_APPLICATION_NAME;

createInertiaApp({
    title: (title) => formatDocumentTitle(title, appName),
    layout: (name) => resolveApplicationLayout(name),
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
