import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Percent,
    Trophy,
    WalletCards,
} from 'lucide-react';

import AppIcon from '@/components/account/app-icon';
import type { AppIconName } from '@/components/account/app-icon';
import WalletLedger from '@/components/account/wallet-ledger';
import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import { cn } from '@/lib/utils';
import type { AccountWalletPageProps } from '@/types/account';

/**
 * The tier ladder is drawn as an ascending set of marks rather than one generic
 * award repeated: coins, then a shield, then a crown, then a cut stone. Read
 * positionally, so it stays meaningful whether the programme runs three tiers or
 * five, and it does not depend on the tier's name being a metal.
 */
const TIER_ICONS = [
    'tier-bronze',
    'tier-silver',
    'tier-gold',
    'tier-diamond',
] as const satisfies readonly AppIconName[];

function tierIconName(index: number, total: number): AppIconName {
    if (index >= total - 1) {
        return TIER_ICONS[TIER_ICONS.length - 1];
    }

    return TIER_ICONS[Math.min(index, TIER_ICONS.length - 2)] ?? 'tier-bronze';
}

/** Weight per rung, so the ladder is visible even in a single colour. */
function tierOpacity(index: number, total: number): number {
    if (total <= 1) {
        return 1;
    }

    return 0.55 + (index / (total - 1)) * 0.45;
}

