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
     *         actionUrls: array{editCredentials: string, resume: string, retryChallenge: string},
     *         tracking: array<string, mixed>|null,
     *     }>,
     * }
     */
    public function execute(Order $order, string $token, string $locale): array
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
        // cancelUrl, paymentStartUrl, credentialsPresent, manualFulfillment,
        // the order's public_id and the item's public_id are deliberately
        // absent for that reason. The action URLs do carry the token, which is
        // correct: the viewer already holds it, and the route is NoStore so the
        // page that carries it is never cached.
        return [
            'number' => (string) $order->getAttribute('order_number'),
            'status' => $order->status->forCustomer()->value,
            'statusNote' => $this->statusNote($order, $locale),
            'placedAt' => $placedAt instanceof CarbonInterface ? $placedAt->toIso8601String() : '',
            'refreshable' => ! $terminal,
            'items' => array_values(array_map(
                fn (OrderItem $item): array => $this->item($item, $token, $locale),
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
     *     actionUrls: array{editCredentials: string, resume: string, retryChallenge: string},
     *     tracking: array<string, mixed>|null,
     * }
     */
    private function item(OrderItem $item, string $token, string $locale): array
    {
        return [
            'name' => (string) $item->getAttribute($locale === 'en' ? 'name_en' : 'name_ar'),
            'platform' => $item->platform->value,
            'imageUrl' => ItemArtwork::for($item),
            'status' => $item->status->forCustomer()->value,
            'quantity' => (int) $item->getAttribute('quantity'),
            'actionUrls' => $this->itemActionUrls($item, $token, $locale),
            'tracking' => ItemTracking::for($item, $locale),
        ];
    }

    /**
     * Where the card's three buttons post to.
     *
     * The URLs travel with the item, and they carry the capability token: the
     * viewer already holds it, so nothing is handed to anyone who did not have
     * it. A URL is not a permission - the server re-authorises every press.
     *
     * @return array{editCredentials: string, resume: string, retryChallenge: string}
     */
    private function itemActionUrls(OrderItem $item, string $token, string $locale): array
    {
        $prefix = $locale === 'en' ? 'localized.store' : 'store';
        $parameters = [
            ...($locale === 'en' ? ['locale' => 'en'] : []),
            'token' => $token,
            'item' => $item->public_id,
        ];

        return [
            'editCredentials' => route("{$prefix}.orders.track.actions.edit-credentials", $parameters, absolute: false),
            'resume' => route("{$prefix}.orders.track.actions.resume", $parameters, absolute: false),
            'retryChallenge' => route("{$prefix}.orders.track.actions.retry-challenge", $parameters, absolute: false),
        ];
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
