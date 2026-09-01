import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ShieldAlert,
    Sparkles,
    Trophy,
} from 'lucide-react';
import { useState } from 'react';

import AccountMetric from '@/components/account/account-metric';
import AccountOrderCard from '@/components/account/account-order-card';
import AccountOrderList from '@/components/account/account-order-list';
import AccountOrderRow from '@/components/account/account-order-row';
import { useResendCountdown } from '@/hooks/use-resend-countdown';
import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import type { AccountOverviewPageProps } from '@/types/account';

export default function AccountOverview() {
    const inertia = usePage<AccountOverviewPageProps>();
    const props = inertia.props;
    const Arrow = props.locale === 'ar' ? ArrowLeft : ArrowRight;
    const numberFormatter = new Intl.NumberFormat(props.locale);
    const hasOrders = props.summary.orderCount > 0;

    const user = props.auth?.user;
    // WhatsApp-first customers may have no email at all: the banner must only
    // target someone who has an address that is still unverified.
    const hasEmail = Boolean(user?.email);
    const isEmailUnverified = hasEmail && user?.email_verified_at === null;
    const emailAlert = props.accountUi.email_alert;
    const verificationSendUrl =
        props.locale === 'en' ? '/en/verify-email/send' : '/verify-email/send';
    const [isSendingVerification, setIsSendingVerification] = useState(false);
    const countdown = useResendCountdown(60);

    function sendVerificationEmail() {
        setIsSendingVerification(true);
        router.post(
            verificationSendUrl,
            {},
            {
                preserveScroll: true,
                onSuccess: () => countdown.start(60),
                onFinish: () => setIsSendingVerification(false),
            },
        );
    }

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
        props.activeOrder?.status === 'pending_payment';

    const activeOrderHeading = isActionNeeded
        ? props.accountUi.overview.active_order
        : (props.accountUi.overview.current_order ??
          props.accountUi.overview.active_order);

    return (
        <MyAccountLayout {...props} current="overview" currentUrl={inertia.url}>
            <Head title={props.accountUi.page_title} />
            <div className="account-overview">
                {isEmailUnverified && emailAlert ? (
                    <aside
                        aria-label={emailAlert.title}
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
                                <strong>{emailAlert.title}</strong>
                                <p>{emailAlert.desc}</p>
                                {props.status === 'verification-link-sent' ? (
                                    <p
                                        className="account-alert-banner__sent"
                                        role="status"
                                    >
                                        {emailAlert.sent ??
                                            (props.locale === 'en'
                                                ? 'We sent a verification link to your email.'
                                                : 'أرسلنا رابط التوثيق إلى بريدك.')}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                        {countdown.isActive ? (
                            <p
                                className="account-alert-banner__resend"
                                role="status"
                            >
                                {(
                                    emailAlert.resend_in ??
                                    (props.locale === 'en'
                                        ? 'Resend in :seconds s'
                                        : 'إعادة الإرسال بعد :seconds ث')
                                ).replace(
                                    ':seconds',
                                    String(countdown.countdown),
                                )}
                            </p>
                        ) : (
                            <button
                                className="account-alert-banner__action"
                                data-testid="send-verification-email"
                                disabled={isSendingVerification}
                                onClick={sendVerificationEmail}
                                type="button"
                            >
                                {emailAlert.action}
                            </button>
                        )}
                    </aside>
                ) : null}

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
                        accent
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
                        value={formatAccountMoney(
                            props.summary.walletBalance ?? {
                                amountMinor: '0',
                                currency: 'SAR',
                            },
                            props.locale,
                        )}
                    />
                </dl>

                {props.activeOrder === null ? null : (
                    <section
                        aria-labelledby="account-active-order-title"
                        className="account-overview__section"
                    >
                        <h2 id="account-active-order-title">
                            {activeOrderHeading}
                        </h2>
                        <AccountOrderCard
                            description={
                                isActionNeeded
                                    ? props.accountUi.overview
                                          .attention_description
                                    : undefined
                            }
                            locale={props.locale}
                            order={props.activeOrder}
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
                        <AccountOrderList className="account-order-list--compact">
                            {visibleRecentOrders.map((order) => (
                                <AccountOrderRow
                                    key={order.id}
                                    locale={props.locale}
                                    order={order}
                                    translations={props.accountUi}
                                />
                            ))}
                        </AccountOrderList>
                    </section>
                ) : null}

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
                        <div className="account-overview__loyalty-actions">
                            <Link
                                className="account-overview__loyalty-link"
                                href={
                                    props.locale === 'en'
                                        ? '/en/my-account/loyalty'
                                        : '/my-account/loyalty'
                                }
                            >
                                {props.accountUi.overview.view_loyalty ??
                                    'عرض برنامج الولاء'}
                                <Arrow aria-hidden="true" />
                            </Link>
                        </div>
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
