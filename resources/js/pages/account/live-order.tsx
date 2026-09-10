import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ChevronDown,
    Copy,
    Info,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import OrderReviewCard from '@/components/account/order-review-card';
import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import { trackBeginCheckout, trackPurchase } from '@/lib/analytics';
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
import { cn } from '@/lib/utils';
import type { AccountLiveOrderPageProps } from '@/types/account';

/**
 * How often an open order asks the server where it is. Replaces the old
 * "refresh status" button (owner decision, 2026-09-10): the page keeps itself
 * current and the customer never has to press anything.
 */
export const ORDER_REFRESH_INTERVAL_MS = 30_000;

type LiveOrderItem = AccountLiveOrderPageProps['order']['items'][number];
type OrderTranslations = AccountLiveOrderPageProps['accountUi']['orders'];

export default function AccountLiveOrder() {
    const page = usePage<AccountLiveOrderPageProps>();
    const props = page.props;
    const BackArrow = props.locale === 'ar' ? ArrowRight : ArrowLeft;
    const [paymentState, setPaymentState] = useState<
        'idle' | 'loading' | 'error'
    >('idle');
    const [cancelState, setCancelState] = useState<
        'idle' | 'confirming' | 'loading'
    >('idle');
    const ordersUrl =
        props.accountNavigation.find((item) => item.key === 'orders')?.url ??
        props.storeShell.accountUrl;
    const placedAt = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(props.order.placedAt));
    const order = props.order;
    const pending = order.status === 'pending_payment';
    const closed = order.status === 'cancelled' || order.status === 'refunded';
    const tracked = !pending && !closed;
    const ui = props.accountUi;

    // Fired once per order per browser; the module keeps the id set that
    // stops a reload from counting a second sale.
    useEffect(() => {
        if (order.analytics !== null) {
            trackPurchase(order.analytics);
        }
    }, [order.analytics]);

    // Silent refresh while the order can still move. Pauses in a background
    // tab so a forgotten page does not poll all night.
    useEffect(() => {
        if (!order.refreshable) {
            return;
        }

        const tick = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['order'] });
            }
        };
        const timer = window.setInterval(tick, ORDER_REFRESH_INTERVAL_MS);

        return () => window.clearInterval(timer);
    }, [order.refreshable, order.id]);

    async function resumePayment() {
        if (order.paymentStartUrl === null || paymentState === 'loading') {
            return;
        }

        setPaymentState('loading');

        try {
            const checkout = await resumePaylinkCheckout(order.paymentStartUrl);

            trackBeginCheckout(
                order.items.map((item) => ({
                    id: item.id,
                    name: item.name,
                    price: Number(item.total.amountMinor) / 100 / item.quantity,
                    quantity: item.quantity,
                })),
                Number(order.paymentAmount.amountMinor) / 100,
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

    function cancelOrder() {
        if (order.cancelUrl === null || cancelState === 'loading') {
            return;
        }

        setCancelState('loading');
        router.post(
            order.cancelUrl,
            {},
            {
                preserveScroll: true,
                onFinish: () => setCancelState('idle'),
            },
        );
    }

    return (
        <MyAccountLayout {...props} current="orders" currentUrl={page.url}>
            <Head title={`${ui.orders.title} · ${order.number}`} />
            <div
                className={cn(
                    'account-live-order',
                    pending && 'account-live-order--pending',
                )}
            >
                <Link className="account-live-order__back" href={ordersUrl}>
                    <BackArrow aria-hidden="true" />
                    {ui.orders.back}
                </Link>

                <section
                    aria-labelledby="account-order-invoice-title"
                    className={cn(
                        'account-invoice',
                        pending && 'account-invoice--request',
                    )}
                >
                    <header className="account-invoice__head">
                        <div className="account-invoice__brand">
                            <p>
                                {pending
                                    ? ui.invoice.request_title
                                    : `${ui.invoice.title} · ${ui.invoice.store_name}`}
                            </p>
                            <h2 id="account-order-invoice-title">
                                <bdi>{order.number}</bdi>
                            </h2>
                            <span className="account-invoice__meta">
                                <time dateTime={order.placedAt}>
                                    {placedAt}
                                </time>
                                {!pending && !closed ? (
                                    <>
                                        {' · '}
                                        {ui.invoice.freelance_label}{' '}
                                        <bdi dir="ltr">FL-621205220</bdi>
                                    </>
                                ) : null}
                            </span>
                        </div>
                        {pending || closed ? (
                            <p
                                className="account-invoice__mark"
                                data-status={order.status}
                            >
                                <span aria-hidden="true" />
                                {ui.statuses[order.status]}
                            </p>
                        ) : null}
                    </header>

                    {tracked ? (
                        <StatusTrack
                            status={order.status}
                            translations={ui.orders}
                        />
                    ) : null}

                    <ol className="account-invoice__items">
                        {order.items.map((item) => (
                            <InvoiceItem
                                item={item}
                                key={item.id}
                                locale={props.locale}
                                translations={ui.orders}
                            />
                        ))}
                    </ol>

                    <dl className="account-invoice__totals">
                        {pending ? (
                            <>
                                {order.walletPayment &&
                                order.walletPayment.amountMinor !== '0' ? (
                                    <>
                                        <div>
                                            <dt>{ui.invoice.subtotal}</dt>
                                            <dd>
                                                {formatAccountMoney(
                                                    order.total,
                                                    props.locale,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>
                                                {ui.invoice.wallet_deduction}
                                            </dt>
                                            <dd className="account-invoice__deduction">
                                                <bdi dir="ltr">
                                                    -
                                                    {formatAccountMoney(
                                                        order.walletPayment,
                                                        props.locale,
                                                    )}
                                                </bdi>
                                            </dd>
                                        </div>
                                    </>
                                ) : null}
                                <div className="account-invoice__grand">
                                    <dt>{ui.invoice.amount_due}</dt>
                                    <dd>
                                        {formatAccountMoney(
                                            order.paymentAmount,
                                            props.locale,
                                        )}
                                    </dd>
                                </div>
                            </>
                        ) : (
                            <>
                                <div>
                                    <dt>{ui.invoice.subtotal}</dt>
                                    <dd>
                                        {formatAccountMoney(
                                            order.subtotal,
                                            props.locale,
                                        )}
                                    </dd>
                                </div>
                                {order.discount.amountMinor !== '0' ? (
                                    <div>
                                        <dt>{ui.orders.discount}</dt>
                                        <dd className="account-invoice__deduction">
                                            <bdi dir="ltr">
                                                -
                                                {formatAccountMoney(
                                                    order.discount,
                                                    props.locale,
                                                )}
                                            </bdi>
                                        </dd>
                                    </div>
                                ) : null}
                                {order.walletPayment &&
                                order.walletPayment.amountMinor !== '0' ? (
                                    <div>
                                        <dt>{ui.invoice.wallet_deduction}</dt>
                                        <dd>
                                            {formatAccountMoney(
                                                order.walletPayment,
                                                props.locale,
                                            )}
                                        </dd>
                                    </div>
                                ) : null}
                                <div className="account-invoice__grand">
                                    <dt>
                                        {closed
                                            ? ui.orders.total
                                            : ui.invoice.total_paid}
                                    </dt>
                                    <dd>
                                        {formatAccountMoney(
                                            order.total,
                                            props.locale,
                                        )}
                                    </dd>
                                </div>
                            </>
                        )}
                    </dl>

                    {!pending && order.paymentMethod != null ? (
                        <p className="account-invoice__method">
                            {ui.invoice.payment_method}
                            {': '}
                            {ui.invoice.methods[order.paymentMethod]}
                            {order.paymentMethod !== 'wallet' &&
                            order.walletPayment &&
                            order.walletPayment.amountMinor !== '0' ? (
                                <>
                                    {' · '}
                                    <bdi>
                                        {formatAccountMoney(
                                            order.paymentAmount,
                                            props.locale,
                                        )}
                                    </bdi>
                                </>
                            ) : null}
                        </p>
                    ) : null}
                </section>

                {order.statusNote ? (
                    <aside
                        aria-labelledby="account-order-status-note-title"
                        className="account-live-order__status-note"
                    >
                        <Info aria-hidden="true" />
                        <div>
                            <h2 id="account-order-status-note-title">
                                {closed
                                    ? ui.orders.closed_title
                                    : ui.orders.team_note_title}
                            </h2>
                            <p>{order.statusNote}</p>
                        </div>
                    </aside>
                ) : null}

                {order.review !== null ? (
                    <OrderReviewCard
                        customerName={props.accountIdentity.name}
                        locale={props.locale === 'en' ? 'en' : 'ar'}
                        review={order.review}
                        translations={ui.orders.review}
                    />
                ) : null}

                {pending && order.paymentStartUrl !== null ? (
                    <div className="account-live-order__actions">
                        {cancelState === 'confirming' ? (
                            <div
                                className="account-live-order__confirm"
                                role="alertdialog"
                                aria-labelledby="account-order-cancel-title"
                            >
                                <p id="account-order-cancel-title">
                                    {ui.orders.cancel_confirm}
                                </p>
                                <div>
                                    <button
                                        className="account-live-order__cancel account-live-order__cancel--confirm"
                                        onClick={cancelOrder}
                                        type="button"
                                    >
                                        {ui.orders.cancel_yes}
                                    </button>
                                    <button
                                        className="account-live-order__cancel"
                                        onClick={() => setCancelState('idle')}
                                        type="button"
                                    >
                                        {ui.orders.cancel_no}
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <>
                                <button
                                    className="account-live-order__pay"
                                    disabled={paymentState === 'loading'}
                                    onClick={resumePayment}
                                    type="button"
                                >
                                    {paymentState === 'loading'
                                        ? ui.orders.refreshing
                                        : `${ui.invoice.pay_action} · ${formatAccountMoney(order.paymentAmount, props.locale)}`}
                                </button>
                                {order.cancelUrl !== null ? (
                                    <button
                                        className="account-live-order__cancel"
                                        disabled={cancelState === 'loading'}
                                        onClick={() =>
                                            setCancelState('confirming')
                                        }
                                        type="button"
                                    >
                                        {cancelState === 'loading'
                                            ? ui.orders.cancelling
                                            : ui.orders.cancel_order}
                                    </button>
                                ) : null}
                            </>
                        )}
                        {paymentState === 'error' ? (
                            <p role="alert">{ui.errors.unexpected}</p>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </MyAccountLayout>
    );
}

/**
 * Where a paid order is, in three steps. "Received" and "in progress" are one
 * step to the customer, and a paused order sits on that step with the note
 * below explaining why.
 */
function StatusTrack({
    status,
    translations,
}: {
    status: AccountLiveOrderPageProps['order']['status'];
    translations: OrderTranslations;
}) {
    const reached = status === 'completed' ? 3 : status === 'received' ? 1 : 2;
    const steps = [
        translations.track_received,
        translations.track_in_progress,
        translations.track_completed,
    ];

    return (
        <ol aria-label={translations.status} className="account-order-track">
            {steps.map((label, index) => {
                const position = index + 1;
                const state =
                    position < reached || reached === 3
                        ? 'done'
                        : position === reached
                          ? 'now'
                          : 'next';

                return (
                    <li
                        aria-current={state === 'now' ? 'step' : undefined}
                        data-state={state}
                        key={label}
                    >
                        <span aria-hidden="true" />
                        {label}
                    </li>
                );
            })}
        </ol>
    );
}

/**
 * One invoice line. The header row is all a customer usually needs; the
 * options they chose and, for a manual service, the account details sit
 * behind one "details" button so the invoice stays short.
 */
function InvoiceItem({
    item,
    locale,
    translations,
}: {
    item: LiveOrderItem;
    locale: 'ar' | 'en';
    translations: OrderTranslations;
}) {
    const [expanded, setExpanded] = useState(false);
    const contentId = `order-item-${item.id}`;

    return (
        <li className="account-invoice__item">
            <img
                alt=""
                height="56"
                loading="lazy"
                src={item.imageUrl}
                width="56"
            />
            <div className="account-invoice__item-main">
                <h3>{item.name}</h3>
                <span>
                    {platformName(item.platform, translations)}
                    {' · '}
                    {translations.item_quantity.replace(
                        ':count',
                        formatInteger(item.quantity, locale),
                    )}
                </span>
            </div>
            <strong className="account-invoice__item-total">
                {formatAccountMoney(item.total, locale)}
            </strong>
            <button
                aria-controls={contentId}
                aria-expanded={expanded}
                className="account-invoice__item-more"
                onClick={() => setExpanded((value) => !value)}
                type="button"
            >
                {expanded ? translations.hide_details : translations.details}
                <ChevronDown aria-hidden="true" />
            </button>
            {expanded ? (
                <div className="account-invoice__item-details" id={contentId}>
                    <dl className="account-order-facts">
                        <OrderFact
                            label={translations.platform}
                            value={platformName(item.platform, translations)}
                        />
                        {item.manualFulfillment !== null ? (
                            <ManualFacts
                                fulfillment={item.manualFulfillment}
                                locale={locale}
                                translations={translations}
                            />
                        ) : null}
                    </dl>
                    {item.manualFulfillment !== null ? (
                        <ManualCredentials
                            fulfillment={item.manualFulfillment}
                            itemId={item.id}
                            translations={translations}
                        />
                    ) : null}
                </div>
            ) : null}
        </li>
    );
}

type ManualFulfillment = NonNullable<LiveOrderItem['manualFulfillment']>;

function ManualFacts({
    fulfillment,
    locale,
    translations,
}: {
    fulfillment: ManualFulfillment;
    locale: 'ar' | 'en';
    translations: OrderTranslations;
}) {
    return (
        <>
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
        </>
    );
}

function ManualCredentials({
    fulfillment,
    itemId,
    translations,
}: {
    fulfillment: ManualFulfillment;
    itemId: string;
    translations: OrderTranslations;
}) {
    const [expanded, setExpanded] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [credentials, setCredentials] = useState<OrderCredentials | null>(
        null,
    );
    const contentId = `order-fulfillment-${itemId}`;
    const credentialsUrl = fulfillment.credentialsUrl;

    if (credentialsUrl === null && fulfillment.squadImageUrl === null) {
        return null;
    }

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
    value: NonNullable<ManualFulfillment['fromDivision']>,
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
