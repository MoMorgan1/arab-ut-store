<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use App\Payments\PaymentMethodLabel;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

final class ReadLiveOrder
{
    /** @return array<string, mixed> */
    public function for(User $user, string $publicId, string $locale): array
    {
        $order = Order::query()
            ->select([
                'id',
                'public_id',
                'user_id',
                'order_number',
                'status',
                'locale',
                'channel',
                'completed_at',
                'currency',
                'subtotal_halalah',
                'discount_halalah',
                'wallet_halalah',
                'payment_halalah',
                'total_halalah',
                'placed_at',
                'created_at',
            ])
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->with(['payments' => fn ($payments) => $payments
                ->select(['id', 'order_id', 'provider', 'provider_metadata'])
                ->orderByDesc('id')])
            ->with(['items' => fn ($items) => $items
                ->select([
                    'id',
                    'public_id',
                    'order_id',
                    'product_variant_id',
                    'name_ar',
                    'name_en',
                    'service_type',
                    'platform',
                    'status',
                    'sku',
                    'quantity',
                    'unit_price_halalah',
                    'total_halalah',
                    'configuration',
                ])
                ->with([
                    'productVariant' => fn ($variants) => $variants
                        ->select(['id', 'product_id'])
                        ->with(['product' => fn ($products) => $products
                            ->select(['id'])
                            ->with('media')]),
                ])
                ->withExists('secret')
                ->withExists('squadImage')
                ->orderBy('id')])
            ->firstOrFail();
        $terminal = in_array($order->status, [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ], true);
        $placedAt = $order->getAttribute('placed_at') ?? $order->getAttribute('created_at');
        $walletHalalah = (int) ($order->getAttribute('wallet_halalah') ?? 0);

        return [
            'id' => (string) $order->getAttribute('public_id'),
            'number' => (string) $order->getAttribute('order_number'),
            'status' => $order->status->forCustomer()->value,
            'statusNote' => $this->statusNote($order, $locale),
            'placedAt' => $placedAt instanceof CarbonInterface ? $placedAt->toIso8601String() : '',
            'total' => AccountMoney::fromMinor(
                (int) $order->getAttribute('total_halalah'),
                (string) $order->getAttribute('currency'),
            ),
            'subtotal' => AccountMoney::fromMinor(
                (int) $order->getAttribute('subtotal_halalah'),
                (string) $order->getAttribute('currency'),
            ),
            'discount' => AccountMoney::fromMinor(
                (int) $order->getAttribute('discount_halalah'),
                (string) $order->getAttribute('currency'),
            ),
            'paymentAmount' => AccountMoney::fromMinor(
                (int) $order->getAttribute('payment_halalah'),
                (string) $order->getAttribute('currency'),
            ),
            'paymentMethod' => $this->paymentMethod($order),
            'walletPayment' => $walletHalalah > 0
                ? AccountMoney::fromMinor(
                    $walletHalalah,
                    (string) $order->getAttribute('currency'),
                )
                : null,
            'refreshable' => ! $terminal,
            'analytics' => $this->analytics($order),
            'review' => $this->review($order, $publicId, $locale),
            'paymentStartUrl' => $order->status === OrderStatus::PendingPayment
                ? route(
                    $locale === 'en'
                        ? 'localized.store.orders.paylink-payment'
                        : 'store.orders.paylink-payment',
                    [...($locale === 'en' ? ['locale' => 'en'] : []), 'order' => $publicId],
                    absolute: false,
                )
                : null,
            'items' => $order->items
                ->map(fn (OrderItem $item): array => [
                    'id' => (string) $item->getAttribute('public_id'),
                    'name' => (string) $item->getAttribute($locale === 'en' ? 'name_en' : 'name_ar'),
                    'platform' => $item->platform->value,
                    'imageUrl' => $this->itemImageUrl($item),
                    'status' => $item->status->forCustomer()->value,
                    'quantity' => (int) $item->getAttribute('quantity'),
                    'total' => AccountMoney::fromMinor(
                        (int) $item->getAttribute('total_halalah'),
                        (string) $order->getAttribute('currency'),
                    ),
                    'credentialsPresent' => (bool) $item->getAttribute('secret_exists'),
                    'manualFulfillment' => $this->manualFulfillment($item, $publicId, $locale),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The review slot on the order page.
     *
     * `null` means the order cannot be reviewed at all, which is a different
     * thing from "not reviewed yet": a Salla-imported order arrives already
     * completed and stays read-only, and an order still in progress has nothing
     * to judge. Both hide the card entirely.
     *
     * @return array{url: string, submitted: array{rating: int, body: ?string, publishedAt: ?string, visible: bool}|null}|null
     */
    private function review(Order $order, string $publicId, string $locale): ?array
    {
        if ($order->completed_at === null || $order->channel === 'salla_import') {
            return null;
        }

        $review = Review::query()
            ->select(['id', 'order_id', 'rating', 'body_ar', 'body_en', 'is_visible', 'published_at'])
            ->where('order_id', $order->id)
            ->first();
        $publishedAt = $review instanceof Review
            ? CarbonImmutable::make($review->getRawOriginal('published_at'))
            : null;

        return [
            'url' => route(
                $locale === 'en'
                    ? 'localized.account.orders.review.store'
                    : 'account.orders.review.store',
                ['order' => $publicId],
                absolute: false,
            ),
            'submitted' => $review instanceof Review
                ? [
                    'rating' => (int) $review->rating,
                    'body' => $locale === 'en'
                        ? ($review->body_en ?? $review->body_ar)
                        : ($review->body_ar ?? $review->body_en),
                    'publishedAt' => $publishedAt?->toIso8601String(),
                    'visible' => (bool) $review->is_visible,
                ]
                : null,
        ];
    }

    /**
     * Why the order is where it is, in the customer's own words.
     *
     * Only the newest order-level entry counts, and only while it still
     * describes the current status - so resuming an order clears the message
     * on its own instead of leaving a stale explanation on the page.
     */
    /**
     * The purchase event the browser may send once for this order, or null
     * when there is nothing to report. Wallet credit is not revenue (owner
     * decision, 2026-09-02): a wallet-only order sends no purchase and a
     * mixed order reports only the amount that went through Paylink. Computed
     * from the raw status, before forCustomer() folds Refunded into Cancelled.
     *
     * @return array{orderId: string, value: float, currency: string, items: list<array{id: string, name: string, quantity: int, price: float}>}|null
     */
    private function analytics(Order $order): ?array
    {
        $paymentHalalah = (int) ($order->getAttribute('payment_halalah') ?? 0);

        if ($paymentHalalah <= 0 || in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::Cancelled], true)) {
            return null;
        }

        return [
            'orderId' => (string) $order->getAttribute('public_id'),
            'value' => round($paymentHalalah / 100, 2),
            'currency' => (string) $order->getAttribute('currency'),
            'items' => array_values($order->items
                ->map(fn (OrderItem $item): array => [
                    'id' => (string) $item->getAttribute('sku'),
                    'name' => (string) $item->getAttribute('name_en'),
                    'quantity' => (int) $item->getAttribute('quantity'),
                    'price' => round(((int) $item->getAttribute('unit_price_halalah')) / 100, 2),
                ])
                ->all()),
        ];
    }

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

    /**
     * What the customer paid with, from the newest payment row - the method,
     * never the gateway. A receipt says "mada", not the name of the plumbing.
     */
    private function paymentMethod(Order $order): ?string
    {
        return PaymentMethodLabel::for($order->payments->first());
    }

    /**
     * The product image for an invoice line, resolved like the cart does.
     *
     * Coin items have no media row; they use the same storefront coin asset.
     * Everything else reads the product's first media entry through the same
     * path checks the cart applies before a URL leaves the server.
     */
    private function itemImageUrl(OrderItem $item): ?string
    {
        if ($item->service_type === ServiceType::Coins) {
            return '/images/store/coins/ut-coin-80.webp';
        }

        $variant = $item->productVariant;

        if (! $variant instanceof ProductVariant) {
            return null;
        }

        $product = $variant->product;

        if (! $product instanceof Product) {
            return null;
        }

        return $this->safeImageUrl($product->media->first());
    }

    private function safeImageUrl(?ProductMedia $media): ?string
    {
        if (! $media instanceof ProductMedia || $media->disk !== 'public') {
            return null;
        }

        $path = (string) $media->path;

        if ($path === '' || str_contains($path, '..')
            || preg_match('/\A[A-Za-z0-9_\/.\-]+\z/D', $path) !== 1) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /** @return array<string, mixed>|null */
    private function manualFulfillment(OrderItem $item, string $orderId, string $locale): ?array
    {
        if (! in_array($item->service_type, [ServiceType::FutChampions, ServiceType::Rivals], true)) {
            return null;
        }

        $configuration = is_array($item->configuration) ? $item->configuration : [];
        $localized = $locale === 'en';
        $routeParameters = [
            'order' => $orderId,
            'orderItem' => $item->public_id,
        ];
        $credentialsUrl = (bool) $item->getAttribute('secret_exists')
            ? route(
                $localized
                    ? 'localized.account.orders.items.credentials'
                    : 'account.orders.items.credentials',
                $routeParameters,
                absolute: false,
            )
            : null;
        $squadImageUrl = (bool) $item->getAttribute('squad_image_exists')
            ? route(
                $localized
                    ? 'localized.account.orders.items.squad-image'
                    : 'account.orders.items.squad-image',
                $routeParameters,
                absolute: false,
            )
            : null;

        return [
            'credentialsUrl' => $credentialsUrl,
            'squadImageUrl' => $squadImageUrl,
            'platform' => $item->platform->value,
            ...$this->safeManualConfiguration($configuration, $item->service_type),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, bool|int|string|null>
     */
    private function safeManualConfiguration(array $configuration, ServiceType $service): array
    {
        $safe = [];
        $launcher = $configuration['pc_store'] ?? null;

        if (in_array($launcher, ['ea_app', 'steam'], true)) {
            $safe['pcLauncher'] = $launcher;
        }

        if ($service === ServiceType::FutChampions) {
            $rank = $configuration['rank'] ?? null;
            $urgent = $configuration['urgent'] ?? null;
            $matchesPlayed = $configuration['matches_played'] ?? null;

            if (is_int($rank) && $rank >= 1 && $rank <= 6) {
                $safe['targetRank'] = $rank;
            }

            if (is_bool($urgent)) {
                $safe['urgent'] = $urgent;
            }

            if (is_int($matchesPlayed) && $matchesPlayed >= 0 && $matchesPlayed <= 100) {
                $safe['matchesPlayed'] = $matchesPlayed;
            }

            return $safe;
        }

        if (($configuration['mode'] ?? null) === 'weekly_matches') {
            $includedWins = $configuration['included_wins'] ?? null;
            $safe['weeklyMatches'] = true;

            if (is_int($includedWins) && $includedWins > 0 && $includedWins <= 100) {
                $safe['includedWins'] = $includedWins;
            }

            return $safe;
        }

        foreach (['current_division' => 'fromDivision', 'target_division' => 'toDivision'] as $key => $output) {
            $division = $configuration[$key] ?? null;

            if (is_string($division) && in_array($division, ['7', '6', '5', '4', '3', '2', '1', 'elite'], true)) {
                $safe[$output] = $division;
            }
        }

        return $safe;
    }
}
