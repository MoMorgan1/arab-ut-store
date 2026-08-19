import AuthLayout from '@/layouts/auth-layout';
import ChatRootLayout from '@/layouts/chat-root-layout';

export function usesAuthLayout(name: string): boolean {
    return name.startsWith('auth/');
}

export function resolveApplicationLayout(name: string) {
    return usesAuthLayout(name) ? [ChatRootLayout, AuthLayout] : ChatRootLayout;
}
