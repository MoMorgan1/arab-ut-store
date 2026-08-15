import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AuthLayout from '@/layouts/auth-layout';
import {
    DEFAULT_APPLICATION_NAME,
    formatDocumentTitle,
} from '@/lib/document-title';
import { usesAuthLayout } from '@/lib/page-layout';

const appName = import.meta.env.VITE_APP_NAME || DEFAULT_APPLICATION_NAME;

createInertiaApp({
    title: (title) => formatDocumentTitle(title, appName),
    layout: (name) => {
        return usesAuthLayout(name) ? AuthLayout : null;
    },
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
