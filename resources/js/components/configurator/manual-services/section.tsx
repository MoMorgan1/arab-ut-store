import type { ReactNode } from 'react';

import { formatInteger } from '@/lib/money';

export function ManualSection({
    children,
    hint,
    id,
    locale,
    number,
    title,
}: {
    children: ReactNode;
    hint?: string;
    id: string;
    locale: 'ar' | 'en';
    number: number;
    title: string;
}) {
    return (
        <section aria-labelledby={id} className="manual-section">
            <header className="manual-section__header">
                <span aria-hidden="true" className="manual-section__badge">
                    {formatInteger(number, locale)}
                </span>
                <div className="manual-section__heading">
                    <h2 className="manual-section__title" id={id}>
                        {title}
                    </h2>
                    {hint ? (
                        <p className="manual-section__hint">{hint}</p>
                    ) : null}
                </div>
            </header>
            <div className="manual-section__content">{children}</div>
        </section>
    );
}
