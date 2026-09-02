import type { Auth } from '@/types/auth';
import type { ChatSharedProps } from '@/types/chat';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale?: string;
            chat?: ChatSharedProps;
            sidebarOpen: boolean;
            status?: string | null;
            [key: string]: unknown;
        };
    }
}

declare global {
    interface Window {
        /** Vendor ids injected by app.blade.php; absent when analytics is off. */
        __arabutAnalytics?: { ga4?: string; meta?: string; tiktok?: string };
        dataLayer?: unknown[];
        gtag?: (...args: unknown[]) => void;
        fbq?: ((...args: unknown[]) => void) & { queue?: unknown[] };
        ttq?: {
            load: (id: string) => void;
            page: () => void;
            track: (event: string, params?: Record<string, unknown>) => void;
        };
    }
}

export {};
