import { usePage } from '@inertiajs/react';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import type { AuthSharedProps } from '@/types/auth';

export default function AuthLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { authPage, authRoutes, authUi, direction, locale } = usePage()
        .props as unknown as AuthSharedProps;
    const pageCopy = authUi[authPage];

    return (
        <AuthLayoutTemplate
            brand={authUi.brand}
            description={pageCopy.description}
            direction={direction}
            homeUrl={authRoutes.homeUrl}
            locale={locale}
            title={pageCopy.title}
        >
            {children}
        </AuthLayoutTemplate>
    );
}
