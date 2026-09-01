import { Clock, ShieldCheck } from 'lucide-react';

import { formatMinorUnits } from '@/lib/money';
import type {
    ManualServiceCommonTranslations,
    ManualServiceMoney,
} from '@/types/manual-services';

export function ManualServicePanel({
    eta,
    facts,
    image,
    locale,
    price,
    status,
    submitDisabled = false,
    submitLabel,
    title,
    translations,
}: {
    eta: string;
    facts: Array<{ label: string; value: string }>;
    image: { alt: string; url: string };
    locale: 'ar' | 'en';
    price: ManualServiceMoney | null;
    status: 'idle' | 'loading' | 'success' | 'error';
    submitDisabled?: boolean;
    submitLabel?: string;
    title: string;
    translations: ManualServiceCommonTranslations;
}) {
    const formattedPrice =
        price === null
            ? '—'
            : formatMinorUnits(price.amountMinor, price.currency, locale);

    return (
        <aside className="manual-service-panel">
            <div className="manual-service-panel__media">
                <img
                    alt={image.alt}
                    height="180"
                    loading="lazy"
                    src={image.url}
                    width="320"
                />
            </div>

            <div className="manual-service-panel__header">
                <span className="manual-service-panel__eyebrow">
                    {translations.panel_title}
                </span>
                <h2 className="manual-service-panel__title">{title}</h2>
            </div>

            <dl className="manual-service-panel__facts">
                {facts.map((fact, index) => (
                    <div
                        className="manual-service-panel__fact"
                        key={`${fact.label}-${index}`}
                    >
                        <dt>{fact.label}</dt>
                        <dd>{fact.value}</dd>
                    </div>
                ))}
            </dl>

            <div className="manual-service-panel__eta">
                <Clock aria-hidden="true" />
                <span>{eta}</span>
            </div>

            <div className="manual-service-panel__bar">
                <div className="manual-service-panel__total">
                    <span className="manual-service-panel__total-label">
                        {translations.review_total}
                    </span>
                    <strong
                        aria-live="polite"
                        className="manual-service-panel__total-amount"
                    >
                        {formattedPrice}
                    </strong>
                </div>

                <button
                    className="manual-configurator__submit"
                    disabled={submitDisabled || status === 'loading'}
                    type="submit"
                >
                    {status === 'loading'
                        ? translations.adding
                        : (submitLabel ?? translations.add_to_cart)}
                </button>

                {status === 'success' ? (
                    <p className="manual-service-panel__status" role="status">
                        {translations.added}
                    </p>
                ) : null}
                {status === 'error' ? (
                    <p className="manual-service-panel__alert" role="alert">
                        {translations.add_error}
                    </p>
                ) : null}
            </div>

            <p className="manual-service-panel__trust">
                <ShieldCheck aria-hidden="true" />
                <span>{translations.review_credentials_ready}</span>
            </p>
        </aside>
    );
}
