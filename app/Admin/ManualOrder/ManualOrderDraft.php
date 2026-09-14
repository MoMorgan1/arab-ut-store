<?php

namespace App\Admin\ManualOrder;

use App\Enums\ServiceType;

/**
 * What staff filled in, once it has been validated and before anything is
 * written.
 *
 * This exists so the action's signature cannot drift into a bag of arrays: the
 * money question ("is there a payment?") and the delivery question ("was it
 * placed by hand?") are both answered per draft, and every item carries its own
 * answer to the second one because a supplier reference belongs to an item
 * rather than to an order (owner decision, 2026-09-13).
 */
final readonly class ManualOrderDraft
{
    /** @param  list<ManualOrderItemDraft>  $items */
    public function __construct(
        public bool $isGift,
        public ?ManualOrderPayment $payment,
        public array $items,
    ) {}

    /**
     * A gift is priced at nothing. The items are still recorded in full, so the
     * order shows what was given; what is not recorded is a value nobody
     * received, and pricing it would have to land somewhere - as a total that
     * disagrees with its own payment, or as a discount that pollutes discount
     * reporting. A zero total is also the only thing that marks the order a
     * gift, so it has to be true rather than cosmetic.
     */
    public function lineTotalHalalah(ManualOrderItemDraft $item): int
    {
        return $this->isGift ? 0 : $item->priceHalalah;
    }

    public function subtotalHalalah(): int
    {
        return array_sum(array_map(
            fn (ManualOrderItemDraft $item): int => $this->lineTotalHalalah($item),
            $this->items,
        ));
    }

    /** Whether any item names a supplier, which is what a fulfillment job needs. */
    public function hasPlacements(): bool
    {
        foreach ($this->items as $item) {
            if ($item->placement instanceof ManualOrderPlacement) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ServiceType> */
    public function serviceTypes(): array
    {
        return array_values(array_unique(array_map(
            fn (ManualOrderItemDraft $item): ServiceType => $item->serviceType,
            $this->items,
        ), SORT_REGULAR));
    }
}
