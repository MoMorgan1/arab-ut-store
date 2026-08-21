import AdminLayout from '@/layouts/admin-layout';
import AuthLayout from '@/layouts/auth-layout';
import ChatRootLayout from '@/layouts/chat-root-layout';

export function usesAuthLayout(name: string): boolean {
    return name.startsWith('auth/');
}

export function usesAdminLayout(name: string): boolean {
    return name.startsWith('admin/');
}

export function resolveApplicationLayout(name: string) {
    if (usesAdminLayout(name)) {
        return [ChatRootLayout, AdminLayout];
    }

    return usesAuthLayout(name) ? [ChatRootLayout, AuthLayout] : ChatRootLayout;
}
