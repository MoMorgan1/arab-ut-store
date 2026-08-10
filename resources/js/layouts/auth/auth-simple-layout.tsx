import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export default function AuthSimpleLayout({
    brand,
    children,
    description,
    direction,
    homeUrl,
    locale,
    title,
}: {
    brand: string;
    children?: ReactNode;
    description: string;
    direction: 'rtl' | 'ltr';
    homeUrl: string;
    locale: 'ar' | 'en';
    title: string;
}) {
    return (
        <main className="auth-shell" dir={direction} lang={locale}>
            <div className="auth-shell__panel">
                <div className="auth-shell__content">
                    <header className="auth-shell__heading">
                        <Link
                            aria-label={brand}
                            className="auth-shell__brand"
                            href={homeUrl}
                        >
                            <img
                                alt=""
                                aria-hidden="true"
                                height="64"
                                src="/images/arabut-logo-header.webp"
                                width="64"
                            />
                            <span>{brand}</span>
                        </Link>

                        <h1>{title}</h1>
                        <p>{description}</p>
                    </header>
                    {children}
                </div>
            </div>
        </main>
    );
}
