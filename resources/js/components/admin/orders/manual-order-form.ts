import type { FormDataConvertible } from '@inertiajs/core';

import { parseSarToHalalah } from '@/components/admin/admin-money';
import type {
    AdminManualOrderCustomer,
    AdminManualOrderOptions,
} from '@/types/admin';

/**
 * The shape of one item while it is being filled in.
 *
 * Everything is a string because that is what an input holds. The conversion to
 * the integers and booleans the API takes happens once, in `buildPayload`, so
 * there is a single place where a half-typed number can never leak into a
 * price.
 */
export type ManualOrderItemForm = {
    key: string;
    serviceType: string;
    platform: string;
    price: string;
    suggestedHalalah: number | null;
    suggestionReason: string | null;
    productVariantId: string;
    configuration: Record<string, string>;
    eaEmail: string;
    eaPassword: string;
    backupCodes: string;
    showCredentials: boolean;
    supplier: string;
    supplierOrderId: string;
    deliveryPhase: string;
    challengeIds: string;
};

export type ManualOrderForm = {
    isGift: boolean;
    /**
     * `placed` means the supplier reference is already in hand. It is the
     * default because it is what actually happens (owner, 2026-09-13).
     */
    delivery: 'placed' | 'later';
    customer: AdminManualOrderCustomer | null;
    paymentReference: string;
    paymentReceivedAt: string;
    items: ManualOrderItemForm[];
};

export function emptyItem(
    serviceType: string,
    platform: string,
): ManualOrderItemForm {
    return {
        key: `item-${Math.random().toString(36).slice(2, 10)}`,
        serviceType,
        platform,
        price: '',
        suggestedHalalah: null,
        suggestionReason: null,
        productVariantId: '',
        configuration: {},
        eaEmail: '',
        eaPassword: '',
        backupCodes: '',
        showCredentials: false,
        supplier: '',
        supplierOrderId: '',
        deliveryPhase: '',
        challengeIds: '',
    };
}

