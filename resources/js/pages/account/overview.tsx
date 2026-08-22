import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ShieldAlert,
    Sparkles,
    Trophy,
    WalletCards,
} from 'lucide-react';

import AccountMetric from '@/components/account/account-metric';
import AccountOrderCard from '@/components/account/account-order-card';
import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import type { AccountOverviewPageProps } from '@/types/account';

export default function AccountOverview() {
    const inertia = usePage<AccountOverviewPageProps>();
    const props = inertia.props;
    const Arrow = props.locale === 'ar' ? ArrowLeft : ArrowRight;
    const numberFormatter = new Intl.NumberFormat(props.locale);
    const hasOrders = props.summary.orderCount > 0;

    // Filter recent orders to ensure activeOrder is never duplicated
    const visibleRecentOrders = props.recentOrders
        .filter(
            (order) =>
                order.id !== props.activeOrder?.id &&
                order.number !== props.activeOrder?.number,
        )
        .slice(0, 3);

    const ordersUrl =
        props.accountNavigation.find((n) => n.key === 'orders')?.url ??
        (props.locale === 'en'
            ? '/en/my-account/orders'
            : '/my-account/orders');

    const isActionNeeded =
        props.activeOrder?.status === 'waiting_for_customer' ||
        props.activeOrder?.status === 'failed' ||
        props.activeOrder?.status === 'pending_payment';

    const activeOrderHeading = isActionNeeded
        ? props.accountUi.overview.active_order
        : (props.accountUi.overview.current_order ??
          props.accountUi.overview.active_order);

    const isEmailUnverified = props.auth?.user?.email_verified_at === null;

    return (
        <MyAccountLayout {...props} current="overview" currentUrl={inertia.url}>
            <Head title={props.accountUi.page_title} />
            <div className="account-overview">
                {isEmailUnverified && props.accountUi.email_alert ? (
                    <aside
                        aria-label={props.accountUi.email_alert.title}
                        className="account-alert-banner"
                    >
                        <div className="account-alert-banner__content">
                            <span
                                aria-hidden="true"
                                className="account-alert-banner__icon"
                            >
                                <ShieldAlert />
                            </span>
                            <div>
                                <strong>
                                    {props.accountUi.email_alert.title}
                                </strong>
                                <p>{props.accountUi.email_alert.desc}</p>
                            </div>
                        </div>
                        <Link
                            className="account-alert-banner__action"
                            href={
                                props.locale === 'en'
                                    ? '/en/verify-email'
                                    : '/verify-email'
                            }
                        >
                            {props.accountUi.email_alert.action}
                        </Link>
                    </aside>
                ) : null}

                {props.activeOrder === null ? null : (
                    <section
                        aria-labelledby="account-active-order-title"
                        className="account-overview__section"
                    >
                        <h2 id="account-active-order-title">
                            {activeOrderHeading}
                        </h2>
                        <AccountOrderCard
                            locale={props.locale}
                            order={props.activeOrder}
                            prominent
                            translations={props.accountUi}
                        />
                    </section>
                )}

                {visibleRecentOrders.length > 0 ? (
                    <section
                        aria-labelledby="account-recent-orders-title"
                        className="account-overview__section"
                    >
                        <div className="account-overview__section-heading">
                            <h2 id="account-recent-orders-title">
                                {props.accountUi.overview.recent_orders}
                            </h2>
                            <Link
                                className="account-overview__view-all"
                                href={ordersUrl}
                            >
                                {props.accountUi.overview.view_all ??
                                    'عرض الكل'}
                                <Arrow aria-hidden="true" />
                            </Link>
                        </div>
                        <div className="account-overview__orders">
                            {visibleRecentOrders.map((order) => (
                                <AccountOrderCard
                                    key={order.id}
                                    locale={props.locale}
                                    order={order}
                                    translations={props.accountUi}
                                />
                            ))}
                        </div>
                    </section>
                ) : null}

                <section
                    aria-labelledby="account-wallet-overview-title"
                    className="account-overview__wallet-card"
                >
                    <div className="account-overview__wallet-header">
                        <div className="account-overview__wallet-info">
                            <span
                                aria-hidden="true"
                                className="account-overview__wallet-icon"
                            >
                                <WalletCards />
                            </span>
                            <div>
                                <h2 id="account-wallet-overview-title">
                                    {props.accountUi.overview.wallet_metric}
                                </h2>
                                <p>
                                    {props.accountUi.wallet
                                        ?.coming_soon_notice ??
                                        'ستظهر أرصدتك وعمليات الاسترداد هنا عند إطلاق المحفظة.'}
                                </p>
                            </div>
                        </div>
                        <span className="account-overview__coming-soon-badge">
                            {props.accountUi.wallet?.coming_soon ?? 'قريبًا'}
                        </span>
                    </div>
                </section>

                <dl
                    aria-label={props.accountUi.overview.title}
                    className="account-overview__metrics"
                >
                    <AccountMetric
                        kind="orders"
                        label={props.accountUi.overview.orders_metric}
                        value={numberFormatter.format(props.summary.orderCount)}
                    />
                    <AccountMetric
                        kind="open"
                        label={props.accountUi.overview.open_orders_metric}
                        value={numberFormatter.format(
                            props.summary.openOrderCount,
                        )}
                    />
                    <AccountMetric
                        kind="completed"
                        label={props.accountUi.overview.completed_orders_metric}
                        value={numberFormatter.format(
                            props.summary.completedOrderCount,
                        )}
                    />
                    <AccountMetric
                        kind="wallet"
                        label={props.accountUi.overview.wallet_metric}
                        value={
                            props.summary.walletBalance === null
                                ? (props.accountUi.wallet?.coming_soon ??
                                  'قريبًا')
                                : formatAccountMoney(
                                      props.summary.walletBalance,
                                      props.locale,
                                  )
                        }
                    />
                </dl>

                {props.loyalty === null ? null : (
                    <section
                        aria-labelledby="account-loyalty-title"
                        className="account-overview__loyalty"
                    >
                        <div className="account-overview__loyalty-heading">
                            <span aria-hidden="true">
                                <Trophy />
                            </span>
                            <div>
                                <h2 id="account-loyalty-title">
                                    {props.accountUi.overview.loyalty}
                                </h2>
                                <p>{props.loyalty.currentTier?.name ?? '—'}</p>
                            </div>
                            <strong>{props.loyalty.progressPercent}%</strong>
                        </div>
                        <div
                            aria-label={props.accountUi.overview.loyalty}
                            aria-valuemax={100}
                            aria-valuemin={0}
                            aria-valuenow={props.loyalty.progressPercent}
                            className="account-overview__progress"
                            role="progressbar"
                        >
                            <span
                                style={{
                                    inlineSize: `${props.loyalty.progressPercent}%`,
                                }}
                            />
                        </div>
                        <p className="account-overview__loyalty-copy">
                            {props.loyalty.nextTier === null ||
                            props.loyalty.remaining === null
                                ? props.accountUi.overview.loyalty_complete
                                : props.accountUi.overview.loyalty_remaining
                                      .replace(
                                          ':amount',
                                          formatAccountMoney(
                                              props.loyalty.remaining,
                                              props.locale,
                                          ),
                                      )
                                      .replace(
                                          ':tier',
                                          props.loyalty.nextTier.name,
                                      )}
                        </p>
                    </section>
                )}

                {!hasOrders && props.activeOrder === null ? (
                    <section className="account-overview__empty">
                        <span aria-hidden="true">
                            <Sparkles />
                        </span>
                        <h2>{props.accountUi.overview.empty_title}</h2>
                        <p>{props.accountUi.overview.empty_description}</p>
                        <Link
                            className="account-overview__empty-cta"
                            href={props.storeShell.coinsUrl}
                        >
                            {props.accountUi.overview.browse_services}
                            <Arrow aria-hidden="true" />
                        </Link>
                    </section>
                ) : null}
            </div>
        </MyAccountLayout>
    );
}
