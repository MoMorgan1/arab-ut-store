import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Ban,
    CircleAlert,
    CircleCheck,
    Clock3,
} from 'lucide-react';

import { formatAccountMoney } from '@/lib/account-money';
import { formatOrderDate, formatOrderNumber } from '@/lib/account-order-format';
import type {
    AccountOrder,
    AccountOrderAction,
    AccountTranslations,
} from '@/types/account';

function actionLabel(
    type: AccountOrderAction,
    actions: AccountTranslations['actions'],
): string {
    return actions[type];
}

export default function AccountOrderCard({
    description,
    locale,
    order,
    translations,
}: {
    description?: string;
    locale: 'ar' | 'en';
    order: AccountOrder;
    translations: AccountTranslations;
}) {
    const Arrow = locale === 'ar' ? ArrowLeft : ArrowRight;
    const status = translations.statuses[order.status];
    const action = order.action?.type ?? 'view_order';
    const date = formatOrderDate(order.placedAt, locale);
    const displayNumber = formatOrderNumber(order.number);

    const StatusIcon =
        order.status === 'completed'
            ? CircleCheck
            : order.status === 'in_progress' || order.status === 'received'
              ? Clock3
              : order.status === 'cancelled' || order.status === 'refunded'
                ? Ban
                : CircleAlert;

    return (
        <article className="account-order-card account-order-card--prominent">
            <span aria-hidden="true" className="account-order-card__mark">
                <StatusIcon />
            </span>
            <div className="account-order-card__main">
                <div className="account-order-card__topline">
                    <span
                        className="account-order-card__status"
                        data-status={order.status}
                    >
                        <span aria-hidden="true" />
                        {status}
                    </span>
                    <span className="account-order-card__meta">
                        <span
                            className="account-order-card__number"
                            dir="ltr"
                            title={order.number}
                        >
                            {displayNumber}
                        </span>
                        {' · '}
                        <time dateTime={order.placedAt}>{date}</time>
                        {order.walletPayment &&
                        order.walletPayment.amountMinor !== '0' ? (
                            <>
                                {' · '}
                                <span className="account-order-card__wallet-paid">
                                    {translations.orders.wallet_paid.replace(
                                        ':amount',
                                        formatAccountMoney(
                                            order.walletPayment,
                                            locale,
                                        ),
                                    )}
                                </span>
                            </>
                        ) : null}
                    </span>
                </div>
                <h3>{order.summary}</h3>
                {description ? (
                    <p className="account-order-card__description">
                        {description}
                    </p>
                ) : null}
            </div>
            <div className="account-order-card__side">
                <strong className="account-order-card__total">
                    {formatAccountMoney(order.total, locale)}
                </strong>
                <Link
                    className="account-order-card__action"
                    href={order.detailUrl}
                >
                    {actionLabel(action, translations.actions)}
                    <Arrow aria-hidden="true" />
                </Link>
            </div>
        </article>
    );
}
