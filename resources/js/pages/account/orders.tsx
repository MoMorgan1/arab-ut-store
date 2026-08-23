import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, PackageSearch, Search, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import AccountOrderList from '@/components/account/account-order-list';
import AccountOrderRow from '@/components/account/account-order-row';
import MyAccountLayout from '@/layouts/my-account-layout';
import type { AccountOrdersPageProps } from '@/types/account';

export default function AccountOrders() {
    const page = usePage<AccountOrdersPageProps>();
    const props = page.props;
    const Arrow = props.locale === 'ar' ? ArrowLeft : ArrowRight;
    const ordersUrl =
        props.accountNavigation.find((item) => item.key === 'orders')?.url ??
        page.url.split('?')[0];

    const [searchQuery, setSearchQuery] = useState(props.filters.q ?? '');
    const isFirstMount = useRef(true);

    const performSearch = useCallback(
        (queryText: string) => {
            const trimmed = queryText.trim();
            const data: Record<string, string> = {};

            if (props.filters.status && props.filters.status !== 'all') {
                data.status = props.filters.status;
            }

            if (trimmed) {
                data.q = trimmed;
            }

            router.get(ordersUrl, data, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },
        [ordersUrl, props.filters.status],
    );

    const [syncedQuery, setSyncedQuery] = useState(props.filters.q ?? '');

    if (syncedQuery !== (props.filters.q ?? '')) {
        setSyncedQuery(props.filters.q ?? '');
        setSearchQuery(props.filters.q ?? '');
    }

    useEffect(() => {
        if (isFirstMount.current) {
            isFirstMount.current = false;

            return;
        }

        if (searchQuery === (props.filters.q ?? '')) {
            return;
        }

        const timer = setTimeout(() => {
            performSearch(searchQuery);
        }, 350);

        return () => clearTimeout(timer);
    }, [searchQuery, props.filters.q, performSearch]);

    const handleSearchSubmit = (event: FormEvent) => {
        event.preventDefault();
        performSearch(searchQuery);
    };

    const handleClearSearch = () => {
        setSearchQuery('');
        performSearch('');
    };

    const filterUrl = (filterKey: string) => {
        const params = new URLSearchParams();

        if (filterKey !== 'all') {
            params.set('status', filterKey);
        }

        if (props.filters.q) {
            params.set('q', props.filters.q);
        }

        const qs = params.toString();

        return qs ? `${ordersUrl}?${qs}` : ordersUrl;
    };

    const filters = [
        {
            key: 'all',
            label: props.accountUi.orders.all,
            count: props.counts.all,
        },
        {
            key: 'open',
            label: props.accountUi.orders.open,
            count: props.counts.open,
        },
        {
            key: 'completed',
            label: props.accountUi.orders.completed,
            count: props.counts.completed,
        },
    ] as const;

    const showingText = props.accountUi.orders.showing
        .replace(':shown', String(props.orders.length))
        .replace(':total', String(props.pagination.total));

    const isSearching = Boolean(props.filters.q);

    const headings = props.accountUi.orders.columns ?? {
        service: props.locale === 'ar' ? 'الخدمة' : 'Service',
        status: props.locale === 'ar' ? 'الحالة' : 'Status',
        total: props.locale === 'ar' ? 'الإجمالي' : 'Total',
    };

    return (
        <MyAccountLayout {...props} current="orders" currentUrl={page.url}>
            <Head title={props.accountUi.orders.title} />
            <div className="account-orders-page">
                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>{props.accountUi.orders.title}</h2>
                    <span>{props.accountUi.orders.description}</span>
                </header>

                <div className="account-orders-toolbar">
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
                                href={filterUrl(filter.key)}
                                key={filter.key}
                                preserveScroll
                            >
                                <span>{filter.label}</span>
                                <span className="account-order-filters__count">
                                    {filter.count}
                                </span>
                            </Link>
                        ))}
                    </nav>

                    <form
                        className="account-orders-search"
                        onSubmit={handleSearchSubmit}
                        role="search"
                    >
                        <span
                            aria-hidden="true"
                            className="account-orders-search__icon"
                        >
                            <Search />
                        </span>
                        <input
                            aria-label={
                                props.accountUi.orders.search_label ??
                                (props.locale === 'ar'
                                    ? 'البحث في الطلبات'
                                    : 'Search orders')
                            }
                            className="account-orders-search__input"
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder={
                                props.accountUi.orders.search_placeholder ??
                                (props.locale === 'ar'
                                    ? 'ابحث برقم الطلب أو اسم الخدمة'
                                    : 'Search by order number or service name')
                            }
                            type="search"
                            value={searchQuery}
                        />
                        {searchQuery ? (
                            <button
                                aria-label={
                                    props.locale === 'ar'
                                        ? 'مسح البحث'
                                        : 'Clear search'
                                }
                                className="account-orders-search__clear"
                                onClick={handleClearSearch}
                                type="button"
                            >
                                <X aria-hidden="true" />
                            </button>
                        ) : null}
                    </form>
                </div>

                {props.orders.length === 0 ? (
                    <section className="account-overview__empty">
                        <span aria-hidden="true">
                            <PackageSearch />
                        </span>
                        <h2>
                            {isSearching
                                ? (props.accountUi.orders.search_empty ??
                                  (props.locale === 'ar'
                                      ? 'لا توجد طلبات تطابق بحثك'
                                      : 'No orders match your search.'))
                                : props.accountUi.orders.empty_title}
                        </h2>
                        {!isSearching ? (
                            <>
                                <p>
                                    {props.accountUi.orders.empty_description}
                                </p>
                                <Link
                                    className="account-overview__empty-cta"
                                    href={props.storeShell.coinsUrl}
                                >
                                    {props.accountUi.overview.browse_services}
                                    <Arrow aria-hidden="true" />
                                </Link>
                            </>
                        ) : null}
                    </section>
                ) : (
                    <AccountOrderList
                        aria-label={props.accountUi.orders.title}
                        headings={headings}
                    >
                        {props.orders.map((order) => (
                            <AccountOrderRow
                                key={order.id}
                                locale={props.locale}
                                order={order}
                                translations={props.accountUi}
                            />
                        ))}
                    </AccountOrderList>
                )}

                {props.pagination.total > 0 ? (
                    <footer className="account-orders-page__pagination">
                        <p className="account-pagination__summary">
                            {showingText}
                        </p>
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
                                            String(
                                                props.pagination.currentPage,
                                            ),
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
                    </footer>
                ) : null}
            </div>
        </MyAccountLayout>
    );
}
