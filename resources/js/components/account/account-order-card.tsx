import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CalendarDays } from 'lucide-react';

import { formatAccountMoney } from '@/lib/account-money';
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
    locale,
    order,
    prominent = false,
    translations,
}: {
    locale: 'ar' | 'en';
    order: AccountOrder;
    prominent?: boolean;
    translations: AccountTranslations;
}) {
    const Arrow = locale === 'ar' ? ArrowLeft : ArrowRight;
    const status = translations.statuses[order.status];
    const action = order.action?.type ?? 'view_order';
    const date = new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA' : 'en-GB', {
        dateStyle: 'medium',
    }).format(new Date(order.placedAt));

    return (
        <article
            className={`account-order-card${prominent ? 'account-order-card--prominent' : ''}`}
        >
            <div className="account-order-card__topline">
                <span
                    className="account-order-card__status"
                    data-status={order.status}
                >
                    <span aria-hidden="true" />
                    {status}
                </span>
                <span className="account-order-card__date">
                    <CalendarDays aria-hidden="true" />
                    <time dateTime={order.placedAt}>{date}</time>
                </span>
            </div>
            <div className="account-order-card__body">
                <div>
                    <bdi className="account-order-card__number">
                        {order.number}
                    </bdi>
                    <h3>{order.summary}</h3>
                </div>
                <strong className="account-order-card__total">
                    {formatAccountMoney(order.total, locale)}
                </strong>
            </div>
            <Link className="account-order-card__action" href={order.detailUrl}>
                {actionLabel(action, translations.actions)}
                <Arrow aria-hidden="true" />
            </Link>
        </article>
    );
}
