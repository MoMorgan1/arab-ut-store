import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    RotateCcw,
    Sparkles,
    Trophy,
} from 'lucide-react';

import MyAccountLayout from '@/layouts/my-account-layout';
import { formatAccountMoney } from '@/lib/account-money';
import { cn } from '@/lib/utils';
import type { AccountLoyaltyPageProps } from '@/types/account';

const tierColors: Record<string, string> = {
    bronze: '#c98a5b',
    silver: '#b8c0cc',
    gold: 'var(--arabut-gold)',
    platinum: '#cfd8ff',
};

export default function AccountLoyalty() {
    const inertia = usePage<AccountLoyaltyPageProps>();
    const props = inertia.props;
    const isAr = props.locale === 'ar';
    const BackArrow = isAr ? ArrowRight : ArrowLeft;
    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    const hasTiers = Array.isArray(props.tiers) && props.tiers.length > 0;
    const currentTierKey = props.currentTier?.key ?? '';
    const currentTierColor = tierColors[currentTierKey] ?? 'var(--arabut-gold)';

    const overviewUrl = isAr ? '/my-account' : '/en/my-account';

    const progressCopy =
        props.nextTier === null || props.remaining === null
            ? (props.accountUi.loyalty?.progress_complete ??
              (isAr
                  ? 'وصلت إلى أعلى فئة ولاء متاحة.'
                  : 'You have reached the highest available loyalty tier.'))
            : (
                  props.accountUi.loyalty?.progress_remaining ??
                  (isAr
                      ? 'تبقى :amount للوصول إلى فئة :tier.'
                      : ':amount remaining to reach :tier.')
              )
                  .replace(
                      ':amount',
                      formatAccountMoney(props.remaining, props.locale),
                  )
                  .replace(':tier', props.nextTier.name);

    return (
        <MyAccountLayout {...props} current="overview" currentUrl={inertia.url}>
            <Head
                title={
                    props.accountUi.loyalty?.title ??
                    (isAr ? 'برنامج الولاء' : 'Loyalty Programme')
                }
            />
            <div className="account-loyalty-page">
                <div>
                    <Link className="account-page-back" href={overviewUrl}>
                        <BackArrow aria-hidden="true" />
                        <span>
                            {props.accountUi.loyalty?.back_to_overview ??
                                (isAr
                                    ? 'العودة إلى نظرة عامة'
                                    : 'Back to Overview')}
                        </span>
                    </Link>
                </div>

                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>
                        {props.accountUi.loyalty?.title ??
                            (isAr ? 'برنامج الولاء' : 'Loyalty Programme')}
                    </h2>
                    <span>
                        {props.accountUi.loyalty?.description ??
                            (isAr
                                ? 'اكسب كاش باك على كل طلب وارتقِ بين الفئات لمكافآت أكبر.'
                                : 'Earn cashback on every order and climb tiers for bigger rewards.')}
                    </span>
                </header>

                {!hasTiers ? (
                    <section className="account-overview__empty">
                        <span aria-hidden="true">
                            <Trophy />
                        </span>
                        <h2>
                            {props.accountUi.loyalty?.empty_tiers_title ??
                                (isAr
                                    ? 'برنامج الولاء غير متاح حاليًا'
                                    : 'Loyalty programme is not available currently')}
                        </h2>
                        <p>
                            {props.accountUi.loyalty?.empty_tiers_desc ??
                                (isAr
                                    ? 'سنعلن عن تفاصيل البرنامج ومكافآت الكاش باك قريبًا.'
                                    : 'We will announce loyalty programme details and cashback rewards soon.')}
                        </p>
                    </section>
                ) : (
                    <>
                        {/* Hero Card */}
                        <section
                            aria-labelledby="account-loyalty-hero-title"
                            className="account-loyalty-hero"
                        >
                            <div className="account-loyalty-hero__top">
                                <div className="account-loyalty-hero__tier-meta">
                                    <span
                                        aria-hidden="true"
                                        className="account-loyalty-hero__icon"
                                        style={{ color: currentTierColor }}
                                    >
                                        <Trophy />
                                    </span>
                                    <div>
                                        <p id="account-loyalty-hero-title">
                                            {props.accountUi.loyalty
                                                ?.hero_badge ??
                                                (isAr
                                                    ? 'فئتك الحالية'
                                                    : 'Current Tier')}
                                        </p>
                                        <div className="account-loyalty-hero__tier-badge-row">
                                            <span
                                                className="account-loyalty-hero__badge"
                                                style={{
                                                    backgroundColor: `${currentTierColor}20`,
                                                    borderColor:
                                                        currentTierColor,
                                                    color: currentTierColor,
                                                }}
                                            >
                                                {props.currentTier?.name ??
                                                    props.accountUi.loyalty
                                                        ?.unranked ??
                                                    (isAr
                                                        ? 'غير مصنف'
                                                        : 'Unranked')}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div className="account-loyalty-hero__percent">
                                    <strong>{props.progressPercent}%</strong>
                                </div>
                            </div>

                            <div
                                aria-label={
                                    props.accountUi.loyalty?.title ??
                                    'Loyalty progress'
                                }
                                aria-valuemax={100}
                                aria-valuemin={0}
                                aria-valuenow={props.progressPercent}
                                className="account-overview__progress account-loyalty-hero__progress"
                                role="progressbar"
                            >
                                <span
                                    style={{
                                        inlineSize: `${props.progressPercent}%`,
                                    }}
                                />
                            </div>

                            <p className="account-loyalty-hero__copy">
                                {progressCopy}
                            </p>

                            <div className="account-loyalty-hero__stats">
                                <div className="account-loyalty-hero__stat">
                                    <span>
                                        {props.accountUi.loyalty
                                            ?.eligible_spend ??
                                            (isAr
                                                ? 'الإنفاق المؤهل'
                                                : 'Eligible spend')}
                                    </span>
                                    <strong>
                                        <bdi>
                                            {formatAccountMoney(
                                                props.eligibleSpend,
                                                props.locale,
                                            )}
                                        </bdi>
                                    </strong>
                                </div>
                                <div className="account-loyalty-hero__stat">
                                    <span>
                                        {props.accountUi.loyalty
                                            ?.lifetime_cashback ??
                                            (isAr
                                                ? 'كاش باك مكتسب'
                                                : 'Cashback earned')}
                                    </span>
                                    <strong>
                                        <bdi>
                                            {formatAccountMoney(
                                                props.cashback.lifetime,
                                                props.locale,
                                            )}
                                        </bdi>
                                    </strong>
                                </div>
                            </div>
                        </section>

                        {/* 4-tier table */}
                        <section
                            aria-labelledby="account-loyalty-table-title"
                            className="account-loyalty-tiers-section"
                        >
                            <h3 id="account-loyalty-table-title">
                                {props.accountUi.loyalty?.table_title ??
                                    (isAr
                                        ? 'فئات الولاء ونسب الكاش باك'
                                        : 'Loyalty Tiers & Cashback Rates')}
                            </h3>
                            <div className="account-loyalty-table-container">
                                <table className="account-loyalty-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">
                                                {props.accountUi.loyalty
                                                    ?.table_tier ??
                                                    (isAr ? 'الفئة' : 'Tier')}
                                            </th>
                                            <th scope="col">
                                                {props.accountUi.loyalty
                                                    ?.table_spend ??
                                                    (isAr
                                                        ? 'الحد الأدنى للإنفاق'
                                                        : 'Minimum Spend')}
                                            </th>
                                            <th scope="col">
                                                {props.accountUi.loyalty
                                                    ?.table_cashback ??
                                                    (isAr
                                                        ? 'نسبة الكاش باك'
                                                        : 'Cashback Rate')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {props.tiers.map((tier) => {
                                            const isCurrent =
                                                props.currentTier?.key ===
                                                tier.key;
                                            const color =
                                                tierColors[tier.key] ??
                                                'var(--arabut-gold)';

                                            return (
                                                <tr
                                                    className={cn(
                                                        isCurrent &&
                                                            'account-loyalty-table__row--current',
                                                    )}
                                                    data-current={
                                                        isCurrent
                                                            ? 'true'
                                                            : undefined
                                                    }
                                                    key={tier.key}
                                                >
                                                    <td>
                                                        <div className="account-loyalty-table__tier-cell">
                                                            <span
                                                                aria-hidden="true"
                                                                className="account-loyalty-tier-dot"
                                                                style={{
                                                                    backgroundColor:
                                                                        color,
                                                                }}
                                                            />
                                                            <strong>
                                                                {tier.name}
                                                            </strong>
                                                            {isCurrent ? (
                                                                <span className="account-loyalty-tier-badge">
                                                                    {props
                                                                        .accountUi
                                                                        .loyalty
                                                                        ?.current_badge ??
                                                                        (isAr
                                                                            ? 'فئتك الحالية'
                                                                            : 'Your current tier')}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <bdi>
                                                            {formatAccountMoney(
                                                                tier.minimum,
                                                                props.locale,
                                                            )}
                                                        </bdi>
                                                    </td>
                                                    <td>
                                                        <strong>
                                                            {
                                                                tier.cashbackPercent
                                                            }
                                                            %
                                                        </strong>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        {/* How it works */}
                        <section
                            aria-labelledby="account-loyalty-rules-title"
                            className="account-loyalty-rules"
                        >
                            <h3 id="account-loyalty-rules-title">
                                {props.accountUi.loyalty?.how_it_works_title ??
                                    (isAr
                                        ? 'كيف يعمل البرنامج؟'
                                        : 'How it works')}
                            </h3>
                            <ul className="account-loyalty-rules__list">
                                <li className="account-loyalty-rules__item">
                                    <span
                                        aria-hidden="true"
                                        className="account-loyalty-rules__icon"
                                    >
                                        <CheckCircle2 />
                                    </span>
                                    <p>
                                        {props.accountUi.loyalty
                                            ?.how_it_works_1 ??
                                            (isAr
                                                ? 'يُحسب على المبلغ المدفوع فعليًا بعد الخصومات والمحفظة'
                                                : 'Calculated on the net amount paid after discounts and wallet balance')}
                                    </p>
                                </li>
                                <li className="account-loyalty-rules__item">
                                    <span
                                        aria-hidden="true"
                                        className="account-loyalty-rules__icon"
                                    >
                                        <CheckCircle2 />
                                    </span>
                                    <p>
                                        {props.accountUi.loyalty
                                            ?.how_it_works_2 ??
                                            (isAr
                                                ? 'يُضاف للمحفظة عند اكتمال الطلب'
                                                : 'Added to your wallet once the order is completed')}
                                    </p>
                                </li>
                                <li className="account-loyalty-rules__item">
                                    <span
                                        aria-hidden="true"
                                        className="account-loyalty-rules__icon"
                                    >
                                        <CheckCircle2 />
                                    </span>
                                    <p>
                                        {props.accountUi.loyalty
                                            ?.how_it_works_3 ??
                                            (isAr
                                                ? 'يُسترجع عند استرجاع الطلب'
                                                : 'Reversed if the order is refunded')}
                                    </p>
                                </li>
                            </ul>
                        </section>

                        {/* Recent Cashback List */}
                        <section
                            aria-labelledby="account-loyalty-cashback-title"
                            className="account-loyalty-cashback-section"
                        >
                            <h3 id="account-loyalty-cashback-title">
                                {props.accountUi.loyalty
                                    ?.recent_cashback_title ??
                                    (isAr ? 'آخر كاش باك' : 'Recent Cashback')}
                            </h3>

                            {props.cashback.entries.length === 0 ? (
                                <div className="account-overview__empty">
                                    <span aria-hidden="true">
                                        <Sparkles />
                                    </span>
                                    <h2>
                                        {props.accountUi.loyalty
                                            ?.empty_cashback_title ??
                                            (isAr
                                                ? 'لا توجد عمليات كاش باك بعد'
                                                : 'No cashback activity yet')}
                                    </h2>
                                    <p>
                                        {props.accountUi.loyalty
                                            ?.empty_cashback_desc ??
                                            (isAr
                                                ? 'اكسب كاش باك مع أول طلب مكتمل لك.'
                                                : 'Earn cashback with your first completed order.')}
                                    </p>
                                </div>
                            ) : (
                                <ol className="account-order-list">
                                    {props.cashback.entries.map((entry) => {
                                        const amountStr = formatAccountMoney(
                                            entry.amount,
                                            props.locale,
                                        );
                                        const signedAmount =
                                            entry.effect === 'credit'
                                                ? `+${amountStr}`
                                                : `−${amountStr}`;
                                        const isCredit =
                                            entry.effect === 'credit';
                                        const Icon = isCredit
                                            ? ArrowDownLeft
                                            : RotateCcw;
                                        const typeLabel =
                                            props.accountUi.wallet?.[
                                                entry.type
                                            ] ?? entry.type;

                                        return (
                                            <li
                                                className="account-order-row account-loyalty-row"
                                                data-effect={entry.effect}
                                                key={entry.id}
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className="account-order-row__mark account-loyalty-row__mark"
                                                >
                                                    <Icon />
                                                </span>
                                                <div className="account-order-row__main">
                                                    <div className="account-loyalty-row__heading">
                                                        <h3>{typeLabel}</h3>
                                                        <strong className="account-loyalty-row__amount">
                                                            <bdi>
                                                                {signedAmount}
                                                            </bdi>
                                                        </strong>
                                                    </div>
                                                    <div className="account-order-row__meta">
                                                        {entry.createdAt ? (
                                                            <time
                                                                dateTime={
                                                                    entry.createdAt
                                                                }
                                                            >
                                                                {dateFormatter.format(
                                                                    new Date(
                                                                        entry.createdAt,
                                                                    ),
                                                                )}
                                                            </time>
                                                        ) : null}
                                                        {entry.order ? (
                                                            <Link
                                                                className="account-order-row__title-link"
                                                                href={
                                                                    entry.order
                                                                        .url
                                                                }
                                                            >
                                                                {props.accountUi.wallet?.related_order?.replace(
                                                                    ':number',
                                                                    entry.order
                                                                        .number,
                                                                ) ??
                                                                    entry.order
                                                                        .number}
                                                            </Link>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ol>
                            )}
                        </section>
                    </>
                )}
            </div>
        </MyAccountLayout>
    );
}