export function today(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * The configuration keys a service cannot be ordered without, mirroring
 * `CreateManualOrderRequest::requiredConfigurationKeys`. Kept here so the
 * drawer can disable its own submit button instead of learning the rule from a
 * 422, and deliberately the same list rather than a looser one.
 */
export function requiredKeys(
    item: Pick<
        ManualOrderItemForm,
        'configuration' | 'platform' | 'serviceType'
    >,
): string[] {
    switch (item.serviceType) {
        case 'coins':
            return item.platform === 'pc'
                ? ['coins_quantity']
                : ['coins_quantity', 'delivery'];
        case 'sbc':
            return ['completion_count'];
        case 'fut_champions':
            return ['rank', 'matches_played'];
        case 'rivals':
            return item.configuration.mode === 'weekly_matches'
                ? ['mode']
                : ['mode', 'current_division', 'target_division'];
        default:
            return [];
    }
}

export function acceptsPlacement(
    item: ManualOrderItemForm,
    options: AdminManualOrderOptions | null,
): boolean {
    return (
        options?.services.find((service) => service.value === item.serviceType)
            ?.acceptsPlacement === true
    );
}

export function itemHalalah(item: ManualOrderItemForm): number {
    return parseSarToHalalah(item.price);
}

export function totalHalalah(form: ManualOrderForm): number {
    if (form.isGift) {
        return 0;
    }

    return form.items.reduce((sum, item) => sum + itemHalalah(item), 0);
}

/** Splits a textarea into the non-empty lines it holds. */
export function lines(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

/**
 * Whether the form can be submitted at all. Mirrors the server's refusals so
 * the button is disabled rather than the request refused: a gift with a
 * payment and a transfer without one are both impossible here.
 */
export function blockingProblem(
    form: ManualOrderForm,
    options: AdminManualOrderOptions | null,
): string | null {
    if (form.customer === null) {
        return 'noCustomer';
    }

    if (form.items.length === 0) {
        return 'noItems';
    }

    for (const item of form.items) {
        if (item.serviceType === '' || item.platform === '') {
            return 'incompleteItem';
        }

        if (item.eaEmail.trim() === '') {
            return 'incompleteItem';
        }

        if (!form.isGift && itemHalalah(item) <= 0) {
            return 'incompleteItem';
        }

        for (const key of requiredKeys(item)) {
            if ((item.configuration[key] ?? '') === '') {
                return 'incompleteItem';
            }
        }

        if (item.serviceType === 'sbc' && item.productVariantId === '') {
            return 'incompleteItem';
        }

        if (!hasCompletePlacement(item, form, options)) {
            return 'incompleteItem';
        }
    }

    if (!form.isGift && form.paymentReference.trim() === '') {
        return 'incompletePayment';
    }

    return null;
}

/**
 * A placement is all-or-nothing. Half of one - a supplier with no reference, or
 * a challenge with no ids - is refused by `RecordSupplierPlacement`, and the
 * whole order rolls back with it, so it is caught here instead.
 */
function hasCompletePlacement(
    item: ManualOrderItemForm,
    form: ManualOrderForm,
    options: AdminManualOrderOptions | null,
): boolean {
    if (form.delivery !== 'placed' || !acceptsPlacement(item, options)) {
        return true;
    }

    const started =
        item.supplier !== '' ||
        item.supplierOrderId.trim() !== '' ||
        item.deliveryPhase !== '';

    if (!started) {
        // Nothing typed: the item is simply created without a job, which is a
        // legitimate order.
        return true;
    }

    if (
        item.supplier === '' ||
        item.supplierOrderId.trim() === '' ||
        item.deliveryPhase === ''
    ) {
        return false;
    }

    return (
        item.deliveryPhase !== 'challenge' ||
        lines(item.challengeIds).length > 0
    );
}

/**
 * @returns the body `ManualOrderController::store` expects.
 */
export function buildPayload(
    form: ManualOrderForm,
    options: AdminManualOrderOptions | null,
): Record<string, FormDataConvertible> {
    const payload: Record<string, FormDataConvertible> = {
        customer_id: form.customer?.handle ?? '',
        is_gift: form.isGift,
        items: form.items.map((item) => buildItem(item, form, options)),
    };

    if (!form.isGift) {
        // The amount is the order total, never a separate figure:
        // `CreateManualOrder` refuses a payment that does not match the items,
        // so a second editable number could only ever disagree with the first.
        payload.payment = {
            amount_halalah: totalHalalah(form),
            reference: form.paymentReference.trim(),
            received_at: form.paymentReceivedAt,
        };
    }

    return payload;
}

function buildItem(
    item: ManualOrderItemForm,
    form: ManualOrderForm,
    options: AdminManualOrderOptions | null,
): Record<string, FormDataConvertible> {
    const built: Record<string, FormDataConvertible> = {
        service_type: item.serviceType,
        platform: item.platform,
        product_variant_id:
            item.productVariantId === '' ? null : item.productVariantId,
        price_halalah: form.isGift ? 0 : itemHalalah(item),
        configuration: buildConfiguration(item),
        credentials: buildCredentials(item),
    };

    if (
        form.delivery === 'placed' &&
        acceptsPlacement(item, options) &&
        item.supplierOrderId.trim() !== ''
    ) {
        built.placement = {
            supplier: item.supplier,
            supplier_order_id: item.supplierOrderId.trim(),
            delivery_phase: item.deliveryPhase,
            challenge_ids:
                item.deliveryPhase === 'challenge'
                    ? lines(item.challengeIds)
                    : [],
        };
    }

    return built;
}

/**
 * The counts become integers and `urgent` a boolean, because the request
 * validates them with `integer:strict` and a string would be refused. Anything
 * not a count is passed through as typed.
 */
function buildConfiguration(
    item: ManualOrderItemForm,
): Record<string, FormDataConvertible> {
    const configuration: Record<string, FormDataConvertible> = {};

    for (const [key, value] of Object.entries(item.configuration)) {
        if (value === '') {
            continue;
        }

        if (
            [
                'coins_quantity',
                'completion_count',
                'rank',
                'matches_played',
            ].includes(key)
        ) {
            configuration[key] = Number.parseInt(value, 10);

            continue;
        }

        if (key === 'urgent') {
            configuration[key] = value === '1';

            continue;
        }

        configuration[key] = value;
    }

    return configuration;
}

/**
 * The email is required and everything else is not (owner, 2026-09-13): an item
 * placed by hand has already been signed into by the person creating the order.
 */
function buildCredentials(
    item: ManualOrderItemForm,
): Record<string, FormDataConvertible> | null {
    const email = item.eaEmail.trim();

    if (email === '') {
        return null;
    }

    const credentials: Record<string, FormDataConvertible> = {
        ea_email: email,
    };

    if (item.eaPassword !== '') {
        credentials.ea_password = item.eaPassword;
    }

    const codes = lines(item.backupCodes);

    if (codes.length > 0) {
        credentials.backup_codes = codes;
    }

    return credentials;
}
