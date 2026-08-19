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
            chat?: ChatSharedProps;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
