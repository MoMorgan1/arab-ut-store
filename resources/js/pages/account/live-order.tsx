import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ChevronDown,
    Copy,
    Info,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import OrderReviewCard from '@/components/account/order-review-card';
import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import { DATE_LOCALE } from '@/lib/date-locale';
import { formatInteger } from '@/lib/money';
import { loadOrderCredentials } from '@/lib/order-fulfillment-api';
import type { OrderCredentials } from '@/lib/order-fulfillment-api';
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
    const placedAt = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(props.order.placedAt));

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
                title={`${props.accountUi.orders.title} · ${props.order.number}`}
            />
            <div className="account-live-order">
                <Link className="account-live-order__back" href={ordersUrl}>
                    <BackArrow aria-hidden="true" />
                    {props.accountUi.orders.back}
                </Link>

                <section
                    aria-labelledby="account-order-invoice-title"
                    className={
                        props.order.status === 'pending_payment'
                            ? 'account-invoice account-invoice--request'
                            : 'account-invoice'
                    }
                >
                    {props.order.status === 'pending_payment' ? (
                        <header className="account-invoice__head">
                            <h2 id="account-order-invoice-title">
                                {props.accountUi.invoice.request_title}
                            </h2>
                            <dl className="account-invoice__meta">
                                <div>
                                    <dt>{props.accountUi.orders.number}</dt>
                                    <dd>
                                        <bdi>{props.order.number}</bdi>
                                    </dd>
                                </div>
                                <div>
                                    <dt>{props.accountUi.orders.placed_at}</dt>
                                    <dd>
                                        <time dateTime={props.order.placedAt}>
                                            {placedAt}
                                        </time>
                                    </dd>
                                </div>
                            </dl>
                        </header>
                    ) : (
                        <header className="account-invoice__head">
                            <div className="account-invoice__brand">
                                <p>{props.accountUi.invoice.title}</p>
                                <h2 id="account-order-invoice-title">
                                    {props.accountUi.invoice.store_name}
                                </h2>
                                <span className="account-invoice__freelance">
                                    {props.accountUi.invoice.freelance_label}{' '}
                                    <bdi dir="ltr">FL-621205220</bdi>
                                </span>
                            </div>
                            {props.order.status === 'cancelled' ||
                            props.order.status === 'refunded' ? (
                                <p
                                    className="account-invoice__mark"
                                    data-status={props.order.status}
                                >
                                    <span aria-hidden="true" />
                                    {
                                        props.accountUi.statuses[
                                            props.order.status
                                        ]
                                    }
                                </p>
                            ) : null}
                            <dl className="account-invoice__meta">
                                <div>
                                    <dt>{props.accountUi.orders.number}</dt>
                                    <dd>
                                        <bdi>{props.order.number}</bdi>
                                    </dd>
                                </div>
                                <div>
                                    <dt>{props.accountUi.orders.placed_at}</dt>
                                    <dd>
                                        <time dateTime={props.order.placedAt}>
                                            {placedAt}
                                        </time>
                                    </dd>
                                </div>
                            </dl>
                        </header>
                    )}

                    <ol className="account-invoice__items">
                        {props.order.items.map((item) => (
                            <li key={item.id}>
                                {item.imageUrl ? (
                                    <img
                                        alt=""
                                        height="44"
                                        loading="lazy"
                                        src={item.imageUrl}
                                        width="44"
                                    />
                                ) : (
                                    <span
                                        aria-hidden="true"
                                        className="account-invoice__item-image"
                                    />
                                )}
                                <div className="account-invoice__item-main">
                                    <h3>{item.name}</h3>
                                    <span>
                                        {platformName(
                                            item.platform,
                                            props.accountUi.orders,
                                        )}
                                    </span>
                                    <small>
                                        {props.accountUi.orders.item_quantity.replace(
                                            ':count',
                                            formatInteger(
                                                item.quantity,
                                                props.locale,
                                            ),
                                        )}
                                    </small>
                                </div>
                                <strong className="account-invoice__item-total">
                                    {formatAccountMoney(
                                        item.total,
                                        props.locale,
                                    )}
                                </strong>
                            </li>
                        ))}
                    </ol>

                    {props.order.status === 'pending_payment' ? (
                        <>
                            <dl className="account-invoice__totals">
                                <div className="account-invoice__grand">
                                    <dt>
                                        {props.accountUi.invoice.amount_due}
                                    </dt>
                                    <dd>
                                        {formatAccountMoney(
                                            props.order.paymentAmount,
                                            props.locale,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                            {props.order.paymentStartUrl === null ? null : (
                                <button
                                    className="account-invoice__pay"
                                    disabled={paymentState === 'loading'}
                                    onClick={resumePayment}
                                    type="button"
                                >
                                    {paymentState === 'loading'
                                        ? props.accountUi.orders.refreshing
                                        : props.accountUi.invoice.pay_action}
                                </button>
                            )}
                        </>
                    ) : (
                        <>
                            <dl className="account-invoice__totals">
                                <div>
                                    <dt>{props.accountUi.invoice.subtotal}</dt>
                                    <dd>
                                        {formatAccountMoney(
                                            props.order.subtotal,
                                            props.locale,
                                        )}
                                    </dd>
                                </div>
                                {props.order.discount.amountMinor !== '0' ? (
                                    <div>
                                        <dt>
                                            {props.accountUi.orders.discount}
                                        </dt>
                                        <dd className="account-invoice__deduction">
                                            <bdi dir="ltr">
                                                -
                                                {formatAccountMoney(
                                                    props.order.discount,
                                                    props.locale,
                                                )}
                                            </bdi>
                                        </dd>
                                    </div>
                                ) : null}
                                {props.order.walletPayment &&
                                props.order.walletPayment.amountMinor !==
                                    '0' ? (
                                    <div>
                                        <dt>
                                            {
                                                props.accountUi.invoice
                                                    .wallet_deduction
                                            }
                                        </dt>
                                        <dd>
                                            {formatAccountMoney(
                                                props.order.walletPayment,
                                                props.locale,
                                            )}
                                        </dd>
                                    </div>
                                ) : null}
                                <div className="account-invoice__grand">
                                    <dt>
                                        {props.accountUi.invoice.total_paid}
                                    </dt>
                                    <dd>
                                        {formatAccountMoney(
                                            props.order.total,
                                            props.locale,
                                        )}
                                    </dd>
                                </div>
                            </dl>

                            {props.order.paymentMethod != null ? (
                                <p className="account-invoice__method">
                                    {props.accountUi.invoice.payment_method}
                                    {': '}
                                    {
                                        props.accountUi.invoice.methods[
                                            props.order.paymentMethod
                                        ]
                                    }
                                    {props.order.paymentMethod !== 'wallet' &&
                                    props.order.walletPayment &&
                                    props.order.walletPayment.amountMinor !==
                                        '0' ? (
                                        <>
                                            {' · '}
                                            <bdi>
                                                {formatAccountMoney(
                                                    props.order.paymentAmount,
                                                    props.locale,
                                                )}
                                            </bdi>
                                        </>
                                    ) : null}
                                </p>
                            ) : null}
                        </>
                    )}
                </section>

                <div className="account-live-order__statusbar">
                    <div aria-live="polite">
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

                {props.order.review !== null ? (
                    <OrderReviewCard
                        customerName={props.accountIdentity.name}
                        locale={props.locale === 'en' ? 'en' : 'ar'}
                        review={props.order.review}
                        translations={props.accountUi.orders.review}
                    />
                ) : null}

                {props.order.statusNote ? (
                    <aside
                        aria-labelledby="account-order-status-note-title"
                        className="account-live-order__status-note"
                    >
                        <Info aria-hidden="true" />
                        <div>
                            <h2 id="account-order-status-note-title">
                                {props.accountUi.orders.status_note_title}
                            </h2>
                            <p>{props.order.statusNote}</p>
                        </div>
                    </aside>
                ) : null}

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
                                {item.manualFulfillment !== null ? (
                                    <ManualOrderFulfillment
                                        item={item}
                                        locale={props.locale}
                                        translations={props.accountUi.orders}
                                    />
                                ) : null}
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

type LiveOrderItem = AccountLiveOrderPageProps['order']['items'][number];
type OrderTranslations = AccountLiveOrderPageProps['accountUi']['orders'];

function ManualOrderFulfillment({
    item,
    locale,
    translations,
}: {
    item: LiveOrderItem;
    locale: 'ar' | 'en';
    translations: OrderTranslations;
}) {
    const fulfillment = item.manualFulfillment;
    const [expanded, setExpanded] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [credentials, setCredentials] = useState<OrderCredentials | null>(
        null,
    );

    if (fulfillment === null) {
        return null;
    }

    const contentId = `order-fulfillment-${item.id}`;
    const credentialsUrl = fulfillment.credentialsUrl;
    const platform =
        fulfillment.platform === 'playstation'
            ? translations.platform_playstation
            : translations.platform_pc;

    async function toggleDetails() {
        if (expanded) {
            setExpanded(false);

            return;
        }

        setExpanded(true);
        setFailed(false);

        if (credentials !== null || credentialsUrl === null) {
            return;
        }

        setLoading(true);

        try {
            setCredentials(await loadOrderCredentials(credentialsUrl));
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="account-order-fulfillment">
            <h4>{translations.manual_details}</h4>
            <dl className="account-order-fulfillment__summary">
                <OrderFact label={translations.platform} value={platform} />
                {fulfillment.pcLauncher !== undefined ? (
                    <OrderFact
                        label={translations.launcher}
                        value={
                            fulfillment.pcLauncher === 'steam'
                                ? translations.launcher_steam
                                : translations.launcher_ea_app
                        }
                    />
                ) : null}
                {fulfillment.targetRank !== undefined ? (
                    <OrderFact
                        label={translations.rank}
                        value={translations.rank_value.replace(
                            ':rank',
                            formatInteger(fulfillment.targetRank, locale),
                        )}
                    />
                ) : null}
                {fulfillment.urgent !== undefined ? (
                    <OrderFact
                        label={translations.urgent}
                        value={
                            fulfillment.urgent
                                ? translations.urgent_yes
                                : translations.urgent_no
                        }
                    />
                ) : null}
                {fulfillment.matchesPlayed !== undefined ? (
                    <OrderFact
                        label={translations.matches_played}
                        value={formatInteger(fulfillment.matchesPlayed, locale)}
                    />
                ) : null}
                {fulfillment.weeklyMatches ? (
                    <OrderFact
                        label={translations.mode}
                        value={translations.mode_weekly}
                    />
                ) : null}
                {fulfillment.includedWins !== undefined ? (
                    <OrderFact
                        label={translations.included_wins}
                        value={formatInteger(fulfillment.includedWins, locale)}
                    />
                ) : null}
                {fulfillment.fromDivision !== undefined ? (
                    <OrderFact
                        label={translations.from_division}
                        value={divisionName(
                            fulfillment.fromDivision,
                            locale,
                            translations,
                        )}
                    />
                ) : null}
                {fulfillment.toDivision !== undefined ? (
                    <OrderFact
                        label={translations.to_division}
                        value={divisionName(
                            fulfillment.toDivision,
                            locale,
                            translations,
                        )}
                    />
                ) : null}
            </dl>
            {fulfillment.credentialsUrl !== null ? (
                <button
                    aria-controls={contentId}
                    aria-expanded={expanded}
                    className="account-order-fulfillment__toggle"
                    disabled={loading}
                    onClick={toggleDetails}
                    type="button"
                >
                    <span>
                        <ShieldCheck aria-hidden="true" />
                        {loading
                            ? translations.credentials_loading
                            : expanded
                              ? translations.hide_credentials
                              : translations.show_credentials}
                    </span>
                    <ChevronDown aria-hidden="true" />
                </button>
            ) : null}
            {expanded ? (
                <div
                    className="account-order-fulfillment__revealed"
                    id={contentId}
                >
                    {failed ? (
                        <p role="alert">{translations.credentials_error}</p>
                    ) : null}
                    {credentials !== null ? (
                        <CredentialsValues
                            credentials={credentials}
                            translations={translations}
                        />
                    ) : null}
                    {fulfillment.squadImageUrl !== null ? (
                        <figure>
                            <figcaption>{translations.squad_image}</figcaption>
                            <img
                                alt={translations.squad_image}
                                loading="lazy"
                                src={fulfillment.squadImageUrl}
                            />
                        </figure>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

function CredentialsValues({
    credentials,
    translations,
}: {
    credentials: OrderCredentials;
    translations: OrderTranslations;
}) {
    const eaCodes = credentials.eaBackupCodes;
    const copyLabels = { copied: translations.copied, copy: translations.copy };

    return (
        <div className="account-order-fulfillment__credentials" dir="ltr">
            {credentials.platform === 'playstation' ? (
                <>
                    <CredentialFact
                        copyLabels={copyLabels}
                        label={translations.playstation_email}
                        value={credentials.playstationEmail}
                    />
                    <CredentialFact
                        copyLabels={copyLabels}
                        label={translations.playstation_password}
                        value={credentials.playstationPassword}
                    />
                </>
            ) : (
                <>
                    <CredentialFact
                        copyLabels={copyLabels}
                        label={translations.ea_email}
                        value={credentials.eaEmail}
                    />
                    <CredentialFact
                        copyLabels={copyLabels}
                        label={translations.ea_password}
                        value={credentials.eaPassword}
                    />
                    {credentials.pcStore === 'steam' ? (
                        <>
                            <CredentialFact
                                copyLabels={copyLabels}
                                label={translations.steam_username}
                                value={credentials.steamUsername ?? ''}
                            />
                            <CredentialFact
                                copyLabels={copyLabels}
                                label={translations.steam_password}
                                value={credentials.steamPassword ?? ''}
                            />
                        </>
                    ) : null}
                </>
            )}
            <CodesFact
                copyLabels={copyLabels}
                label={translations.ea_codes}
                values={eaCodes}
            />
            {credentials.platform === 'playstation' ? (
                <CodesFact
                    copyLabels={copyLabels}
                    label={translations.playstation_codes}
                    values={credentials.playstationBackupCodes}
                />
            ) : null}
        </div>
    );
}

type CopyLabels = { copied: string; copy: string };

function CopyButton({
    ariaLabel,
    labels,
    value,
}: {
    ariaLabel: string;
    labels: CopyLabels;
    value: string;
}) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 2000);

        return () => window.clearTimeout(timer);
    }, [copied]);

    function copy() {
        if (typeof navigator.clipboard?.writeText !== 'function') {
            return;
        }

        navigator.clipboard
            .writeText(value)
            .then(() => setCopied(true))
            .catch(() => {});
    }

    return (
        <button
            aria-label={`${ariaLabel} — ${copied ? labels.copied : labels.copy}`}
            className="account-order-fulfillment__copy"
            onClick={copy}
            type="button"
        >
            <Copy aria-hidden="true" />
            {copied ? labels.copied : labels.copy}
        </button>
    );
}

function CredentialFact({
    copyLabels,
    label,
    value,
}: {
    copyLabels: CopyLabels;
    label: string;
    value: string;
}) {
    return (
        <div>
            <span>{label}</span>
            <div className="account-order-fulfillment__value-row">
                <bdi>{value}</bdi>
                <CopyButton
                    ariaLabel={label}
                    labels={copyLabels}
                    value={value}
                />
            </div>
        </div>
    );
}

function CodesFact({
    copyLabels,
    label,
    values,
}: {
    copyLabels: CopyLabels;
    label: string;
    values: [string, string, string];
}) {
    return (
        <div>
            <span>{label}</span>
            <div className="account-order-fulfillment__codes">
                {values.map((code, index) => (
                    <span
                        className="account-order-fulfillment__code"
                        key={`${index}-${code}`}
                    >
                        <bdi>{code}</bdi>
                        <CopyButton
                            ariaLabel={label}
                            labels={copyLabels}
                            value={code}
                        />
                    </span>
                ))}
            </div>
        </div>
    );
}

function OrderFact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}

function divisionName(
    value: NonNullable<
        NonNullable<LiveOrderItem['manualFulfillment']>['fromDivision']
    >,
    locale: 'ar' | 'en',
    translations: OrderTranslations,
): string {
    return value === 'elite'
        ? translations.elite
        : formatInteger(Number(value), locale);
}

function platformName(
    value: LiveOrderItem['platform'],
    translations: OrderTranslations,
): string {
    if (value === 'playstation') {
        return translations.platform_playstation;
    }

    return value === 'xbox'
        ? translations.platform_xbox
        : translations.platform_pc;
}
