import type { Ref } from 'react';

import { formatCoins, formatMinorUnits } from '@/lib/money';
import type {
    CoinsDeliveryValue,
    CoinsPlatformValue,
    CoinsQuote,
    CoinsStoreTranslations,
} from '@/types/coins';

type SummaryStepProps = {
    delivery: CoinsDeliveryValue | null;
    error: string | null;
    focusRef: Ref<HTMLHeadingElement>;
    locale: 'ar' | 'en';
    onAdd: () => void;
    onBack: () => void;
    onCancel: () => void;
    pending: boolean;
    platform: CoinsPlatformValue;
    quote: CoinsQuote;
    retrying: boolean;
    translations: CoinsStoreTranslations;
};

export function SummaryStep(props: SummaryStepProps) {
    const {
        delivery,
        error,
        focusRef,
        locale,
        onAdd,
        onBack,
        onCancel,
        pending,
        platform,
        quote,
        retrying,
        translations,
    } = props;
    const deliveryLabel =
        delivery === null
            ? translations.summary.delivery_pc
            : translations.delivery.options[delivery];

    return (
        <section aria-labelledby="coins-summary-title" className="coins-step">
            <h2
                className="coins-step__title"
                id="coins-summary-title"
                ref={focusRef}
                tabIndex={-1}
            >
                {translations.summary.title}
            </h2>
            <dl className="coins-order-summary">
                <SummaryRow
                    label={translations.summary.service}
                    value={translations.summary.service_value}
                />
                <SummaryRow
                    label={translations.summary.platform}
                    value={translations.platform.options[platform]}
                />
                <SummaryRow
                    label={translations.summary.delivery}
                    value={deliveryLabel}
                />
                <SummaryRow
                    label={translations.summary.quantity}
                    value={`${formatCoins(quote.quantity, locale)} ${translations.units.coins}`}
                />
                <SummaryRow
                    emphasized
                    label={translations.summary.total}
                    value={formatMinorUnits(
                        quote.total.amountHalalah,
                        quote.total.currency,
                        locale,
                    )}
                />
            </dl>
            <p className="coins-credentials-ready">
                <span aria-hidden="true" className="coins-security-mark" />
                {translations.summary.credentials_ready}
            </p>
            {error === null ? null : (
                <p className="coins-submit-error" role="alert">
                    {error}
                </p>
            )}
            <div className="coins-step__actions coins-step__actions--split">
                <button
                    className="coins-secondary-action"
                    disabled={pending}
                    onClick={onBack}
                    type="button"
                >
                    {translations.actions.back}
                </button>
                <button
                    className="coins-primary-action"
                    disabled={pending}
                    onClick={onAdd}
                    type="button"
                >
                    {pending
                        ? translations.summary.adding
                        : retrying
                          ? translations.summary.retry
                          : translations.summary.add}
                </button>
            </div>
            <button
                className="coins-clear-action"
                disabled={pending}
                onClick={onCancel}
                type="button"
            >
                {translations.credentials.clear}
            </button>
        </section>
    );
}

function SummaryRow({
    emphasized = false,
    label,
    value,
}: {
    emphasized?: boolean;
    label: string;
    value: string;
}) {
    return (
        <div className={emphasized ? 'coins-order-summary__total' : undefined}>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}
