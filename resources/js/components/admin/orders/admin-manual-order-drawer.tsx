import { router } from '@inertiajs/react';
import { Check, Plus, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { formatHalalahToSar } from '@/components/admin/admin-money';
import AdminManualOrderItem from '@/components/admin/orders/admin-manual-order-item';
import {
    blockingProblem,
    buildPayload,
    emptyItem,
    requiredKeys,
    today,
    totalHalalah,
} from '@/components/admin/orders/manual-order-form';
import type {
    ManualOrderForm,
    ManualOrderItemForm,
} from '@/components/admin/orders/manual-order-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    AdminManualOrderCustomer,
    AdminManualOrderOptions,
    AdminManualOrderTranslations,
    AdminManualOrderUrls,
} from '@/types/admin';

/** Long enough that typing a phone number is one search, not eleven. */
const SEARCH_DEBOUNCE_MS = 350;

/** The price follows the fields, so it waits for them to stop moving. */
const PRICE_DEBOUNCE_MS = 400;

export type AdminManualOrderDrawerProps = {
    copy: AdminManualOrderTranslations;
    onClose: () => void;
    open: boolean;
    urls: AdminManualOrderUrls;
};

/**
 * Creates an order the store did not sell.
 *
 * It lives on the orders screen rather than a page of its own (owner,
 * 2026-09-13) and extends the drawer pattern the coupons screen established.
 * The branching is the design: a gift has no payment group at all rather than a
 * greyed-out one, and a service delivered by a person shows no supplier
 * reference, because a reference pasted there would make a job nothing reads.
 */
