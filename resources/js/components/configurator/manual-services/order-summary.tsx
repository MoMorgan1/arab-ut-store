import { ShieldCheck } from 'lucide-react';

import { formatMinorUnits } from '@/lib/money';
import type {
    ManualServiceCommonTranslations,
    ManualServiceMoney,
} from '@/types/manual-services';

export function ManualOrderSummary({
    facts,
    locale,
    price,
    translations,
}: {
    facts: Array<{ label: string; value: string }>;
    locale: 'ar' | 'en';
    price: ManualServiceMoney | null;
    translations: ManualServiceCommonTranslations;
}) {
    return (
        <aside className="manual-order-summary">
            <h2>{translations.review_title}</h2>
            <dl>
                {facts.map((fact) => (
                    <div key={fact.label}>
                        <dt>{fact.label}</dt>
                        <dd>{fact.value}</dd>
                    </div>
                ))}
                <div className="manual-order-summary__total">
                    <dt>{translations.review_total}</dt>
                    <dd>
                        {price === null
                            ? '—'
                            : formatMinorUnits(
                                  price.amountMinor,
                                  price.currency,
                                  locale,
                              )}
                    </dd>
                </div>
            </dl>
            <p>
                <ShieldCheck aria-hidden="true" />
                {translations.review_credentials_ready}
            </p>
        </aside>
    );
}
