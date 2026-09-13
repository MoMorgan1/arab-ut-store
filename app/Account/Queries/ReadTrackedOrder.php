<?php

namespace App\Account\Queries;

use App\Account\Presenters\ItemArtwork;
use App\Account\Presenters\ItemTracking;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * The read-only, capability-URL view of an order.
 *
 * The order arrives from TrackingLinkHandle with its items, their artwork and
 * their fulfillment jobs already eager-loaded; this class only chooses which
 * fields of that graph may leave the server. The allowlist below is the whole
 * payload - nothing else, ever.
 */
final class ReadTrackedOrder
{
    /**
     * @return array{
     *     number: string,
     *     status: string,
     *     statusNote: string|null,
     *     placedAt: string,
     *     refreshable: bool,
     *     items: list<array{
     *         name: string,
     *         platform: string,
     *         imageUrl: string,
     *         status: string,
     *         quantity: int,
     *         tracking: array<string, mixed>|null,
     *     }>,
     * }
     */
    public function execute(Order $order, string $locale): array
    {
        $terminal = in_array($order->status, [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ], true);
        $placedAt = $order->getAttribute('placed_at') ?? $order->getAttribute('created_at');

        // The items already have their order in hand; say so, or ItemTracking
        // fetches the same row again once per challenge item.
        foreach ($order->items as $orderItem) {
            $orderItem->setRelation('order', $order);
        }

        // A capability URL is held by whoever has the message - possibly a
        // forwarded screenshot - so it shows the state of the work and nothing
        // about the money or the account. Every money field, paymentMethod,
        // walletPayment, analytics (the purchase double-count), review,
        // cancelUrl, paymentStartUrl, credentialsPresent, manualFulfillment, the
        // order's public_id, the item's public_id and actionUrls are deliberately
        // absent for that reason.
        return [
            'number' => (string) $order->getAttribute('order_number'),
            'status' => $order->status->forCustomer()->value,
            'statusNote' => $this->statusNote($order, $locale),
            'placedAt' => $placedAt instanceof CarbonInterface ? $placedAt->toIso8601String() : '',
            'refreshable' => ! $terminal,
            'items' => array_values(array_map(
                fn (OrderItem $item): array => $this->item($item, $locale),
                $order->items->all(),
            )),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     platform: string,
     *     imageUrl: string,
     *     status: string,
     *     quantity: int,
     *     tracking: array<string, mixed>|null,
     * }
     */
    private function item(OrderItem $item, string $locale): array
    {
        return [
            'name' => (string) $item->getAttribute($locale === 'en' ? 'name_en' : 'name_ar'),
            'platform' => $item->platform->value,
            'imageUrl' => ItemArtwork::for($item),
            'status' => $item->status->forCustomer()->value,
            'quantity' => (int) $item->getAttribute('quantity'),
            'tracking' => $this->tracking($item, $locale),
        ];
    }

    /** @return array<string, mixed>|null */
    private function tracking(OrderItem $item, string $locale): ?array
    {
        $tracking = ItemTracking::for($item, $locale);

        if ($tracking === null) {
            return null;
        }

        // Actions are blanked in this slice on purpose. Acting over a bearer
        // link needs its own authorisation path and its own audit identity -
        // SecretAccessLog.user_id has no user to name when nobody signed in -
        // and that is the next task. Blanking the lists, not dropping the keys,
        // leaves the card with no buttons (its two `actions.length` guards)
        // without changing the tracking shape, so this is not a presenter that
        // forgot to copy a field.
        $tracking['actions'] = [];

        $challenges = $tracking['challenges'] ?? null;

        if (is_array($challenges)) {
            foreach (array_keys($challenges) as $index) {
                $challenges[$index]['actions'] = [];
            }

            $tracking['challenges'] = $challenges;
        }

        return $tracking;
    }

    /** The same note the account page shows: the newest order-level entry, while it still describes the current status. */
    private function statusNote(Order $order, string $locale): ?string
    {
        $latest = OrderStatusHistory::query()
            ->select(['id', 'status', 'note_ar', 'note_en'])
            ->where('order_id', $order->id)
            ->whereNull('order_item_id')
            ->orderByDesc('id')
            ->first();

        if (! $latest instanceof OrderStatusHistory) {
            return null;
        }

        $historyStatus = $latest->getAttribute('status');
        $historyStatus = $historyStatus instanceof BackedEnum
            ? (string) $historyStatus->value
            : (string) $historyStatus;

        if ($historyStatus !== $order->status->value) {
            return null;
        }

        $note = $latest->getAttribute($locale === 'en' ? 'note_en' : 'note_ar');

        return is_string($note) && trim($note) !== '' ? $note : null;
    }
}
