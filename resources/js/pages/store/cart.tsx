import { router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    CreditCard,
    Pencil,
    ShoppingBag,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { interpolate } from '@/components/configurator/coins/configurator-copy';
import OneTimeCodeField from '@/components/one-time-code-field';
import PhoneNumberField from '@/components/phone-number-field';
import { SbcCatalogCard } from '@/components/store/catalog/sbc-catalog-card';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import { Label } from '@/components/ui/label';
import StoreLayout from '@/layouts/store-layout';
import { riyals, trackBeginCheckout } from '@/lib/analytics';
import { newAttemptKey } from '@/lib/attempt-key';
import {
    applyCartCoupon,
    CartCouponError,
    removeCartCoupon,
} from '@/lib/cart-coupon-api';
import {
    loadCartCredentials,
    loadManualCartCredentials,
    updateCartCredentials,
    updateManualCartCredentials,
} from '@/lib/cart-credentials-api';
import type {
    StoredCartCredentials,
    StoredManualCartCredentials,
} from '@/lib/cart-credentials-api';
import {
    CartRestoreConflict,
    removeCartItem,
    restoreCartItem,
} from '@/lib/cart-items-api';
import { toggleCartWallet } from '@/lib/cart-wallet-api';
import {
    CheckoutPhoneError,
    reloadAfterPhoneVerification,
    sendCheckoutPhoneCode,
    verifyCheckoutPhoneCode,
} from '@/lib/checkout-phone-api';
import { focusSiblingCodeField } from '@/lib/code-field-focus';
import { formatCoins, formatInteger, formatMinorUnits } from '@/lib/money';
import {
    navigateToHostedPayment,
    navigateToOrder,
    PaylinkCheckoutError,
    startPaylinkCheckout,
} from '@/lib/paylink-checkout-api';
import type { PaylinkRepricing } from '@/lib/paylink-checkout-api';
import type {
    StoredCartCoupon,
    StoreCartItem,
    StoreCartPageProps,
    StoreCartSuggestions,
    StoreCartSuggestionsTranslations,
    StoreCartTranslations,
} from '@/types/store-shell';

export default function StoreCart() {
    const page = usePage<StoreCartPageProps>();
    const {
        cart,
        cartCount,
        cartPage,
        direction,
        displayCurrencies,
        displayCurrency,
        locale,
        storeShell,
        ui,
    } = page.props;
    const authenticated = page.props.auth.user !== null;
    const items = cart.items;
    // An unavailable line has no live price, and the server leaves it out of the
    // totals it computes. Counting it here would make every expected total
    // disagree and refuse the checkout outright.
    const totalHalalah = items.reduce(
        (total, cartItem) =>
            cartItem.unavailableReason
                ? total
                : total +
                  cartItem.totalHalalah -
                  (cartItem.promotion?.discountHalalah ?? 0),
        0,
    );

    const checkoutController = useCheckoutController({
        authenticated,
        canCheckout: cart.canCheckout,
        checkout: cartPage.checkout,
        coupon: cart.coupon,
        items,
        totalHalalah,
        initialUseWallet: cart.useWallet,
    });
    const countCopy =
        cart.count === 1
            ? cartPage.translations.items_count_one
            : interpolate(cartPage.translations.items_count, {
                  count: formatInteger(cart.count, locale),
              });

    // One tap removes the line; the bar below offers the way back. Only the
    // latest removal gets a bar — an earlier one simply stays removed, still
    // restorable server-side inside its 30-minute window.
    const [undo, setUndo] = useState<{
        key: number;
        itemId: string;
        name: string;
        restoreUrl: string | null;
    } | null>(null);
    const undoKey = useRef(0);

    function itemRemoved(removal: {
        cartCount: number;
        itemId: string;
        name: string;
        restoreUrl: string | null;
    }) {
        // Reload rather than filter local state: removing an unavailable item
        // has to refresh `cart.canCheckout` too, or the checkout button stays
        // dead until a manual refresh.
        undoKey.current += 1;
        setUndo({ ...removal, key: undoKey.current });
        router.reload({ only: ['cart'] });
        window.dispatchEvent(
            new CustomEvent<number>('arabut:cart-count', {
                detail: removal.cartCount,
            }),
        );
    }

    async function undoRemove(): Promise<'restored' | 'duplicate' | 'failed'> {
        if (undo?.restoreUrl === null || undo?.restoreUrl === undefined) {
            return 'failed';
        }

        try {
            const result = await restoreCartItem(undo.restoreUrl);
            setUndo(null);
            router.reload({ only: ['cart'] });
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: result.cartCount,
                }),
            );

            return 'restored';
        } catch (error) {
            return error instanceof CartRestoreConflict
                ? 'duplicate'
                : 'failed';
        }
    }

    return (
        <StoreLayout
            cartCount={cartCount}
            currentUrl={page.url}
            direction={direction}
            displayCurrencies={displayCurrencies}
            displayCurrency={displayCurrency}
            locale={locale}
            storeShell={storeShell}
            ui={ui}
        >
            <StoreSeoHead title={cartPage.translations.title} />
            <section
                aria-labelledby="store-cart-title"
                className="store-cart-page"
            >
                <header className="store-cart-page__heading">
                    <div>
                        <p>{cartPage.translations.eyebrow}</p>
                        <h1 id="store-cart-title">
                            {cartPage.translations.title}
                        </h1>
                    </div>
                    {items.length > 0 ? (
                        <span className="store-cart-page__count">
                            {countCopy}
                        </span>
                    ) : null}
                </header>

                {items.length === 0 ? (
                    <CartEmptyState
                        coinsUrl={storeShell.coinsUrl}
                        translations={cartPage.translations}
                    />
                ) : (
                    <>
                        <CheckoutProgress
                            authenticated={authenticated}
                            phoneVerified={cartPage.checkout.phoneVerified}
                            translations={cartPage.translations}
                        />
                        <div className="store-cart-page__layout">
                            <section
                                aria-labelledby="store-cart-items-title"
                                className="store-cart-page__items"
                            >
                                <h2 id="store-cart-items-title">
                                    {cartPage.translations.items_heading}
                                </h2>
                                {items.some((item) => item.priceChanged) ? (
                                    <p
                                        className="store-cart-repriced"
                                        role="status"
                                    >
                                        <strong>
                                            {
                                                cartPage.translations
                                                    .prices_updated
                                            }
                                        </strong>
                                        {
                                            cartPage.translations
                                                .prices_updated_note
                                        }
                                    </p>
                                ) : null}
                                <ol className="store-cart-lines">
                                    {items.map((cartItem) => (
                                        <CartLine
                                            cartItem={cartItem}
                                            key={cartItem.id}
                                            locale={locale}
                                            onRemoved={itemRemoved}
                                            translations={cartPage.translations}
                                        />
                                    ))}
                                </ol>
                            </section>
                            <CartSuggestions
                                locale={locale}
                                suggestions={cartPage.suggestions}
                                translations={cartPage.translations.suggestions}
                            />
                            <CheckoutSummary
                                authenticated={authenticated}
                                canCheckout={cart.canCheckout}
                                blockedByUnavailable={items.some(
                                    (item) => item.unavailableReason,
                                )}
                                checkout={cartPage.checkout}
                                controller={checkoutController}
                                coupon={cart.coupon}
                                locale={locale}
                                policyLinks={{
                                    terms: {
                                        label: ui.footer.terms,
                                        url: storeShell.termsUrl,
                                    },
                                    warranty: {
                                        label: ui.footer.warranty,
                                        url: storeShell.warrantyUrl,
                                    },
                                }}
                                totalHalalah={totalHalalah}
                                translations={cartPage.translations}
                            />
                        </div>
                        <CartDock
                            authenticated={authenticated}
                            canCheckout={cart.canCheckout}
                            checkout={cartPage.checkout}
                            controller={checkoutController}
                            locale={locale}
                            translations={cartPage.translations}
                        />
                    </>
                )}
                {/* Outside the empty/filled branch: removing the last line
                    flips the page to the empty state, and the undo must
                    survive that. */}
                {undo !== null ? (
                    <CartUndoBar
                        key={undo.key}
                        name={undo.name}
                        onHidden={() => setUndo(null)}
                        onUndo={undoRemove}
                        translations={cartPage.translations}
                    />
                ) : null}
            </section>
        </StoreLayout>
    );
}

function CartEmptyState({
    coinsUrl,
    translations,
}: {
    coinsUrl: string;
    translations: StoreCartTranslations;
}) {
    return (
        <section className="store-cart-empty">
            <span aria-hidden="true">
                <ShoppingBag />
            </span>
            <h2>{translations.empty_title}</h2>
            <p>{translations.empty_description}</p>
            <div>
                <a href={coinsUrl}>{translations.browse_coins}</a>
            </div>
        </section>
    );
}

