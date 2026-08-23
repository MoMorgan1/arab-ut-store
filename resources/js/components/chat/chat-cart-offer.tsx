import { Link } from '@inertiajs/react';
import { Check, Loader2, ShoppingCart } from 'lucide-react';
import type { FormEvent } from 'react';
import { useId, useRef, useState } from 'react';

import { announceCartAddition } from '@/lib/cart-added-event';
import type { ChatCoinsCartOffer } from '@/lib/chat-cart';
import { CoinsCartRequestError, submitCoinsCart } from '@/lib/coins-cart-api';
import { formatCoins, formatMinorUnits } from '@/lib/money';
import type { ChatServicePrices } from '@/types/chat';
import type { CoinsCredentialField, CoinsCredentials } from '@/types/coins';

import { chatCartCopy } from './chat-cart-copy';

const EMPTY_CREDENTIALS: CoinsCredentials = {
    eaEmail: '',
    eaPassword: '',
    backupCodes: ['', '', ''],
    currentBalance: '',
    companionMarketOpen: false,
    policyAccepted: false,
};

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const COIN_IMAGE = '/images/store/coins/ut-coin-160.webp';

const INPUT_CLASS =
    'mt-1 w-full rounded-xl border bg-[var(--chat-card)] px-3 py-2 text-[13px] text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--arabut-focus)]';

/**
 * Mirrors the cart endpoint's own rules so an obvious mistake is caught before
 * a round trip. The server stays the authority: whatever it rejects is mapped
 * back onto the same fields.
 */
function localValidation(
    credentials: CoinsCredentials,
    requiresBalance: boolean,
): CoinsCredentialField[] {
    const rejected: CoinsCredentialField[] = [];

    if (
        credentials.eaEmail.length > 254 ||
        !EMAIL_PATTERN.test(credentials.eaEmail)
    ) {
        rejected.push('email');
    }

    if (
        credentials.eaPassword.length < 1 ||
        credentials.eaPassword.length > 128
    ) {
        rejected.push('password');
    }

    ([0, 1, 2] as const).forEach((index) => {
        const code = credentials.backupCodes[index];

        if (
            !/^[0-9]{8}$/.test(code) ||
            credentials.backupCodes.indexOf(code) !== index
        ) {
            rejected.push(`code-${index}`);
        }
    });

    if (requiresBalance) {
        const balance = Number(credentials.currentBalance);

        if (
            credentials.currentBalance === '' ||
            !Number.isSafeInteger(balance) ||
            balance < 0 ||
            balance > 100_000_000
        ) {
            rejected.push('current-balance');
        }
    }

    if (credentials.companionMarketOpen !== true) {
        rejected.push('companion');
    }

    if (credentials.policyAccepted !== true) {
        rejected.push('policy');
    }

    return rejected;
}

/**
 * Puts a configured coins order in the customer's cart without leaving the
 * chat.
 *
 * The store requires EA details at cart-add time, so the panel collects them.
 * They are typed here and posted straight to the cart endpoint over the
 * customer's own session — never sent as a chat message, never written to the
 * transcript, and never reaching the model. The price is fetched live for the
 * same reason chat never stores one: history is permanent and prices move.
 */
export function ChatCartOffer({
    offer,
    locale,
    servicePrices,
    onNavigate,
}: {
    offer: ChatCoinsCartOffer | null;
    locale: string;
    servicePrices?: ChatServicePrices;
    onNavigate?: () => void;
}) {
    if (offer === null) {
        return null;
    }

    return (
        <CoinsCartPanel
            key={`${offer.platform}:${offer.delivery ?? '-'}:${offer.quantity}`}
            locale={locale}
            offer={offer}
            servicePrices={servicePrices}
            onNavigate={onNavigate}
        />
    );
}

