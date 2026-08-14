import type { ReactNode } from 'react';
import type { AuthUiTranslations } from '@/types/auth';

function BenefitIcon({ index }: { index: number }) {
    const paths = [
        'M4 7h16v12H4zM8 7V5a4 4 0 0 1 8 0v2',
        'M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6zM9 12l2 2 4-4',
        'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm-9-9h18M12 3c2.2 2.5 3.3 5.5 3.3 9S14.2 18.5 12 21c-2.2-2.5-3.3-5.5-3.3-9S9.8 5.5 12 3Z',
    ];

    return (
        <svg aria-hidden="true" fill="none" viewBox="0 0 24 24">
            <path
                d={paths[index]}
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="1.7"
            />
        </svg>
    );
}

export default function AuthSimpleLayout({
    benefits,
    children,
    description,
    direction,
    showBenefits,
    title,
}: {
    benefits: AuthUiTranslations['benefits'];
    children?: ReactNode;
    description: string;
    direction: 'rtl' | 'ltr';
    showBenefits: boolean;
    title: string;
}) {
    return (
        <section
            className="auth-shell"
            aria-labelledby="auth-page-title"
            dir={direction}
        >
            <div className="auth-shell__inner">
                <div
                    className={[
                        'auth-shell__grid',
                        showBenefits ? null : 'auth-shell__grid--focused',
                    ]
                        .filter(Boolean)
                        .join(' ')}
                >
                    {showBenefits ? (
                        <aside
                            aria-labelledby="auth-benefits-title"
                            className="auth-shell__benefits"
                            dir={direction}
                        >
                            <div
                                className="auth-shell__benefits-brand"
                                aria-hidden="true"
                            >
                                <img
                                    alt=""
                                    height="64"
                                    src="/images/arabut-logo-header.webp"
                                    width="64"
                                />
                            </div>
                            <p className="auth-shell__eyebrow">
                                {benefits.eyebrow}
                            </p>
                            <h2 id="auth-benefits-title">{benefits.title}</h2>
                            <p className="auth-shell__benefits-description">
                                {benefits.description}
                            </p>
                            <ul>
                                {benefits.items.map((benefit, index) => (
                                    <li key={benefit}>
                                        <span className="auth-shell__benefit-icon">
                                            <BenefitIcon index={index} />
                                        </span>
                                        <span>{benefit}</span>
                                    </li>
                                ))}
                            </ul>
                        </aside>
                    ) : null}

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
