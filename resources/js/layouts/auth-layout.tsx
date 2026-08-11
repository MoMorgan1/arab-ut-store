import { usePage } from '@inertiajs/react';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import StoreLayout from '@/layouts/store-layout';
import type { AuthSharedProps } from '@/types/auth';

export default function AuthLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage();
    const {
        authPage,
        authUi,
        cartCount,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        storeShell,
        ui,
    } = page.props as unknown as AuthSharedProps;
    const pageCopy = authUi[authPage];
    const showsBenefits = authPage === 'login' || authPage === 'register';

    return (
        <StoreLayout
            cartCount={cartCount}
            currentUrl={page.url}
            direction={direction}
            displayCurrencies={displayCurrencies}
            displayCurrency={displayCurrency}
            locale={locale}
            storeShell={storeShell}
            ui={ui}
        >
            <AuthLayoutTemplate
                benefits={authUi.benefits}
                description={pageCopy.description}
                direction={direction}
                showBenefits={showsBenefits}
                title={pageCopy.title}
            >
                {children}
            </AuthLayoutTemplate>
        </StoreLayout>
    );
}