export default function AdminManualOrderDrawer({
    copy,
    onClose,
    open,
    urls,
}: AdminManualOrderDrawerProps) {
    const [options, setOptions] = useState<AdminManualOrderOptions | null>(
        null,
    );
    const [optionsFailed, setOptionsFailed] = useState(false);
    const [form, setForm] = useState<ManualOrderForm>(() => ({
        isGift: false,
        delivery: 'placed',
        customer: null,
        paymentReference: '',
        paymentReceivedAt: today(),
        items: [],
    }));
    const [search, setSearch] = useState('');
    // The answer is stored with the question it answers, so what is shown is
    // derived rather than cleared: no effect has to reach back and blank the
    // list when the query moves on, and a stale list can never be displayed
    // against a newer search.
    const [found, setFound] = useState<{
        customers: AdminManualOrderCustomer[];
        query: string;
    } | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // Read once, when the drawer opens. The orders list is loaded constantly
    // and a manual order is rare, so the catalogue does not ride along on it.
    useEffect(() => {
        if (!open || options !== null) {
            return;
        }

        let live = true;

        void fetch(urls.optionsUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(response),
            )
            .then((json: { data: AdminManualOrderOptions }) => {
                if (!live) {
                    return;
                }

                setOptions(json.data);
                setForm((current) =>
                    current.items.length > 0
                        ? current
                        : {
                              ...current,
                              items: [
                                  emptyItem(
                                      json.data.services[0]?.value ?? '',
                                      json.data.services[0]?.platforms[0] ?? '',
                                  ),
                              ],
                          },
                );
            })
            .catch(() => {
                if (live) {
                    setOptionsFailed(true);
                }
            });

        return () => {
            live = false;
        };
    }, [open, options, urls.optionsUrl]);

    // Customer search, as you type. Everything it sets, it sets from the
    // request's own callback - never synchronously in the effect body, which
    // is what makes the render above the only place that decides what shows.
    const query = search.trim();
    const searching = query.length >= 2 && found?.query !== query;
    const matches = found?.query === query ? found.customers : null;

    useEffect(() => {
        if (query.length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            void fetch(
                `${urls.customerSearchUrl}?q=${encodeURIComponent(query)}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) =>
                    response.ok ? response.json() : Promise.reject(response),
                )
                .then(
                    (json: {
                        data: { customers: AdminManualOrderCustomer[] };
                    }) => setFound({ customers: json.data.customers, query }),
                )
                .catch(() => {
                    if (!controller.signal.aborted) {
                        // An empty answer rather than a stuck spinner: the
                        // search found nothing it can show, which is what the
                        // person needs to know.
                        setFound({ customers: [], query });
                    }
                });
        }, SEARCH_DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, urls.customerSearchUrl]);

    const patchItem = useCallback(
        (index: number, patch: Partial<ManualOrderItemForm>) => {
            setForm((current) => ({
                ...current,
                items: current.items.map((item, at) =>
                    at === index ? { ...item, ...patch } : item,
                ),
            }));
        },
        [],
    );

    const patchConfiguration = useCallback(
        (index: number, key: string, value: string) => {
            setForm((current) => ({
                ...current,
                items: current.items.map((item, at) =>
                    at === index
                        ? {
                              ...item,
                              configuration: {
                                  ...item.configuration,
                                  [key]: value,
                              },
                          }
                        : item,
                ),
            }));
        },
        [],
    );

    const total = totalHalalah(form);
    const problem = blockingProblem(form, options);
    const submit = () => {
        setSaving(true);
        setErrors({});
        setMessage(null);

        router.post(urls.createUrl, buildPayload(form, options), {
            onError: (pageErrors) => {
                setErrors(pageErrors as Record<string, string>);
                setMessage(copy.errors.validation);
            },
            onFinish: () => setSaving(false),
            // An exception is not a validation failure and must not be read as
            // one: the form says the order was not created and keeps every
            // field, rather than clearing itself and losing the typing.
            onHttpException: () => {
                setMessage(copy.errors.generic);

                return false;
            },
            onNetworkError: () => {
                setMessage(copy.errors.network);

                return false;
            },
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <Sheet
            onOpenChange={(next) => !next && !saving && onClose()}
            open={open}
        >
            <SheetContent
                className="flex max-h-screen w-full flex-col overflow-y-auto motion-reduce:animate-none motion-reduce:transition-none sm:max-w-sm"
                side="right"
            >
                <SheetHeader className="border-b border-border pb-4">
                    <SheetTitle className="text-lg font-bold text-foreground">
                        {copy.title}
                    </SheetTitle>
                    <SheetDescription className="text-xs leading-relaxed text-muted-foreground">
                        {copy.description}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-1 flex-col gap-6 py-4">
                    {optionsFailed ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {copy.errors.optionsFailed}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {message === null ? null : (
                        <Alert variant="destructive">
                            <AlertDescription>{message}</AlertDescription>
                        </Alert>
                    )}

                    {/* Type first: it decides whether there is money to record at all. */}
                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.typeLabel}
                        </legend>
                        <div className="grid grid-cols-2 gap-1 rounded-md border border-border p-1">
                            {[
                                { gift: false, label: copy.typeTransfer },
                                { gift: true, label: copy.typeGift },
                            ].map((choice) => (
                                <Button
                                    aria-pressed={form.isGift === choice.gift}
                                    className="min-h-11"
                                    key={choice.label}
                                    onClick={() =>
                                        setForm((current) => ({
                                            ...current,
                                            isGift: choice.gift,
                                            paymentReference: choice.gift
                                                ? ''
                                                : current.paymentReference,
                                        }))
                                    }
                                    type="button"
                                    variant={
                                        form.isGift === choice.gift
                                            ? 'default'
                                            : 'ghost'
                                    }
                                >
                                    {choice.label}
                                </Button>
                            ))}
                        </div>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            {form.isGift ? copy.giftNote : copy.transferNote}
                        </p>
                    </fieldset>

                    <CustomerPicker
                        copy={copy}
                        customer={form.customer}
                        error={errors.customer_id}
                        matches={matches}
                        onClear={() =>
                            setForm((current) => ({
                                ...current,
                                customer: null,
                            }))
                        }
                        onSearch={setSearch}
                        onSelect={(customer) => {
                            setForm((current) => ({ ...current, customer }));
                            setSearch('');
                            setFound(null);
                        }}
                        search={search}
                        searching={searching}
                    />

                    {/* Delivery sits above the items because it decides what each one asks for. */}
                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.deliveryLabel}
                        </legend>
                        {[
                            {
                                body: copy.deliveryPlacedBody,
                                title: copy.deliveryPlacedTitle,
                                value: 'placed' as const,
                            },
                            {
                                body: copy.deliveryLaterBody,
                                title: copy.deliveryLaterTitle,
                                value: 'later' as const,
                            },
                        ].map((choice) => (
                            <label
                                className={`flex min-h-11 cursor-pointer items-start gap-3 rounded-md border p-3 ${
                                    form.delivery === choice.value
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border'
                                }`}
                                key={choice.value}
                            >
                                <input
                                    checked={form.delivery === choice.value}
                                    className="mt-1 size-4 accent-primary"
                                    name="manual-order-delivery"
                                    onChange={() =>
                                        setForm((current) => ({
                                            ...current,
                                            delivery: choice.value,
                                        }))
                                    }
                                    type="radio"
                                    value={choice.value}
                                />
                                <span>
                                    <span className="block text-sm font-medium text-foreground">
                                        {choice.title}
                                    </span>
                                    <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                                        {choice.body}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>

                    {form.items.map((item, index) => (
                        <ItemWithPrice
                            copy={copy}
                            errors={errors}
                            index={index}
                            isGift={form.isGift}
                            item={item}
                            key={item.key}
                            onChange={(patch) => patchItem(index, patch)}
                            onConfigurationChange={(key, value) =>
                                patchConfiguration(index, key, value)
                            }
                            onRemove={
                                form.items.length > 1
                                    ? () =>
                                          setForm((current) => ({
                                              ...current,
                                              items: current.items.filter(
                                                  (_, at) => at !== index,
                                              ),
                                          }))
                                    : null
                            }
                            options={options}
                            priceUrl={urls.priceUrl}
                            showPlacement={form.delivery === 'placed'}
                        />
                    ))}

                    <Button
                        className="min-h-11 w-full"
                        disabled={options === null}
                        onClick={() =>
                            setForm((current) => ({
                                ...current,
                                items: [
                                    ...current.items,
                                    emptyItem(
                                        options?.services[0]?.value ?? '',
                                        options?.services[0]?.platforms[0] ??
                                            '',
                                    ),
                                ],
                            }))
                        }
                        type="button"
                        variant="outline"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                        {copy.addItem}
                    </Button>

                    {form.isGift ? (
                        <div className="rounded-md border border-primary/40 bg-primary/5 p-3">
                            <p className="text-sm font-medium text-foreground">
                                {copy.giftHeading}
                            </p>
                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                {copy.giftBody}
                            </p>
                        </div>
                    ) : (
                        <section className="flex flex-col gap-3 rounded-lg border border-border bg-card/60 p-4">
                            <h2 className="text-sm font-semibold text-primary">
                                {copy.paymentLabel}
                            </h2>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="manual-payment-amount">
                                    {copy.paymentAmount}
                                </Label>
                                {/* Derived, not typed: CreateManualOrder refuses a
                                    payment that does not match the items, so a
                                    second editable number could only disagree. */}
                                <output
                                    className="flex min-h-11 items-center rounded-md border border-border bg-muted/40 px-3 text-sm font-bold text-foreground tabular-nums"
                                    id="manual-payment-amount"
                                >
                                    <bdi>
                                        {formatHalalahToSar(total)}{' '}
                                        {copy.currency}
                                    </bdi>
                                </output>
                                <p className="text-xs text-muted-foreground">
                                    {copy.paymentAmountHelp}
                                </p>
                                {errors['payment.amount_halalah'] ===
                                undefined ? null : (
                                    <p className="text-xs text-destructive">
                                        {errors['payment.amount_halalah']}
                                    </p>
                                )}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="manual-payment-date">
                                    {copy.paymentReceivedAt}
                                </Label>
                                <Input
                                    className="min-h-11"
                                    id="manual-payment-date"
                                    max={today()}
                                    onChange={(event) =>
                                        setForm((current) => ({
                                            ...current,
                                            paymentReceivedAt:
                                                event.target.value,
                                        }))
                                    }
                                    type="date"
                                    value={form.paymentReceivedAt}
                                />
                                {errors['payment.received_at'] ===
                                undefined ? null : (
                                    <p className="text-xs text-destructive">
                                        {errors['payment.received_at']}
                                    </p>
                                )}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="manual-payment-reference">
                                    {copy.paymentReference}
                                </Label>
                                <Input
                                    className="min-h-11 font-mono"
                                    dir="ltr"
                                    id="manual-payment-reference"
                                    onChange={(event) =>
                                        setForm((current) => ({
                                            ...current,
                                            paymentReference:
                                                event.target.value,
                                        }))
                                    }
                                    value={form.paymentReference}
                                />
                                {errors['payment.reference'] ===
                                undefined ? null : (
                                    <p className="text-xs text-destructive">
                                        {errors['payment.reference']}
                                    </p>
                                )}
                            </div>

                            <p className="text-xs leading-relaxed text-muted-foreground">
                                {copy.paymentHelp}
                            </p>
                        </section>
                    )}
                </div>

                <SheetFooter className="flex-col gap-3 border-t border-border pt-4">
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {copy.total}
                        </span>
                        <span className="text-base font-bold text-foreground tabular-nums">
                            <bdi>
                                {formatHalalahToSar(total)} {copy.currency}
                            </bdi>
                        </span>
                    </div>
                    <p className="text-xs leading-relaxed text-muted-foreground">
                        {copy.auditNote}
                    </p>
                    {problem === 'noCustomer' ? (
                        <p className="text-xs text-muted-foreground">
                            {copy.errors.noCustomer}
                        </p>
                    ) : null}
                    <Button
                        className="min-h-11 w-full"
                        disabled={
                            saving || problem !== null || options === null
                        }
                        onClick={submit}
                        type="button"
                    >
                        {saving ? copy.submitting : copy.submit}
                    </Button>
                    <Button
                        className="min-h-11 w-full"
                        disabled={saving}
                        onClick={onClose}
                        type="button"
                        variant="ghost"
                    >
                        {copy.cancel}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

/**
 * An item plus the catalogue's opinion of its price.
 *
 * The fetch lives here rather than in the drawer so one item's suggestion
 * cannot be overwritten by another's: each card owns its own request and its
 * own abort.
 */
function ItemWithPrice({
    priceUrl,
    ...props
}: React.ComponentProps<typeof AdminManualOrderItem> & { priceUrl: string }) {
    const { item, onChange } = props;
    const latest = useRef(0);

    // Serialised so the effect re-runs when a value changes, not when the
    // object identity does.
    const signature = JSON.stringify([
        item.serviceType,
        item.platform,
        item.productVariantId,
        item.configuration,
    ]);

    useEffect(() => {
        const [serviceType, platform, productVariantId, configuration] =
            JSON.parse(signature) as [
                string,
                string,
                string,
                Record<string, string>,
            ];

        if (serviceType === '' || platform === '') {
            return;
        }

        // Nothing to ask about until the service has what it prices on.
        const missing = requiredKeys({
            configuration,
            platform,
            serviceType,
        }).some((key) => (configuration[key] ?? '') === '');

        if (missing) {
            return;
        }

        const query = new URLSearchParams({
            platform,
            service_type: serviceType,
        });

        for (const [key, value] of Object.entries(configuration)) {
            if (value !== '') {
                query.set(`configuration[${key}]`, value);
            }
        }

        if (productVariantId !== '') {
            query.set('configuration[product_variant_id]', productVariantId);
        }

        const ticket = latest.current + 1;
        latest.current = ticket;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            void fetch(`${priceUrl}?${query.toString()}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) =>
                    response.ok ? response.json() : Promise.reject(response),
                )
                .then(
                    (json: {
                        data: {
                            price_halalah: number | null;
                            product_variant_id: string | null;
                            reason: string | null;
                        };
                    }) => {
                        // A slower earlier request must not land on a newer answer.
                        if (ticket !== latest.current) {
                            return;
                        }

                        onChange({
                            suggestedHalalah: json.data.price_halalah,
                            suggestionReason: json.data.reason,
                            ...(json.data.product_variant_id !== null &&
                            productVariantId === ''
                                ? {
                                      productVariantId:
                                          json.data.product_variant_id,
                                  }
                                : {}),
                        });
                    },
                )
                .catch(() => {
                    // A suggestion is a courtesy. Losing it changes nothing
                    // about the order, so it fails silently and the price
                    // stays whatever was typed.
                });
        }, PRICE_DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [signature, priceUrl]);

    return <AdminManualOrderItem {...props} />;
}

