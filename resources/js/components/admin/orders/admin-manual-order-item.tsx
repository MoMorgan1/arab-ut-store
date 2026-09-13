import { Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { formatHalalahToSar } from '@/components/admin/admin-money';
import { acceptsPlacement } from '@/components/admin/orders/manual-order-form';
import type { ManualOrderItemForm } from '@/components/admin/orders/manual-order-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AdminManualOrderOptions,
    AdminManualOrderTranslations,
} from '@/types/admin';

const FIELD = 'min-h-11';
const TEXTAREA =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

export type AdminManualOrderItemProps = {
    copy: AdminManualOrderTranslations;
    errors: Record<string, string>;
    index: number;
    isGift: boolean;
    item: ManualOrderItemForm;
    onChange: (patch: Partial<ManualOrderItemForm>) => void;
    onConfigurationChange: (key: string, value: string) => void;
    onRemove: (() => void) | null;
    options: AdminManualOrderOptions | null;
    showPlacement: boolean;
};

/**
 * One item on a manual order.
 *
 * It repeats because an order takes several (owner, 2026-09-13), and each card
 * carries its own supplier reference rather than the order carrying one:
 * `fulfillment_jobs.order_item_id` is unique, so a job belongs to an item and
 * each item is its own order at the supplier.
 */
