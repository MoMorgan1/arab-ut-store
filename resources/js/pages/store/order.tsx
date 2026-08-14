import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';

import StoreLayout from '@/layouts/store-layout';
import { formatMinorUnits } from '@/lib/money';
import {
    navigateToHostedPayment,
    navigateToOrder,
    PaylinkCheckoutError,
    resumePaylinkCheckout,
} from '@/lib/paylink-checkout-api';
import type { StoreOrderPageProps } from '@/types/store-shell';

export default function StoreOrder() {
    const page = usePage<StoreOrderPageProps>();
    const {
        cartCount,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        order,
        orderPage,
        storeShell,
        ui,
    } = page.props;
    const [paymentState, setPaymentState] = useState<
        'idle' | 'loading' | 'error'
    >('idle');

    async function resumePayment() {
        if (order.paymentStartUrl === null || paymentState === 'loading') {
            return;
        }

        setPaymentState('loading');

        try {
            const checkout = await resumePaylinkCheckout(order.paymentStartUrl);

            if (checkout.paymentUrl === null) {
                navigateToOrder(checkout.orderUrl);
            } else {
                navigateToHostedPayment(checkout.paymentUrl);
            }
        } catch (error) {
            if (!(error instanceof PaylinkCheckoutError)) {
                throw error;
            }

            setPaymentState('error');
        }
    }

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
            <Head title={`${orderPage.title} ${order.number}`} />
            <section
                aria-labelledby="store-order-title"
                className="store-order-page"
            >
                <header className="store-order-page__heading">
                    <p>{orderPage.eyebrow}</p>
                    <h1 id="store-order-title">{orderPage.title}</h1>
                </header>

                <dl className="store-order-summary">
                    <div>
                        <dt>{orderPage.number}</dt>
                        <dd dir="ltr">{order.number}</dd>
                    </div>
                    <div>
                        <dt>{orderPage.status}</dt>
                        <dd>{orderPage.statuses[order.status]}</dd>
                    </div>
                    <div>
                        <dt>{orderPage.total}</dt>
                        <dd>
                            {formatMinorUnits(
                                order.totalHalalah,
                                order.currency,
                                locale,
                            )}
                        </dd>
                    </div>
                </dl>

                <ol className="store-order-items">
                    {order.items.map((item) => (
                        <li key={item.id}>
                            <div>
                                <h2>{item.name}</h2>
                                <span>{orderPage.statuses[item.status]}</span>
                            </div>
                            <strong>
                                {formatMinorUnits(
                                    item.totalHalalah,
                                    order.currency,
                                    locale,
                                )}
                            </strong>
                        </li>
                    ))}
                </ol>

                {order.paymentStartUrl !== null ? (
                    <div className="store-order-payment">
                        <button
                            disabled={paymentState === 'loading'}
                            onClick={resumePayment}
                            type="button"
                        >
                            {paymentState === 'loading'
                                ? orderPage.pay_loading
                                : orderPage.pay_now}
                        </button>
                        {paymentState === 'error' ? (
                            <p
                                className="store-order-payment__error"
                                role="alert"
                            >
                                {orderPage.pay_error}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                <a className="store-order-page__back" href={storeShell.homeUrl}>
                    {orderPage.back}
                </a>
            </section>
        </StoreLayout>
    );
}
