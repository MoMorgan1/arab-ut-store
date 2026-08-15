import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Sparkles, Trophy } from 'lucide-react';

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

    return (
        <MyAccountLayout {...props} current="overview" currentUrl={inertia.url}>
            <Head title={props.accountUi.page_title} />
            <div className="account-overview">
                <header className="account-overview__intro">
                    <div>
                        <p>{props.accountUi.eyebrow}</p>
                        <h2>{props.accountUi.overview.title}</h2>
                        <span>{props.accountUi.overview.description}</span>
                    </div>
                    <Link
                        className="account-overview__browse"
                        href={props.storeShell.coinsUrl}
                    >
                        {props.accountUi.overview.browse_services}
                        <Arrow aria-hidden="true" />
                    </Link>
                </header>

                <section
                    aria-labelledby="account-welcome-title"
                    className="account-overview__welcome"
                >
                    <span aria-hidden="true">
                        <Sparkles />
                    </span>
                    <div>
                        <h3 id="account-welcome-title">
                            {props.accountIdentity.greeting}
                        </h3>
                        <p>{props.accountUi.introduction}</p>
                    </div>
                </section>

                {props.activeOrder === null ? null : (
                    <section
                        aria-labelledby="account-active-order-title"
                        className="account-overview__section"
                    >
                        <h2 id="account-active-order-title">
                            {props.accountUi.overview.active_order}
                        </h2>
                        <AccountOrderCard
                            locale={props.locale}
                            order={props.activeOrder}
                            prominent
                            translations={props.accountUi}
                        />
                    </section>
                )}

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
                                ? '—'
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

                {!hasOrders ? (
                    <section className="account-overview__empty">
                        <span aria-hidden="true">
                            <Sparkles />
                        </span>
                        <h2>{props.accountUi.overview.empty_title}</h2>
                        <p>{props.accountUi.overview.empty_description}</p>
                        <Link href={props.storeShell.coinsUrl}>
                            {props.accountUi.overview.browse_services}
                            <Arrow aria-hidden="true" />
                        </Link>
                    </section>
                ) : (
                    <section
                        aria-label={props.accountUi.overview.recent_orders}
                        className="account-overview__section"
                    >
                        <h2>{props.accountUi.overview.recent_orders}</h2>
                        <div className="account-overview__orders">
                            {props.recentOrders.map((order) => (
                                <AccountOrderCard
                                    key={order.id}
                                    locale={props.locale}
                                    order={order}
                                    translations={props.accountUi}
                                />
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </MyAccountLayout>
    );
}
