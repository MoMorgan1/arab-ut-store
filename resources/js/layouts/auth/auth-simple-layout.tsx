import type { ReactNode } from 'react';

export default function AuthSimpleLayout({
    children,
    description,
    direction,
    title,
}: {
    children?: ReactNode;
    description: string;
    direction: 'rtl' | 'ltr';
    title: string;
}) {
    return (
        <section
            className="auth-shell"
            aria-labelledby="auth-page-title"
            dir={direction}
        >
            <div className="auth-shell__inner">
                <div className="auth-shell__grid">
                    <div className="auth-shell__brand" aria-hidden="true">
                        <img
                            alt=""
                            height="64"
                            src="/images/arabut-logo-header.webp"
                            width="64"
                        />
                    </div>
                    <article className="auth-shell__form-card" dir={direction}>
                        <div className="auth-shell__heading">
                            <h1
                                className="auth-shell__title"
                                id="auth-page-title"
                            >
                                {title}
                            </h1>
                            <p>{description}</p>
                        </div>
                        {children}
                    </article>
                </div>
            </div>
        </section>
    );
}
