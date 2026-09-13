import { Head, usePage } from '@inertiajs/react';

import OrderTracking from '@/components/account/order-tracking';
import StoreLayout from '@/layouts/store-layout';
import { formatTimestamp } from '@/lib/date-locale';
import type {
    AccountOrderStatus,
    AccountTranslations,
    OrderItemTracking,
} from '@/types/account';
import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

type TrackOrderItem = {
    name: string;
    platform: 'playstation' | 'xbox' | 'pc';
    imageUrl: string;
    status: AccountOrderStatus;
    quantity: number;
    tracking: OrderItemTracking | null;
};

type TrackOrder = {
    number: string;
    status: AccountOrderStatus;
    statusNote: string | null;
    placedAt: string;
    refreshable: boolean;
    items: TrackOrderItem[];
};

type TrackOrderPageProps = {
    cartCount: number;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: 'ar' | 'en';
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
    order: TrackOrder;
    accountUi: AccountTranslations;
};

/**
 * The signed, read-only tracking page a WhatsApp link opens.
 *
 * This is the store's replacement for track.arab-ut.com: it shows the state of
 * the work and nothing about the money or the account. No analytics import, no
 * purchase event, no money anywhere.
 */
export default function StoreTrackOrder() {
    const page = usePage<TrackOrderPageProps>();
    const props = page.props;
    const { order, accountUi } = props;
    const placedAt = formatTimestamp(order.placedAt);
    const trackedItems = order.items.filter(
        (item): item is TrackOrderItem & { tracking: OrderItemTracking } =>
            item.tracking !== null,
    );

    return (
        <StoreLayout
            cartCount={props.cartCount}
            currentUrl={page.url}
            direction={props.direction}
            displayCurrency={props.displayCurrency}
            displayCurrencies={props.displayCurrencies}
            locale={props.locale}
            storeShell={props.storeShell}
            ui={props.ui}
        >
            <Head title={`${accountUi.track_order.title} · ${order.number}`}>
                <meta content="noindex, nofollow" name="robots" />
            </Head>

            <section
                aria-labelledby="track-order-title"
                className="mx-auto w-full max-w-2xl px-4 py-8"
            >
                <div className="account-invoice">
                    <header className="account-invoice__head">
                        <div className="account-invoice__brand">
                            <p>{accountUi.track_order.title}</p>
                            <h1 id="track-order-title">
                                <bdi>{order.number}</bdi>
                            </h1>
                            <span className="account-invoice__meta">
                                <time dateTime={order.placedAt}>
                                    {placedAt}
                                </time>
                            </span>
                        </div>
                        <p
                            className="account-invoice__mark"
                            data-status={order.status}
                        >
                            <span aria-hidden="true" />
                            {accountUi.statuses[order.status]}
                        </p>
                    </header>

                    {order.statusNote !== null ? (
                        <p className="account-invoice__meta">
                            {order.statusNote}
                        </p>
                    ) : null}
                </div>

                {trackedItems.map((item, index) => (
                    <OrderTracking
                        // The presenter blanks every action list on purpose (acting
                        // over a bearer link is a later task), so the card renders no
                        // buttons and these URLs and the callback are never read.
                        actionUrls={{
                            editCredentials: '',
                            resume: '',
                            retryChallenge: '',
                        }}
                        imageUrl={item.imageUrl}
                        itemName={item.name}
                        key={`${item.name}-${index}`}
                        locale={props.locale}
                        onTracking={() => undefined}
                        platform={item.platform}
                        strings={accountUi.orders.tracking}
                        tracking={item.tracking}
                    />
                ))}
            </section>
        </StoreLayout>
    );
}
