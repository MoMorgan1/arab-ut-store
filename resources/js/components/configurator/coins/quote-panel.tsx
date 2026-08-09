import { formatHalalah } from '@/lib/money';
import type {
    CoinsQuoteViewState,
    CoinsStoreTranslations,
} from '@/types/coins';

type QuotePanelProps = {
    locale: 'ar' | 'en';
    state: CoinsQuoteViewState;
    translations: Pick<CoinsStoreTranslations, 'quote'>;
};

export function QuotePanel({ locale, state, translations }: QuotePanelProps) {
    const quote =
        state.status === 'success' || state.status === 'refreshing'
            ? state.quote
            : null;

    return (
        <section
            aria-labelledby="coins-quote-title"
            className="coins-quote-panel"
        >
            <h3 className="coins-quote-panel__title" id="coins-quote-title">
                {translations.quote.title}
            </h3>

            {state.status === 'idle' ? (
                <div aria-hidden="true" className="coins-quote-panel__rule" />
            ) : null}

            {state.status === 'loading' ? (
                <p aria-live="polite" className="coins-quote-panel__message">
                    <span aria-hidden="true" className="coins-loading-mark" />
                    {translations.quote.loading}
                </p>
            ) : null}

            {quote !== null ? (
                <div
                    aria-busy={state.status === 'refreshing'}
                    aria-live="polite"
                    className="coins-quote-panel__result"
                >
                    <span>{translations.quote.total}</span>
                    <strong>
                        {formatHalalah(
                            quote.total.amountHalalah,
                            quote.total.currency,
                            locale,
                        )}
                    </strong>
                </div>
            ) : null}

            {state.status === 'refreshing' ? (
                <p aria-live="polite" className="coins-quote-panel__refreshing">
                    <span aria-hidden="true" className="coins-loading-mark" />
                    {translations.quote.refreshing}
                </p>
            ) : null}

            {state.status === 'validation' ? (
                <p className="coins-quote-panel__error" role="alert">
                    {translations.quote.validation_error}
                </p>
            ) : null}

            {state.status === 'unavailable' ? (
                <p className="coins-quote-panel__error" role="alert">
                    {translations.quote.unavailable}
                </p>
            ) : null}
        </section>
    );
}