function CheckoutProgress({
    authenticated,
    phoneVerified,
    translations,
}: {
    authenticated: boolean;
    phoneVerified: boolean;
    translations: StoreCartTranslations;
}) {
    const currentStep = authenticated && phoneVerified ? 1 : 0;
    const steps = [
        { icon: ShoppingBag, label: translations.step_cart },
        { icon: CreditCard, label: translations.step_payment },
    ];

    return (
        <nav
            aria-label={translations.checkout_progress}
            className="store-cart-progress"
        >
            <ol>
                {steps.map((step, index) => {
                    const Icon = step.icon;
                    const state =
                        index < currentStep
                            ? 'complete'
                            : index === currentStep
                              ? 'current'
                              : 'upcoming';

                    return (
                        <li
                            aria-current={
                                state === 'current' ? 'step' : undefined
                            }
                            data-state={state}
                            key={step.label}
                        >
                            <span aria-hidden="true">
                                <Icon />
                            </span>
                            <strong>{step.label}</strong>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

type CheckoutMachineState = 'idle' | 'loading' | 'confirming' | 'error';

export type CheckoutController = {
    state: CheckoutMachineState;
    errorCode: string | null;
    repricing: PaylinkRepricing | null;
    useWallet: boolean;
    walletBusy: boolean;
    showWalletToggle: boolean;
    discountHalalah: number;
    totalAfterDiscount: number;
    walletUsedHalalah: number;
    payableHalalah: number;
    handleWalletToggle: (
        event: React.ChangeEvent<HTMLInputElement>,
    ) => Promise<void>;
    startPayment: (confirmed?: PaylinkRepricing) => Promise<void>;
    dismissRepricing: () => void;
};

// One state machine feeds both the in-flow summary and the phone dock, so the
// two controls can never disagree about what the checkout is doing.
function useCheckoutController({
    authenticated,
    canCheckout,
    checkout,
    coupon,
    items,
    totalHalalah,
    initialUseWallet,
}: {
    authenticated: boolean;
    canCheckout: boolean;
    checkout: StoreCartPageProps['cartPage']['checkout'];
    coupon: StoredCartCoupon | null;
    items: StoreCartItem[];
    totalHalalah: number;
    initialUseWallet: boolean;
}): CheckoutController {
    const idempotencyKey = useRef(newAttemptKey('checkout'));
    const [state, setState] = useState<CheckoutMachineState>('idle');
    const [errorCode, setErrorCode] = useState<string | null>(null);
    const [repricing, setRepricing] = useState<PaylinkRepricing | null>(null);
    const [useWallet, setUseWallet] = useState(initialUseWallet);
    const [walletBusy, setWalletBusy] = useState(false);

    const [syncedUseWallet, setSyncedUseWallet] = useState(initialUseWallet);

    if (syncedUseWallet !== initialUseWallet) {
        setSyncedUseWallet(initialUseWallet);
        setUseWallet(initialUseWallet);
    }

    const showWalletToggle =
        authenticated && (checkout.walletBalanceHalalah ?? 0) > 0;
    const discountHalalah = coupon?.discountHalalah ?? 0;
    const totalAfterDiscount = Math.max(totalHalalah - discountHalalah, 0);
    const walletUsedHalalah =
        useWallet && showWalletToggle
            ? Math.min(checkout.walletBalanceHalalah ?? 0, totalAfterDiscount)
            : 0;
    const payableHalalah = Math.max(totalAfterDiscount - walletUsedHalalah, 0);

    async function handleWalletToggle(
        event: React.ChangeEvent<HTMLInputElement>,
    ) {
        const nextValue = event.target.checked;
        setUseWallet(nextValue);
        setWalletBusy(true);

        try {
            await toggleCartWallet(checkout.walletToggleUrl, nextValue);
            router.reload({ only: ['cart'] });
        } catch {
            setUseWallet(!nextValue);
        } finally {
            setWalletBusy(false);
        }
    }

    async function startPayment(confirmed?: PaylinkRepricing) {
        if (!canCheckout || state === 'loading') {
            return;
        }

        setState('loading');
        setErrorCode(null);

        try {
            const result = await startPaylinkCheckout(
                checkout.checkoutUrl,
                idempotencyKey.current,
                confirmed?.payableHalalah ?? payableHalalah,
                confirmed?.orderTotalHalalah ?? totalAfterDiscount,
            );

            // After the server accepted the checkout, so a re-priced retry
            // reports one begin_checkout, not two.
            trackBeginCheckout(
                items.map((item) => ({
                    id: item.id,
                    name: item.product.name,
                    price: riyals(item.unitPriceHalalah),
                    quantity: item.quantity,
                })),
                riyals(confirmed?.payableHalalah ?? payableHalalah),
            );

            if (result.paymentUrl === null) {
                navigateToOrder(result.orderUrl);
            } else {
                navigateToHostedPayment(result.paymentUrl);
            }
        } catch (error) {
            if (error instanceof PaylinkCheckoutError) {
                if (error.conclusive) {
                    idempotencyKey.current = newAttemptKey('checkout');
                }

                // Not an error the customer has to read and recover from: the
                // price moved, and they are being asked to agree to the new one.
                if (error.repricing !== null) {
                    setRepricing(error.repricing);
                    setState('confirming');

                    return;
                }

                setErrorCode(error.code);
            } else {
                setErrorCode('checkout_error');
            }

            setState('error');
        }
    }

    function dismissRepricing() {
        setRepricing(null);
        setState('idle');
        router.reload({ only: ['cart'] });
    }

    return {
        state,
        errorCode,
        repricing,
        useWallet,
        walletBusy,
        showWalletToggle,
        discountHalalah,
        totalAfterDiscount,
        walletUsedHalalah,
        payableHalalah,
        handleWalletToggle,
        startPayment,
        dismissRepricing,
    };
}

function scrollCheckoutTarget(targetId: string, focusFirstField: boolean) {
    const target = document.getElementById(targetId);

    if (!target) {
        return;
    }

    target.scrollIntoView({ block: 'center' });

    if (focusFirstField) {
        target
            .querySelector<HTMLElement>('input, select, textarea, button')
            ?.focus({ preventScroll: true });
    }
}

function CheckoutSummary({
    authenticated,
    blockedByUnavailable,
    canCheckout,
    checkout,
    controller,
    coupon,
    locale,
    policyLinks,
    totalHalalah,
    translations,
}: {
    authenticated: boolean;
    blockedByUnavailable: boolean;
    canCheckout: boolean;
    checkout: StoreCartPageProps['cartPage']['checkout'];
    controller: CheckoutController;
    coupon: StoredCartCoupon | null;
    locale: 'ar' | 'en';
    policyLinks: {
        terms: { label: string; url: string };
        warranty: { label: string; url: string };
    };
    totalHalalah: number;
    translations: StoreCartTranslations;
}) {
    const {
        state,
        errorCode,
        repricing,
        useWallet,
        walletBusy,
        showWalletToggle,
        walletUsedHalalah,
        payableHalalah,
        handleWalletToggle,
        startPayment,
        dismissRepricing,
    } = controller;

    return (
        <aside
            className="store-cart-checkout store-cart-summary"
            aria-label={translations.checkout}
        >
            <header className="store-cart-checkout__heading">
                <span aria-hidden="true">
                    <ShieldCheck />
                </span>
                <h2>{translations.summary_title}</h2>
            </header>
            <CouponField
                applyUrl={checkout.couponApplyUrl}
                coupon={coupon}
                locale={locale}
                removeUrl={checkout.couponRemoveUrl}
                translations={translations}
            />
            {showWalletToggle ? (
                <label className="store-cart-wallet-toggle">
                    <span>
                        {interpolate(translations.wallet_toggle, {
                            balance: formatMinorUnits(
                                checkout.walletBalanceHalalah,
                                'SAR',
                                locale,
                            ),
                        })}
                    </span>
                    <input
                        checked={useWallet}
                        disabled={walletBusy}
                        name="use_wallet"
                        onChange={handleWalletToggle}
                        type="checkbox"
                    />
                </label>
            ) : null}
            <div className="store-cart-checkout__subtotal">
                <span>{translations.subtotal}</span>
                <strong>{formatMinorUnits(totalHalalah, 'SAR', locale)}</strong>
            </div>
            {coupon !== null ? (
                <div className="store-cart-checkout__discount">
                    <span>{translations.coupon_discount}</span>
                    <strong dir="ltr">
                        -
                        {formatMinorUnits(
                            coupon.discountHalalah,
                            'SAR',
                            locale,
                        )}
                    </strong>
                </div>
            ) : null}
            {useWallet && walletUsedHalalah > 0 ? (
                <div className="store-cart-checkout__wallet">
                    <span>{translations.wallet_deduction}</span>
                    <strong dir="ltr">
                        -{formatMinorUnits(walletUsedHalalah, 'SAR', locale)}
                    </strong>
                </div>
            ) : null}
            <div className="store-cart-checkout__total">
                <span>{translations.order_total}</span>
                <strong>
                    {formatMinorUnits(payableHalalah, 'SAR', locale)}
                </strong>
            </div>
            <p className="store-cart-checkout__policies">
                <a href={policyLinks.terms.url}>{policyLinks.terms.label}</a>
                <span aria-hidden="true">·</span>
                <a href={policyLinks.warranty.url}>
                    {policyLinks.warranty.label}
                </a>
            </p>
            {!authenticated ? (
                <a
                    className="store-cart-checkout__action store-cart-checkout__action--desktop"
                    href={checkout.loginUrl}
                >
                    {translations.checkout_login}
                </a>
            ) : null}
            {authenticated && !checkout.phoneVerified ? (
                <div id="store-cart-phone-form">
                    <CheckoutPhoneForm
                        checkout={checkout}
                        locale={locale}
                        translations={translations}
                    />
                </div>
            ) : null}
            {state === 'confirming' && repricing !== null ? (
                <div
                    className="store-cart-confirm"
                    id="store-cart-confirm"
                    role="alert"
                >
                    <p className="store-cart-confirm__title">
                        {translations.confirm_total_title}
                    </p>
                    <p className="store-cart-confirm__note">
                        {translations.confirm_total_note}
                    </p>
                    <div className="store-cart-confirm__figures">
                        {/* The order total is shown whenever it moved, not just
                            the cash. A wallet can absorb a price rise entirely,
                            leaving the payable identical - which is the exact
                            case the two-figure check exists for, and showing
                            only the payable would present two equal numbers
                            under the words "your total changed". */}
                        {repricing.orderTotalHalalah !==
                        repricing.previousOrderTotalHalalah ? (
                            <>
                                <span>
                                    {translations.confirm_order_previous}
                                </span>
                                <del>
                                    {formatMinorUnits(
                                        repricing.previousOrderTotalHalalah,
                                        'SAR',
                                        locale,
                                    )}
                                </del>
                                <span>{translations.confirm_order_new}</span>
                                <strong>
                                    {formatMinorUnits(
                                        repricing.orderTotalHalalah,
                                        'SAR',
                                        locale,
                                    )}
                                </strong>
                            </>
                        ) : null}
                        <span>{translations.confirm_total_previous}</span>
                        <del>
                            {formatMinorUnits(
                                repricing.previousPayableHalalah,
                                'SAR',
                                locale,
                            )}
                        </del>
                        <span>{translations.confirm_total_new}</span>
                        <strong>
                            {formatMinorUnits(
                                repricing.payableHalalah,
                                'SAR',
                                locale,
                            )}
                        </strong>
                    </div>
                    {repricing.couponRemoved ? (
                        <p className="store-cart-confirm__coupon">
                            {translations.confirm_coupon_removed}
                        </p>
                    ) : null}
                    <div className="store-cart-confirm__actions">
                        <button
                            onClick={() => startPayment(repricing)}
                            type="button"
                        >
                            {translations.confirm_pay}
                        </button>
                        <button onClick={dismissRepricing} type="button">
                            {translations.confirm_cancel}
                        </button>
                    </div>
                </div>
            ) : null}
            {authenticated && checkout.phoneVerified ? (
                <>
                    <button
                        className="store-cart-checkout__action store-cart-checkout__action--desktop"
                        data-busy={state === 'loading'}
                        disabled={!canCheckout || state === 'loading'}
                        onClick={() => startPayment()}
                        type="button"
                    >
                        {state === 'loading'
                            ? translations.checkout_loading
                            : translations.checkout}
                    </button>
                    {blockedByUnavailable ? (
                        <p className="store-cart-checkout__blocked">
                            {translations.unavailable_note}
                        </p>
                    ) : null}
                </>
            ) : null}
            {state === 'error' ? (
                <p className="store-cart-checkout__error" role="alert">
                    {checkoutErrorCopy(errorCode, translations)}
                </p>
            ) : null}
        </aside>
    );
}

function checkoutErrorCopy(
    errorCode: string | null,
    translations: StoreCartTranslations,
): string {
    switch (errorCode) {
        case 'cart_changed':
            return translations.checkout_cart_changed;
        case 'pricing_updating':
            return translations.checkout_pricing_updating;
        case 'too_many_requests':
            return translations.checkout_too_many_requests;
        default:
            return translations.checkout_error;
    }
}

function CartDock({
    authenticated,
    canCheckout,
    checkout,
    controller,
    locale,
    translations,
}: {
    authenticated: boolean;
    canCheckout: boolean;
    checkout: StoreCartPageProps['cartPage']['checkout'];
    controller: CheckoutController;
    locale: 'ar' | 'en';
    translations: StoreCartTranslations;
}) {
    const { errorCode, state, repricing, payableHalalah, startPayment } =
        controller;

    return (
        <div className="store-cart-dock">
            <div className="store-cart-dock__total">
                <span className="store-cart-dock__label">
                    {translations.order_total}
                </span>
                <strong className="store-cart-dock__amount">
                    {formatMinorUnits(payableHalalah, 'SAR', locale)}
                </strong>
            </div>
            {!authenticated ? (
                <a className="store-cart-dock__action" href={checkout.loginUrl}>
                    {translations.checkout_login}
                </a>
            ) : state === 'confirming' && repricing !== null ? (
                <button
                    className="store-cart-dock__action"
                    onClick={() =>
                        scrollCheckoutTarget('store-cart-confirm', false)
                    }
                    type="button"
                >
                    {translations.review_reprice}
                </button>
            ) : !checkout.phoneVerified ? (
                <button
                    className="store-cart-dock__action"
                    onClick={() =>
                        scrollCheckoutTarget('store-cart-phone-form', true)
                    }
                    type="button"
                >
                    {translations.verify_phone_short}
                </button>
            ) : (
                <button
                    className="store-cart-dock__action"
                    data-busy={state === 'loading'}
                    disabled={!canCheckout || state === 'loading'}
                    onClick={() => startPayment()}
                    type="button"
                >
                    {state === 'loading'
                        ? translations.checkout_loading
                        : translations.pay_now}
                </button>
            )}
            {state === 'error' ? (
                <p className="store-cart-dock__error">
                    {checkoutErrorCopy(errorCode, translations)}
                </p>
            ) : null}
        </div>
    );
}

const UNDO_VISIBLE_MS = 6_000;

function CartUndoBar({
    name,
    onHidden,
    onUndo,
    translations,
}: {
    name: string;
    onHidden: () => void;
    onUndo: () => Promise<'restored' | 'duplicate' | 'failed'>;
    translations: StoreCartTranslations;
}) {
    const [notice, setNotice] = useState<'removed' | 'duplicate' | 'failed'>(
        'removed',
    );
    const [undoing, setUndoing] = useState(false);
    // The bar hides itself; hovering or touching it holds the timer so the
    // customer can read it first.
    const remaining = useRef(UNDO_VISIBLE_MS);
    const deadline = useRef(0);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    function clearTimer() {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }

        if (deadline.current !== 0) {
            remaining.current = Math.max(0, deadline.current - Date.now());
            deadline.current = 0;
        }
    }

    function startTimer() {
        clearTimer();
        deadline.current = Date.now() + remaining.current;
        timer.current = setTimeout(onHidden, remaining.current);
    }

    useEffect(() => {
        remaining.current = UNDO_VISIBLE_MS;
        startTimer();

        return clearTimer;
        // Once per bar: a replacement mounts a fresh bar through its key.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // A new outcome deserves a full read, so the timer restarts on it.
    useEffect(() => {
        if (notice !== 'removed') {
            remaining.current = UNDO_VISIBLE_MS;
            startTimer();
        }

        return clearTimer;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [notice]);

    async function undo() {
        if (undoing) {
            return;
        }

        setUndoing(true);

        const outcome = await onUndo();

        // A restore unmounts this bar through the parent state; the other two
        // outcomes stay readable inside it instead.
        if (outcome === 'restored') {
            return;
        }

        setNotice(outcome);
        setUndoing(false);
    }

    const message =
        notice === 'duplicate'
            ? translations.restore_duplicate
            : notice === 'failed'
              ? translations.undo_failed
              : interpolate(translations.removed_line, { name });

    return (
        <div
            className="store-cart-undo"
            onBlur={(event) => {
                if (
                    !event.currentTarget.contains(
                        event.relatedTarget as Node | null,
                    )
                ) {
                    startTimer();
                }
            }}
            onFocus={clearTimer}
            onMouseEnter={clearTimer}
            onMouseLeave={startTimer}
            onPointerCancel={(event) => {
                if (event.pointerType === 'touch') {
                    startTimer();
                }
            }}
            onPointerDown={(event) => {
                if (event.pointerType === 'touch') {
                    clearTimer();
                }
            }}
            onPointerEnter={clearTimer}
            onPointerLeave={startTimer}
            onPointerUp={(event) => {
                if (event.pointerType === 'touch') {
                    startTimer();
                }
            }}
            role="status"
        >
            <p className="store-cart-undo__message">{message}</p>
            {notice === 'removed' ? (
                <button
                    className="store-cart-undo__action"
                    disabled={undoing}
                    onClick={() => void undo()}
                    type="button"
                >
                    {translations.undo}
                </button>
            ) : null}
            <span
                aria-hidden="true"
                className="store-cart-undo__progress"
                key={notice}
            />
        </div>
    );
}

function CheckoutPhoneForm({
    checkout,
    locale,
    translations,
}: {
    checkout: StoreCartPageProps['cartPage']['checkout'];
    locale: 'ar' | 'en';
    translations: StoreCartTranslations;
}) {
    const [phone, setPhone] = useState('');
    const [code, setCode] = useState('');
    const [stage, setStage] = useState<'phone' | 'code'>('phone');
    const [busy, setBusy] = useState(false);
    const [errorCode, setErrorCode] = useState<string | null>(null);
    const phoneValid = /^\+[1-9][0-9]{7,14}$/.test(phone);

    async function sendCode() {
        if (!phoneValid || busy) {
            setErrorCode('invalid_input');

            return;
        }

        setBusy(true);
        setErrorCode(null);

        try {
            await sendCheckoutPhoneCode(checkout.phoneCodeUrl, phone);
            setStage('code');
        } catch (error) {
            setErrorCode(
                error instanceof CheckoutPhoneError ? error.code : 'unknown',
            );
        } finally {
            setBusy(false);
        }
    }

    async function verifyCode() {
        if (!/^[0-9]{6}$/.test(code) || busy) {
            setErrorCode('invalid_input');

            return;
        }

        setBusy(true);
        setErrorCode(null);

        try {
            await verifyCheckoutPhoneCode(checkout.phoneVerifyUrl, phone, code);
            reloadAfterPhoneVerification();
        } catch (error) {
            setErrorCode(
                error instanceof CheckoutPhoneError ? error.code : 'unknown',
            );
            setBusy(false);
        }
    }

    return (
        <div className="store-cart-phone">
            <p className="store-cart-checkout__notice">
                {translations.checkout_phone}
            </p>
            <Label htmlFor="checkout-phone-number">
                {translations.phone_number}
            </Label>
            <div className="store-cart-phone__number">
                <PhoneNumberField
                    id="checkout-phone-number"
                    locale={locale}
                    value={phone}
                    onChange={setPhone}
                    disabled={busy || stage === 'code'}
                    labels={{
                        country: translations.phone_country,
                        number: translations.phone_number,
                    }}
                />
            </div>
            {stage === 'phone' ? (
                <button
                    className="store-cart-phone__button"
                    disabled={busy || !phoneValid}
                    onClick={sendCode}
                    type="button"
                >
                    {busy
                        ? translations.phone_sending
                        : translations.phone_send}
                </button>
            ) : (
                <>
                    <p className="store-cart-phone__sent" role="status">
                        {translations.phone_sent}
                    </p>
                    <OneTimeCodeField
                        id="checkout-phone-code"
                        label={translations.phone_code}
                        value={code}
                        onChange={setCode}
                        onComplete={verifyCode}
                        disabled={busy}
                        autoFocus
                    />
                    <button
                        className="store-cart-phone__button"
                        disabled={busy || code.length !== 6}
                        onClick={verifyCode}
                        type="button"
                    >
                        {busy
                            ? translations.phone_verifying
                            : translations.phone_verify}
                    </button>
                </>
            )}
            {errorCode !== null ? (
                <p className="store-cart-checkout__error" role="alert">
                    {errorCode === 'phone_unavailable'
                        ? translations.phone_unavailable
                        : errorCode === 'whatsapp_unavailable'
                          ? translations.phone_delivery_error
                          : translations.phone_invalid}
                </p>
            ) : null}
        </div>
    );
}

function couponErrorCopy(
    error: CartCouponError,
    translations: StoreCartTranslations,
): string {
    if (
        error.code === 'coupon_minimum' &&
        error.detail !== null &&
        error.detail !== ''
    ) {
        return interpolate(translations.coupon_minimum, {
            amount: error.detail,
        });
    }

    switch (error.code) {
        case 'coupon_invalid': {
            return translations.coupon_invalid;
        }
        case 'coupon_expired': {
            return translations.coupon_expired;
        }
        case 'coupon_limit': {
            return translations.coupon_limit;
        }
        default: {
            return error.detail ?? translations.coupon_error;
        }
    }
}

function CouponField({
    applyUrl,
    coupon,
    locale,
    removeUrl,
    translations,
}: {
    applyUrl: string;
    coupon: StoredCartCoupon | null;
    locale: 'ar' | 'en';
    removeUrl: string;
    translations: StoreCartTranslations;
}) {
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [errorText, setErrorText] = useState<string | null>(null);
    // Most orders carry no coupon, so the field stays folded behind one line
    // and only the customers who arrived with a code pay for the space.
    const [open, setOpen] = useState(false);

    async function apply() {
        if (busy || code.trim() === '') {
            return;
        }

        setBusy(true);
        setErrorText(null);

        try {
            await applyCartCoupon(applyUrl, code);
            setCode('');
            router.reload({ only: ['cart'] });
        } catch (error) {
            setErrorText(
                couponErrorCopy(
                    error instanceof CartCouponError
                        ? error
                        : new CartCouponError('coupon_error'),
                    translations,
                ),
            );
        } finally {
            setBusy(false);
        }
    }

    async function remove() {
        if (busy) {
            return;
        }

        setBusy(true);
        setErrorText(null);

        try {
            await removeCartCoupon(removeUrl);
            router.reload({ only: ['cart'] });
        } catch {
            setErrorText(translations.coupon_error);
        } finally {
            setBusy(false);
        }
    }

    if (coupon !== null) {
        return (
            <div className="store-cart-coupon store-cart-coupon--applied">
                <p className="store-cart-coupon__status" role="status">
                    <CheckCircle2 aria-hidden="true" />
                    <span>
                        {translations.coupon_applied}
                        {' — '}
                        <bdi dir="ltr">{coupon.code}</bdi>
                    </span>
                </p>
                <button
                    className="store-cart-coupon__remove"
                    disabled={busy}
                    onClick={remove}
                    type="button"
                >
                    {busy
                        ? translations.coupon_removing
                        : translations.coupon_remove}
                </button>
            </div>
        );
    }

    if (!open) {
        return (
            <div className="store-cart-coupon">
                <button
                    aria-expanded={false}
                    className="store-cart-coupon__toggle"
                    onClick={() => setOpen(true)}
                    type="button"
                >
                    {translations.coupon_prompt}
                </button>
            </div>
        );
    }

    return (
        <div className="store-cart-coupon">
            <Label
                dir={locale === 'ar' ? 'rtl' : 'ltr'}
                htmlFor="cart-coupon-code"
            >
                {translations.coupon_label}
            </Label>
            <div className="store-cart-coupon__row">
                <input
                    aria-label={translations.coupon_label}
                    autoComplete="off"
                    autoFocus
                    dir="ltr"
                    id="cart-coupon-code"
                    maxLength={24}
                    name="coupon_code"
                    onChange={(event) =>
                        setCode(event.currentTarget.value.toUpperCase())
                    }
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void apply();
                        }
                    }}
                    placeholder={translations.coupon_placeholder}
                    spellCheck={false}
                    type="text"
                    value={code}
                />
                <button
                    className="store-cart-coupon__apply"
                    disabled={busy || code.trim() === ''}
                    onClick={() => {
                        void apply();
                    }}
                    type="button"
                >
                    {busy
                        ? translations.coupon_applying
                        : translations.coupon_apply}
                </button>
            </div>
            {errorText !== null ? (
                <p className="store-cart-checkout__error" role="alert">
                    {errorText}
                </p>
            ) : null}
        </div>
    );
}

function CartSuggestions({
    locale,
    suggestions,
    translations,
}: {
    locale: 'ar' | 'en';
    suggestions: StoreCartSuggestions;
    translations: StoreCartSuggestionsTranslations;
}) {
    if (
        suggestions.products.length === 0 &&
        suggestions.services.length === 0
    ) {
        return null;
    }

    const cardTranslations = {
        included: translations.included,
        platform_prices: translations.platform_prices,
        unavailable_price: translations.unavailable_price,
    };

    return (
        <section
            aria-labelledby="store-cart-suggestions-title"
            className="manual-section store-cart-suggestions"
        >
            <header className="store-cart-suggestions__header">
                <div className="store-cart-suggestions__heading">
                    <p className="store-cart-suggestions__eyebrow">
                        {translations.eyebrow}
                    </p>
                    <h2
                        className="store-cart-suggestions__title"
                        id="store-cart-suggestions-title"
                    >
                        {translations.title}
                    </h2>
                </div>
                <a
                    className="store-cart-suggestions__see-all"
                    href={suggestions.sbcUrl}
                >
                    {translations.see_all}
                </a>
            </header>
            <ul className="store-cart-suggestions__rail">
                {suggestions.products.map((product, index) => (
                    <SbcCatalogCard
                        key={product.id}
                        locale={locale}
                        product={product}
                        reason={
                            index === 0
                                ? (suggestions.reason ?? undefined)
                                : undefined
                        }
                        translations={cardTranslations}
                    />
                ))}
                {suggestions.services.map((service, index) => (
                    <li
                        className="store-cart-suggestions__service"
                        key={service.key}
                    >
                        {suggestions.products.length === 0 &&
                        index === 0 &&
                        suggestions.reason !== null ? (
                            <span className="store-cart-suggestions__reason">
                                {suggestions.reason}
                            </span>
                        ) : null}
                        <a
                            className="store-cart-suggestions__service-target"
                            href={service.href}
                        >
                            <span className="store-cart-suggestions__service-media">
                                <img
                                    alt=""
                                    height="360"
                                    loading="lazy"
                                    src={service.imageUrl}
                                    width="640"
                                />
                            </span>
                            <span className="store-cart-suggestions__service-body">
                                <strong>{service.title}</strong>
                                <span>{service.description}</span>
                                <span className="store-cart-suggestions__open">
                                    {translations.open}
                                </span>
                            </span>
                        </a>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function CartLine({
    cartItem,
    locale,
    onRemoved,
    translations,
}: {
    cartItem: StoreCartItem;
    locale: 'ar' | 'en';
    onRemoved: (removal: {
        cartCount: number;
        itemId: string;
        name: string;
        restoreUrl: string | null;
    }) => void;
    translations: StoreCartTranslations;
}) {
    // One tap removes the line at once; the undo bar is the way back. The
    // hold gesture it replaces never painted its fill on iOS, so lines just
    // disappeared with no recovery.
    const [removing, setRemoving] = useState(false);
    const [failed, setFailed] = useState(false);
    const configuration = cartItem.configuration;
    const isCoins = cartItem.product.serviceType === 'coins';
    const isFutChampions = cartItem.product.serviceType === 'fut_champions';
    const isRivals = cartItem.product.serviceType === 'rivals';
    const isManualService = isFutChampions || isRivals;
    const platform =
        configuration.platform === 'pc'
            ? translations.platform_pc
            : configuration.platform === 'playstation'
              ? isManualService
                  ? translations.platform_playstation_manual
                  : translations.platform_playstation
              : '—';
    const delivery =
        configuration.platform === 'pc' && configuration.delivery === null
            ? translations.delivery_pc
            : configuration.platform === 'playstation' &&
                configuration.delivery === 'fast'
              ? translations.delivery_fast
              : configuration.platform === 'playstation' &&
                  configuration.delivery === 'normal'
                ? translations.delivery_normal
                : '—';
    const quantity =
        configuration.coins_quantity === undefined
            ? '—'
            : `${formatCoins(configuration.coins_quantity, locale)} ${translations.coins_unit}`;

    function remove() {
        if (removing) {
            return;
        }

        setRemoving(true);
        setFailed(false);
        void removeCartItem(cartItem.deleteUrl)
            .then((result) =>
                onRemoved({
                    cartCount: result.cartCount,
                    itemId: cartItem.id,
                    name: cartItem.product.name,
                    restoreUrl: result.restoreUrl,
                }),
            )
            .catch(() => {
                setRemoving(false);
                setFailed(true);
            });
    }

    return (
        <li
            className={[
                'store-cart-line',
                cartItem.unavailableReason
                    ? 'store-cart-line--unavailable'
                    : '',
                removing ? 'store-cart-line--removing' : '',
            ]
                .filter(Boolean)
                .join(' ')}
        >
            <div className="store-cart-line__top">
                <span
                    className={[
                        'store-cart-line__thumb',
                        cartItem.product.serviceType === 'sbc'
                            ? 'store-cart-line__thumb--raised'
                            : '',
                    ]
                        .filter(Boolean)
                        .join(' ')}
                >
                    {cartItem.product.imageUrl !== null ? (
                        <img
                            alt={isCoins ? '' : cartItem.product.name}
                            aria-hidden={isCoins ? 'true' : undefined}
                            height="62"
                            src={cartItem.product.imageUrl}
                            width="62"
                        />
                    ) : (
                        <ShoppingBag aria-hidden="true" />
                    )}
                </span>
                <div className="store-cart-line__name">
                    <span>{translations.service}</span>
                    <h2>{cartItem.product.name}</h2>
                    {cartItem.unavailableReason ? (
                        <p className="store-cart-line__unavailable">
                            <span>{translations.unavailable}</span>
                            {translations.unavailable_note}
                        </p>
                    ) : null}
                </div>
                <div className="store-cart-line__trailing">
                    {cartItem.promotion ? (
                        <p className="store-cart-line__total store-cart-line__total--promo">
                            <strong>
                                {formatMinorUnits(
                                    cartItem.totalHalalah -
                                        cartItem.promotion.discountHalalah,
                                    'SAR',
                                    locale,
                                )}
                            </strong>
                            <del className="store-price-compare">
                                {formatMinorUnits(
                                    cartItem.totalHalalah,
                                    'SAR',
                                    locale,
                                )}
                            </del>
                            <span className="store-promo-badge">
                                {cartItem.promotion.badge}
                            </span>
                        </p>
                    ) : (
                        <p className="store-cart-line__total">
                            <strong>
                                {formatMinorUnits(
                                    cartItem.totalHalalah,
                                    'SAR',
                                    locale,
                                )}
                            </strong>
                        </p>
                    )}
                </div>
            </div>
            {failed ? (
                <p className="store-cart-line__remove-error" role="alert">
                    {translations.remove_error}
                </p>
            ) : null}
            <dl className="store-cart-line__chips">
                <CartFact label={translations.platform} value={platform} />
                {isCoins ? (
                    <CartFact label={translations.delivery} value={delivery} />
                ) : null}
                {isCoins && configuration.coins_quantity !== undefined ? (
                    <CartFact label={translations.quantity} value={quantity} />
                ) : null}
                {!isCoins &&
                cartItem.product.serviceType === 'sbc' &&
                configuration.completion_count !== undefined ? (
                    <CartFact
                        label={translations.completions}
                        value={formatInteger(
                            configuration.completion_count,
                            locale,
                        )}
                    />
                ) : null}
                {isManualService && configuration.pc_launcher !== undefined ? (
                    <CartFact
                        label={translations.launcher}
                        value={
                            configuration.pc_launcher === 'steam'
                                ? translations.launcher_steam
                                : translations.launcher_ea_app
                        }
                    />
                ) : null}
                {isFutChampions && configuration.target_rank !== undefined ? (
                    <CartFact
                        label={translations.rank}
                        value={interpolate(translations.rank_value, {
                            rank: formatInteger(
                                configuration.target_rank,
                                locale,
                            ),
                        })}
                    />
                ) : null}
                {isFutChampions && configuration.urgent !== undefined ? (
                    <CartFact
                        label={translations.urgent}
                        value={
                            configuration.urgent
                                ? translations.urgent_yes
                                : translations.urgent_no
                        }
                    />
                ) : null}
                {isFutChampions &&
                configuration.matches_played !== undefined ? (
                    <CartFact
                        label={translations.matches_played}
                        value={formatInteger(
                            configuration.matches_played,
                            locale,
                        )}
                    />
                ) : null}
                {isRivals && configuration.weekly_matches ? (
                    <CartFact
                        label={translations.mode}
                        value={translations.mode_weekly}
                    />
                ) : null}
                {isRivals && configuration.included_wins !== undefined ? (
                    <CartFact
                        label={translations.included_wins}
                        value={formatInteger(
                            configuration.included_wins,
                            locale,
                        )}
                    />
                ) : null}
                {isRivals && configuration.from_division !== undefined ? (
                    <CartFact
                        label={translations.from_division}
                        value={divisionLabel(
                            configuration.from_division,
                            locale,
                            translations,
                        )}
                    />
                ) : null}
                {isRivals && configuration.to_division !== undefined ? (
                    <CartFact
                        label={translations.to_division}
                        value={divisionLabel(
                            configuration.to_division,
                            locale,
                            translations,
                        )}
                    />
                ) : null}
            </dl>
            {cartItem.priceChanged && cartItem.previousTotalHalalah !== null ? (
                <p className="store-cart-line__repriced">
                    <span>{translations.price_was}</span>
                    <del>
                        {formatMinorUnits(
                            cartItem.previousTotalHalalah,
                            'SAR',
                            locale,
                        )}
                    </del>
                </p>
            ) : null}
            {cartItem.credentialsKind === 'manual' ? (
                <ManualCredentialState
                    cartItem={cartItem}
                    locale={locale}
                    translations={translations}
                />
            ) : (
                <CredentialState
                    cartItem={cartItem}
                    locale={locale}
                    translations={translations}
                />
            )}
            <div className="store-cart-line__actions">
                {cartItem.editUrl !== null && cartItem.editUrl !== undefined ? (
                    <a
                        className="store-cart-line__edit"
                        href={cartItem.editUrl}
                    >
                        <Pencil aria-hidden="true" />
                        <span>{translations.edit_line}</span>
                    </a>
                ) : null}
                <button
                    aria-label={translations.remove_item}
                    className="store-cart-line__remove"
                    disabled={removing}
                    onClick={remove}
                    type="button"
                >
                    <Trash2 aria-hidden="true" />
                    <span
                        aria-hidden="true"
                        className="store-cart-line__remove-label"
                    >
                        {translations.remove_short}
                    </span>
                </button>
            </div>
        </li>
    );
}

function divisionLabel(
    division: NonNullable<StoreCartItem['configuration']['from_division']>,
    locale: 'ar' | 'en',
    translations: StoreCartTranslations,
): string {
    return division === 'elite'
        ? translations.division_elite
        : formatInteger(Number(division), locale);
}

function ManualCredentialState({
    cartItem,
    locale,
    translations,
}: {
    cartItem: StoreCartItem;
    locale: 'ar' | 'en';
    translations: StoreCartTranslations;
}) {
    const [credentials, setCredentials] =
        useState<StoredManualCartCredentials | null>(null);
    const [draft, setDraft] = useState<StoredManualCartCredentials | null>(
        null,
    );
    const [expanded, setExpanded] = useState(false);
    const [editing, setEditing] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveState, setSaveState] = useState<'idle' | 'saved' | 'failed'>(
        'idle',
    );
    const fulfillment = cartItem.fulfillment ?? null;
    const ready =
        fulfillment !== null &&
        fulfillment.credentialsReady &&
        fulfillment.squadImagePresent;

    useEffect(() => {
        if (!expanded || !ready || cartItem.credentialsUrl === null) {
            return;
        }

        const controller = new AbortController();

        loadManualCartCredentials(cartItem.credentialsUrl, controller.signal)
            .then((loaded) => {
                setCredentials(loaded);
                setDraft(loaded);
            })
            .catch((error: unknown) => {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setLoadFailed(true);
                }
            });

        return () => controller.abort();
    }, [cartItem.credentialsUrl, expanded, ready]);

    if (!ready) {
        return (
            <p className="store-cart-line__status store-cart-line__status--missing">
                <span
                    aria-hidden="true"
                    className="store-cart-line__status-dot"
                />
                <span className="store-cart-line__status-label">
                    {translations.fulfillment_missing}
                </span>
            </p>
        );
    }

    const contentId = `cart-manual-credentials-${cartItem.id}`;
    const disclosure = (
        <button
            aria-controls={contentId}
            aria-expanded={expanded}
            className="store-cart-line__status store-cart-line__status--ready store-cart-credentials__disclosure"
            onClick={() => {
                // A failed load is retried on the next expand, not remembered.
                setLoadFailed(false);
                setExpanded((current) => !current);

                if (expanded) {
                    setEditing(false);
                    setDraft(credentials);
                    setSaveState('idle');
                }
            }}
            type="button"
        >
            <span aria-hidden="true" className="store-cart-line__status-dot" />
            <span className="store-cart-line__status-label">
                {translations.fulfillment_ready}
            </span>
            <ChevronDown aria-hidden="true" />
        </button>
    );

    if (!expanded) {
        return <div className="store-cart-credentials">{disclosure}</div>;
    }

    if (loadFailed) {
        return (
            <div className="store-cart-credentials">
                {disclosure}
                <p
                    className="store-cart-line__status store-cart-line__status--missing"
                    id={contentId}
                    role="alert"
                >
                    {translations.details_load_error}
                </p>
            </div>
        );
    }

    if (credentials === null || draft === null) {
        return (
            <div className="store-cart-credentials">
                {disclosure}
                <p id={contentId} role="status">
                    {translations.fulfillment_ready}
                </p>
            </div>
        );
    }

    function updateDraft<Key extends keyof StoredManualCartCredentials>(
        key: Key,
        value: StoredManualCartCredentials[Key],
    ) {
        setDraft((current) =>
            current === null ? current : { ...current, [key]: value },
        );
        setSaveState('idle');
    }

    function updateEaCode(index: 0 | 1 | 2, value: string) {
        if (draft === null) {
            return;
        }

        const eaCodes: [string, string, string] = [...draft.eaCodes];
        eaCodes[index] = value.replace(/[^0-9]/g, '').slice(0, 8);
        updateDraft('eaCodes', eaCodes);
    }

    function updatePlayStationCode(index: 0 | 1 | 2, value: string) {
        if (draft === null) {
            return;
        }

        const playstationCodes: [string, string, string] = [
            ...draft.playstationCodes,
        ];
        playstationCodes[index] = value
            .replace(/[^A-Za-z0-9]/g, '')
            .toUpperCase()
            .slice(0, 6);
        updateDraft('playstationCodes', playstationCodes);
    }

    async function save() {
        if (
            draft === null ||
            saving ||
            !isManualDraftValid(draft) ||
            cartItem.credentialsUrl === null
        ) {
            return;
        }

        setSaving(true);
        setSaveState('idle');

        try {
            await updateManualCartCredentials(cartItem.credentialsUrl, draft);
            setCredentials(draft);
            setEditing(false);
            setSaveState('saved');
        } catch {
            setSaveState('failed');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="store-cart-credentials">
            {disclosure}
            <div className="store-cart-credentials__content" id={contentId}>
                {editing ? (
                    <ManualCredentialForm
                        draft={draft}
                        groupId={cartItem.id}
                        locale={locale}
                        onCancel={() => {
                            setDraft(credentials);
                            setEditing(false);
                            setSaveState('idle');
                        }}
                        onEaCodeChange={updateEaCode}
                        onFieldChange={updateDraft}
                        onPlayStationCodeChange={updatePlayStationCode}
                        onSave={save}
                        saving={saving}
                        translations={translations}
                    />
                ) : (
                    <>
                        <ManualCredentialValues
                            credentials={credentials}
                            locale={locale}
                            translations={translations}
                        />
                        <button
                            className="store-cart-credentials__edit"
                            onClick={() => setEditing(true)}
                            type="button"
                        >
                            {translations.edit_details}
                        </button>
                    </>
                )}
                {saveState === 'saved' ? (
                    <p role="status">{translations.details_saved}</p>
                ) : null}
                {saveState === 'failed' ? (
                    <p role="alert">{translations.details_save_error}</p>
                ) : null}
            </div>
        </div>
    );
}

const MANUAL_EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MANUAL_EA_CODE_PATTERN = /^[0-9]{8}$/;
const MANUAL_PS_CODE_PATTERN = /^[A-Za-z0-9]{6}$/;

function isCodeTriple(
    codes: [string, string, string],
    pattern: RegExp,
): boolean {
    return (
        codes.every((code) => pattern.test(code)) && new Set(codes).size === 3
    );
}

function isManualDraftValid(draft: StoredManualCartCredentials): boolean {
    if (!isCodeTriple(draft.eaCodes, MANUAL_EA_CODE_PATTERN)) {
        return false;
    }

    if (draft.platform === 'playstation') {
        return (
            MANUAL_EMAIL_PATTERN.test(draft.playstationEmail) &&
            draft.playstationEmail.length <= 254 &&
            draft.playstationPassword.length >= 1 &&
            draft.playstationPassword.length <= 256 &&
            isCodeTriple(draft.playstationCodes, MANUAL_PS_CODE_PATTERN)
        );
    }

    return (
        MANUAL_EMAIL_PATTERN.test(draft.eaEmail) &&
        draft.eaEmail.length <= 254 &&
        draft.eaPassword.length >= 1 &&
        draft.eaPassword.length <= 256 &&
        (draft.launcher !== 'steam' ||
            (draft.steamUsername.trim() !== '' &&
                draft.steamUsername.length <= 128 &&
                draft.steamPassword.length >= 1 &&
                draft.steamPassword.length <= 256))
    );
}

function ManualCredentialValues({
    credentials,
    locale,
    translations,
}: {
    credentials: StoredManualCartCredentials;
    locale: 'ar' | 'en';
    translations: StoreCartTranslations;
}) {
    const rows: Array<{ label: string; value: string }> = [];

    if (credentials.platform === 'playstation') {
        rows.push(
            {
                label: translations.playstation_email,
                value: credentials.playstationEmail,
            },
            {
                label: translations.playstation_password,
                value: credentials.playstationPassword,
            },
        );
    } else {
        rows.push(
            { label: translations.ea_email, value: credentials.eaEmail },
            {
                label: translations.ea_password,
                value: credentials.eaPassword,
            },
        );
    }

    credentials.eaCodes.forEach((code, index) => {
        rows.push({
            label: `${translations.ea_backup_codes} · ${formatInteger(index + 1, locale)}`,
            value: code,
        });
    });

    if (credentials.platform === 'playstation') {
        credentials.playstationCodes.forEach((code, index) => {
            rows.push({
                label: `${translations.playstation_backup_codes} · ${formatInteger(index + 1, locale)}`,
                value: code,
            });
        });
    }

    if (credentials.platform === 'pc' && credentials.launcher === 'steam') {
        rows.push(
            {
                label: translations.steam_username,
                value: credentials.steamUsername,
            },
            {
                label: translations.steam_password,
                value: credentials.steamPassword,
            },
        );
    }

    return (
        <>
            <dl className="store-cart-credentials__values" dir="ltr">
                {rows.map((row, index) => (
                    <div key={index}>
                        <dt>{row.label}</dt>
                        <dd>{row.value}</dd>
                    </div>
                ))}
            </dl>
            <p className="store-cart-line__status store-cart-line__status--ready">
                <span
                    aria-hidden="true"
                    className="store-cart-line__status-dot"
                />
                <span className="store-cart-line__status-label">
                    {translations.squad_image_ready}
                </span>
            </p>
        </>
    );
}

function ManualCredentialForm({
    draft,
    groupId,
    locale,
    onCancel,
    onEaCodeChange,
    onFieldChange,
    onPlayStationCodeChange,
    onSave,
    saving,
    translations,
}: {
    draft: StoredManualCartCredentials;
    groupId: string;
    locale: 'ar' | 'en';
    onCancel: () => void;
    onEaCodeChange: (index: 0 | 1 | 2, value: string) => void;
    onFieldChange: <Key extends keyof StoredManualCartCredentials>(
        key: Key,
        value: StoredManualCartCredentials[Key],
    ) => void;
    onPlayStationCodeChange: (index: 0 | 1 | 2, value: string) => void;
    onSave: () => void;
    saving: boolean;
    translations: StoreCartTranslations;
}) {
    // Scoped per line: two manual lines on one platform must not share a
    // code group, or the auto-advance would jump between them.
    const group = `cart-manual-${groupId}`;

    return (
        <div className="store-cart-credentials__form">
            {draft.platform === 'playstation' ? (
                <>
                    <label>
                        <span>{translations.playstation_email}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                onFieldChange(
                                    'playstationEmail',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="email"
                            value={draft.playstationEmail}
                        />
                    </label>
                    <label>
                        <span>{translations.playstation_password}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                onFieldChange(
                                    'playstationPassword',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="text"
                            value={draft.playstationPassword}
                        />
                    </label>
                </>
            ) : (
                <>
                    <label>
                        <span>{translations.ea_email}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                onFieldChange(
                                    'eaEmail',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="email"
                            value={draft.eaEmail}
                        />
                    </label>
                    <label>
                        <span>{translations.ea_password}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                onFieldChange(
                                    'eaPassword',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="text"
                            value={draft.eaPassword}
                        />
                    </label>
                </>
            )}
            {draft.eaCodes.map((code, index) => (
                <label key={`ea-${index}`}>
                    <span>
                        {interpolate(translations.backup_code, {
                            number: formatInteger(index + 1, locale),
                        })}
                    </span>
                    <input
                        autoComplete="off"
                        data-code-field=""
                        data-code-group={group}
                        dir="ltr"
                        inputMode="numeric"
                        maxLength={8}
                        onChange={(event) => {
                            if (
                                event.currentTarget.value
                                    .replace(/[^0-9]/g, '')
                                    .slice(0, 8).length === 8 &&
                                code.length < 8
                            ) {
                                focusSiblingCodeField(event.currentTarget, 1);
                            }

                            onEaCodeChange(
                                index as 0 | 1 | 2,
                                event.currentTarget.value,
                            );
                        }}
                        onKeyDown={(event) => {
                            if (
                                event.key === 'Backspace' &&
                                event.currentTarget.value === ''
                            ) {
                                event.preventDefault();
                                focusSiblingCodeField(event.currentTarget, -1);
                            }
                        }}
                        pattern="[0-9]{8}"
                        required
                        value={code}
                    />
                </label>
            ))}
            {draft.platform === 'playstation'
                ? draft.playstationCodes.map((code, index) => (
                      <label key={`ps-${index}`}>
                          <span>
                              {interpolate(translations.backup_code, {
                                  number: formatInteger(index + 4, locale),
                              })}
                          </span>
                          <input
                              autoComplete="off"
                              data-code-field=""
                              data-code-group={group}
                              dir="ltr"
                              inputMode="text"
                              maxLength={6}
                              onChange={(event) => {
                                  if (
                                      event.currentTarget.value
                                          .replace(/[^A-Za-z0-9]/g, '')
                                          .toUpperCase()
                                          .slice(0, 6).length === 6 &&
                                      code.length < 6
                                  ) {
                                      focusSiblingCodeField(
                                          event.currentTarget,
                                          1,
                                      );
                                  }

                                  onPlayStationCodeChange(
                                      index as 0 | 1 | 2,
                                      event.currentTarget.value,
                                  );
                              }}
                              onKeyDown={(event) => {
                                  if (
                                      event.key === 'Backspace' &&
                                      event.currentTarget.value === ''
                                  ) {
                                      event.preventDefault();
                                      focusSiblingCodeField(
                                          event.currentTarget,
                                          -1,
                                      );
                                  }
                              }}
                              pattern="[A-Za-z0-9]{6}"
                              required
                              value={code}
                          />
                      </label>
                  ))
                : null}
            {draft.platform === 'pc' && draft.launcher === 'steam' ? (
                <>
                    <label>
                        <span>{translations.steam_username}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                onFieldChange(
                                    'steamUsername',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="text"
                            value={draft.steamUsername}
                        />
                    </label>
                    <label>
                        <span>{translations.steam_password}</span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            onChange={(event) =>
                                onFieldChange(
                                    'steamPassword',
                                    event.currentTarget.value,
                                )
                            }
                            required
                            type="text"
                            value={draft.steamPassword}
                        />
                    </label>
                </>
            ) : null}
            <div className="store-cart-credentials__actions">
                <button
                    disabled={saving || !isManualDraftValid(draft)}
                    onClick={onSave}
                    type="button"
                >
                    {translations.save_details}
                </button>
                <button onClick={onCancel} type="button">
                    {translations.cancel_edit}
                </button>
            </div>
        </div>
    );
}

function CredentialState({
    cartItem,
    locale,
    translations,
}: {
    cartItem: StoreCartItem;
    locale: 'ar' | 'en';
    translations: StoreCartTranslations;
}) {
    const coinsRequiresBalance =
        usePage<StoreCartPageProps>().props.coinsRequiresBalance === true;
    const [credentials, setCredentials] =
        useState<StoredCartCredentials | null>(null);
    const [draft, setDraft] = useState<StoredCartCredentials | null>(null);
    const [expanded, setExpanded] = useState(false);
    const [editing, setEditing] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saveState, setSaveState] = useState<'idle' | 'saved' | 'failed'>(
        'idle',
    );

    useEffect(() => {
        if (
            !expanded ||
            cartItem.requiresCredentials ||
            cartItem.credentials === null ||
            cartItem.credentialsUrl === null
        ) {
            return;
        }

        const controller = new AbortController();

        loadCartCredentials(cartItem.credentialsUrl, controller.signal)
            .then((loaded) => {
                setCredentials(loaded);
                setDraft(loaded);
            })
            .catch((error: unknown) => {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setLoadFailed(true);
                }
            });

        return () => controller.abort();
    }, [
        cartItem.credentials,
        cartItem.credentialsUrl,
        cartItem.requiresCredentials,
        expanded,
    ]);

    if (cartItem.requiresCredentials || cartItem.credentials === null) {
        return (
            <p className="store-cart-line__status store-cart-line__status--missing">
                <span
                    aria-hidden="true"
                    className="store-cart-line__status-dot"
                />
                <span className="store-cart-line__status-label">
                    {translations.credentials_missing}
                </span>
            </p>
        );
    }

    const contentId = `cart-credentials-${cartItem.id}`;
    const statusLabel = `${translations.credentials} · ${interpolate(
        translations.backup_codes,
        {
            count: formatInteger(cartItem.credentials.backupCodeCount, locale),
        },
    )}`;
    const disclosure = (
        <button
            aria-controls={contentId}
            aria-expanded={expanded}
            aria-label={
                expanded
                    ? translations.credentials_hide
                    : translations.credentials_show
            }
            className="store-cart-line__status store-cart-line__status--ready store-cart-credentials__disclosure"
            onClick={() => {
                setExpanded((current) => !current);

                if (expanded) {
                    setEditing(false);
                    setDraft(credentials);
                    setSaveState('idle');
                }
            }}
            type="button"
        >
            <span aria-hidden="true" className="store-cart-line__status-dot" />
            <span className="store-cart-line__status-label">{statusLabel}</span>
            <ChevronDown aria-hidden="true" />
        </button>
    );

    if (!expanded) {
        return <div className="store-cart-credentials">{disclosure}</div>;
    }

    if (loadFailed) {
        return (
            <div className="store-cart-credentials">
                {disclosure}
                <p
                    className="store-cart-line__status store-cart-line__status--missing"
                    id={contentId}
                    role="alert"
                >
                    {translations.credentials_load_error}
                </p>
            </div>
        );
    }

    if (credentials === null || draft === null) {
        return (
            <div className="store-cart-credentials">
                {disclosure}
                <p id={contentId} role="status">
                    {translations.credentials_ready}
                </p>
            </div>
        );
    }

    const isCoins = cartItem.product.serviceType === 'coins';
    const requiresBalance =
        coinsRequiresBalance &&
        isCoins &&
        cartItem.configuration.platform === 'playstation' &&
        cartItem.configuration.delivery === 'fast';

    const draftIsValid =
        /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(draft.eaEmail) &&
        draft.eaEmail.length <= 254 &&
        draft.eaPassword.length >= 1 &&
        draft.eaPassword.length <= 128 &&
        draft.backupCodes.every((code) => /^[0-9]{8}$/.test(code)) &&
        new Set(draft.backupCodes).size === 3 &&
        (!isCoins ||
            (draft.companionMarketOpen &&
                draft.policyAccepted &&
                (!requiresBalance || draft.currentBalance !== null)));

    function updateDraft<Key extends keyof StoredCartCredentials>(
        key: Key,
        value: StoredCartCredentials[Key],
    ) {
        setDraft((current) =>
            current === null ? current : { ...current, [key]: value },
        );
        setSaveState('idle');
    }

    function updateCode(index: 0 | 1 | 2, value: string) {
        if (draft === null) {
            return;
        }

        const backupCodes: [string, string, string] = [...draft.backupCodes];
        backupCodes[index] = value.replace(/[^0-9]/g, '').slice(0, 8);
        updateDraft('backupCodes', backupCodes);
    }

    async function save() {
        if (
            draft === null ||
            saving ||
            !draftIsValid ||
            cartItem.credentialsUrl === null
        ) {
            return;
        }

        setSaving(true);
        setSaveState('idle');

        try {
            // With the balance requirement off, a stored balance from before
            // the switch must not ride along — the server refuses a field the
            // store no longer collects.
            const outgoing = requiresBalance
                ? draft
                : { ...draft, currentBalance: null };

            await updateCartCredentials(cartItem.credentialsUrl, outgoing);
            setCredentials(outgoing);
            setEditing(false);
            setSaveState('saved');
        } catch {
            setSaveState('failed');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="store-cart-credentials">
            {disclosure}
            <div className="store-cart-credentials__content" id={contentId}>
                {editing ? (
                    <div className="store-cart-credentials__form">
                        <label>
                            <span>{translations.ea_email}</span>
                            <input
                                autoComplete="off"
                                dir="ltr"
                                onChange={(event) =>
                                    updateDraft(
                                        'eaEmail',
                                        event.currentTarget.value,
                                    )
                                }
                                required
                                type="email"
                                value={draft.eaEmail}
                            />
                        </label>
                        <label>
                            <span>{translations.ea_password}</span>
                            <input
                                autoComplete="off"
                                dir="ltr"
                                onChange={(event) =>
                                    updateDraft(
                                        'eaPassword',
                                        event.currentTarget.value,
                                    )
                                }
                                required
                                type="text"
                                value={draft.eaPassword}
                            />
                        </label>
                        {requiresBalance ? (
                            <label>
                                <span>{translations.current_balance}</span>
                                <input
                                    dir="ltr"
                                    inputMode="numeric"
                                    maxLength={9}
                                    onChange={(event) => {
                                        const digits = event.currentTarget.value
                                            .replace(/[^0-9]/g, '')
                                            .slice(0, 9);
                                        updateDraft(
                                            'currentBalance',
                                            digits === ''
                                                ? null
                                                : Number(digits),
                                        );
                                    }}
                                    required
                                    type="text"
                                    value={draft.currentBalance ?? ''}
                                />
                            </label>
                        ) : null}
                        {draft.backupCodes.map((code, index) => (
                            <label key={index}>
                                <span>
                                    {interpolate(translations.backup_code, {
                                        number: formatInteger(
                                            index + 1,
                                            locale,
                                        ),
                                    })}
                                </span>
                                <input
                                    autoComplete="off"
                                    data-code-field=""
                                    data-code-group={`cart-backup-${cartItem.id}`}
                                    dir="ltr"
                                    inputMode="numeric"
                                    maxLength={8}
                                    onChange={(event) => {
                                        if (
                                            event.currentTarget.value
                                                .replace(/[^0-9]/g, '')
                                                .slice(0, 8).length === 8 &&
                                            code.length < 8
                                        ) {
                                            focusSiblingCodeField(
                                                event.currentTarget,
                                                1,
                                            );
                                        }

                                        updateCode(
                                            index as 0 | 1 | 2,
                                            event.currentTarget.value,
                                        );
                                    }}
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Backspace' &&
                                            event.currentTarget.value === ''
                                        ) {
                                            event.preventDefault();
                                            focusSiblingCodeField(
                                                event.currentTarget,
                                                -1,
                                            );
                                        }
                                    }}
                                    pattern="[0-9]{8}"
                                    required
                                    value={code}
                                />
                            </label>
                        ))}
                        <div className="store-cart-credentials__actions">
                            <button
                                disabled={saving || !draftIsValid}
                                onClick={save}
                                type="button"
                            >
                                {translations.save_credentials}
                            </button>
                            <button
                                onClick={() => {
                                    setDraft(credentials);
                                    setEditing(false);
                                    setSaveState('idle');
                                }}
                                type="button"
                            >
                                {translations.cancel_edit}
                            </button>
                        </div>
                    </div>
                ) : (
                    <>
                        <dl
                            className="store-cart-credentials__values"
                            dir="ltr"
                        >
                            <div>
                                <dt>{translations.ea_email}</dt>
                                <dd>{credentials.eaEmail}</dd>
                            </div>
                            <div>
                                <dt>{translations.ea_password}</dt>
                                <dd>{credentials.eaPassword}</dd>
                            </div>
                            {credentials.backupCodes.map((code, index) => (
                                <div key={index}>
                                    <dt>
                                        {interpolate(translations.backup_code, {
                                            number: formatInteger(
                                                index + 1,
                                                locale,
                                            ),
                                        })}
                                    </dt>
                                    <dd>{code}</dd>
                                </div>
                            ))}
                            {isCoins && credentials.currentBalance !== null ? (
                                <div>
                                    <dt>{translations.current_balance}</dt>
                                    <dd>
                                        {formatInteger(
                                            credentials.currentBalance,
                                            locale,
                                        )}
                                    </dd>
                                </div>
                            ) : null}
                        </dl>
                        <button
                            className="store-cart-credentials__edit"
                            onClick={() => setEditing(true)}
                            type="button"
                        >
                            {translations.edit_credentials}
                        </button>
                    </>
                )}
                {saveState === 'saved' ? (
                    <p role="status">{translations.credentials_saved}</p>
                ) : null}
                {saveState === 'failed' ? (
                    <p role="alert">{translations.credentials_save_error}</p>
                ) : null}
            </div>
        </div>
    );
}

function CartFact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}