export default function AccountWallet() {
    const inertia = usePage<AccountWalletPageProps>();
    const props = inertia.props;
    const Arrow = props.locale === 'ar' ? ArrowLeft : ArrowRight;

    const balanceMoney = props.wallet.balance ?? {
        amountMinor: '0',
        currency: 'SAR',
    };

    const lifetimeCashback = props.wallet.lifetimeCashback;
    const hasLifetimeCashback = Number(lifetimeCashback.amountMinor) > 0;

    const loyalty = props.loyalty;
    // "Top tier" only when the customer actually holds a tier and none is
    // above it; below the first tier the card says what is left to reach it.
    const isTopTier =
        loyalty !== null &&
        loyalty.currentTier !== null &&
        loyalty.nextTier === null;

    const currentTierKey = loyalty?.currentTier?.key ?? null;
    const currentTierInList =
        currentTierKey === null
            ? undefined
            : loyalty?.tiers.find((t) => t.key === currentTierKey);
    const currentCashbackPercent = currentTierInList?.cashbackPercent ?? null;

    const tierHeading = isTopTier
        ? props.accountUi.wallet.top_tier
        : loyalty?.currentTier
          ? props.accountUi.wallet.tier_current.replace(
                ':tier',
                loyalty.currentTier.name,
            )
          : props.accountUi.wallet.loyalty_title;

    const remainingText =
        !isTopTier &&
        loyalty?.remaining !== null &&
        loyalty?.remaining !== undefined &&
        loyalty?.nextTier !== null &&
        loyalty?.nextTier !== undefined
            ? props.accountUi.wallet.remaining_to
                  .replace(
                      ':amount',
                      formatAccountMoney(loyalty.remaining, props.locale),
                  )
                  .replace(':tier', loyalty.nextTier.name)
            : null;

    return (
        <MyAccountLayout {...props} current="wallet" currentUrl={inertia.url}>
            <Head title={props.accountUi.wallet.title} />
            <div className="account-wallet-page">
                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>{props.accountUi.wallet.title}</h2>
                    <span>{props.accountUi.wallet.description}</span>
                </header>

                <div className="account-wallet-metrics">
                    <section className="account-wallet-balance">
                        <div className="account-wallet-balance__header">
                            <span
                                aria-hidden="true"
                                className="account-wallet-balance__icon"
                            >
                                <WalletCards />
                            </span>
                            <p>{props.accountUi.wallet.available_balance}</p>
                        </div>
                        <h3 className="account-wallet-balance__amount">
                            <bdi>
                                {formatAccountMoney(balanceMoney, props.locale)}
                            </bdi>
                        </h3>
                        {hasLifetimeCashback ? (
                            <p className="account-wallet-balance__cashback">
                                {props.accountUi.wallet.cashback_earned.replace(
                                    ':amount',
                                    formatAccountMoney(
                                        lifetimeCashback,
                                        props.locale,
                                    ),
                                )}
                            </p>
                        ) : null}
                    </section>
                </div>

                {loyalty === null ? (
                    <p className="account-wallet-no-programme">
                        {props.accountUi.wallet.no_programme}
                    </p>
                ) : (
                    <section
                        aria-label={props.accountUi.wallet.loyalty_title}
                        className="account-wallet-loyalty"
                    >
                        <div className="account-wallet-loyalty__top">
                            <div className="account-wallet-loyalty__meta">
                                <span
                                    aria-hidden="true"
                                    className="account-wallet-loyalty__icon"
                                >
                                    <Trophy />
                                </span>
                                <h3>{tierHeading}</h3>
                            </div>
                            {currentCashbackPercent === null ? null : (
                                <div className="account-wallet-loyalty__rate-pill">
                                    <Percent aria-hidden="true" />
                                    <span>
                                        {props.accountUi.wallet.cashback_rate.replace(
                                            ':percent',
                                            String(currentCashbackPercent),
                                        )}
                                    </span>
                                </div>
                            )}
                        </div>

                        <div
                            aria-label={props.accountUi.wallet.loyalty_title}
                            aria-valuemax={100}
                            aria-valuemin={0}
                            aria-valuenow={loyalty.progressPercent}
                            className="account-wallet-loyalty__progress"
                            role="progressbar"
                        >
                            <span
                                style={{
                                    inlineSize: `${loyalty.progressPercent}%`,
                                }}
                            />
                        </div>

                        {remainingText ? (
                            <p className="account-wallet-loyalty__remaining">
                                {remainingText}
                            </p>
                        ) : null}

                        <div className="account-wallet-loyalty__chips">
                            {loyalty.tiers.map((tier, index) => {
                                const isCurrent =
                                    loyalty.currentTier?.key === tier.key;
                                const iconName = tierIconName(
                                    index,
                                    loyalty.tiers.length,
                                );
                                const minSpend =
                                    props.accountUi.wallet.tier_from.replace(
                                        ':amount',
                                        formatAccountMoney(
                                            tier.minimum,
                                            props.locale,
                                        ),
                                    );
                                const cashbackPercentText =
                                    props.accountUi.wallet.cashback_rate.replace(
                                        ':percent',
                                        String(tier.cashbackPercent),
                                    );

                                return (
                                    <div
                                        aria-current={
                                            isCurrent ? 'true' : undefined
                                        }
                                        className={cn(
                                            'account-wallet-loyalty__chip',
                                            isCurrent &&
                                                'account-wallet-loyalty__chip--current',
                                        )}
                                        key={tier.key}
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="account-wallet-loyalty__chip-icon"
                                            style={{
                                                opacity: tierOpacity(
                                                    index,
                                                    loyalty.tiers.length,
                                                ),
                                            }}
                                        >
                                            <AppIcon name={iconName} />
                                        </span>
                                        <strong className="account-wallet-loyalty__chip-name">
                                            {tier.name}
                                        </strong>
                                        <span className="account-wallet-loyalty__chip-spend">
                                            {minSpend}
                                        </span>
                                        <span className="account-wallet-loyalty__chip-rate">
                                            {cashbackPercentText}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}

                {props.wallet.entries.length === 0 ? (
                    <section className="account-overview__empty">
                        <span aria-hidden="true">
                            <WalletCards />
                        </span>
                        <h2>{props.accountUi.wallet.empty_title}</h2>
                        <p>{props.accountUi.wallet.empty_description}</p>
                    </section>
                ) : (
                    <WalletLedger
                        entries={props.wallet.entries}
                        locale={props.locale}
                        translations={props.accountUi.wallet}
                    />
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
