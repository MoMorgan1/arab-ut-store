import { Link } from '@inertiajs/react';
import { Ban, CircleAlert, CircleCheck, Clock3 } from 'lucide-react';

import { formatAccountMoney } from '@/lib/account-money';
import { formatOrderDate, formatOrderNumber } from '@/lib/account-order-format';
import { cn } from '@/lib/utils';
import type { AccountOrder, AccountTranslations } from '@/types/account';

export default function AccountOrderRow({
    locale,
    order,
    translations,
}: {
    locale: 'ar' | 'en';
    order: AccountOrder;
    translations: AccountTranslations;
}) {
    const isAttention =
        order.status === 'waiting_for_customer' ||
        order.status === 'pending_payment' ||
        order.status === 'failed';

    const StatusIcon =
        order.status === 'completed'
            ? CircleCheck
            : order.status === 'in_progress' || order.status === 'received'
              ? Clock3
              : order.status === 'cancelled' || order.status === 'refunded'
                ? Ban
                : CircleAlert;

    const action = order.action?.type ?? 'view_order';
    const actionText = translations.actions[action];
    const date = formatOrderDate(order.placedAt, locale);
    const displayNumber = formatOrderNumber(order.number);
    const statusLabel = translations.statuses[order.status];

    return (
        <li
            className={cn(
                'account-order-row',
                isAttention && 'account-order-row--attention',
            )}
            data-status={order.status}
        >
            <span aria-hidden="true" className="account-order-row__mark">
                <StatusIcon />
            </span>
            <div className="account-order-row__main">
                <h3>{order.summary}</h3>
                <p className="account-order-row__meta">
                    <span dir="ltr" title={order.number}>
                        {displayNumber}
                    </span>
                    {' · '}
                    <time dateTime={order.placedAt}>{date}</time>
                    <span className="account-order-row__meta-status">
                        {' · '}
                        <span>{statusLabel}</span>
                    </span>
                </p>
            </div>
            <span
                className="account-order-row__status"
                data-status={order.status}
            >
                <span
                    aria-hidden="true"
                    className="account-order-row__status-dot"
                />
                {statusLabel}
            </span>
            <strong className="account-order-row__total">
                {formatAccountMoney(order.total, locale)}
            </strong>
            <Link
                className={cn(
                    'account-order-row__action',
                    isAttention && 'account-order-row__action--primary',
                )}
                href={order.detailUrl}
            >
                {actionText}
            </Link>
        </li>
    );
}
