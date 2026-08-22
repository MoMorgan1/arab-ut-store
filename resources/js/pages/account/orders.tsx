import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, PackageSearch } from 'lucide-react';

import AccountOrderCard from '@/components/account/account-order-card';
import MyAccountLayout from '@/layouts/my-account-layout';
import type { AccountOrdersPageProps } from '@/types/account';

export default function AccountOrders() {
    const page = usePage<AccountOrdersPageProps>();
    const props = page.props;
    const Arrow = props.locale === 'ar' ? ArrowLeft : ArrowRight;
    const ordersUrl =
        props.accountNavigation.find((item) => item.key === 'orders')?.url ??
        page.url;
    const filters = [
        { key: 'all', label: props.accountUi.orders.all },
        { key: 'open', label: props.accountUi.orders.open },
        { key: 'completed', label: props.accountUi.orders.completed },
    ] as const;

    return (
        <MyAccountLayout {...props} current="orders" currentUrl={page.url}>
            <Head title={props.accountUi.orders.title} />
            <div className="account-orders-page">
                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>{props.accountUi.orders.title}</h2>
                    <span>{props.accountUi.orders.description}</span>
                </header>

                <nav
                    aria-label={props.accountUi.orders.filters_label}
                    className="account-order-filters"
                >
                    {filters.map((filter) => (
                        <Link
                            aria-current={
                                props.filters.status === filter.key
                                    ? 'page'
                                    : undefined
                            }
                            href={
                                filter.key === 'all'
                                    ? ordersUrl
                                    : `${ordersUrl}?status=${filter.key}`
                            }
                            key={filter.key}
                            preserveScroll
                        >
                            {filter.label}
                        </Link>
                    ))}
                </nav>

                {props.orders.length === 0 ? (
                    <section className="account-overview__empty">
                        <span aria-hidden="true">
                            <PackageSearch />
                        </span>
                        <h2>{props.accountUi.orders.empty_title}</h2>
                        <p>{props.accountUi.orders.empty_description}</p>
                        <Link
                            className="account-overview__empty-cta"
                            href={props.storeShell.coinsUrl}
                        >
                            {props.accountUi.overview.browse_services}
                            <Arrow aria-hidden="true" />
                        </Link>
                    </section>
                ) : (
                    <section
                        aria-label={props.accountUi.orders.title}
                        className="account-orders-page__list"
                    >
                        {props.orders.map((order) => (
                            <AccountOrderCard
                                key={order.id}
                                locale={props.locale}
                                order={order}
                                translations={props.accountUi}
                            />
                        ))}
                    </section>
                )}

                {props.pagination.lastPage > 1 ? (
                    <nav
                        aria-label={props.accountUi.orders.pagination}
                        className="account-pagination"
                    >
                        {props.pagination.previousUrl === null ? (
                            <span aria-disabled="true">
                                {props.accountUi.orders.previous}
                            </span>
                        ) : (
                            <Link
                                href={props.pagination.previousUrl}
                                preserveScroll
                            >
                                {props.accountUi.orders.previous}
                            </Link>
                        )}
                        <bdi>
                            {props.accountUi.orders.page_status
                                .replace(
                                    ':current',
                                    String(props.pagination.currentPage),
                                )
                                .replace(
                                    ':total',
                                    String(props.pagination.lastPage),
                                )}
                        </bdi>
                        {props.pagination.nextUrl === null ? (
                            <span aria-disabled="true">
                                {props.accountUi.orders.next}
                            </span>
                        ) : (
                            <Link
                                href={props.pagination.nextUrl}
                                preserveScroll
                            >
                                {props.accountUi.orders.next}
                                <Arrow aria-hidden="true" />
                            </Link>
                        )}
                    </nav>
                ) : null}
            </div>
        </MyAccountLayout>
    );
}
