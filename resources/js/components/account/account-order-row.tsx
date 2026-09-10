import { Link } from '@inertiajs/react';

import { formatAccountMoney } from '@/lib/account-money';
import { formatOrderDate, formatOrderNumber } from '@/lib/account-order-format';
import { formatInteger } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { AccountOrder, AccountTranslations } from '@/types/account';

/**
 * One order in the list: the service artwork (two stacked when the order
 * mixes services), what was bought, the number and date, the status, the
 * total, and for an unpaid order the one thing to do next.
 */
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
        order.status === 'pending_payment';
    // The card's one action names the next step: pay, or give the details
    // staff are waiting for. Anything else opens the order from its title.
    const action =
        order.action?.type ??
        (order.status === 'pending_payment'
            ? 'pay_now'
            : order.status === 'waiting_for_customer'
              ? 'provide_details'
              : 'view_order');
    const date = formatOrderDate(order.placedAt);
    const displayNumber = formatOrderNumber(order.number);
    const statusLabel = translations.statuses[order.status];
    const images = order.images.slice(0, 2);

    return (
        <li
            className={cn(
                'account-order-row',
                isAttention && 'account-order-row--attention',
            )}
            data-status={order.status}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'account-order-row__art',
                    images.length > 1 && 'account-order-row__art--stack',
                )}
            >
                {images.map((src) => (
                    <img alt="" height="48" key={src} src={src} width="48" />
                ))}
                {order.itemCount > 1 ? (
                    <b>{formatInteger(order.itemCount, locale)}</b>
                ) : null}
            </span>
            <div className="account-order-row__main">
                <h3>
                    <Link
                        className="account-order-row__title-link"
                        href={order.detailUrl}
                    >
                        {order.summary}
                    </Link>
                </h3>
                <p className="account-order-row__meta">
                    <bdi dir="ltr" title={order.number}>
                        {displayNumber}
                    </bdi>
                    {' · '}
                    <bdi>
                        <time dateTime={order.placedAt}>{date}</time>
                    </bdi>
                    {order.itemCount > 1 ? (
                        <>
                            {' · '}
                            <bdi>
                                {translations.orders.item_count.replace(
                                    ':count',
                                    formatInteger(order.itemCount, locale),
                                )}
                            </bdi>
                        </>
                    ) : null}
                </p>
            </div>
            <div className="account-order-row__side">
                <strong className="account-order-row__total">
                    {formatAccountMoney(order.total, locale)}
                </strong>
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
            </div>
            {isAttention ? (
                <Link
                    className="account-order-row__action"
                    href={order.detailUrl}
                >
                    {translations.actions[action]}
                </Link>
            ) : null}
        </li>
    );
}