function CoinsCartPanel({
    offer,
    locale,
    servicePrices = {},
    onNavigate,
}: {
    offer: ChatCoinsCartOffer;
    locale: string;
    servicePrices?: ChatServicePrices;
    onNavigate?: () => void;
}) {
    const isEn = locale === 'en';
    const moneyLocale: 'ar' | 'en' = isEn ? 'en' : 'ar';
    const copy = chatCartCopy(moneyLocale);
    const { delivery, platform, quantity } = offer;
    const requiresBalance = platform === 'playstation' && delivery === 'fast';

    const [expanded, setExpanded] = useState(false);
    const [credentials, setCredentials] =
        useState<CoinsCredentials>(EMPTY_CREDENTIALS);
    const [rejected, setRejected] = useState<CoinsCredentialField[]>([]);
    const [pending, setPending] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [addedCartUrl, setAddedCartUrl] = useState<string | null>(null);

    // The cart endpoint is idempotent per key, so one key covers a retry after
    // an inconclusive failure: a timeout that actually landed adds one item,
    // not two.
    const idempotencyKey = useRef<string | null>(null);
    const formRef = useRef<HTMLFormElement | null>(null);
    const errorId = useId();

    // The price comes from the one cached map the widget already fetches for
    // cards and the shelf, keyed exactly as the server named this offer. A
    // per-panel quote request would fire once per reply and log a console
    // error every time pricing was unavailable.
    const priceKey = `coins:${platform}${delivery === null ? '' : `:${delivery}`}:${quantity}`;
    const price = servicePrices[priceKey];

    let quote: string | null = null;

    if (
        price !== undefined &&
        typeof price.amountMinor === 'number' &&
        typeof price.currency === 'string'
    ) {
        try {
            quote = formatMinorUnits(
                price.amountMinor,
                price.currency,
                moneyLocale,
            );
        } catch {
            quote = null;
        }
    }

    // Nobody should commit to a purchase without having seen the number. When
    // the store cannot price this configuration, the panel says so instead of
    // offering a button.
    const priced = quote !== null;

    const selectionLabel = [
        copy.platforms[platform],
        delivery === null ? null : copy.deliveries[delivery],
        copy.quantity(formatCoins(quantity, moneyLocale)),
    ]
        .filter((part): part is string => part !== null)
        .join(' · ');

    function expand() {
        setExpanded(true);
        // The button that had focus is about to unmount. Without this, focus
        // falls to the document and a keyboard user is dropped out of the panel.
        window.requestAnimationFrame(() => {
            formRef.current?.querySelector('input')?.focus();
        });
    }

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const invalid = localValidation(credentials, requiresBalance);
        setRejected(invalid);

        if (invalid.length > 0) {
            setErrorMessage(copy.fixFields);

            return;
        }

        idempotencyKey.current ??= crypto.randomUUID();
        setPending(true);
        setErrorMessage(null);

        try {
            const addition = await submitCoinsCart({
                cartUrl: isEn ? '/en/cart/items/coins' : '/cart/items/coins',
                credentials,
                delivery,
                idempotencyKey: idempotencyKey.current,
                platform,
                quantity,
            });

            // The details have done their job. Drop them from component state
            // rather than leaving a long-lived panel holding a password.
            setCredentials(EMPTY_CREDENTIALS);
            idempotencyKey.current = null;
            setAddedCartUrl(addition.cartUrl);
            setExpanded(false);
            announceCartAddition({
                cartUrl: addition.cartUrl,
                imageAlt: selectionLabel,
                imageUrl: COIN_IMAGE,
                itemLabel: copy.quantity(formatCoins(quantity, moneyLocale)),
                selectionLabel,
            });
            window.dispatchEvent(
                new CustomEvent<number>('arabut:cart-count', {
                    detail: addition.cartCount,
                }),
            );
        } catch (error) {
            if (error instanceof CoinsCartRequestError) {
                setRejected(error.validationFields);
                setErrorMessage(
                    error.code === 'validation_error'
                        ? copy.fixFields
                        : copy.addFailed,
                );

                // A conclusive rejection spends the idempotency claim; reusing
                // the key would replay the failure instead of retrying.
                if (error.conclusive) {
                    idempotencyKey.current = null;
                }
            } else {
                setErrorMessage(copy.addFailed);
            }
        } finally {
            setPending(false);
        }
    }

    function updateCode(index: 0 | 1 | 2, value: string) {
        const codes: [string, string, string] = [...credentials.backupCodes];
        codes[index] = value.replace(/\D/g, '').slice(0, 8);
        setCredentials({ ...credentials, backupCodes: codes });
    }

    /**
     * Colour alone does not communicate a rejected field — `aria-invalid` and
     * a pointer at the error text do, for anyone not reading the border.
     */
    function fieldProps(field: CoinsCredentialField, extra = '') {
        const invalid = rejected.includes(field);

        return {
            'aria-describedby': invalid ? errorId : undefined,
            'aria-invalid': invalid,
            className: `${INPUT_CLASS} ${extra} ${
                invalid
                    ? 'border-[var(--chat-danger)]'
                    : 'border-[var(--chat-line-strong)]'
            }`,
        };
    }

    function checkboxProps(field: CoinsCredentialField) {
        const invalid = rejected.includes(field);

        return {
            'aria-describedby': invalid ? errorId : undefined,
            'aria-invalid': invalid,
            className: `h-5 w-5 flex-shrink-0 rounded border ${
                invalid
                    ? 'border-[var(--chat-danger)]'
                    : 'border-[var(--chat-line-strong)]'
            }`,
        };
    }

    /**
     * Third-party password managers offer to save whatever they recognise. An
     * EA account is not the customer's Arab UT login, and a manager quietly
     * filing it under this site is a footgun the storefront form already
     * declines the same way.
     */
    const SECRET_INPUT = {
        autoComplete: 'off' as const,
        'data-1p-ignore': true,
        'data-lpignore': 'true',
        spellCheck: false,
    };

    return (
        <div
            className="chat-service-card mt-2 rounded-2xl border border-[var(--chat-line-strong)] bg-[var(--chat-card)] p-3 shadow-[0_2px_10px_rgb(13_11_8/0.06)]"
            data-testid="chat-cart-offer"
        >
            <div className="flex items-start gap-2">
                <ShoppingCart
                    aria-hidden="true"
                    className="mt-0.5 h-4 w-4 flex-shrink-0 text-[var(--chat-accent-ink)]"
                />
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-semibold text-[var(--chat-ink)]">
                        {selectionLabel}
                    </p>
                    {priced && (
                        <p
                            className="mt-0.5 text-start text-sm font-bold text-[var(--chat-accent-ink)]"
                            data-testid="chat-cart-price"
                            dir="ltr"
                        >
                            {quote}
                        </p>
                    )}
                    {quote === null && (
                        <p
                            className="mt-0.5 text-[11px] text-[var(--chat-muted)]"
                            data-testid="chat-cart-unpriced"
                        >
                            {copy.noPrice}
                        </p>
                    )}
                </div>
            </div>

            {addedCartUrl !== null && (
                <div className="mt-2.5 flex items-center gap-2">
                    <Check
                        aria-hidden="true"
                        className="h-4 w-4 flex-shrink-0 text-[var(--chat-accent-ink)]"
                    />
                    <span
                        className="text-[13px] font-semibold text-[var(--chat-ink)]"
                        data-testid="chat-cart-added"
                        role="status"
                    >
                        {copy.added}
                    </span>
                    <Link
                        className="chat-press ms-auto rounded-xl bg-[var(--chat-accent)] px-3 py-1.5 text-[13px] font-semibold text-[var(--chat-on-accent)]"
                        href={addedCartUrl}
                        onClick={onNavigate}
                        prefetch
                    >
                        {copy.viewCart}
                    </Link>
                </div>
            )}

            {addedCartUrl === null && !expanded && priced && (
                <button
                    className="chat-press mt-2.5 flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--chat-accent)] px-3 py-2 text-[13px] font-semibold text-[var(--chat-on-accent)]"
                    data-testid="chat-cart-start"
                    onClick={expand}
                    type="button"
                >
                    {copy.addToCart}
                </button>
            )}

            {addedCartUrl === null && expanded && priced && (
                // The panel reports its own errors, in the store's language:
                // the browser's native bubbles arrive in the browser's locale
                // and would contradict the Arabic beside them.
                <form
                    className="mt-2.5 space-y-2"
                    noValidate
                    onSubmit={handleSubmit}
                    ref={formRef}
                >
                    <p className="text-[11px] leading-snug text-[var(--chat-muted)]">
                        {copy.credentialsNotice}
                    </p>

                    <label className="block">
                        <span className="text-[11px] font-semibold text-[var(--chat-muted)]">
                            {copy.eaEmail}
                        </span>
                        <input
                            {...SECRET_INPUT}
                            {...fieldProps('email')}
                            dir="ltr"
                            name="ea_email"
                            onChange={(event) =>
                                setCredentials({
                                    ...credentials,
                                    eaEmail: event.target.value,
                                })
                            }
                            type="email"
                            value={credentials.eaEmail}
                        />
                    </label>

                    <label className="block">
                        <span className="text-[11px] font-semibold text-[var(--chat-muted)]">
                            {copy.eaPassword}
                        </span>
                        <input
                            {...SECRET_INPUT}
                            {...fieldProps('password')}
                            dir="ltr"
                            name="ea_password"
                            onChange={(event) =>
                                setCredentials({
                                    ...credentials,
                                    eaPassword: event.target.value,
                                })
                            }
                            type="password"
                            value={credentials.eaPassword}
                        />
                    </label>

                    <fieldset className="block">
                        <legend className="text-[11px] font-semibold text-[var(--chat-muted)]">
                            {copy.backupCodes}
                        </legend>
                        <div className="flex gap-1.5" dir="ltr">
                            {([0, 1, 2] as const).map((index) => (
                                <input
                                    {...SECRET_INPUT}
                                    {...fieldProps(
                                        `code-${index}`,
                                        'text-center',
                                    )}
                                    aria-label={copy.backupCode(index + 1)}
                                    inputMode="numeric"
                                    key={index}
                                    maxLength={8}
                                    onChange={(event) =>
                                        updateCode(index, event.target.value)
                                    }
                                    type="text"
                                    value={credentials.backupCodes[index]}
                                />
                            ))}
                        </div>
                    </fieldset>

                    {requiresBalance && (
                        <label className="block">
                            <span className="text-[11px] font-semibold text-[var(--chat-muted)]">
                                {copy.currentBalance}
                            </span>
                            <input
                                {...SECRET_INPUT}
                                {...fieldProps('current-balance')}
                                dir="ltr"
                                inputMode="numeric"
                                maxLength={9}
                                onChange={(event) =>
                                    setCredentials({
                                        ...credentials,
                                        currentBalance:
                                            event.target.value.replace(
                                                /\D/g,
                                                '',
                                            ),
                                    })
                                }
                                type="text"
                                value={credentials.currentBalance ?? ''}
                            />
                        </label>
                    )}

                    <label className="flex items-start gap-2">
                        <input
                            {...checkboxProps('companion')}
                            checked={credentials.companionMarketOpen === true}
                            onChange={(event) =>
                                setCredentials({
                                    ...credentials,
                                    companionMarketOpen: event.target.checked,
                                })
                            }
                            type="checkbox"
                        />
                        <span className="text-[11px] leading-snug text-[var(--chat-ink)]">
                            {copy.companion}
                        </span>
                    </label>

                    <label className="flex items-start gap-2">
                        <input
                            {...checkboxProps('policy')}
                            checked={credentials.policyAccepted === true}
                            onChange={(event) =>
                                setCredentials({
                                    ...credentials,
                                    policyAccepted: event.target.checked,
                                })
                            }
                            type="checkbox"
                        />
                        <span className="text-[11px] leading-snug text-[var(--chat-ink)]">
                            {copy.policy}
                        </span>
                    </label>

                    {errorMessage !== null && (
                        <p
                            className="text-[11px] font-semibold text-[var(--chat-danger)]"
                            data-testid="chat-cart-error"
                            id={errorId}
                            role="alert"
                        >
                            {errorMessage}
                        </p>
                    )}

                    <button
                        className="chat-press flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--chat-accent)] px-3 py-2 text-[13px] font-semibold text-[var(--chat-on-accent)] disabled:opacity-60"
                        data-testid="chat-cart-submit"
                        disabled={pending}
                        type="submit"
                    >
                        {pending && (
                            <Loader2
                                aria-hidden="true"
                                className="h-4 w-4 animate-spin motion-reduce:animate-none"
                            />
                        )}
                        {pending ? copy.adding : copy.confirmAdd}
                    </button>
                </form>
            )}
        </div>
    );
}
