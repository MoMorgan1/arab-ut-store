import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Sparkles, WalletCards } from 'lucide-react';

import WalletLedger from '@/components/account/wallet-ledger';
import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import type { AccountWalletPageProps } from '@/types/account';

export default function AccountWallet() {
    const inertia = usePage<AccountWalletPageProps>();
    const props = inertia.props;
    const Arrow = props.locale === 'ar' ? ArrowLeft : ArrowRight;

    return (
        <MyAccountLayout {...props} current="wallet" currentUrl={inertia.url}>
            <Head title={props.accountUi.wallet.title} />
            <div className="account-wallet-page">
                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>{props.accountUi.wallet.title}</h2>
                    <span>{props.accountUi.wallet.description}</span>
                </header>

                {props.wallet.status !== 'unavailable' &&
                props.wallet.balance === null &&
                props.wallet.entries.length === 0 ? (
                    <section className="account-wallet-coming-soon">
                        <div className="account-wallet-coming-soon__badge">
                            {props.accountUi.wallet.coming_soon ?? 'قريبًا'}
                        </div>
                        <span
                            aria-hidden="true"
                            className="account-wallet-coming-soon__icon"
                        >
                            <WalletCards />
                        </span>
                        <h2>
                            {props.accountUi.wallet.page_coming_soon_title ??
                                'محفظتك ستكون متاحة قريبًا'}
                        </h2>
                        <p>
                            {props.accountUi.wallet.page_coming_soon_desc ??
                                'ستتمكن من متابعة رصيدك وعمليات الإضافة والخصم والاسترداد من مكان واحد بعد إطلاق الخدمة.'}
                        </p>
                        <div className="account-wallet-coming-soon__features">
                            <div className="account-wallet-coming-soon__feature">
                                <Sparkles aria-hidden="true" />
                                <span>
                                    {props.accountUi.wallet.feature_balance ??
                                        'رصيد فوري وسريع'}
                                </span>
                            </div>
                            <div className="account-wallet-coming-soon__feature">
                                <Sparkles aria-hidden="true" />
                                <span>
                                    {props.accountUi.wallet.feature_refund ??
                                        'استرداد تلقائي للمحفظة'}
                                </span>
                            </div>
                            <div className="account-wallet-coming-soon__feature">
                                <Sparkles aria-hidden="true" />
                                <span>
                                    {props.accountUi.wallet.feature_checkout ??
                                        'دفع مباشر بضغطة واحدة'}
                                </span>
                            </div>
                        </div>
                    </section>
                ) : (
                    <>
                        <section className="account-wallet-balance">
                            <span aria-hidden="true">
                                <WalletCards />
                            </span>
                            <div>
                                <p>
                                    {props.accountUi.wallet.available_balance}
                                </p>
                                {props.wallet.balance === null ? (
                                    <h3>
                                        {
                                            props.accountUi.wallet
                                                .unavailable_balance
                                        }
                                    </h3>
                                ) : (
                                    <h3>
                                        <bdi>
                                            {formatAccountMoney(
                                                props.wallet.balance,
                                                props.locale,
                                            )}
                                        </bdi>
                                    </h3>
                                )}
                            </div>
                        </section>

                        {props.wallet.entries.length === 0 ? (
                            <section className="account-overview__empty">
                                <span aria-hidden="true">
                                    <WalletCards />
                                </span>
                                <h2>{props.accountUi.wallet.empty_title}</h2>
                                <p>
                                    {props.accountUi.wallet.empty_description}
                                </p>
                            </section>
                        ) : (
                            <WalletLedger
                                entries={props.wallet.entries}
                                locale={props.locale}
                                translations={props.accountUi.wallet}
                            />
                        )}
                    </>
                )}

                {props.wallet.pagination.lastPage > 1 ? (
                    <nav
                        aria-label={props.accountUi.wallet.pagination}
                        className="account-pagination"
                    >
                        {props.wallet.pagination.previousUrl === null ? (
                            <span aria-disabled="true">
                                {props.accountUi.wallet.previous}
                            </span>
                        ) : (
                            <Link href={props.wallet.pagination.previousUrl}>
                                {props.accountUi.wallet.previous}
                            </Link>
                        )}
                        <bdi>
                            {props.accountUi.wallet.page_status
                                .replace(
                                    ':current',
                                    String(props.wallet.pagination.currentPage),
                                )
                                .replace(
                                    ':total',
                                    String(props.wallet.pagination.lastPage),
                                )}
                        </bdi>
                        {props.wallet.pagination.nextUrl === null ? (
                            <span aria-disabled="true">
                                {props.accountUi.wallet.next}
                            </span>
                        ) : (
                            <Link href={props.wallet.pagination.nextUrl}>
                                {props.accountUi.wallet.next}
                                <Arrow aria-hidden="true" />
                            </Link>
                        )}
                    </nav>
                ) : null}
            </div>
        </MyAccountLayout>
    );
}