export default function AdminManualOrderItem({
    copy,
    errors,
    index,
    isGift,
    item,
    onChange,
    onConfigurationChange,
    onRemove,
    options,
    showPlacement,
}: AdminManualOrderItemProps) {
    const id = (field: string) => `manual-item-${index}-${field}`;
    const error = (field: string) => errors[`items.${index}.${field}`];
    const service = options?.services.find((s) => s.value === item.serviceType);
    const platforms = (service?.platforms ?? []).map((value) => ({
        value,
        label:
            options?.platforms.find((p) => p.value === value)?.label ?? value,
    }));
    const placementAvailable = acceptsPlacement(item, options);
    const supplier = options?.suppliers.find((s) => s.value === item.supplier);

    return (
        <section className="flex flex-col gap-3 rounded-lg border border-border bg-card/60 p-4">
            <header className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-primary">
                    {copy.itemLabel.replace(':number', String(index + 1))}
                </h3>
                {onRemove ? (
                    <Button
                        aria-label={copy.removeItem.replace(
                            ':number',
                            String(index + 1),
                        )}
                        className="size-11 text-destructive"
                        onClick={onRemove}
                        size="icon"
                        type="button"
                        variant="ghost"
                    >
                        <Trash2 aria-hidden="true" className="size-4" />
                    </Button>
                ) : null}
            </header>

            <Field
                error={error('service_type')}
                htmlFor={id('service')}
                label={copy.serviceLabel}
            >
                <Select
                    onValueChange={(value) =>
                        onChange({
                            serviceType: value,
                            // Every field below the service is about that
                            // service, so none of them survive the change.
                            configuration: {},
                            productVariantId: '',
                            price: '',
                            suggestedHalalah: null,
                            suggestionReason: null,
                            supplier: '',
                            supplierOrderId: '',
                            deliveryPhase: '',
                            challengeIds: '',
                            platform:
                                options?.services
                                    .find((s) => s.value === value)
                                    ?.platforms.includes(item.platform) === true
                                    ? item.platform
                                    : '',
                        })
                    }
                    value={item.serviceType}
                >
                    <SelectTrigger className={FIELD} id={id('service')}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(options?.services ?? []).map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <Field
                error={error('platform')}
                htmlFor={id('platform')}
                label={copy.platformLabel}
            >
                <Select
                    onValueChange={(value) =>
                        onChange({
                            platform: value,
                            productVariantId: '',
                            suggestedHalalah: null,
                            suggestionReason: null,
                        })
                    }
                    value={item.platform}
                >
                    <SelectTrigger className={FIELD} id={id('platform')}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {platforms.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <ServiceFields
                copy={copy}
                error={error}
                id={id}
                item={item}
                onConfigurationChange={onConfigurationChange}
                onChange={onChange}
                options={options}
            />

            {isGift ? null : (
                <Field
                    error={error('price_halalah')}
                    htmlFor={id('price')}
                    label={copy.priceLabel}
                >
                    <div className="flex items-center gap-2">
                        <Input
                            className={`${FIELD} tabular-nums`}
                            id={id('price')}
                            inputMode="decimal"
                            onChange={(event) =>
                                onChange({ price: event.target.value })
                            }
                            placeholder="0.00"
                            value={item.price}
                        />
                        <span className="text-xs text-muted-foreground">
                            {copy.currency}
                        </span>
                    </div>
                    <PriceSuggestion
                        copy={copy}
                        item={item}
                        onUse={(price) => onChange({ price })}
                    />
                </Field>
            )}

            <hr className="border-border" />

            <Field
                error={error('credentials.ea_email')}
                htmlFor={id('email')}
                label={copy.eaEmail}
            >
                <Input
                    autoComplete="off"
                    className={FIELD}
                    dir="ltr"
                    id={id('email')}
                    onChange={(event) =>
                        onChange({ eaEmail: event.target.value })
                    }
                    type="email"
                    value={item.eaEmail}
                />
            </Field>

            <p className="text-xs leading-relaxed text-muted-foreground">
                {copy.credentialsOptional}
            </p>

            {item.showCredentials ? (
                <>
                    <Field
                        error={error('credentials.ea_password')}
                        htmlFor={id('password')}
                        label={copy.eaPassword}
                    >
                        <Input
                            autoComplete="off"
                            className={FIELD}
                            dir="ltr"
                            id={id('password')}
                            onChange={(event) =>
                                onChange({ eaPassword: event.target.value })
                            }
                            type="password"
                            value={item.eaPassword}
                        />
                    </Field>
                    <Field
                        error={error('credentials.backup_codes')}
                        hint={copy.backupCodesHelp}
                        htmlFor={id('codes')}
                        label={copy.backupCodes}
                    >
                        <textarea
                            className={TEXTAREA}
                            dir="ltr"
                            id={id('codes')}
                            onChange={(event) =>
                                onChange({ backupCodes: event.target.value })
                            }
                            value={item.backupCodes}
                        />
                    </Field>
                </>
            ) : null}

            <Button
                className="min-h-11 justify-start px-0 text-xs"
                onClick={() =>
                    onChange({
                        showCredentials: !item.showCredentials,
                        // Dropping the disclosure drops what it held, so a
                        // password typed and then hidden is never submitted
                        // without being visible.
                        ...(item.showCredentials
                            ? { eaPassword: '', backupCodes: '' }
                            : {}),
                    })
                }
                type="button"
                variant="link"
            >
                {item.showCredentials
                    ? copy.hideCredentials
                    : copy.addCredentials}
            </Button>

            {showPlacement && !placementAvailable ? (
                <p className="rounded-md border border-border bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground">
                    {copy.placementUnavailable}
                </p>
            ) : null}

            {showPlacement && placementAvailable ? (
                <>
                    <hr className="border-border" />

                    <Field
                        error={error('placement.supplier')}
                        htmlFor={id('supplier')}
                        label={copy.supplierLabel}
                    >
                        <Select
                            onValueChange={(value) =>
                                onChange({
                                    supplier: value,
                                    // UTT never delivers a challenge, so the
                                    // phase cannot outlive the switch to it.
                                    deliveryPhase:
                                        options?.suppliers.find(
                                            (s) => s.value === value,
                                        )?.handlesChallenges === false &&
                                        item.deliveryPhase === 'challenge'
                                            ? ''
                                            : item.deliveryPhase,
                                })
                            }
                            value={item.supplier}
                        >
                            <SelectTrigger
                                className={FIELD}
                                id={id('supplier')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(options?.suppliers ?? []).map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {supplier?.handlesChallenges === false ? (
                            <p className="text-xs text-muted-foreground">
                                {copy.uttNoChallenges}
                            </p>
                        ) : null}
                    </Field>

                    <Field
                        error={error('placement.delivery_phase')}
                        htmlFor={id('phase')}
                        label={copy.phaseLabel}
                    >
                        <Select
                            onValueChange={(value) =>
                                onChange({
                                    deliveryPhase: value,
                                    challengeIds:
                                        value === 'challenge'
                                            ? item.challengeIds
                                            : '',
                                })
                            }
                            value={item.deliveryPhase}
                        >
                            <SelectTrigger className={FIELD} id={id('phase')}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(options?.deliveryPhases ?? [])
                                    .filter(
                                        (option) =>
                                            option.value !== 'challenge' ||
                                            supplier === undefined ||
                                            supplier.handlesChallenges,
                                    )
                                    .map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field
                        error={error('placement.supplier_order_id')}
                        htmlFor={id('reference')}
                        label={copy.supplierReference}
                    >
                        <Input
                            className={`${FIELD} font-mono`}
                            dir="ltr"
                            id={id('reference')}
                            onChange={(event) =>
                                onChange({
                                    supplierOrderId: event.target.value,
                                })
                            }
                            value={item.supplierOrderId}
                        />
                    </Field>

                    {item.deliveryPhase === 'challenge' ? (
                        <Field
                            error={
                                error('placement.challenge_ids') ??
                                error('placement.challenge_ids.0')
                            }
                            hint={copy.challengeIdsHelp}
                            htmlFor={id('challenges')}
                            label={copy.challengeIds}
                        >
                            <textarea
                                className={`${TEXTAREA} font-mono`}
                                dir="ltr"
                                id={id('challenges')}
                                onChange={(event) =>
                                    onChange({
                                        challengeIds: event.target.value,
                                    })
                                }
                                value={item.challengeIds}
                            />
                        </Field>
                    ) : null}
                </>
            ) : null}
        </section>
    );
}

/** The fields only one service asks for. */
function ServiceFields({
    copy,
    error,
    id,
    item,
    onChange,
    onConfigurationChange,
    options,
}: {
    copy: AdminManualOrderTranslations;
    error: (field: string) => string | undefined;
    id: (field: string) => string;
    item: ManualOrderItemForm;
    onChange: (patch: Partial<ManualOrderItemForm>) => void;
    onConfigurationChange: (key: string, value: string) => void;
    options: AdminManualOrderOptions | null;
}) {
    const configurationError = (key: string) => error(`configuration.${key}`);

    if (item.serviceType === 'coins') {
        return (
            <>
                <Field
                    error={configurationError('coins_quantity')}
                    htmlFor={id('quantity')}
                    label={copy.coinsQuantity}
                >
                    <Input
                        className={`${FIELD} tabular-nums`}
                        id={id('quantity')}
                        inputMode="numeric"
                        onChange={(event) =>
                            onConfigurationChange(
                                'coins_quantity',
                                event.target.value,
                            )
                        }
                        value={item.configuration.coins_quantity ?? ''}
                    />
                </Field>
                {item.platform !== '' && item.platform !== 'pc' ? (
                    <Field
                        error={configurationError('delivery')}
                        htmlFor={id('coins-delivery')}
                        label={copy.coinsDelivery}
                    >
                        <Select
                            onValueChange={(value) =>
                                onConfigurationChange('delivery', value)
                            }
                            value={item.configuration.delivery ?? ''}
                        >
                            <SelectTrigger
                                className={FIELD}
                                id={id('coins-delivery')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="normal">
                                    {copy.deliveryNormal}
                                </SelectItem>
                                <SelectItem value="fast">
                                    {copy.deliveryFast}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                ) : null}
            </>
        );
    }

    if (item.serviceType === 'sbc') {
        const variants = (options?.sbcVariants ?? []).filter(
            (variant) =>
                item.platform === '' || variant.platform === item.platform,
        );

        return (
            <>
                <Field
                    error={error('product_variant_id')}
                    htmlFor={id('sbc-variant')}
                    label={copy.sbcVariant}
                >
                    <Select
                        onValueChange={(value) =>
                            onChange({ productVariantId: value })
                        }
                        value={item.productVariantId}
                    >
                        <SelectTrigger className={FIELD} id={id('sbc-variant')}>
                            <SelectValue
                                placeholder={copy.sbcVariantPlaceholder}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {variants.map((variant) => (
                                <SelectItem
                                    key={variant.publicId}
                                    value={variant.publicId}
                                >
                                    {variant.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    error={configurationError('completion_count')}
                    htmlFor={id('completions')}
                    label={copy.completionCount}
                >
                    <Input
                        className={`${FIELD} tabular-nums`}
                        id={id('completions')}
                        inputMode="numeric"
                        onChange={(event) =>
                            onConfigurationChange(
                                'completion_count',
                                event.target.value,
                            )
                        }
                        value={item.configuration.completion_count ?? ''}
                    />
                </Field>
            </>
        );
    }

    if (item.serviceType === 'fut_champions') {
        return (
            <>
                <Field
                    error={configurationError('rank')}
                    htmlFor={id('rank')}
                    label={copy.rank}
                >
                    <Select
                        onValueChange={(value) =>
                            onConfigurationChange('rank', value)
                        }
                        value={item.configuration.rank ?? ''}
                    >
                        <SelectTrigger className={FIELD} id={id('rank')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(options?.futChampions?.ranks ?? []).map(
                                (rank) => (
                                    <SelectItem key={rank} value={String(rank)}>
                                        {copy.rankNumber.replace(
                                            ':number',
                                            String(rank),
                                        )}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    error={configurationError('matches_played')}
                    htmlFor={id('matches')}
                    label={copy.matchesPlayed}
                >
                    <Input
                        className={`${FIELD} tabular-nums`}
                        id={id('matches')}
                        inputMode="numeric"
                        onChange={(event) =>
                            onConfigurationChange(
                                'matches_played',
                                event.target.value,
                            )
                        }
                        value={item.configuration.matches_played ?? ''}
                    />
                </Field>
                <Field htmlFor={id('urgent')} label={copy.urgent}>
                    <Select
                        onValueChange={(value) =>
                            onConfigurationChange('urgent', value)
                        }
                        value={item.configuration.urgent ?? '0'}
                    >
                        <SelectTrigger className={FIELD} id={id('urgent')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="0">
                                {copy.deliveryNormal}
                            </SelectItem>
                            <SelectItem value="1">{copy.urgent}</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
            </>
        );
    }

    if (item.serviceType === 'rivals') {
        const isWeekly = item.configuration.mode === 'weekly_matches';
        const divisions = options?.rivals?.divisions ?? [];
        const divisionLabel = (division: string) =>
            division === 'elite'
                ? copy.divisionElite
                : copy.divisionNumber.replace(':number', division);

        return (
            <>
                <Field
                    error={configurationError('mode')}
                    htmlFor={id('mode')}
                    label={copy.rivalsMode}
                >
                    <Select
                        onValueChange={(value) => {
                            onConfigurationChange('mode', value);

                            if (value === 'weekly_matches') {
                                // RivalsCartRequest:31 forbids the divisions on
                                // this mode, so they are cleared rather than
                                // carried along invisibly.
                                onConfigurationChange('current_division', '');
                                onConfigurationChange('target_division', '');
                            }
                        }}
                        value={item.configuration.mode ?? ''}
                    >
                        <SelectTrigger className={FIELD} id={id('mode')}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="promotion">
                                {copy.rivalsPromotion}
                            </SelectItem>
                            {options?.rivals?.offersWeeklyMatches ? (
                                <SelectItem value="weekly_matches">
                                    {copy.rivalsWeekly}
                                </SelectItem>
                            ) : null}
                        </SelectContent>
                    </Select>
                </Field>
                {isWeekly ? null : (
                    <>
                        <Field
                            error={configurationError('current_division')}
                            htmlFor={id('from')}
                            label={copy.currentDivision}
                        >
                            <Select
                                onValueChange={(value) =>
                                    onConfigurationChange(
                                        'current_division',
                                        value,
                                    )
                                }
                                value={
                                    item.configuration.current_division ?? ''
                                }
                            >
                                <SelectTrigger
                                    className={FIELD}
                                    id={id('from')}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {divisions.map((division) => (
                                        <SelectItem
                                            key={division}
                                            value={division}
                                        >
                                            {divisionLabel(division)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            error={configurationError('target_division')}
                            htmlFor={id('to')}
                            label={copy.targetDivision}
                        >
                            <Select
                                onValueChange={(value) =>
                                    onConfigurationChange(
                                        'target_division',
                                        value,
                                    )
                                }
                                value={item.configuration.target_division ?? ''}
                            >
                                <SelectTrigger className={FIELD} id={id('to')}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {divisions
                                        .slice(
                                            divisions.indexOf(
                                                item.configuration
                                                    .current_division ?? '',
                                            ) + 1,
                                        )
                                        .map((division) => (
                                            <SelectItem
                                                key={division}
                                                value={division}
                                            >
                                                {divisionLabel(division)}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </>
                )}
            </>
        );
    }

    return null;
}

/** What the catalogue would have charged, and a one-press way to take it. */
function PriceSuggestion({
    copy,
    item,
    onUse,
}: {
    copy: AdminManualOrderTranslations;
    item: ManualOrderItemForm;
    onUse: (price: string) => void;
}) {
    if (item.suggestedHalalah === null) {
        return item.suggestionReason === null ? null : (
            <p className="text-xs text-muted-foreground">
                {item.suggestionReason}
            </p>
        );
    }

    const suggested = formatHalalahToSar(item.suggestedHalalah);
    const formatted = `${suggested} ${copy.currency}`;

    if (item.price === suggested) {
        return (
            <p className="text-xs text-muted-foreground">
                {copy.priceSuggestion.replace(':price', formatted)}
            </p>
        );
    }

    return (
        <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <span>
                {item.price === ''
                    ? copy.priceSuggestion.replace(':price', formatted)
                    : copy.priceChanged.replace(':price', formatted)}
            </span>
            <Button
                className="min-h-11 px-0 text-xs"
                onClick={() => onUse(suggested)}
                type="button"
                variant="link"
            >
                {copy.priceUse}
            </Button>
        </p>
    );
}

function Field({
    children,
    error,
    hint,
    htmlFor,
    label,
}: {
    children: ReactNode;
    error?: string | undefined;
    hint?: string;
    htmlFor: string;
    label: string;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint === undefined ? null : (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {error === undefined ? null : (
                <p className="text-xs text-destructive">{error}</p>
            )}
        </div>
    );
}
