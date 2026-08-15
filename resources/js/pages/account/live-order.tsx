import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';

import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import {
    navigateToHostedPayment,
    navigateToOrder,
    PaylinkCheckoutError,
    resumePaylinkCheckout,
} from '@/lib/paylink-checkout-api';
import type { AccountLiveOrderPageProps } from '@/types/account';

export default function AccountLiveOrder() {
    const page = usePage<AccountLiveOrderPageProps>();
    const props = page.props;
    const BackArrow = props.locale === 'ar' ? ArrowRight : ArrowLeft;
    const [refreshing, setRefreshing] = useState(false);
    const [paymentState, setPaymentState] = useState<
        'idle' | 'loading' | 'error'
    >('idle');
    const ordersUrl =
        props.accountNavigation.find((item) => item.key === 'orders')?.url ??
        props.storeShell.accountUrl;
    const placedAt = new Intl.DateTimeFormat(
        props.locale === 'ar' ? 'ar-SA' : 'en-GB',
        { dateStyle: 'long', timeStyle: 'short' },
    ).format(new Date(props.order.placedAt));

    function refreshStatus() {
        if (refreshing) {
            return;
        }

        setRefreshing(true);
        router.reload({
            only: ['order'],
            onFinish: () => setRefreshing(false),
        });
    }

    async function resumePayment() {
        if (
            props.order.paymentStartUrl === null ||
            paymentState === 'loading'
        ) {
            return;
        }

        setPaymentState('loading');

        try {
            const checkout = await resumePaylinkCheckout(
                props.order.paymentStartUrl,
            );

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
        <MyAccountLayout {...props} current="orders" currentUrl={page.url}>
            <Head
                title={`${props.accountUi.orders.title} ${props.order.number}`}
            />
            <div className="account-live-order">
                <Link className="account-live-order__back" href={ordersUrl}>
                    <BackArrow aria-hidden="true" />
                    {props.accountUi.orders.back}
                </Link>

                <header className="account-live-order__header">
                    <div>
                        <p>{props.accountUi.orders.number}</p>
                        <h2>
                            <bdi>{props.order.number}</bdi>
                        </h2>
                        <span className="account-live-order__date">
                            <CalendarDays aria-hidden="true" />
                            <time dateTime={props.order.placedAt}>
                                {placedAt}
                            </time>
                        </span>
                    </div>
                    <div className="account-live-order__total">
                        <span>{props.accountUi.orders.total}</span>
                        <strong>
                            {formatAccountMoney(
                                props.order.total,
                                props.locale,
                            )}
                        </strong>
                    </div>
                </header>

                <div className="account-live-order__statusbar">
                    <div>
                        <span>{props.accountUi.orders.status}</span>
                        <strong>
                            {props.accountUi.statuses[props.order.status]}
                        </strong>
                    </div>
                    {props.order.refreshable ? (
                        <button
                            disabled={refreshing}
                            onClick={refreshStatus}
                            type="button"
                        >
                            <RefreshCw
                                aria-hidden="true"
                                className={refreshing ? 'is-spinning' : ''}
                            />
                            {refreshing
                                ? props.accountUi.orders.refreshing
                                : props.accountUi.orders.refresh_status}
                        </button>
                    ) : null}
                </div>

                <section
                    aria-labelledby="account-order-items-title"
                    className="account-live-order__items"
                >
                    <h2 id="account-order-items-title">
                        {props.accountUi.orders.items_title}
                    </h2>
                    <ol>
                        {props.order.items.map((item) => (
                            <li key={item.id}>
                                <div className="account-live-order__item-mark">
                                    <CheckCircle2 aria-hidden="true" />
                                </div>
                                <div className="account-live-order__item-main">
                                    <h3>{item.name}</h3>
                                    <span>
                                        {props.accountUi.statuses[item.status]}
                                    </span>
                                    {item.credentialsPresent ? (
                                        <small>
                                            {
                                                props.accountUi.orders
                                                    .credentials_ready
                                            }
                                        </small>
                                    ) : null}
                                </div>
                                <div className="account-live-order__item-total">
                                    <small>
                                        {props.accountUi.orders.item_quantity.replace(
                                            ':count',
                                            String(item.quantity),
                                        )}
                                    </small>
                                    <strong>
                                        {formatAccountMoney(
                                            item.total,
                                            props.locale,
                                        )}
                                    </strong>
                                </div>
                            </li>
                        ))}
                    </ol>
                </section>

                {props.order.paymentStartUrl === null ? null : (
                    <section className="account-live-order__payment">
                        <button
                            disabled={paymentState === 'loading'}
                            onClick={resumePayment}
                            type="button"
                        >
                            {paymentState === 'loading'
                                ? props.accountUi.orders.refreshing
                                : props.accountUi.actions.pay_now}
                        </button>
                        {paymentState === 'error' ? (
                            <p role="alert">
                                {props.accountUi.errors.unexpected}
                            </p>
                        ) : null}
                    </section>
                )}
            </div>
        </MyAccountLayout>
    );
}