function CustomerPicker({
    copy,
    customer,
    error,
    matches,
    onClear,
    onSearch,
    onSelect,
    search,
    searching,
}: {
    copy: AdminManualOrderTranslations;
    customer: AdminManualOrderCustomer | null;
    error: string | undefined;
    matches: AdminManualOrderCustomer[] | null;
    onClear: () => void;
    onSearch: (value: string) => void;
    onSelect: (customer: AdminManualOrderCustomer) => void;
    search: string;
    searching: boolean;
}) {
    if (customer !== null) {
        return (
            <section className="flex flex-col gap-2">
                <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {copy.customerLabel}
                </h2>
                <div className="flex items-center justify-between gap-2 rounded-md border border-primary/40 bg-primary/5 p-3">
                    <span>
                        <span className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                            <Check
                                aria-hidden="true"
                                className="size-3.5 text-primary"
                            />
                            {customer.name}
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            <bdi>{customer.email}</bdi>
                            {customer.isActive ? null : (
                                <span className="text-destructive">
                                    {' '}
                                    · {copy.customerSuspended}
                                </span>
                            )}
                        </span>
                    </span>
                    <Button
                        className="min-h-11 text-xs"
                        onClick={onClear}
                        type="button"
                        variant="ghost"
                    >
                        {copy.customerChange}
                    </Button>
                </div>
                {error === undefined ? null : (
                    <p className="text-xs text-destructive">{error}</p>
                )}
            </section>
        );
    }

    return (
        <section className="flex flex-col gap-2">
            <Label
                className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                htmlFor="manual-customer-search"
            >
                {copy.customerLabel}
            </Label>
            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground"
                />
                <Input
                    autoComplete="off"
                    className="min-h-11 ps-9"
                    id="manual-customer-search"
                    onChange={(event) => onSearch(event.target.value)}
                    placeholder={copy.customerPlaceholder}
                    value={search}
                />
            </div>

            {searching ? (
                <p className="text-xs text-muted-foreground">
                    {copy.customerSearching}
                </p>
            ) : null}

            {matches !== null && !searching ? (
                matches.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {copy.customerNoResults}
                    </p>
                ) : (
                    <ul className="divide-y divide-border overflow-hidden rounded-md border border-border">
                        {matches.map((match) => (
                            <li key={match.handle}>
                                <button
                                    className="flex min-h-11 w-full items-center justify-between gap-2 p-3 text-start hover:bg-muted/50"
                                    onClick={() => onSelect(match)}
                                    type="button"
                                >
                                    <span>
                                        <span className="block text-sm text-foreground">
                                            {match.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            <bdi>
                                                {match.phone ?? match.email}
                                            </bdi>{' '}
                                            ·{' '}
                                            {copy.customerOrders.replace(
                                                ':count',
                                                String(match.ordersCount),
                                            )}
                                            {match.isActive
                                                ? ''
                                                : ` · ${copy.customerSuspended}`}
                                        </span>
                                    </span>
                                    <span className="text-xs text-primary">
                                        {copy.customerSelect}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )
            ) : null}

            <p className="text-xs leading-relaxed text-muted-foreground">
                {copy.customerHelp}
            </p>
            {error === undefined ? null : (
                <p className="text-xs text-destructive">{error}</p>
            )}
        </section>
    );
}
