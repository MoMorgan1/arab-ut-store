import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { formatAccountMoney } from '@/lib/account-money';
import { formatOrderAge, formatOrderNumber } from '@/lib/account-order-format';
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
    const age = formatOrderAge(order.placedAt, locale);
    const displayNumber = formatOrderNumber(order.number);
    const statusLabel = translations.statuses[order.status];
    const images = order.images.slice(0, 2);
    // The order number is the title and the lines wait behind one "details"
    // button that opens them in place (owner decision, 2026-09-10).
    const [showItems, setShowItems] = useState(false);
    const itemsId = `account-order-items-${order.id}`;

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
                        title={order.number}
                    >
                        <bdi dir="ltr">{displayNumber}</bdi>
                    </Link>
                </h3>
                <p className="account-order-row__meta">
                    <time dateTime={order.placedAt}>{age}</time>
                    {' · '}
                    <bdi>
                        {order.itemCount === 1
                            ? translations.orders.item_count_one
                            : translations.orders.item_count.replace(
                                  ':count',
                                  formatInteger(order.itemCount, locale),
                              )}
                    </bdi>
                </p>
                <button
                    aria-controls={itemsId}
                    aria-expanded={showItems}
                    className="account-order-row__more"
                    onClick={() => setShowItems((value) => !value)}
                    type="button"
                >
                    {showItems
                        ? translations.orders.hide_details
                        : translations.orders.details}
                </button>
                {showItems ? (
                    <ul className="account-order-row__items" id={itemsId}>
                        {order.items.map((item, index) => (
                            <li key={`${index}-${item.name}`}>{item.name}</li>
                        ))}
                    </ul>
                ) : null}
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
